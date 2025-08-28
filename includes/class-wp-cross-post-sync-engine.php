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
    private $site_handler;
    private $api_handler;
    private $post_data_preparer;
    private $rate_limit_manager;

    public function __construct(
        $auth_manager, 
        $image_manager, 
        $error_manager,
        $debug_manager,
        $site_handler,
        $api_handler,
        $post_data_preparer,
        $rate_limit_manager
    ) {
        $this->auth_manager = $auth_manager;
        $this->image_manager = $image_manager;
        $this->error_manager = $error_manager;
        $this->debug_manager = $debug_manager;
        $this->site_handler = $site_handler;
        $this->api_handler = $api_handler;
        $this->post_data_preparer = $post_data_preparer;
        $this->rate_limit_manager = $rate_limit_manager;
    }

    /**
     * 投稿の同期
     *
     * @param int $post_id 投稿ID
     * @param array $selected_sites 選択されたサイトIDの配列
     * @return array|WP_Error 同期結果
     */
    public function sync_post($post_id, $selected_sites = array()) {
        $this->debug_manager->log('投稿同期を開始', 'info', array(
            'post_id' => $post_id,
            'selected_site_count' => count($selected_sites)
        ));
        
        add_filter('https_ssl_verify', '__return_false'); // 暫定SSL対策
        add_filter('http_request_timeout', function() { return 30; });

        try {
            // メイン投稿の存在確認
            $main_post = get_post($post_id);
            if (!$main_post || is_wp_error($main_post)) {
                $this->debug_manager->log('メイン投稿が見つかりません', 'error', array(
                    'post_id' => $post_id
                ));
                return $this->error_manager->handle_general_error('メイン投稿が見つかりません', 'invalid_post');
            }

            // カテゴリー・タグ事前同期
            $this->sync_taxonomies_to_all_sites();

            // 設定から非同期処理の有効/無効を取得
            $config_manager = WP_Cross_Post_Config_Manager::get_settings();
            $async_sync = isset($config_manager['sync_settings']['async_sync']) ? 
                          $config_manager['sync_settings']['async_sync'] : false;
            
            if ($async_sync) {
                // 非同期処理で同期
                return $this->sync_post_async($post_id, $selected_sites);
            } else {
                // 同期処理で同期
                return $this->sync_post_sync($post_id, $selected_sites);
            }

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
    
    /**
     * 同期処理で投稿を同期
     */
    private function sync_post_sync($post_id, $selected_sites) {
        $this->debug_manager->log('同期処理で投稿同期を開始', 'info', array(
            'post_id' => $post_id,
            'selected_site_count' => count($selected_sites)
        ));
        
        try {
            // メイン投稿の存在確認
            $main_post = get_post($post_id);
            if (!$main_post || is_wp_error($main_post)) {
                $this->debug_manager->log('メイン投稿が見つかりません', 'error', array(
                    'post_id' => $post_id
                ));
                return $this->error_manager->handle_general_error('メイン投稿が見つかりません', 'invalid_post');
            }

            // サブサイトへの同期処理
            $results = array();
            $sites = $this->site_handler->get_sites();
            
            // 設定から並列処理の有効/無効を取得
            $config_manager = WP_Cross_Post_Config_Manager::get_settings();
            $parallel_sync = isset($config_manager['sync_settings']['parallel_sync']) ? 
                             $config_manager['sync_settings']['parallel_sync'] : false;
            
            // 選択されたサイトのみを処理
            if ($parallel_sync) {
                // 並列処理で同期
                $results = $this->sync_posts_parallel($main_post, $sites, $selected_sites);
            } else {
                // シリアル処理で同期
                $results = $this->sync_posts_serial($main_post, $sites, $selected_sites);
            }

            $processed_results = $this->process_results($results);
            
            $this->debug_manager->log('投稿同期を完了', 'info', array(
                'post_id' => $post_id,
                'success_count' => count(array_filter($results, function($result) { return !is_wp_error($result); })),
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
    
    /**
     * 非同期処理で投稿を同期
     */
    private function sync_post_async($post_id, $selected_sites) {
        $this->debug_manager->log('非同期処理で投稿同期を開始', 'info', array(
            'post_id' => $post_id,
            'selected_site_count' => count($selected_sites)
        ));
        
        // 非同期処理用のポストを作成
        $post_data = array(
            'post_title' => 'Async Sync Task - Post ' . $post_id,
            'post_type' => 'wp_cross_post_async',
            'post_status' => 'publish',
        );
        
        $task_id = wp_insert_post($post_data);
        
        if ($task_id) {
            // メタデータとして投稿IDと選択されたサイトを保存
            update_post_meta($task_id, '_wp_cross_post_post_id', $post_id);
            update_post_meta($task_id, '_wp_cross_post_selected_sites', $selected_sites);
            
            // すぐに処理をスケジュール
            wp_schedule_single_event(time(), 'wp_cross_post_process_async_sync', array($post_id, $selected_sites));
            
            $this->debug_manager->log('非同期同期処理をスケジュール', 'info', array(
                'post_id' => $post_id,
                'task_id' => $task_id
            ));
            
            return array(
                'status' => 'scheduled',
                'task_id' => $task_id,
                'message' => '同期処理をスケジュールしました。'
            );
        }
        
        return $this->error_manager->handle_general_error('非同期処理のスケジュールに失敗しました。', 'async_schedule_failed');
    }
    
    /**
     * シリアル処理で投稿を同期
     */
    private function sync_posts_serial($main_post, $sites, $selected_sites) {
        $results = array();
        
        foreach ($sites as $site) {
            // 選択されたサイトのみ処理する
            if (!empty($selected_sites) && !in_array($site['id'], $selected_sites)) {
                continue;
            }
            
            $this->debug_manager->log('サイトへの接続テストを開始', 'info', array(
                'site_url' => $site['url']
            ));
            
            $test_result = $this->test_site_connection($site);
            if (!$test_result['success']) {
                $this->debug_manager->log('サイトへの接続テストに失敗', 'error', array(
                    'site_url' => $site['url'],
                    'error' => $test_result['error']
                ));
                
                $results[$site['id']] = $this->error_manager->handle_general_error(
                    '接続失敗: ' . $test_result['error'], 
                    'connection_failed'
                );
                continue;
            }

            // 実際の投稿処理
            $processed_content = $this->process_content($main_post->post_content);
            
            // メディアの同期
            $media_ids = $this->image_manager->sync_media($site, $processed_content['media'], array($this, 'download_media'));
            
            // WordPress 6.5以降のアプリケーションパスワード対応
            $auth_header = $this->auth_manager->get_auth_header($site);
            
            // 投稿データの準備
            $post_data = $this->post_data_preparer->prepare_post_data($main_post, array($site['id']));
            
            // アイキャッチ画像の同期
            if (isset($post_data['featured_media'])) {
                $media_result = $this->image_manager->sync_featured_image($site, $post_data['featured_media'], $main_post->ID);
                if ($media_result) {
                    $post_data['featured_media'] = $media_result['id'];
                }
            }
            
            // レート制限を考慮した投稿の同期
            $remote_post_id = $this->rate_limit_manager->sync_with_rate_limit($site, $post_data);
            
            if (is_wp_error($remote_post_id)) {
                $results[$site['id']] = $remote_post_id;
            } else {
                $results[$site['id']] = $remote_post_id;
                
                // 同期情報を保存
                $this->save_sync_info($main_post->ID, $site['id'], $remote_post_id);
            }
        }
        
        return $results;
    }
    
    /**
     * 並列処理で投稿を同期
     */
    private function sync_posts_parallel($main_post, $sites, $selected_sites) {
        $results = array();
        $requests = array();
        
        // リクエストの準備
        foreach ($sites as $site) {
            // 選択されたサイトのみ処理する
            if (!empty($selected_sites) && !in_array($site['id'], $selected_sites)) {
                continue;
            }
            
            $this->debug_manager->log('サイトへの接続テストを開始', 'info', array(
                'site_url' => $site['url']
            ));
            
            $test_result = $this->test_site_connection($site);
            if (!$test_result['success']) {
                $this->debug_manager->log('サイトへの接続テストに失敗', 'error', array(
                    'site_url' => $site['url'],
                    'error' => $test_result['error']
                ));
                
                $results[$site['id']] = $this->error_manager->handle_general_error(
                    '接続失敗: ' . $test_result['error'], 
                    'connection_failed'
                );
                continue;
            }

            // 実際の投稿処理
            $processed_content = $this->process_content($main_post->post_content);
            
            // メディアの同期
            $media_ids = $this->image_manager->sync_media($site, $processed_content['media'], array($this, 'download_media'));
            
            // WordPress 6.5以降のアプリケーションパスワード対応
            $auth_header = $this->auth_manager->get_auth_header($site);
            
            // 投稿データの準備
            $post_data = $this->post_data_preparer->prepare_post_data($main_post, array($site['id']));
            
            // アイキャッチ画像の同期
            if (isset($post_data['featured_media'])) {
                $media_result = $this->image_manager->sync_featured_image($site, $post_data['featured_media'], $main_post->ID);
                if ($media_result) {
                    $post_data['featured_media'] = $media_result['id'];
                }
            }
            
            // リクエストを準備
            $requests[$site['id']] = array(
                'url' => $site['url'] . '/wp-json/wp/v2/posts',
                'args' => array(
                    'method' => 'POST',
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => $auth_header,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode($post_data)
                )
            );
        }
        
        // 並列リクエストを実行
        $responses = $this->execute_parallel_requests($requests);
        
        // レスポンスを処理
        foreach ($responses as $site_id => $response) {
            $remote_post_id = $this->handle_response($response);
            if (is_wp_error($remote_post_id)) {
                $results[$site_id] = $remote_post_id;
            } else {
                $results[$site_id] = $remote_post_id;
                
                // 同期情報を保存
                $this->save_sync_info($main_post->ID, $site_id, $remote_post_id);
            }
        }
        
        return $results;
    }
    
    /**
     * 並列リクエストを実行
     */
    private function execute_parallel_requests($requests) {
        $responses = array();
        
        // WordPress 6.5以降の非同期処理機能を使用
        if (function_exists('wp_remote_request_async')) {
            // 非同期リクエストを実行
            $async_requests = array();
            foreach ($requests as $site_id => $request) {
                $async_requests[$site_id] = wp_remote_request_async(
                    $request['url'],
                    $request['args']
                );
            }
            
            // レスポンスを取得
            foreach ($async_requests as $site_id => $async_request) {
                $responses[$site_id] = wp_remote_retrieve_response($async_request);
            }
        } else {
            // WordPress 6.5より前のバージョンでは、curl_multiを使用
            $responses = $this->execute_curl_multi_requests($requests);
        }
        
        return $responses;
    }
    
    /**
     * curl_multiを使用して並列リクエストを実行
     */
    private function execute_curl_multi_requests($requests) {
        $responses = array();
        $curl_handles = array();
        $mh = curl_multi_init();
        
        // cURLハンドルを初期化
        foreach ($requests as $site_id => $request) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $request['url']);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request['args']['method']);
            curl_setopt($ch, CURLOPT_TIMEOUT, $request['args']['timeout']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: ' . $request['args']['headers']['Authorization'],
                'Content-Type: ' . $request['args']['headers']['Content-Type']
            ));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['args']['body']);
            
            curl_multi_add_handle($mh, $ch);
            $curl_handles[$site_id] = $ch;
        }
        
        // 並列リクエストを実行
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);
        
        // レスポンスを取得
        foreach ($curl_handles as $site_id => $ch) {
            $responses[$site_id] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($mh);
        
        return $responses;
    }
    
    /**
     * サイト接続テスト
     */
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
        $errors = array();

        foreach ($results as $site_id => $result) {
            if (is_wp_error($result)) {
                $error_count++;
                $errors[$site_id] = $result->get_error_message();
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
                '同期が%d件成功し、%d件失敗しました。',
                $success_count,
                $error_count
            );
            
            if ($success_count > 0) {
                // 一部成功した場合は警告として扱う
                $this->debug_manager->log('一部の同期に失敗', 'warning', array(
                    'success_count' => $success_count,
                    'error_count' => $error_count
                ));
                return new WP_Error('partial_sync_success', $error_message, array('errors' => $errors));
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
    
    /**
     * タクソノミーの全サイト同期
     */
    public function sync_taxonomies_to_all_sites() {
        $this->debug_manager->log('すべてのサイトのタクソノミーを同期', 'info');
        
        // Site_Handlerを使用して全サイトのタクソノミーを同期
        $result = $this->site_handler->sync_all_sites_taxonomies();
        
        if (is_wp_error($result)) {
            $this->debug_manager->log('タクソノミーの同期に失敗', 'error', array(
                'error' => $result->get_error_message()
            ));
            return $result;
        }
        
        $this->debug_manager->log('すべてのサイトのタクソノミーを同期完了', 'info', array(
            'success_sites' => count($result['success_sites']),
            'failed_sites' => count($result['failed_sites'])
        ));
        
        return $result;
    }
    
    /**
     * コンテンツの処理
     */
    private function process_content($content) {
        $this->debug_manager->log('コンテンツを処理', 'info', array(
            'content_length' => strlen($content)
        ));
        
        // ここにコンテンツ処理ロジックを実装
        // 例えば、ブロックエディターのコンテンツを解析し、
        // 画像URLを抽出してメディア同期の準備をするなど
        
        return array(
            'html' => $content,
            'media' => array() // ここに抽出したメディア情報を格納
        );
    }
    
    /**
     * メディアのダウンロード
     */
    private function download_media($url) {
        $this->debug_manager->log('メディアをダウンロード', 'info', array(
            'url' => $url
        ));
        
        // ここにメディアダウンロードロジックを実装
        // 例えば、指定されたURLからメディアファイルをダウンロードし、
        // ローカルに一時保存するなど
        
        return '';
    }
    
    /**
     * メディアURLの置換
     */
    private function replace_media_urls($html, $media_ids) {
        $this->debug_manager->log('メディアURLを置換', 'info', array(
            'media_count' => count($media_ids)
        ));
        
        // ここにメディアURL置換ロジックを実装
        // 例えば、HTML内のメディアURLを同期先サイトのURLに置換するなど
        
        return $html;
    }
    
    /**
     * ユニークなスラッグの生成
     */
    private function generate_unique_slug($site, $slug) {
        $this->debug_manager->log('ユニークなスラッグを生成', 'info', array(
            'site_url' => $site['url'],
            'original_slug' => $slug
        ));
        
        // ここにユニークなスラッグ生成ロジックを実装
        // 例えば、既存のスラッグと重複しないように連番を付けるなど
        
        return $slug;
    }
    
    /**
     * カテゴリーのマッピング
     */
    private function map_categories($site, $post_id) {
        $this->debug_manager->log('カテゴリーをマップ', 'info', array(
            'site_url' => $site['url'],
            'post_id' => $post_id
        ));
        
        // ここにカテゴリーのマッピングロジックを実装
        // 例えば、元のサイトのカテゴリーIDを同期先サイトのカテゴリーIDに変換するなど
        
        return array();
    }
    
    /**
     * タグのマッピング
     */
    private function map_tags($site, $post_id) {
        $this->debug_manager->log('タグをマップ', 'info', array(
            'site_url' => $site['url'],
            'post_id' => $post_id
        ));
        
        // ここにタグのマッピングロジックを実装
        // 例えば、元のサイトのタグIDを同期先サイトのタグIDに変換するなど
        
        return array();
    }
    
    /**
     * 同期情報の保存
     */
    private function save_sync_info($local_post_id, $site_id, $remote_post_id) {
        $sync_info = get_post_meta($local_post_id, '_wp_cross_post_sync_info', true);
        if (!is_array($sync_info)) {
            $sync_info = array();
        }
        $sync_info[$site_id] = array(
            'remote_post_id' => $remote_post_id,
            'sync_time' => current_time('mysql'),
            'status' => is_wp_error($remote_post_id) ? 'error' : 'success'
        );
        update_post_meta($local_post_id, '_wp_cross_post_sync_info', $sync_info);
    }
    
    /**
     * 非同期同期処理を実行
     */
    public function process_async_sync($post_id, $selected_sites) {
        $this->debug_manager->log('非同期同期処理を開始', 'info', array(
            'post_id' => $post_id
        ));
        
        // 同期処理を実行
        $result = $this->sync_post_sync($post_id, $selected_sites);
        
        if (is_wp_error($result)) {
            $this->debug_manager->log('非同期同期処理に失敗', 'error', array(
                'post_id' => $post_id,
                'error' => $result->get_error_message()
            ));
        } else {
            $this->debug_manager->log('非同期同期処理が成功', 'info', array(
                'post_id' => $post_id
            ));
        }
        
        // 非同期処理用のポストを削除
        $tasks = get_posts(array(
            'post_type' => 'wp_cross_post_async',
            'meta_query' => array(
                array(
                    'key' => '_wp_cross_post_post_id',
                    'value' => $post_id,
                )
            ),
            'posts_per_page' => 1,
        ));
        
        if (!empty($tasks)) {
            wp_delete_post($tasks[0]->ID, true);
        }
        
        return $result;
    }
}