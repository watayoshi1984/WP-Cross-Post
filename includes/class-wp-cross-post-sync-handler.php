<?php
/**
 * WP Cross Post 同期ハンドラー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-handler.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-sync-handler.php';

/**
 * WP Cross Post 同期ハンドラークラス
 *
 * 投稿同期処理を管理します。
 */
class WP_Cross_Post_Sync_Handler implements WP_Cross_Post_Sync_Handler_Interface {

    private $api_handler;
    private $debug_manager;
    private $site_handler;
    private $auth_manager;
    private $image_manager;
    private $post_data_preparer;
    private $error_manager;
    private $rate_limit_manager;

    public function __construct($api_handler, $debug_manager, $site_handler, $auth_manager, $image_manager, $post_data_preparer, $error_manager, $rate_limit_manager) {
        $this->api_handler = $api_handler;
        $this->debug_manager = $debug_manager;
        $this->site_handler = $site_handler;
        $this->auth_manager = $auth_manager;
        $this->image_manager = $image_manager;
        $this->post_data_preparer = $post_data_preparer;
        $this->error_manager = $error_manager;
        $this->rate_limit_manager = $rate_limit_manager;
    }

    /**
     * Build a human-friendly site label robustly for UI.
     * Falls back to site URL host, then site_id, then 'Unknown Site'.
     */
    private function build_site_label($site_id, $site) {
        // Prefer configured name
        if (is_array($site)) {
            if (!empty($site['name'])) {
                return (string) $site['name'];
            }
            if (!empty($site['url'])) {
                $host = parse_url($site['url'], PHP_URL_HOST);
                if (!empty($host)) {
                    return (string) $host;
                }
            }
        }
        if (!empty($site_id)) {
            return (string) $site_id;
        }
        return 'Unknown Site';
    }

    public function ajax_sync_post() {
        check_ajax_referer('wp_cross_post_sync', 'nonce');

        if (!current_user_can('edit_posts')) {
            $this->debug_manager->log('権限のないユーザーが同期を試みました', 'warning');
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $post_id = intval($_POST['post_id']);
        $selected_sites = isset($_POST['selected_sites']) ? array_map('sanitize_text_field', (array) $_POST['selected_sites']) : array();
        $per_site_settings = array();
        if (isset($_POST['per_site_settings'])) {
            // Sanitize nested array
            $raw = $_POST['per_site_settings'];
            if (is_array($raw)) {
                foreach ($raw as $sid => $conf) {
                    $sid_clean = sanitize_text_field($sid);
                    $item = array();
                    if (isset($conf['status'])) $item['status'] = sanitize_text_field($conf['status']);
                    if (isset($conf['date'])) $item['date'] = sanitize_text_field($conf['date']);
                    if (isset($conf['category'])) $item['category'] = sanitize_text_field($conf['category']);
                    if (isset($conf['tags'])) {
                        $tags = is_array($conf['tags']) ? array_map('sanitize_text_field', $conf['tags']) : array();
                        $item['tags'] = $tags;
                    }
                    $per_site_settings[$sid_clean] = $item;
                }
            }
        }
        // Robust boolean parsing: accept '1', 'true', 'on', 'yes' as true; '0', 'false', 'off', 'no' as false
        $parallel_sync = false;
        if (isset($_POST['parallel_sync'])) {
            $parsed = filter_var($_POST['parallel_sync'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $parallel_sync = ($parsed === true);
        }
        $async_sync = false;
        if (isset($_POST['async_sync'])) {
            $parsed_async = filter_var($_POST['async_sync'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $async_sync = ($parsed_async === true);
        }

        // 設定から非同期処理の有効/無効を取得
        $config_manager = WP_Cross_Post_Config_Manager::get_settings();
        $global_async_sync = isset($config_manager['sync_settings']['async_sync']) ? 
                             $config_manager['sync_settings']['async_sync'] : false;
        
        // グローバル設定とユーザー選択の両方がtrueの場合のみ非同期処理を実行
        $async_sync = $async_sync && $global_async_sync;

        if ($async_sync) {
            $this->debug_manager->log('非同期投稿同期を開始', 'info', array(
                'post_id' => $post_id,
                'selected_site_count' => count($selected_sites),
                'async_sync' => $async_sync
            ));
            
            // 各サイトに対して個別に非同期処理をスケジュール
            $scheduled_tasks = array();
            foreach ($selected_sites as $site_id) {
                $task_id = $this->schedule_async_sync($post_id, $site_id);
                if ($task_id) {
                    $scheduled_tasks[] = $task_id;
                }
            }
            
            wp_send_json_success(array(
                'message' => sprintf(
                    '%d件の非同期同期タスクをスケジュールしました。',
                    count($scheduled_tasks)
                ),
                'type' => 'success',
                'details' => array(
                    'scheduled_tasks' => $scheduled_tasks
                )
            ));
        } elseif ($parallel_sync) {
            $this->debug_manager->log('並列投稿同期を開始', 'info', array(
                'post_id' => $post_id,
                'selected_site_count' => count($selected_sites),
                'parallel_sync' => $parallel_sync
            ));
            
            $this->debug_manager->start_performance_monitoring('sync_post_parallel_' . $post_id);
            $result = $this->sync_post_parallel($post_id, $selected_sites);
            $this->debug_manager->end_performance_monitoring('sync_post_parallel_' . $post_id);
        } else {
            $this->debug_manager->log('投稿同期を開始', 'info', array(
                'post_id' => $post_id,
                'selected_site_count' => count($selected_sites),
                'parallel_sync' => $parallel_sync
            ));
            
            $this->debug_manager->start_performance_monitoring('sync_post_' . $post_id);
            $result = $this->sync_post($post_id, $selected_sites, $per_site_settings);
            $this->debug_manager->end_performance_monitoring('sync_post_' . $post_id);
        }

        if (isset($result) && is_wp_error($result)) {
            $this->debug_manager->log('同期に失敗: ' . $result->get_error_message(), 'error', array(
                'post_id' => $post_id,
                'error_data' => $result->get_error_data()
            ));
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error',
                'details' => $result->get_error_data()
            ));
        } elseif (isset($result)) {
            $success_sites = array();
            $failed_sites = array();
            
            foreach ($result as $site_id => $sync_result) {
                $site = $this->site_handler->get_site_data($site_id);
                $site_label = $this->build_site_label($site_id, $site);

                if (is_wp_error($sync_result)) {
                    $failed_sites[] = array(
                        'site_id' => $site_id,
                        'site_label' => $site_label,
                        'site_name' => $site_label,
                        'name' => $site_label,
                        'error' => $sync_result->get_error_message()
                    );
                } else {
                    $success_sites[] = array(
                        'site_id' => $site_id,
                        'site_label' => $site_label,
                        'site_name' => $site_label,
                        'name' => $site_label,
                        'remote_post_id' => $sync_result
                    );
                }
            }

            $success_count = count($success_sites);
            $total_count = count($selected_sites);

            if ($success_count === 0) {
                $this->debug_manager->log('すべてのサイトで同期に失敗', 'error', array(
                    'post_id' => $post_id,
                    'failed_site_count' => count($failed_sites)
                ));
                
                wp_send_json_error(array(
                    'message' => 'すべてのサイトで同期に失敗しました。',
                    'type' => 'error',
                    'details' => array(
                        'failed_sites' => $failed_sites
                    )
                ));
            } elseif ($success_count < $total_count) {
                $this->debug_manager->log('一部のサイトで同期に失敗', 'warning', array(
                    'post_id' => $post_id,
                    'success_count' => $success_count,
                    'total_count' => $total_count
                ));
                
                wp_send_json_success(array(
                    'message' => sprintf(
                        '一部のサイトで同期が完了しました（%d/%d成功）。',
                        $success_count,
                        $total_count
                    ),
                    'type' => 'warning',
                    'details' => array(
                        'success_sites' => $success_sites,
                        'failed_sites' => $failed_sites
                    )
                ));
            } else {
                $this->debug_manager->log('すべてのサイトで同期が成功', 'info', array(
                    'post_id' => $post_id,
                    'success_site_count' => $success_count
                ));
                
                wp_send_json_success(array(
                    'message' => 'すべてのサイトで同期が完了しました。',
                    'type' => 'success',
                    'details' => array(
                        'success_sites' => $success_sites
                    )
                ));
            }
        }
    }
    
    /**
     * 非同期処理をスケジュール
     */
    private function schedule_async_sync($post_id, $site_id) {
        // WP_Cross_Postのインスタンスを取得
        global $wp_cross_post;
        
        if ($wp_cross_post && method_exists($wp_cross_post, 'schedule_async_sync')) {
            return $wp_cross_post->schedule_async_sync($post_id, $site_id);
        }
        
        // フォールバック: 通常の同期処理
        $this->debug_manager->log('非同期処理が利用できません。通常の同期処理を実行します。', 'warning', array(
            'post_id' => $post_id,
            'site_id' => $site_id
        ));
        
        return $this->sync_to_single_site($post_id, $site_id);
    }
    
    public function sync_post($post_id, $selected_sites, $per_site_settings = array()) {
        $this->debug_manager->log(sprintf(
            '投稿ID %d の同期を開始（対象サイト: %s）',
            $post_id,
            implode(', ', $selected_sites)
        ), 'info', array(
            'post_id' => $post_id,
            'selected_sites' => $selected_sites
        ));

        $post = get_post($post_id);
        if (!$post) {
            $this->debug_manager->log('無効な投稿ID', 'error', array(
                'post_id' => $post_id
            ));
            return $this->error_manager->handle_general_error('無効な投稿IDです。', 'invalid_post');
        }

        // 投稿データの準備
        $post_data = $this->prepare_post_data($post, $selected_sites);

        if (is_wp_error($post_data)) {
            $this->debug_manager->log('投稿データの準備に失敗', 'error', array(
                'post_id' => $post_id,
                'error' => $post_data->get_error_message()
            ));
            return $post_data;
        }

        $results = array();
        foreach($selected_sites as $site_id) {
            $this->debug_manager->start_performance_monitoring('sync_to_site_' . $site_id);
            $site_data = $this->site_handler->get_site_data($site_id, true);
            if (!$site_data) {
                $this->debug_manager->log('無効なサイトID: ' . $site_id, 'error', array(
                    'site_id' => $site_id
                ));
                $results[$site_id] = $this->error_manager->handle_general_error('無効なサイトIDです。', 'invalid_site');
                continue;
            }

            // サイトへの接続テスト
            $test_result = $this->api_handler->test_connection($site_data);
            if (is_wp_error($test_result)) {
                $this->debug_manager->log(sprintf(
                    'サイト %s への接続に失敗: %s',
                    $site_data['url'],
                    $test_result->get_error_message()
                ), 'error', array(
                    'site_url' => $site_data['url'],
                    'error' => $test_result->get_error_message()
                ));
                $results[$site_id] = $test_result;
                continue;
            }

            // UI でのサイト別設定を適用（REST準拠: リモートIDのみ）
            if (is_array($per_site_settings) && isset($per_site_settings[$site_id])) {
                $ps = $per_site_settings[$site_id];
                // status/date
                if (!empty($ps['status'])) {
                    $post_data['status'] = $ps['status'];
                    if ($ps['status'] === 'future' && !empty($ps['date'])) {
                        $post_data['date'] = $ps['date'];
                        $post_data['date_gmt'] = get_gmt_from_date($ps['date']);
                    }
                }
                // category
                if (!empty($ps['category']) && is_numeric($ps['category'])) {
                    $post_data['categories'] = array( (int) $ps['category'] );
                }
                // tags
                if (!empty($ps['tags']) && is_array($ps['tags'])) {
                    $tag_ids = array();
                    foreach ($ps['tags'] as $tid) {
                        if (is_numeric($tid)) $tag_ids[] = (int) $tid;
                    }
                    if (!empty($tag_ids)) {
                        $post_data['tags'] = $tag_ids;
                    }
                }
            }

            // アイキャッチ画像の同期
            if (isset($post_data['featured_media'])) {
                $this->debug_manager->log('アイキャッチ画像の同期を開始', 'info', array(
                    'post_id' => $post_id,
                    'site_url' => $site_data['url']
                ));
                
                $media_result = $this->image_manager->sync_featured_image($site_data, $post_data['featured_media'], $post_id);

                if ($media_result) {
                    // メディアURLの取得を確認
                    $media_url = $this->image_manager->get_remote_media_url($media_result['id'], $site_data);
                    if (!is_wp_error($media_url)) {
                        $post_data['featured_media'] = $media_result;
                        $this->debug_manager->log('アイキャッチ画像の同期が完了: ' . $media_url, 'info', array(
                            'post_id' => $post_id,
                            'site_url' => $site_data['url'],
                            'media_url' => $media_url
                        ));
                    } else {
                        $this->debug_manager->log('アイキャッチ画像のURL取得に失敗しましたが、投稿の同期は継続します', 'warning', array(
                            'post_id' => $post_id,
                            'site_url' => $site_data['url'],
                            'error' => $media_url->get_error_message()
                        ));
                        // リモートで有効なIDが無いため送信しない
                        unset($post_data['featured_media']);
                    }
                } else {
                    $this->debug_manager->log('アイキャッチ画像の同期に失敗しましたが、投稿の同期は継続します', 'warning', array(
                        'post_id' => $post_id,
                        'site_url' => $site_data['url']
                    ));
                    // リモートで有効なIDが無いため送信しない
                    unset($post_data['featured_media']);
                }
            }
            
            // レート制限を考慮した投稿の同期
            $remote_post_id = $this->sync_with_rate_limit($site_data, $post_data);
            $results[$site_id] = $remote_post_id;

            if (!is_wp_error($remote_post_id)) {
                $this->save_sync_info($post_id, $site_id, $remote_post_id);
                $this->debug_manager->log(sprintf(
                    'サイト %s への同期が完了（リモート投稿ID: %d）',
                    $site_data['url'],
                    $remote_post_id
                ), 'info', array(
                    'post_id' => $post_id,
                    'site_url' => $site_data['url'],
                    'remote_post_id' => $remote_post_id
                ));
            } else {
                $this->debug_manager->log(sprintf(
                    'サイト %s への同期に失敗: %s',
                    $site_data['url'],
                    $remote_post_id->get_error_message()
                ), 'error', array(
                    'post_id' => $post_id,
                    'site_url' => $site_data['url'],
                    'error' => $remote_post_id->get_error_message()
                ));
            }

            $this->debug_manager->end_performance_monitoring('sync_to_site_' . $site_id);
        }

        $this->debug_manager->log('投稿同期を完了', 'info', array(
            'post_id' => $post_id,
            'success_count' => count(array_filter($results, function($result) { return !is_wp_error($result); })),
            'total_count' => count($results)
        ));
        
        return $results;
    }

    /**
     * 単一サイトへの同期処理
     */
    public function sync_to_single_site($post_id, $site_id) {
        $this->debug_manager->log(sprintf(
            '投稿ID %d をサイト %s に同期開始',
            $post_id,
            $site_id
        ), 'info');

        $post = get_post($post_id);
        if (!$post) {
            $this->debug_manager->log('無効な投稿ID: ' . $post_id, 'error');
            return new WP_Error('invalid_post', '無効な投稿IDです。');
        }

        // サイトデータの取得
        $site_data = $this->site_handler->get_site_data($site_id, true);
        if (!$site_data) {
            $this->debug_manager->log('無効なサイトID: ' . $site_id, 'error');
            return new WP_Error('invalid_site', '無効なサイトIDです。');
        }

        // サイトへの接続テスト
        $test_result = $this->api_handler->test_connection($site_data);
        if (is_wp_error($test_result)) {
            $this->debug_manager->log(sprintf(
                'サイト %s への接続に失敗: %s',
                $site_data['url'],
                $test_result->get_error_message()
            ), 'error');
            return $test_result;
        }

        // 投稿データの準備
        $post_data = $this->prepare_post_data($post, array($site_id));

        if (is_wp_error($post_data)) {
            $this->debug_manager->log('投稿データの準備に失敗', 'error', array(
                'post_id' => $post_id,
                'error' => $post_data->get_error_message()
            ));
            return $post_data;
        }

        // アイキャッチ画像の同期
        if (isset($post_data['featured_media'])) {
            $this->debug_manager->log('アイキャッチ画像の同期を開始', 'info', array(
                'post_id' => $post_id,
                'site_url' => $site_data['url']
            ));
            
            $media_result = $this->image_manager->sync_featured_image($site_data, $post_data['featured_media'], $post_id);

            if ($media_result) {
                // メディアURLの取得を確認
                $media_url = $this->image_manager->get_remote_media_url($media_result['id'], $site_data);
                if (!is_wp_error($media_url)) {
                    $post_data['featured_media'] = $media_result;
                    $this->debug_manager->log('アイキャッチ画像の同期が完了: ' . $media_url, 'info', array(
                        'post_id' => $post_id,
                        'site_url' => $site_data['url'],
                        'media_url' => $media_url
                    ));
                } else {
                    $this->debug_manager->log('アイキャッチ画像のURL取得に失敗しましたが、投稿の同期は継続します', 'warning', array(
                        'post_id' => $post_id,
                        'site_url' => $site_data['url'],
                        'error' => $media_url->get_error_message()
                    ));
                    // リモートで有効なIDが無いため送信しない
                    unset($post_data['featured_media']);
                }
            } else {
                $this->debug_manager->log('アイキャッチ画像の同期に失敗しましたが、投稿の同期は継続します', 'warning', array(
                    'post_id' => $post_id,
                    'site_url' => $site_data['url']
                ));
                // リモートで有効なIDが無いため送信しない
                unset($post_data['featured_media']);
            }
        }
        
        // レート制限のチェックと待機
        $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($site_data['url']);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }
        
        // 投稿の同期（REST準拠: API側でcategories/tagsの型正規化・未設定時のフォールバックあり）
        $remote_post_id = $this->api_handler->sync_post($site_data, $post_data);
        
        if (!is_wp_error($remote_post_id)) {
            $this->site_handler->save_sync_info($post_id, $site_id, $remote_post_id);
            $this->debug_manager->log(sprintf(
                'サイト %s への同期が完了（リモート投稿ID: %d）',
                $site_data['url'],
                $remote_post_id
            ), 'info', array(
                'post_id' => $post_id,
                'site_url' => $site_data['url'],
                'remote_post_id' => $remote_post_id
            ));
        } else {
            $this->debug_manager->log(sprintf(
                'サイト %s への同期に失敗: %s',
                $site_data['url'],
                $remote_post_id->get_error_message()
            ), 'error', array(
                'post_id' => $post_id,
                'site_url' => $site_data['url'],
                'error' => $remote_post_id->get_error_message()
            ));
        }

        return $remote_post_id;
    }

    /**
     * 並列処理による複数サイトへの同期
     */
    public function sync_post_parallel($post_id, $selected_sites) {
        $this->debug_manager->log(sprintf(
            '投稿ID %d の並列同期を開始（対象サイト: %s）',
            $post_id,
            implode(', ', $selected_sites)
        ), 'info', array(
            'post_id' => $post_id,
            'selected_sites' => $selected_sites
        ));

        $post = get_post($post_id);
        if (!$post) {
            $this->debug_manager->log('無効な投稿ID', 'error', array(
                'post_id' => $post_id
            ));
            return $this->error_manager->handle_general_error('無効な投稿IDです。', 'invalid_post');
        }

        // 投稿データの準備
        $post_data = $this->prepare_post_data($post, $selected_sites);

        if (is_wp_error($post_data)) {
            $this->debug_manager->log('投稿データの準備に失敗', 'error', array(
                'post_id' => $post_id,
                'error' => $post_data->get_error_message()
            ));
            return $post_data;
        }

        $results = array();
        $scheduled_tasks = array();
        
        // 各サイトに対して個別に非同期処理をスケジュール
        foreach($selected_sites as $site_id) {
            $site_data = $this->site_handler->get_site_data($site_id, true);
            if (!$site_data) {
                $this->debug_manager->log('無効なサイトID: ' . $site_id, 'error', array(
                    'site_id' => $site_id
                ));
                $results[$site_id] = $this->error_manager->handle_general_error('無効なサイトIDです。', 'invalid_site');
                continue;
            }

            // WP_Cross_Postのインスタンスを取得
            global $wp_cross_post;
            
            if ($wp_cross_post && method_exists($wp_cross_post, 'schedule_async_sync')) {
                $task_id = $wp_cross_post->schedule_async_sync($post_id, $site_id);
                if ($task_id) {
                    $scheduled_tasks[] = $task_id;
                    $results[$site_id] = 'async_request_sent';
                } else {
                    $results[$site_id] = $this->error_manager->handle_general_error('非同期処理のスケジュールに失敗しました。', 'async_schedule_failed');
                }
            } else {
                // フォールバック: 通常の同期処理
                $this->debug_manager->log('非同期処理が利用できません。通常の同期処理を実行します。', 'warning', array(
                    'post_id' => $post_id,
                    'site_id' => $site_id
                ));
                
                $result = $this->sync_to_single_site($post_id, $site_id);
                $results[$site_id] = $result;
            }
        }

        $this->debug_manager->log('並列同期リクエストを送信完了', 'info', array(
            'post_id' => $post_id,
            'sent_request_count' => count($scheduled_tasks)
        ));
        
        return $results;
    }

    private function prepare_post_data($post, $selected_sites) {
        try {
            $site_data = null;
            if (!empty($selected_sites)) {
                $first_site_id = reset($selected_sites);
                $site_data = $this->site_handler->get_site_data($first_site_id, true);
            }

            // 投稿データの準備
            $post_data = array(
                'id' => $post->ID,
                'title'   => $post->post_title,
                'content' => $this->prepare_block_content($post->post_content, $site_data),
                'status'  => $post->post_status, // 投稿状態を元の投稿から取得
                'slug'    => $post->post_name,
                'date'    => $post->post_date,
                'date_gmt' => $post->post_date_gmt,
                'modified' => $post->post_modified,
                'modified_gmt' => $post->post_modified_gmt,
                'author' => $post->post_author,
                'excerpt' => array(
                    'raw' => get_post_meta($post->ID, '_swell_meta', true)['excerpt'] ?? $post->post_excerpt,
                    'rendered' => get_post_meta($post->ID, '_swell_meta', true)['excerpt'] ?? $post->post_excerpt
                ),
                'meta'    => array(
                    '_wp_page_template' => get_post_meta( $post->ID, '_wp_page_template', true ),
                    '_thumbnail_id'     => get_post_meta( $post->ID, '_thumbnail_id', true ),
                    '_swell_meta'       => get_post_meta( $post->ID, '_swell_meta', true),
                )
            );

            // 投稿状態の処理
            // 予約投稿の場合
            if ($post->post_status === 'future') {
                $post_data['status'] = 'future';
                $post_data['date'] = $post->post_date;
                $post_data['date_gmt'] = $post->post_date_gmt;
            } 
            // 下書きの場合
            else if ($post->post_status === 'draft') {
                $post_data['status'] = 'draft';
            }
            // 非公開の場合
            else if ($post->post_status === 'private') {
                $post_data['status'] = 'private';
            }
            // その他の状態（公開など）
            else {
                $post_data['status'] = $post->post_status;
            }

            // スラッグの処理
            if ( empty( $post_data['slug'] ) ) {
                $post_data['slug'] = sanitize_title( $post->post_title );
                $this->debug_manager->log('スラッグを自動生成: ' . $post_data['slug'], 'info');
            }

            // カテゴリーはREST準拠のため、ここではローカルIDを送らない（API側のフォールバックに委ねる）
            $category_terms = wp_get_post_categories( $post->ID, array('fields' => 'ids') );
            if ( is_wp_error( $category_terms ) ) {
                throw new Exception( 'カテゴリーの取得に失敗: ' . $category_terms->get_error_message() );
            }
            // 未設定として空配列。UIやAPI側でリモートID解決/生成を行う
            $post_data['categories'] = array();

            // タグも同様にローカルIDは送らない（REST準拠）。未設定として空配列
            $tag_terms = wp_get_post_tags( $post->ID, array('fields' => 'ids') );
            if ( is_wp_error( $tag_terms ) ) {
                // タグ無しでも続行
                $this->debug_manager->log('タグの取得に失敗: ' . $tag_terms->get_error_message(), 'warning');
            }
            $post_data['tags'] = array();
            $this->debug_manager->log('カテゴリ/タグはUIまたはAPI側で解決するため空で送信', 'debug');

            // アイキャッチ画像の処理を追加
            $thumbnail_id = get_post_thumbnail_id( $post->ID );
            $this->debug_manager->log('Thumbnail ID in prepare_post_data: ' . $thumbnail_id, 'info');
            if ( $thumbnail_id ) {
                $image_data = $this->prepare_featured_image( $thumbnail_id );
                if ( $image_data ) {
                    $post_data['featured_media'] = $image_data;
                }
            }

            // 追加のメタデータを含める（コメント設定など）
            $post_data['meta']['_ping_status'] = get_post_meta($post->ID, '_ping_status', true);
            $post_data['meta']['_comment_status'] = get_post_meta($post->ID, '_comment_status', true);
            
            // コメント設定
            $post_data['comment_status'] = $post->comment_status;
            $post_data['ping_status'] = $post->ping_status;
            
            // 投稿フォーマット
            $post_format = get_post_format($post->ID);
            if ($post_format) {
                $post_data['meta']['_post_format'] = 'post-format-' . $post_format;
            }

            return $post_data;

        } catch ( Exception $e ) {
            $this->debug_manager->log('投稿データの準備中にエラーが発生: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }


    private function prepare_block_content($content, $site_data) {
        if (empty($content)) {
            return $content;
        }
    
        // 画像URLの対応表
        $image_url_map = array();
    
        // ブロックの解析
        $blocks = parse_blocks($content);
    
        // 各ブロックを処理
        $this->process_blocks($blocks, $image_url_map, $site_data);
    
        // 最終的なコンテンツを生成
        $updated_content = serialize_blocks($blocks);
    
        // 画像URLの置換
        foreach ($image_url_map as $original_url => $new_url) {
            $updated_content = str_replace($original_url, $new_url, $updated_content);
        }
    
        return $updated_content;
    }

    private function process_blocks(&$blocks, &$image_url_map, $site_data) {
        foreach ($blocks as &$block) {
            // SWELLテーマのブロックの特別処理
            if (isset($block['blockName']) && strpos($block['blockName'], 'swell') === 0) {
                $block['attrs']['originalContent'] = $block['innerHTML'];
            }
    
            // 画像URLの抽出と置換
            if (!empty($block['innerHTML'])) {
                $updated_inner_html = $this->process_image_urls($block['innerHTML'], $image_url_map, $site_data);
                $block['innerHTML'] = $updated_inner_html;
            }
    
            // インナーブロックを再帰的に処理
            if (!empty($block['innerBlocks'])) {
                $this->process_inner_blocks($block['innerBlocks'], $image_url_map, $site_data);
            }
        }
    }
    
    private function process_inner_blocks(&$blocks, &$image_url_map, $site_data) {
        foreach ($blocks as &$block) {
            // SWELLテーマのブロックの特別処理
            if (isset($block['blockName']) && strpos($block['blockName'], 'swell') === 0) {
                $block['attrs']['originalContent'] = $block['innerHTML'];
            }
    
            // 画像URLの抽出と置換
            if (!empty($block['innerHTML'])) {
                $updated_inner_html = $this->process_image_urls($block['innerHTML'], $image_url_map, $site_data);
                $block['innerHTML'] = $updated_inner_html;
            }
    
            // インナーブロックを再帰的に処理
            if (!empty($block['innerBlocks'])) {
                $this->process_inner_blocks($block['innerBlocks'], $image_url_map, $site_data);
            }
        }
    }
    
    private function process_image_urls($html, &$image_url_map, $site_data) {
        $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';
    
        $updated_html = preg_replace_callback($pattern, function ($matches) use (&$image_url_map, $site_data) {
            $original_url = $matches[1];
    
            // 既に処理済みの場合はスキップ
            if (isset($image_url_map[$original_url])) {
                return str_replace($original_url, $image_url_map[$original_url], $matches[0]);
            }
    
            // 新しい画像URLを生成（最大3回まで再試行）
            $media_result = null;
            $retry_count = 0;
            $max_retries = 3;

            while ($retry_count < $max_retries) {
            $media_result = $this->sync_content_image($site_data, $original_url);
                if ($media_result) {
                    break;
                }
                $retry_count++;
                if ($retry_count < $max_retries) {
                    $this->debug_manager->log(sprintf(
                        'コンテンツ内画像の同期に失敗。%d回目の再試行を行います。',
                        $retry_count + 1
                    ), 'warning');
                    sleep(2); // 2秒待機して再試行
                }
            }
    
            if ($media_result) {
                // 対応表に追加
                $image_url_map[$original_url] = $media_result;
                return str_replace($original_url, $media_result, $matches[0]);
            } else {
                $this->debug_manager->log('コンテンツ内画像の同期に失敗しましたが、元の画像URLを使用します', 'warning');
                return $matches[0]; // エラーの場合は元のまま
            }
        }, $html);
    
        return $updated_html;
    }

     private function sync_content_image($site_data, $image_url) {
        $this->debug_manager->log('コンテンツ内画像の同期を開始: ' . $image_url, 'info');

        // REST APIが有効かどうかを確認
        $rest_api_check = $this->check_rest_api_availability($site_data);
        if (is_wp_error($rest_api_check)) {
            $this->debug_manager->log('REST APIが利用できません: ' . $rest_api_check->get_error_message(), 'error');
            return false;
        }

        // 1. 画像をダウンロード
        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            $this->debug_manager->log('画像のダウンロードに失敗: ' . $temp_file->get_error_message(), 'error');
            return false;
        }

        // 2. ファイル名を準備
        $file_name = basename($image_url);
        $file_type = wp_check_filetype($file_name);
        if (empty($file_type['type'])) {
            $file_name = 'image-' . time() . '.jpg';
            $file_type = array('type' => 'image/jpeg');
        }

        $this->debug_manager->log('画像ファイル名: ' . $file_name, 'info');
        $this->debug_manager->log('画像タイプ: ' . $file_type['type'], 'info');

        // 3. バウンダリを生成
        $boundary = md5(time());

        // 4. リクエスト本文を構築
        $body = '';
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$file_name\"\r\n";
        $body .= "Content-Type: {$file_type['type']}\r\n\r\n";
        $body .= file_get_contents($temp_file) . "\r\n";
        $body .= "--$boundary--\r\n";

        // 5. cURLリクエストを準備
        $curl = curl_init();
        
        // エンドポイントのURLを修正 - 末尾にスラッシュがあることを確認
        $url = rtrim($site_data['url'], '/') . '/wp-json/wp/v2/media';
        $this->debug_manager->log('アップロード先URL: ' . $url, 'info');

        // cURLオプションの設定
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Content-Length: ' . strlen($body)
            ),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_VERBOSE => true
        ));

        // 6. リクエストを実行
        $response_body = curl_exec($curl);
        $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        $curl_info = curl_getinfo($curl);

        // 詳細なデバッグ情報
        $this->debug_manager->log('cURL情報: ' . json_encode($curl_info), 'debug');
        $this->debug_manager->log('レスポンスコード: ' . $response_code, 'info');
        
        if ($curl_error) {
            $this->debug_manager->log('cURLエラー: ' . $curl_error, 'error');
        }

        // 7. リソースを解放
        curl_close($curl);
        
        // 8. 一時ファイルを削除
        @unlink($temp_file);

        // 9. レスポンスを確認
        if ($response_code < 200 || $response_code >= 300) {
            $this->debug_manager->log('画像アップロードに失敗 (HTTP ' . $response_code . '): ' . $response_body, 'error');
            
            // オリジナルのURLを返す（同期はできなかったが、元のURLを使用）
            $this->debug_manager->log('オリジナル画像URLを使用します: ' . $image_url, 'warning');
            return $image_url;
        }

        $media_data = json_decode($response_body, true);
        if (!isset($media_data['source_url'])) {
            $this->debug_manager->log('画像URLの取得に失敗: ' . $response_body, 'error');
            return $image_url; // オリジナルURLを返す
        }

        $this->debug_manager->log('画像アップロード成功: ' . $media_data['source_url'], 'info');
        return $media_data['source_url'];
    }

    /**
     * レート制限を考慮した同期処理
     */
    private function sync_with_rate_limit($site_data, $post_data) {
        $max_retries = 3;
        $retry_count = 0;
        $last_error = null;

        // アイキャッチ画像IDを保存
        $featured_media_id = isset($post_data['featured_media']['id']) ? $post_data['featured_media']['id'] : null;

        // 投稿データからアイキャッチ画像の詳細情報を削除（APIリクエスト用）
        if (isset($post_data['featured_media']) && is_array($post_data['featured_media'])) {
            // APIリクエスト用に、IDのみを残す
            $post_data['featured_media'] = $featured_media_id;
        }

        while ($retry_count < $max_retries) {
            // 投稿の作成を試みる
            $result = $this->api_handler->sync_post($site_data, $post_data);

            if (is_wp_error($result)) {
                $last_error = $result;
                
                // レート制限エラーの場合
                if ($result->get_error_code() === 'rate_limit') {
                    $retry_after = $result->get_error_data()['retry_after'];
                    $this->debug_manager->log(
                        sprintf('レート制限を検出。%d秒後に再試行します。', $retry_after),
                        'warning'
                    );
                    sleep($retry_after);
                    $retry_count++;
                    continue;
                }
                
                // その他のエラーの場合
                $retry_count++;
                if ($retry_count < $max_retries) {
                    $backoff = $this->calculate_backoff($retry_count);
                    $this->debug_manager->log(
                        sprintf('同期に失敗。%dms後に再試行します。エラー: %s', 
                            $backoff, 
                            $result->get_error_message()
                        ),
                        'warning'
                    );
                    usleep($backoff * 1000);
                    continue;
                }
            }

            // 投稿が正常に作成されたことを確認
            if (!is_wp_error($result) && isset($result) && is_numeric($result)) {
                $remote_post_id = $result;
                
                // アイキャッチ画像が指定されている場合は、投稿に設定
                if ($featured_media_id) {
                    $this->debug_manager->log('アイキャッチ画像を投稿に設定: 画像ID ' . $featured_media_id . ', 投稿ID ' . $remote_post_id, 'info');
                    
                    // アイキャッチ画像を設定
                    $update_result = $this->api_handler->update_post($site_data, $remote_post_id, array(
                        'featured_media' => $featured_media_id
                    ));
                    
                    if (is_wp_error($update_result)) {
                        $this->debug_manager->log('アイキャッチ画像の設定に失敗: ' . $update_result->get_error_message(), 'warning');
                    } else {
                        $this->debug_manager->log('アイキャッチ画像の設定が完了', 'info');
                    }
                }
            }

            return $result;
        }

        return $last_error;
    }

    /**
     * バックオフ時間の計算
     */
    private function calculate_backoff($attempt) {
        // 指数バックオフ: 2^attempt * 1000ms
        $base_delay = 1000;
        $max_delay = 30000; // 最大30秒
        $delay = min($base_delay * pow(2, $attempt), $max_delay);
        
        // ジッターを追加（0-10%）
        $jitter = rand(0, intval($delay * 0.1));
        return $delay + $jitter;
    }

    /**
     * アイキャッチ画像の同期処理を改善
     */
    private function sync_featured_image($site_data, $media_data, $post_id) {
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        $response = wp_remote_post($site_data['url'] . '/wp-json/wp/v2/media', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => $auth_header,
                'Content-Disposition' => 'attachment; filename="' . basename($media_data['source_url']) . '"'
            ),
            'body' => file_get_contents($media_data['source_url'])
        ));

        if (is_wp_error($response)) {
            $this->debug_manager->log('アイキャッチ画像の同期に失敗: ' . $response->get_error_message(), 'error');
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 201) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($body['message']) ? $body['message'] : '画像のアップロードに失敗しました。';
            $this->debug_manager->log('アイキャッチ画像の同期に失敗: ' . $error_message, 'error');
            return null;
        }

        $media = json_decode(wp_remote_retrieve_body($response), true);
        return array(
            'id' => $media['id'],
            'source_url' => $media['source_url'],
            'alt_text' => $media['alt_text']
        );
    }

    /**
     * 認証ヘッダーを取得
     */
    private function get_auth_header($site_data) {
        // WordPress 5.6以降のアプリケーションパスワード対応
        if (version_compare(get_bloginfo('version'), '5.6', '>=')) {
            // アプリケーションパスワードの形式で認証
            return 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']);
        } else {
            // 従来のBasic認証
            return 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']);
        }
    }

    /**
     * REST APIが利用可能かを確認
     */
    private function check_rest_api_availability($site_data) {
        $url = rtrim($site_data['url'], '/') . '/wp-json/';
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password'])
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error(
                'rest_api_unavailable',
                'REST API is not available on the target site (HTTP ' . $response_code . ')'
            );
        }
        
        return true;
    }

    private function prepare_featured_image($thumbnail_id) {
            $this->debug_manager->log('アイキャッチ画像の準備を開始: ID ' . $thumbnail_id, 'info');

        // 1. 事前チェック
            if (!$thumbnail_id) {
                return null;
            }

        // 2. メモリ使用量の最適化
        $this->optimize_memory_usage();

        // 3. 画像URLの取得
            $image_url = wp_get_attachment_url($thumbnail_id);
            if (!$image_url) {
            $this->debug_manager->log('アイキャッチ画像のURLの取得に失敗', 'error');
            return null;
            }

        // 4. 画像メタデータの取得
            $image_meta = wp_get_attachment_metadata($thumbnail_id);
            if (!$image_meta) {
            $this->debug_manager->log('アイキャッチ画像のメタデータの取得に失敗', 'error');
            return null;
        }

        // 5. 画像ファイルのパスを取得
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $image_meta['file'];
        if (!file_exists($file_path)) {
            $this->debug_manager->log('アイキャッチ画像ファイルが見つかりません', 'error');
            return null;
        }

        // 6. ファイルサイズの確認
        $file_size = filesize($file_path);
        if ($file_size === false || $file_size === 0) {
            $this->debug_manager->log('無効なファイルサイズです', 'error');
            return null;
        }

        // 7. アップロードサイズの制限チェック
        if ($file_size > wp_max_upload_size()) {
            $this->debug_manager->log('ファイルサイズが大きすぎます', 'error');
            return null;
        }

        // 8. ファイルタイプの確認
        $file_type = wp_check_filetype($file_path);
        if (empty($file_type['type'])) {
            $this->debug_manager->log('無効なファイルタイプです', 'error');
            return null;
            }

            $image_data = array(
                'id' => $thumbnail_id,
                'url' => $image_url,
            'file_path' => $file_path,
                'width' => isset($image_meta['width']) ? $image_meta['width'] : null,
                'height' => isset($image_meta['height']) ? $image_meta['height'] : null,
            'mime_type' => $file_type['type'],
                'alt_text' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true)
            );

            $this->debug_manager->log('アイキャッチ画像の準備が完了: ' . json_encode($image_data), 'info');
            return $image_data;
    }

    /**
     * メモリ使用量の最適化
     */
    private function optimize_memory_usage() {
        // 不要な変数を解放
        unset($temp_data);
        unset($processed_data);
        
        // ガベージコレクションを実行
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * メディアIDからURLを取得（同期先サイト用）
     *
     * @param int $media_id メディアID
     * @param array $site_data サイト情報
     * @return string|WP_Error メディアのURL、またはエラー
     */
    private function get_remote_media_url($media_id, $site_data) {
        try {
            $this->debug_manager->log('リモートメディアURLの取得を開始: ID ' . $media_id, 'info');

            // メディア情報の取得
            $response = wp_remote_get(
                $site_data['url'] . '/wp-json/wp/v2/media/' . $media_id,
                array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password'])
                    )
                )
            );

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                throw new Exception('メディア情報の取得に失敗（HTTP ' . $response_code . '）');
            }

            $media_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($media_data['source_url'])) {
                throw new Exception('メディアURLが見つかりません');
            }

            $this->debug_manager->log('リモートメディアURLの取得が完了: ' . $media_data['source_url'], 'info');
            return $media_data['source_url'];

        } catch (Exception $e) {
            $this->debug_manager->log('リモートメディアURLの取得に失敗: ' . $e->getMessage(), 'error');
            return new WP_Error('remote_media_url_error', $e->getMessage());
        }
    }

    protected function save_sync_info($local_post_id, $site_id, $remote_post_id) {
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


}