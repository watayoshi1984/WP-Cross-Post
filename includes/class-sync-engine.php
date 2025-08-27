<?php
/**
 * WP Cross Post 同期エンジン
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-sync-engine.php';

/**
 * WP Cross Post 同期エンジンクラス
 *
 * 投稿同期処理を管理します。
 */
class WP_Cross_Post_Sync_Engine implements WP_Cross_Post_Sync_Engine_Interface {

    private $auth_manager;
    private $image_manager;
    private $error_manager;
    private $debug_manager;

    public function __construct($auth_manager, $image_manager, $error_manager) {
        $this->auth_manager = $auth_manager;
        $this->image_manager = $image_manager;
        $this->error_manager = $error_manager;
        $this->debug_manager = WP_Cross_Post_Debug_Manager::get_instance();
    }

    public function sync_post($post_id) {
        $this->debug_manager->log('投稿同期を開始', 'info', array(
            'post_id' => $post_id
        ));
        
        add_filter('https_ssl_verify', '__return_false'); // 暫定SSL対策
        add_filter('http_request_timeout', function() { return 30; });

        try {
            $config = WP_Cross_Post_Config::get_config();
            
            // メイン投稿の存在確認を強化
            $main_post = get_post($post_id);
            if (!$main_post || is_wp_error($main_post)) {
                $this->debug_manager->log('メイン投稿が見つかりません', 'error', array(
                    'post_id' => $post_id
                ));
                throw new Exception('メイン投稿が見つかりません');
            }

            // カテゴリー・タグ事前同期
            $this->sync_taxonomies_to_all_sites();

            // サブサイトの接続テストを追加
            $results = [];
            foreach ($config['sub_sites'] as $site) {
                $this->debug_manager->log('サイトへの接続テストを開始', 'info', array(
                    'site_url' => $site['url']
                ));
                
                $test_result = $this->test_site_connection($site);
                if (!$test_result['success']) {
                    $this->debug_manager->log('サイトへの接続テストに失敗', 'error', array(
                        'site_url' => $site['url'],
                        'error' => $test_result['error']
                    ));
                    
                    $results[] = [
                        'success' => false,
                        'error' => '接続失敗: ' . $test_result['error']
                    ];
                    continue;
                }

                // 実際の投稿処理
                $processed_content = $this->process_content($main_post->post_content);
                $media_ids = $this->image_manager->sync_media($site, $processed_content['media'], [$this, 'download_media']);
                
                // WordPress 6.5以降のアプリケーションパスワード対応
                $auth_header = $this->auth_manager->get_auth_header($site);
                
                $response = wp_remote_post($site['url'] . '/wp-json/wp/v2/posts', [
                    'headers' => [
                        'Authorization' => $auth_header
                    ],
                    'body' => [
                        'title' => $main_post->post_title,
                        'content' => $this->replace_media_urls($processed_content['html'], $media_ids),
                        'slug' => $this->generate_unique_slug($site, $main_post->post_name),
                        'status' => 'publish',
                        'categories' => $this->map_categories($site, $post_id),
                        'tags' => $this->map_tags($site, $post_id)
                    ]
                ]);

                $results[] = $this->handle_response($response);
            }

            $processed_results = $this->process_results($results);
            
            $this->debug_manager->log('投稿同期を完了', 'info', array(
                'post_id' => $post_id,
                'success_count' => count(array_filter($results, function($result) { return !isset($result['error']); })),
                'total_count' => count($results)
            ));
            
            return $processed_results;

        } catch (Exception $e) {
            // エラー詳細をログに記録
            $this->debug_manager->log('Sync Error: ' . $e->getMessage(), 'error', array(
                'post_id' => $post_id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            return $this->error_manager->handle_sync_error($e, '投稿同期');
        }
    }
    
    private function test_site_connection($site) {
        $this->debug_manager->log('サイト接続テスト', 'info', array(
            'site_url' => $site['url']
        ));
        
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site);
        
        $test_url = $site['url'] . '/wp-json/';
        $response = wp_remote_get($test_url, [
            'timeout' => 5,
            'headers' => [
                'Authorization' => $auth_header
            ]
        ]);

        $success = !is_wp_error($response) && 
                  wp_remote_retrieve_response_code($response) === 200;
                  
        $this->debug_manager->log('サイト接続テスト結果', 'info', array(
            'site_url' => $site['url'],
            'success' => $success
        ));
        
        return [
            'success' => $success,
            'error' => is_wp_error($response) ? 
                     $response->get_error_message() : 
                     wp_remote_retrieve_body($response)
        ];
    }

    /**
     * APIレスポンスの処理
     */
    private function handle_response($response) {
        if (is_wp_error($response)) {
            $this->debug_manager->log('APIレスポンスがエラー', 'error', array(
                'error' => $response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($response, 'APIレスポンス処理');
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            $this->debug_manager->log('APIレスポンスがエラー', 'error', array(
                'response_code' => $response_code,
                'response_body' => wp_remote_retrieve_body($response)
            ));
            return $this->error_manager->handle_api_error($response, 'APIレスポンス処理');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['id'])) {
            $this->debug_manager->log('無効なAPIレスポンス', 'error', array(
                'response_body' => $body
            ));
            return $this->error_manager->handle_general_error('無効なAPIレスポンス', 'invalid_api_response');
        }

        $this->debug_manager->log('APIレスポンスを処理', 'info', array(
            'remote_post_id' => $data['id']
        ));
        
        return $data['id'];
    }

    /**
     * 結果の処理
     */
    private function process_results($results) {
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($results as $result) {
            if (is_wp_error($result)) {
                $error_count++;
                $errors[] = $result->get_error_message();
            } else {
                $success_count++;
            }
        }

        $this->debug_manager->log('同期結果を処理', 'info', array(
            'success_count' => $success_count,
            'error_count' => $error_count
        ));
        
        if ($error_count > 0) {
            $error_message = sprintf(
                '同期が%d件成功し、%d件失敗しました。エラー: %s',
                $success_count,
                $error_count,
                implode(', ', $errors)
            );
            
            if ($success_count > 0) {
                // 一部成功した場合は警告として扱う
                $this->debug_manager->log('一部の同期に失敗', 'warning', array(
                    'success_count' => $success_count,
                    'error_count' => $error_count
                ));
                return new WP_Error('partial_sync_success', $error_message);
            } else {
                // 全て失敗した場合はエラーとして扱う
                $this->debug_manager->log('すべての同期に失敗', 'error', array(
                    'error_count' => $error_count
                ));
                return $this->error_manager->handle_general_error($error_message, 'sync_failed');
            }
        }

        return $results;
    }
    
    public function sync_taxonomies_to_all_sites() {
        $this->debug_manager->log('すべてのサイトのタクソノミーを同期', 'info');
    }
    
    private function process_content($content) {
        $this->debug_manager->log('コンテンツを処理', 'info', array(
            'content_length' => strlen($content)
        ));
    }
    
    private function download_media($url) {
        $this->debug_manager->log('メディアをダウンロード', 'info', array(
            'url' => $url
        ));
    }
    
    private function replace_media_urls($html, $media_ids) {
        $this->debug_manager->log('メディアURLを置換', 'info', array(
            'media_count' => count($media_ids)
        ));
    }
    
    private function generate_unique_slug($site, $slug) {
        $this->debug_manager->log('ユニークなスラッグを生成', 'info', array(
            'site_url' => $site['url'],
            'original_slug' => $slug
        ));
    }
    
    private function map_categories($site, $post_id) {
        $this->debug_manager->log('カテゴリーをマップ', 'info', array(
            'site_url' => $site['url'],
            'post_id' => $post_id
        ));
    }
    
    private function map_tags($site, $post_id) {
        $this->debug_manager->log('タグをマップ', 'info', array(
            'site_url' => $site['url'],
            'post_id' => $post_id
        ));
    }
}