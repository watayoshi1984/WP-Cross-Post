<?php
<<<<<<< HEAD
/**
 * WP Cross Post サイトハンドラー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-handler.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-site-handler.php';

/**
 * WP Cross Post サイトハンドラークラス
 *
 * サイト管理処理を管理します。
 */
class WP_Cross_Post_Site_Handler implements WP_Cross_Post_Site_Handler_Interface {

    private $debug_manager;
    private $auth_manager;
    private $error_manager;
    private $api_handler;
    private $rate_limit_manager;

    public function __construct($debug_manager, $auth_manager, $error_manager, $api_handler, $rate_limit_manager) {
        $this->debug_manager = $debug_manager;
        $this->auth_manager = $auth_manager;
        $this->error_manager = $error_manager;
        $this->api_handler = $api_handler;
        $this->rate_limit_manager = $rate_limit_manager;
    }

    public function add_site($site_data) {
        $this->debug_manager->log('サイト追加を開始', 'info', array(
            'site_name' => isset($site_data['name']) ? $site_data['name'] : 'unknown',
            'site_url' => isset($site_data['url']) ? $site_data['url'] : 'unknown'
        ));
        
        $sites = get_option('wp_cross_post_sites', array());
        
        // URLの正規化
        $site_data['url'] = $this->api_handler->normalize_site_url($site_data['url']);

        // 接続テスト
        $test_result = $this->api_handler->test_connection($site_data);
        if (is_wp_error($test_result)) {
            $this->debug_manager->log('サイト追加時の接続テストに失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $test_result->get_error_message()
            ));
=======
class WP_Cross_Post_Site_Handler {
    private $debug_manager;

    public function __construct() {
        $this->debug_manager = WP_Cross_Post_Debug_Manager::get_instance();
    }

    public function add_site($site_data) {
        $sites = get_option('wp_cross_post_sites', array());
        
        // URLの正規化
        $api_handler = new WP_Cross_Post_API_Handler();
        $site_data['url'] = $api_handler->normalize_site_url($site_data['url']);

        // 接続テスト
        $test_result = $api_handler->test_connection($site_data);
        if (is_wp_error($test_result)) {
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            return $test_result;
        }

        // サイト名を取得
        $site_name = $this->get_site_name($site_data);
        if (is_wp_error($site_name)) {
<<<<<<< HEAD
            $this->debug_manager->log('サイト名の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $site_name->get_error_message()
            ));
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            return $site_name;
        }

        // 新しいサイトIDを生成
        $site_data['id'] = uniqid('site_');
        $site_data['name'] = $site_name;

        // タクソノミーの同期
        $sync_result = $this->sync_taxonomies($site_data);
        if (is_wp_error($sync_result)) {
<<<<<<< HEAD
            $this->debug_manager->log('タクソノミーの同期に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $sync_result->get_error_message()
            ));
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            return $sync_result;
        }

        $sites[] = $site_data;
        update_option('wp_cross_post_sites', $sites);

<<<<<<< HEAD
        $this->debug_manager->log('サイトを追加', 'info', array(
            'site_id' => $site_data['id'],
            'site_name' => $site_data['name'],
            'site_url' => $site_data['url']
        ));
        
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        return $site_data['id'];
    }

    /**
     * サイト名を取得
     */
    private function get_site_name($site_data) {
<<<<<<< HEAD
        $this->debug_manager->log('サイト名の取得を開始', 'info', array(
            'site_url' => $site_data['url']
        ));
        
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
=======
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->get_auth_header($site_data);
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        
        $response = wp_remote_get($site_data['url'] . '/wp-json', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));

        if (is_wp_error($response)) {
<<<<<<< HEAD
            $this->debug_manager->log('サイト名の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($response, 'サイト名取得');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $site_name = isset($data['name']) ? $data['name'] : parse_url($site_data['url'], PHP_URL_HOST);
        
        $this->debug_manager->log('サイト名を取得', 'info', array(
            'site_url' => $site_data['url'],
            'site_name' => $site_name
        ));
        
        return $site_name;
    }

    public function remove_site($site_id) {
        $this->debug_manager->log('サイト削除を開始', 'info', array(
            'site_id' => $site_id
        ));
        
=======
            return new WP_Error('api_error', 'サイト名の取得に失敗しました: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['name']) ? $data['name'] : parse_url($site_data['url'], PHP_URL_HOST);
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

    public function remove_site($site_id) {
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $key => $site) {
            if ($site['id'] === $site_id) {
                unset($sites[$key]);
                update_option('wp_cross_post_sites', array_values($sites));
<<<<<<< HEAD
                
                $this->debug_manager->log('サイトを削除', 'info', array(
                    'site_id' => $site_id,
                    'site_name' => $site['name'],
                    'site_url' => $site['url']
                ));
                
                return true;
            }
        }
        
        $this->debug_manager->log('削除するサイトが見つかりません', 'warning', array(
            'site_id' => $site_id
        ));
        
        return $this->error_manager->handle_general_error('サイトが見つかりません。', 'site_not_found');
    }

    public function get_sites() {
        $sites = get_option('wp_cross_post_sites', array());
        $this->debug_manager->log('サイト一覧を取得', 'debug', array(
            'site_count' => count($sites)
        ));
        return $sites;
=======
                return true;
            }
        }
        error_log('WP Cross Post: Remove site failed - Site not found: ' . $site_id);
        return new WP_Error('site_not_found', 'サイトが見つかりません。');
    }

    public function get_sites() {
        return get_option('wp_cross_post_sites', array());
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
    }

    private function validate_site_data($site_data) {
        $required_fields = array('name', 'url', 'username', 'app_password');
        foreach ($required_fields as $field) {
            if (empty($site_data[$field])) {
<<<<<<< HEAD
                $this->debug_manager->log('必須フィールドが不足', 'error', array(
                    'missing_field' => $field
                ));
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
                return false;
            }
        }
        return true;
    }

    /**
     * 全サイトのタクソノミーを同期
     */
    public function sync_all_sites_taxonomies() {
<<<<<<< HEAD
        $this->debug_manager->log('全サイトのタクソノミー同期を開始', 'info');
        
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        $sites = get_option('wp_cross_post_sites', array());
        $results = array(
            'success_sites' => array(),
            'failed_sites' => array()
        );

        foreach ($sites as $site) {
            try {
<<<<<<< HEAD
                $this->debug_manager->log('サイトのタクソノミー同期を開始', 'info', array(
                    'site_id' => $site['id'],
                    'site_name' => $site['name'],
                    'site_url' => $site['url']
                ));
                
                // サイトへの接続テスト
                $test_result = $this->api_handler->test_connection($site);
=======
                // サイトへの接続テスト
                $api_handler = new WP_Cross_Post_API_Handler();
                $test_result = $api_handler->test_connection($site);
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
                if (is_wp_error($test_result)) {
                    throw new Exception($test_result->get_error_message());
                }

                // カテゴリーとタグの取得
                $taxonomies = $this->get_remote_taxonomies($site);
                if (is_wp_error($taxonomies)) {
                    throw new Exception($taxonomies->get_error_message());
                }

                // カテゴリーの同期
                if (!empty($taxonomies['categories'])) {
                    foreach ($taxonomies['categories'] as $category) {
                        $this->import_taxonomy_term($category, 'category', $site['name']);
                    }
                }

                // タグの同期
                if (!empty($taxonomies['tags'])) {
                    foreach ($taxonomies['tags'] as $tag) {
                        $this->import_taxonomy_term($tag, 'post_tag', $site['name']);
                    }
                }

                $results['success_sites'][] = array(
                    'site_id' => $site['id'],
                    'name' => $site['name']
                );
<<<<<<< HEAD
                
                $this->debug_manager->log('サイトのタクソノミー同期を完了', 'info', array(
                    'site_id' => $site['id'],
                    'site_name' => $site['name'],
                    'site_url' => $site['url']
                ));

            } catch (Exception $e) {
                $this->debug_manager->log('サイトのタクソノミー同期に失敗', 'error', array(
                    'site_id' => $site['id'],
                    'site_name' => $site['name'],
                    'site_url' => $site['url'],
                    'error' => $e->getMessage()
                ));
                
=======

            } catch (Exception $e) {
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
                $results['failed_sites'][] = array(
                    'site_id' => $site['id'],
                    'name' => $site['name'],
                    'error' => $e->getMessage()
                );
            }
        }

<<<<<<< HEAD
        $this->debug_manager->log('全サイトのタクソノミー同期を完了', 'info', array(
            'success_count' => count($results['success_sites']),
            'failed_count' => count($results['failed_sites'])
        ));
        
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        return $results;
    }

    /**
     * 定期実行用のフック設定
     */
    public function schedule_taxonomies_sync() {
        if (!wp_next_scheduled('wp_cross_post_daily_sync')) {
            wp_schedule_event(strtotime('today 03:00:00'), 'daily', 'wp_cross_post_daily_sync');
<<<<<<< HEAD
            $this->debug_manager->log('タクソノミー同期の定期実行をスケジュール', 'info');
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        }
    }

    /**
     * 定期実行のフックを解除
     */
    public function unschedule_taxonomies_sync() {
        wp_clear_scheduled_hook('wp_cross_post_daily_sync');
<<<<<<< HEAD
        $this->debug_manager->log('タクソノミー同期の定期実行を解除', 'info');
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
    }

    /**
     * 手動同期用のAJAXハンドラー
     */
    public function ajax_sync_taxonomies() {
        check_ajax_referer('wp_cross_post_taxonomy_sync', 'nonce');

        if (!current_user_can('manage_options')) {
<<<<<<< HEAD
            $this->debug_manager->log('権限のないユーザーがタクソノミー同期を試みました', 'warning');
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        try {
            $this->debug_manager->start_performance_monitoring('sync_taxonomies');
            $result = $this->sync_all_sites_taxonomies();
            $this->debug_manager->end_performance_monitoring('sync_taxonomies');

            if (is_wp_error($result)) {
<<<<<<< HEAD
                throw new Exception($result->get_error_message());
            }

            update_option('wp_cross_post_last_sync_time', current_time('mysql'));

            wp_send_json_success(array(
                'message' => 'タクソノミーの同期が完了しました。',
                'type' => 'success',
=======
            throw new Exception($result->get_error_message());
        }

        update_option('wp_cross_post_last_sync_time', current_time('mysql'));
        $this->debug_manager->log('wp_cross_post_last_sync_time updated', 'debug');
        wp_send_json_success(array(
            'message' => 'カテゴリーとタグの同期が完了しました。',
            'type' => 'success',
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
                'details' => $result
            ));

        } catch (Exception $e) {
<<<<<<< HEAD
            $this->debug_manager->end_performance_monitoring('sync_taxonomies');
            $this->debug_manager->log('タクソノミー同期中にエラー発生: ' . $e->getMessage(), 'error', array(
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
=======
            $this->debug_manager->log('タクソノミー同期でエラー: ' . $e->getMessage(), 'error');
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'type' => 'error'
            ));
        }
    }

    /**
<<<<<<< HEAD
     * リモートタクソノミーの取得
     */
    private function get_remote_taxonomies($site) {
        $this->debug_manager->log('リモートタクソノミーの取得を開始', 'info', array(
            'site_url' => $site['url']
        ));
        
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site);
        
        // カテゴリーの取得
        $categories_response = wp_remote_get($site['url'] . '/wp-json/wp/v2/categories?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));

        if (is_wp_error($categories_response)) {
            $this->debug_manager->log('カテゴリーの取得に失敗', 'error', array(
                'site_url' => $site['url'],
                'error' => $categories_response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($categories_response, 'カテゴリー取得');
        }

        $categories = json_decode(wp_remote_retrieve_body($categories_response), true);

        // タグの取得
        $tags_response = wp_remote_get($site['url'] . '/wp-json/wp/v2/tags?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));

        if (is_wp_error($tags_response)) {
            $this->debug_manager->log('タグの取得に失敗', 'error', array(
                'site_url' => $site['url'],
                'error' => $tags_response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($tags_response, 'タグ取得');
        }

        $tags = json_decode(wp_remote_retrieve_body($tags_response), true);

        $this->debug_manager->log('リモートタクソノミーの取得を完了', 'info', array(
            'site_url' => $site['url'],
            'category_count' => count($categories),
            'tag_count' => count($tags)
        ));
        
        return array(
            'categories' => $categories,
            'tags' => $tags
        );
    }

    /**
     * タクソノミータームのインポート
     */
    private function import_taxonomy_term($term, $taxonomy, $site_name) {
        // 既存のタームを検索
        $existing_term = term_exists($term['slug'], $taxonomy);
        
        if ($existing_term) {
            // 既存のタームを更新
            wp_update_term($existing_term['term_id'], $taxonomy, array(
                'name' => $term['name'],
                'description' => $term['description'] ?? ''
            ));
            
            $this->debug_manager->log('既存のタームを更新', 'debug', array(
                'term_id' => $existing_term['term_id'],
                'term_name' => $term['name'],
                'taxonomy' => $taxonomy,
                'site_name' => $site_name
            ));
        } else {
            // 新しいタームを追加
            $result = wp_insert_term($term['name'], $taxonomy, array(
                'slug' => $term['slug'],
                'description' => $term['description'] ?? ''
            ));
            
            if (!is_wp_error($result)) {
                $this->debug_manager->log('新しいタームを追加', 'debug', array(
                    'term_id' => $result['term_id'],
                    'term_name' => $term['name'],
                    'taxonomy' => $taxonomy,
                    'site_name' => $site_name
                ));
            } else {
                $this->debug_manager->log('タームの追加に失敗', 'warning', array(
                    'term_name' => $term['name'],
                    'taxonomy' => $taxonomy,
                    'site_name' => $site_name,
                    'error' => $result->get_error_message()
                ));
            }
        }
    }
}
=======
     * タクソノミーの同期
     */
    private function sync_taxonomies($site_data) {
        $this->debug_manager->log(sprintf('サイト "%s" のタクソノミー同期を開始', $site_data['name']), 'info');
        
        try {
            // タクソノミーの取得
            $taxonomies = $this->get_remote_taxonomies($site_data);
            if (is_wp_error($taxonomies)) {
                throw new Exception('タクソノミーの取得に失敗: ' . $taxonomies->get_error_message());
            }

            $imported_categories = 0;
            $imported_tags = 0;

            // カテゴリーの同期
            if (!empty($taxonomies['categories'])) {
                foreach ($taxonomies['categories'] as $category) {
                    $result = $this->import_taxonomy_term($category, 'category', $site_data['name']);
                    if (!is_wp_error($result)) {
                        $imported_categories++;
                    } else {
                        $this->debug_manager->log(sprintf(
                            'カテゴリー "%s" の同期に失敗: %s',
                            $category['name'],
                            $result->get_error_message()
                        ), 'error');
                    }
                }
            }

            // タグの同期
            if (!empty($taxonomies['tags'])) {
                foreach ($taxonomies['tags'] as $tag) {
                    $result = $this->import_taxonomy_term($tag, 'post_tag', $site_data['name']);
                    if (!is_wp_error($result)) {
                        $imported_tags++;
                    } else {
                        $this->debug_manager->log(sprintf(
                            'タグ "%s" の同期に失敗: %s',
                            $tag['name'],
                            $result->get_error_message()
                        ), 'error');
                    }
                }
            }

            $this->debug_manager->log(sprintf(
                'サイト "%s" の同期完了: %d個のカテゴリーと%d個のタグを同期',
                $site_data['name'],
                $imported_categories,
                $imported_tags
            ), 'info');

            return array(
                'categories' => $imported_categories,
                'tags' => $imported_tags
            );

        } catch (Exception $e) {
            $this->debug_manager->log('タクソノミー同期でエラー: ' . $e->getMessage(), 'error');
            return new WP_Error('sync_failed', $e->getMessage());
        }
    }

    /**
     * リモートサイトからタクソノミーを取得
     */
    private function get_remote_taxonomies($site) {
        try {
            // カテゴリーの取得
            $categories_response = wp_remote_get(
                $site['url'] . '/wp-json/wp/v2/categories?per_page=100',
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . $site['app_password'])
                    )
                )
            );

            if (is_wp_error($categories_response)) {
                throw new Exception('カテゴリーの取得に失敗: ' . $categories_response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($categories_response);
            if ($response_code !== 200) {
                throw new Exception('カテゴリーの取得に失敗: HTTPステータス ' . $response_code);
            }

            $categories = json_decode(wp_remote_retrieve_body($categories_response), true);
            if (!is_array($categories)) {
                throw new Exception('カテゴリーの取得に失敗: 無効なレスポンス');
            }

            // タグの取得
            $tags_response = wp_remote_get(
                $site['url'] . '/wp-json/wp/v2/tags?per_page=100',
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . $site['app_password'])
                    )
                )
            );

            if (is_wp_error($tags_response)) {
                throw new Exception('タグの取得に失敗: ' . $tags_response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($tags_response);
            if ($response_code !== 200) {
                throw new Exception('タグの取得に失敗: HTTPステータス ' . $response_code);
            }

            $tags = json_decode(wp_remote_retrieve_body($tags_response), true);
            if (!is_array($tags)) {
                throw new Exception('タグの取得に失敗: 無効なレスポンス');
            }

            $this->debug_manager->log(sprintf(
                'サイト "%s" からタクソノミーを取得: %d個のカテゴリーと%d個のタグ',
                $site['name'],
                count($categories),
                count($tags)
            ), 'info');

            return array(
                'categories' => $categories,
                'tags' => $tags
            );

        } catch (Exception $e) {
            $this->debug_manager->log('タクソノミーの取得でエラー: ' . $e->getMessage(), 'error');
            return new WP_Error('taxonomy_fetch_failed', $e->getMessage());
        }
    }

    /**
     * タクソノミータームをインポート
     */
    private function import_taxonomy_term($term, $taxonomy, $site_name) {
        try {
            // サイト名をプレフィックスとして追加（名前のみ）
            $term_name = $site_name . ' - ' . $term['name'];
            
            // スラッグは元のサイトのものをそのまま使用
            $term_slug = $term['slug'];

            // 既存の用語を検索
            $existing_term = get_term_by('slug', $term_slug, $taxonomy);
            
            $term_args = array(
                'name' => $term_name,
                'slug' => $term_slug,
                'description' => isset($term['description']['rendered']) ? $term['description']['rendered'] : 
                               (isset($term['description']) ? $term['description'] : '')
            );

            // 親カテゴリーの処理
            if ($taxonomy === 'category' && !empty($term['parent'])) {
                $parent_term = get_term($term['parent'], $taxonomy);
                if ($parent_term && !is_wp_error($parent_term)) {
                    $term_args['parent'] = $parent_term->term_id;
                }
            }

            if ($existing_term) {
                // 既存の用語を更新
                $result = wp_update_term($existing_term->term_id, $taxonomy, $term_args);
                $this->debug_manager->log(sprintf(
                    'タクソノミーターム "%s"（スラッグ: %s）を更新 (%s)',
                    $term_name,
                    $term_slug,
                    $taxonomy
                ), 'info');
            } else {
                // 新しい用語を作成
                $result = wp_insert_term($term_name, $taxonomy, $term_args);
                $this->debug_manager->log(sprintf(
                    'タクソノミーターム "%s"（スラッグ: %s）を作成 (%s)',
                    $term_name,
                    $term_slug,
                    $taxonomy
                ), 'info');
            }

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            return $result;

        } catch (Exception $e) {
            $this->debug_manager->log(sprintf(
                'タクソノミーターム "%s" の同期に失敗: %s',
                $term_name,
                $e->getMessage()
            ), 'error');
            return new WP_Error('term_import_failed', $e->getMessage());
        }
    }

    /**
     * サイト追加のAJAXハンドラー
     */
    public function ajax_add_site() {
        check_ajax_referer('wp_cross_post_add_site', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $site_data = array(
            'name' => sanitize_text_field($_POST['site_name']),
            'url' => esc_url_raw($_POST['site_url']),
            'username' => sanitize_text_field($_POST['username']),
            'app_password' => sanitize_text_field($_POST['app_password'])
        );

        if (!$this->validate_site_data($site_data)) {
            wp_send_json_error(array(
                'message' => '必要な情報が不足しています。',
                'type' => 'error'
            ));
        }

        $result = $this->add_site($site_data);
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error'
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'サイトを追加しました。',
                'type' => 'success',
                'site_id' => $result
            ));
        }
    }

    /**
     * サイト削除のAJAXハンドラー
     */
    public function ajax_remove_site() {
        check_ajax_referer('wp_cross_post_remove_site', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $site_id = sanitize_text_field($_POST['site_id']);
        $result = $this->remove_site($site_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error'
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'サイトを削除しました。',
                'type' => 'success'
            ));
        }
    }
}
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
