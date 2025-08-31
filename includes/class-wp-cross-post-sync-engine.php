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
    private $media_sync_manager;
    private $sync_history_manager;

    public function __construct(
        $auth_manager, 
        $image_manager, 
        $error_manager,
        $debug_manager,
        $site_handler,
        $api_handler,
        $post_data_preparer,
        $rate_limit_manager,
        $media_sync_manager,
        $sync_history_manager
    ) {
        $this->auth_manager = $auth_manager;
        $this->image_manager = $image_manager;
        $this->error_manager = $error_manager;
        $this->debug_manager = $debug_manager;
        $this->site_handler = $site_handler;
        $this->api_handler = $api_handler;
        $this->post_data_preparer = $post_data_preparer;
        $this->rate_limit_manager = $rate_limit_manager;
        $this->media_sync_manager = $media_sync_manager;
        $this->sync_history_manager = $sync_history_manager;
    }

    /**
     * 投稿の同期
     *
     * @param int $post_id 投稿ID
     * @param array $selected_sites 選択されたサイトIDの配列
     * @return array|WP_Error 同期結果
     */
    public function sync_post($post_id, $selected_sites = array()) {
        // selected_sitesが文字列の場合、配列に変換する
        if (!is_array($selected_sites)) {
            $selected_sites = array($selected_sites);
        }
        
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
            
            // グローバル設定がtrueの場合のみ非同期処理を実行
            if ($async_sync) {
                // 非同期処理で同期
                return $this->sync_post_async($post_id, $selected_sites);
            } else {
                // 同期処理で同期（デフォルト）
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
        // selected_sitesが文字列の場合、配列に変換する
        if (!is_array($selected_sites)) {
            $selected_sites = array($selected_sites);
        }
        
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
            'selected_site_count' => is_array($selected_sites) ? count($selected_sites) : 0
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
            
            // WP-Cronが有効かどうかを確認
            if (!wp_next_scheduled('wp_cross_post_process_async_sync', array($post_id, $selected_sites))) {
                // すぐに処理をスケジュール
                $schedule_result = wp_schedule_single_event(time(), 'wp_cross_post_process_async_sync', array($post_id, $selected_sites));
                
                if ($schedule_result) {
                    $this->debug_manager->log('非同期同期処理をスケジュール', 'info', array(
                        'post_id' => $post_id,
                        'task_id' => $task_id
                    ));
                    
                    return array(
                        'status' => 'scheduled',
                        'task_id' => $task_id,
                        'message' => '同期処理をスケジュールしました。'
                    );
                } else {
                    $this->debug_manager->log('非同期同期処理のスケジュールに失敗しました', 'error', array(
                        'post_id' => $post_id,
                        'task_id' => $task_id,
                        'reason' => 'wp_schedule_single_event returned false'
                    ));
                    
                    // スケジュールに失敗した場合は、タスクポストを削除
                    wp_delete_post($task_id, true);
                    
                    return $this->error_manager->handle_general_error('非同期処理のスケジュールに失敗しました。', 'async_schedule_failed');
                }
            } else {
                $this->debug_manager->log('非同期同期処理は既にスケジュールされています', 'info', array(
                    'post_id' => $post_id,
                    'task_id' => $task_id
                ));
                
                return array(
                    'status' => 'scheduled',
                    'task_id' => $task_id,
                    'message' => '同期処理は既にスケジュールされています。'
                );
            }
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
            if (!empty($selected_sites) && is_array($selected_sites) && !in_array($site['id'], $selected_sites)) {
                continue;
            }
            
            // 認証情報を含む完全なサイトデータを取得
            $site = $this->site_handler->get_site_data($site['id'], true);
            if (!$site) {
                $this->debug_manager->log('無効なサイトID', 'error', array(
                    'site_id' => $site['id']
                ));
                continue;
            }
            
            // 同期履歴レコードの作成
            $sync_record_id = $this->sync_history_manager->create_sync_record(
                $site['id'], 
                $main_post->ID, 
                'create', 
                $main_post->post_status
            );
            
            if (!$sync_record_id) {
                $this->debug_manager->log('同期履歴レコードの作成に失敗', 'error', array(
                    'site_id' => $site['id'],
                    'post_id' => $main_post->ID
                ));
                continue;
            }
            
            // 同期開始ステータスに更新
            $this->sync_history_manager->update_sync_status($site['id'], $main_post->ID, 'syncing');
            
            $this->debug_manager->log('サイトへの接続テストを開始', 'info', array(
                'site_url' => $site['url'],
                'sync_record_id' => $sync_record_id
            ));
            
            $test_result = $this->test_site_connection($site);
            if (!$test_result['success']) {
                $error_message = $test_result['error'];
                
                $this->debug_manager->log('サイトへの接続テストに失敗', 'error', array(
                    'site_url' => $site['url'],
                    'error' => $error_message
                ));
                
                // 同期履歴を失敗状態に更新
                $this->sync_history_manager->update_sync_status($site['id'], $main_post->ID, 'failed', null, '接続失敗: ' . $error_message);
                
                $results[$site['id']] = $this->error_manager->handle_general_error(
                    '接続失敗: ' . $error_message, 
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
                if ($media_result && isset($media_result['id'])) {
                    $post_data['featured_media'] = $media_result['id'];
                } else {
                    // 失敗時はfeatured_mediaを送信しない
                    unset($post_data['featured_media']);
                }
            }
            
            // レート制限を考慮した投稿の同期
            // $remote_post_id = $this->rate_limit_manager->sync_with_rate_limit($site, $post_data);
            $remote_post_id = $this->api_handler->sync_post($site, $post_data);
            
            // レート制限エラーの処理
            if (is_wp_error($remote_post_id) && $remote_post_id->get_error_code() === 'rate_limit') {
                $this->rate_limit_manager->handle_rate_limit($site['url'], $remote_post_id);
                // 再試行ロジックをここに実装するか、またはエラーをそのまま返す
                // ここではエラーをそのまま返す
            }
            
            if (is_wp_error($remote_post_id)) {
                // 同期履歴を失敗状態に更新
                $this->sync_history_manager->update_sync_status(
                    $site['id'], 
                    $main_post->ID, 
                    'failed', 
                    null, 
                    $remote_post_id->get_error_message()
                );
                
                $results[$site['id']] = $remote_post_id;
            } else {
                // 同期履歴を成功状態に更新
                $this->sync_history_manager->update_sync_status(
                    $site['id'], 
                    $main_post->ID, 
                    'success', 
                    $remote_post_id
                );
                
                $results[$site['id']] = $remote_post_id;
                
                // 同期情報を保存（従来互換性のため残しておく）
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
        $request_context = array(); // site_id => ['site' => site, 'post_data' => post_data]
        
        // リクエストの準備
        foreach ($sites as $site) {
            // 選択されたサイトのみ処理する
            if (!empty($selected_sites) && is_array($selected_sites) && !in_array($site['id'], $selected_sites)) {
                continue;
            }
            
            // 認証情報を含む完全なサイトデータを取得
            $site = $this->site_handler->get_site_data($site['id'], true);
            if (!$site) {
                $this->debug_manager->log('無効なサイトID', 'error', array(
                    'site_id' => $site['id']
                ));
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
                if ($media_result && isset($media_result['id'])) {
                    $post_data['featured_media'] = $media_result['id'];
                } else {
                    // 失敗時はfeatured_mediaを送信しない（投稿全体の失敗を防ぐ）
                    unset($post_data['featured_media']);
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
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ),
                    'body' => wp_json_encode($post_data)
                )
            );

            // store context for robust response handling
            $request_context[$site['id']] = array(
                'site' => $site,
                'post_data' => $post_data
            );
        }
        
        // 並列リクエストを実行
        $responses = $this->execute_parallel_requests($requests);
        
        // レスポンスを処理
        foreach ($responses as $site_id => $response) {
            $context = isset($request_context[$site_id]) ? $request_context[$site_id] : null;
            if ($context && is_array($context)) {
                $remote_post_id = $this->handle_response_with_lookup($response, $context['site'], $context['post_data']);
            } else {
            $remote_post_id = $this->handle_response($response);
            }
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

        // 正常（オブジェクトにid）
        if (is_array($data) && isset($data['id'])) {
            $this->debug_manager->log('APIレスポンスを処理', 'info', array(
                'remote_post_id' => $data['id']
            ));
            return $data['id'];
        }

        // LocationヘッダーからID抽出のフォールバック
        $location = wp_remote_retrieve_header($response, 'location');
        if (!empty($location) && preg_match('#/wp-json/[^/]+/v\d+/posts/(\d+)#', $location, $m)) {
            return (int) $m[1];
        }
        if (!empty($location) && preg_match('#/wp-json/wp/v2/posts/(\d+)#', $location, $m2)) {
            return (int) $m2[1];
        }
        if (!empty($location) && preg_match('#/posts/(\d+)#', $location, $m3)) {
            return (int) $m3[1];
        }

        // 一部環境で空配列や空ボディで200/201が返るケースのフォールバック
        // 空配列や空文字の場合も、Locationヘッダーがない時に限り、これは後続のサイト固有情報が必要なため
        // 呼び出し側でスラッグ/タイトル検索に委ねるのが安全。ここでは明確なエラーにせず詳細なログを残す。
            $this->debug_manager->log('無効なAPIレスポンス', 'error', array(
                'response_body' => $body
            ));
            return $this->error_manager->handle_general_error('無効なAPIレスポンス', 'invalid_api_response');
        }

    /**
     * APIレスポンスの処理（フォールバック: Location, slug, title 検索）
     */
    private function handle_response_with_lookup($response, $site, $post_data) {
        if (is_wp_error($response)) {
            return $this->error_manager->handle_api_error($response, 'APIレスポンス処理');
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            return $this->error_manager->handle_api_error($response, 'APIレスポンス処理');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (is_array($data) && isset($data['id'])) {
            return (int) $data['id'];
        }

        // Location header fallback
        $location = wp_remote_retrieve_header($response, 'location');
        if (!empty($location)) {
            if (preg_match('#/wp-json/[^/]+/v\\d+/posts/(\\d+)#', $location, $m) ||
                preg_match('#/wp-json/wp/v2/posts/(\\d+)#', $location, $m) ||
                preg_match('#/posts/(\\d+)#', $location, $m)) {
                return (int) $m[1];
            }
        }

        // Slug lookup
        $slug = isset($post_data['slug']) ? $post_data['slug'] : '';
        $auth_header = $this->auth_manager->get_auth_header($site);
        if (!empty($slug)) {
            $slug_for_query = rawurldecode((string) $slug);
            $query = add_query_arg(array(
                'slug' => $slug_for_query,
                'status' => 'publish,future,draft,private',
                'context' => 'edit',
                'per_page' => 1,
                'orderby' => 'date',
                'order' => 'desc'
            ), rtrim($site['url'], '/') . '/wp-json/wp/v2/posts');

            $lookup = wp_remote_get($query, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Accept' => 'application/json'
                )
            ));
            if (!is_wp_error($lookup) && wp_remote_retrieve_response_code($lookup) === 200) {
                $list = json_decode(wp_remote_retrieve_body($lookup), true);
                if (is_array($list) && isset($list[0]['id'])) {
                    return (int) $list[0]['id'];
                }
            }
        }

        // Title search fallback
        $title = isset($post_data['title']) ? $post_data['title'] : '';
        if (is_array($title)) {
            $title = isset($title['raw']) ? $title['raw'] : (isset($title['rendered']) ? wp_strip_all_tags($title['rendered']) : '');
        }
        if (!empty($title)) {
            $query = add_query_arg(array(
                // add_query_arg handles encoding; avoid double-encoding which can break matching
                'search' => wp_strip_all_tags((string) $title),
                'status' => 'publish,future,draft,private',
                'context' => 'edit',
                'per_page' => 5,
                'orderby' => 'date',
                'order' => 'desc'
            ), rtrim($site['url'], '/') . '/wp-json/wp/v2/posts');
            $lookup = wp_remote_get($query, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Accept' => 'application/json'
                )
            ));
            if (!is_wp_error($lookup) && wp_remote_retrieve_response_code($lookup) === 200) {
                $list = json_decode(wp_remote_retrieve_body($lookup), true);
                if (is_array($list) && !empty($list)) {
                    $picked = null;
                    foreach ($list as $item) {
                        if (isset($item['id'])) {
                            if (!empty($slug) && isset($item['slug']) && $item['slug'] === $slug) {
                                $picked = $item; break;
                            }
                            if ($picked === null) {
                                $remote_title = '';
                                if (isset($item['title'])) {
                                    if (is_array($item['title']) && isset($item['title']['rendered'])) {
                                        $remote_title = wp_strip_all_tags($item['title']['rendered']);
                                    } elseif (is_string($item['title'])) {
                                        $remote_title = $item['title'];
                                    }
                                }
                                if (!empty($remote_title) && wp_strip_all_tags((string) $title) === $remote_title) {
                                    $picked = $item;
                                }
                            }
                        }
                    }
                    if ($picked === null) {
                        $picked = $list[0];
                    }
                    if (isset($picked['id'])) {
                        return (int) $picked['id'];
                    }
                }
            }
        }

        // Give up with explicit error
        $this->debug_manager->log('無効なAPIレスポンス（フォールバックでもID特定不可）', 'error', array(
            'response_body' => $body
        ));
        return $this->error_manager->handle_general_error('無効なAPIレスポンス', 'invalid_api_response');
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
                // 一部のサイトで失敗した場合のログ記録を改善
                $this->debug_manager->log('サイトでの同期に失敗', 'error', array(
                    'site_id' => $site_id,
                    'error' => $result->get_error_message()
                ));
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
                    'error_count' => $error_count,
                    'errors' => $errors
                ));
                return new WP_Error('partial_sync_success', $error_message, array('errors' => $errors));
            } else {
                // 全て失敗した場合はエラーとして扱う
                $this->debug_manager->log('すべての同期に失敗', 'error', array(
                    'error_count' => $error_count,
                    'errors' => $errors
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
        
        // selected_sitesが文字列の場合、配列に変換する
        if (!is_array($selected_sites)) {
            $selected_sites = array($selected_sites);
        }
        
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
            'posts_per_page' => -1, // すべてのタスクを取得
        ));
        
        if (!empty($tasks)) {
            foreach ($tasks as $task) {
                wp_delete_post($task->ID, true);
            }
        }
        
        return $result;
    }

    /**
     * 新しいテーブル構造を使用したメディア同期
     */
    private function sync_media_with_tracking($site, $media_list, $post_id) {
        $sync_results = array();
        
        if (empty($media_list) || !is_array($media_list)) {
            return $sync_results;
        }
        
        foreach ($media_list as $media_id) {
            // メディア同期レコードを作成
            $media_attachment = get_post($media_id);
            if (!$media_attachment) {
                continue;
            }
            
            $file_url = wp_get_attachment_url($media_id);
            $file_size = filesize(get_attached_file($media_id));
            $mime_type = get_post_mime_type($media_id);
            
            // 同期レコード作成
            $record_id = $this->media_sync_manager->create_sync_record(
                $site['id'],
                $media_id,
                $file_url,
                $file_size,
                $mime_type
            );
            
            if ($record_id) {
                // 同期中ステータスに更新
                $this->media_sync_manager->update_sync_status($site['id'], $media_id, 'uploading');
                
                // 実際のメディア同期実行
                $sync_result = $this->image_manager->sync_media($site, array($media_id), array($this, 'download_media'));
                
                if (is_wp_error($sync_result)) {
                    // 失敗ステータスに更新
                    $this->media_sync_manager->update_sync_status(
                        $site['id'], 
                        $media_id, 
                        'failed', 
                        null, 
                        null, 
                        $sync_result->get_error_message()
                    );
                } else {
                    // 成功ステータスに更新
                    $remote_media_id = is_array($sync_result) && isset($sync_result[0]) ? $sync_result[0] : $sync_result;
                    $this->media_sync_manager->update_sync_status(
                        $site['id'], 
                        $media_id, 
                        'success', 
                        $remote_media_id, 
                        $file_url
                    );
                }
                
                $sync_results[$media_id] = $sync_result;
            }
        }
        
        return $sync_results;
    }

    /**
     * アイキャッチ画像の同期（新しいテーブル管理）
     */
    private function sync_featured_media_with_tracking($site, $featured_media_id, $post_id) {
        if (!$featured_media_id) {
            return null;
        }
        
        // 既に同期済みかチェック
        $existing_remote_id = $this->media_sync_manager->get_remote_media_id($site['id'], $featured_media_id);
        if ($existing_remote_id) {
            return array('remote_media_id' => $existing_remote_id);
        }
        
        // メディア情報取得
        $media_attachment = get_post($featured_media_id);
        if (!$media_attachment) {
            return null;
        }
        
        $file_url = wp_get_attachment_url($featured_media_id);
        $file_size = filesize(get_attached_file($featured_media_id));
        $mime_type = get_post_mime_type($featured_media_id);
        
        // 同期レコード作成
        $record_id = $this->media_sync_manager->create_sync_record(
            $site['id'],
            $featured_media_id,
            $file_url,
            $file_size,
            $mime_type
        );
        
        if (!$record_id) {
            return null;
        }
        
        // 同期中ステータスに更新
        $this->media_sync_manager->update_sync_status($site['id'], $featured_media_id, 'uploading');
        
        // 実際のアイキャッチ画像同期実行
        $sync_result = $this->image_manager->sync_featured_image($site, $featured_media_id, $post_id);
        
        if (is_wp_error($sync_result)) {
            // 失敗ステータスに更新
            $this->media_sync_manager->update_sync_status(
                $site['id'], 
                $featured_media_id, 
                'failed', 
                null, 
                null, 
                $sync_result->get_error_message()
            );
            return null;
        } else {
            // 成功ステータスに更新
            $remote_media_id = isset($sync_result['id']) ? $sync_result['id'] : $sync_result;
            $this->media_sync_manager->update_sync_status(
                $site['id'], 
                $featured_media_id, 
                'success', 
                $remote_media_id, 
                $file_url
            );
            
            return array('remote_media_id' => $remote_media_id);
        }
    }
}