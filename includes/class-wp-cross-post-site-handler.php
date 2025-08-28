<?php
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

    // キャッシュキーのプレフィックスを定数として定義
    const SITE_CACHE_PREFIX = 'wp_cross_post_site_';
    const TAXONOMY_CACHE_PREFIX = 'wp_cross_post_taxonomies_';
    
    // キャッシュの有効期限（秒）
    const SITE_CACHE_EXPIRY = 30 * MINUTE_IN_SECONDS; // 30分
    const TAXONOMY_CACHE_EXPIRY = HOUR_IN_SECONDS; // 1時間

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
            return $this->error_manager->handle_api_error($test_result, '接続テスト');
        }

        // サイト名を取得
        $site_name = $this->get_site_name($site_data);
        if (is_wp_error($site_name)) {
            $this->debug_manager->log('サイト名の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $site_name->get_error_message()
            ));
            return $this->error_manager->handle_general_error($site_name->get_error_message(), 'サイト名取得');
        }

        // 新しいサイトIDを生成
        $site_data['id'] = uniqid('site_');
        $site_data['name'] = $site_name;

        // タクソノミーの同期
        $sync_result = $this->sync_taxonomies($site_data);
        if (is_wp_error($sync_result)) {
            $this->debug_manager->log('タクソノミーの同期に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $sync_result->get_error_message()
            ));
            return $this->error_manager->handle_general_error($sync_result->get_error_message(), 'タクソノミー同期');
        }

        $sites[] = $site_data;
        update_option('wp_cross_post_sites', $sites);

        // すべてのサイトデータのキャッシュをクリア
        $this->clear_all_sites_cache();

        $this->debug_manager->log('サイトを追加', 'info', array(
            'site_id' => $site_data['id'],
            'site_name' => $site_data['name'],
            'site_url' => $site_data['url']
        ));
        
        return $site_data['id'];
    }

    /**
     * サイト名を取得
     */
    private function get_site_name($site_data) {
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        $response = wp_remote_get($site_data['url'] . '/wp-json', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'サイト名の取得に失敗しました: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['name']) ? $data['name'] : parse_url($site_data['url'], PHP_URL_HOST);
    }

    public function remove_site($site_id) {
        $this->debug_manager->log('サイト削除を開始', 'info', array(
            'site_id' => $site_id
        ));
        
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $key => $site) {
            if ($site['id'] === $site_id) {
                unset($sites[$key]);
                update_option('wp_cross_post_sites', array_values($sites));
                
                // 削除されたサイトのキャッシュをクリア
                $this->clear_site_cache($site_id);
                $this->clear_taxonomies_cache($site_id);
                
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
        
        return false;
    }

    public function get_sites() {
        // 設定からキャッシュの有効/無効を取得
        $config_manager = WP_Cross_Post_Config_Manager::get_settings();
        $enable_cache = isset($config_manager['cache_settings']['enable_cache']) ? 
                        $config_manager['cache_settings']['enable_cache'] : true;
        
        if ($enable_cache) {
            // キャッシュキーの生成
            $cache_key = self::SITE_CACHE_PREFIX . 'all_sites';
            
            // キャッシュからサイトデータを取得
            $sites = get_transient($cache_key);
            
            if ($sites !== false) {
                $this->debug_manager->log('サイトデータをキャッシュから取得', 'info');
                return $sites;
            }
        }
        
        // キャッシュにない場合は、オプションから取得
        $sites = get_option('wp_cross_post_sites', array());
        
        if ($enable_cache) {
            // サイトデータをキャッシュに保存
            $cache_duration = isset($config_manager['cache_settings']['cache_duration']) ? 
                              $config_manager['cache_settings']['cache_duration'] : self::SITE_CACHE_EXPIRY;
            set_transient($cache_key, $sites, $cache_duration);
            
            $this->debug_manager->log('サイトデータをキャッシュに保存', 'info', array(
                'cache_duration' => $cache_duration
            ));
        }
        
        return $sites;
    }

    private function validate_site_data($site_data) {
        $required_fields = array('name', 'url', 'username', 'app_password');
        foreach ($required_fields as $field) {
            if (empty($site_data[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * タクソノミーの同期
     */
    public function sync_taxonomies($site_data) {
        $this->debug_manager->log('タクソノミー同期を開始', 'info', array(
            'site_url' => $site_data['url']
        ));

        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        // カテゴリー情報の取得
        $categories_response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/categories?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));
        
        if (is_wp_error($categories_response)) {
            $this->debug_manager->log('カテゴリー情報の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $categories_response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($categories_response, 'カテゴリー取得');
        }
        
        $categories = json_decode(wp_remote_retrieve_body($categories_response), true);
        
        // タグ情報の取得
        $tags_response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/tags?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));
        
        if (is_wp_error($tags_response)) {
            $this->debug_manager->log('タグ情報の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $tags_response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($tags_response, 'タグ取得');
        }
        
        $tags = json_decode(wp_remote_retrieve_body($tags_response), true);
        
        // 現在のタクソノミー情報を取得
        $current_taxonomies = get_option('wp_cross_post_taxonomies', array());
        
        // サイトごとのタクソノミー情報を更新
        $current_taxonomies[$site_data['id']] = array(
            'categories' => $categories,
            'tags' => $tags,
            'last_updated' => current_time('mysql')
        );
        
        // オプションを更新
        update_option('wp_cross_post_taxonomies', $current_taxonomies);
        
        // タクソノミー情報のキャッシュをクリア
        $this->clear_taxonomies_cache($site_data['id']);
        
        $this->debug_manager->log('タクソノミー同期を完了', 'info', array(
            'site_url' => $site_data['url'],
            'category_count' => count($categories),
            'tag_count' => count($tags)
        ));
        
        return true;
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
     * サイトデータを取得（キャッシュ対応）
     */
    public function get_site_data($site_id) {
        // 設定からキャッシュの有効/無効を取得
        $config_manager = WP_Cross_Post_Config_Manager::get_settings();
        $enable_cache = isset($config_manager['cache_settings']['enable_cache']) ? 
                        $config_manager['cache_settings']['enable_cache'] : true;
        
        if ($enable_cache) {
            // キャッシュキーの生成
            $cache_key = self::SITE_CACHE_PREFIX . $site_id;
            
            // キャッシュからサイトデータを取得
            $site_data = get_transient($cache_key);
            
            if ($site_data !== false) {
                $this->debug_manager->log('サイトデータをキャッシュから取得', 'info', array(
                    'site_id' => $site_id
                ));
                return $site_data;
            }
        }
        
        // キャッシュにない場合は、オプションから取得
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $site) {
            if ($site['id'] === $site_id) {
                $site_data = $site;
                break;
            }
        }
        
        if (!$site_data) {
            return null;
        }
        
        if ($enable_cache) {
            // サイトデータをキャッシュに保存
            $cache_duration = isset($config_manager['cache_settings']['cache_duration']) ? 
                              $config_manager['cache_settings']['cache_duration'] : self::SITE_CACHE_EXPIRY;
            set_transient($cache_key, $site_data, $cache_duration);
            
            $this->debug_manager->log('サイトデータをキャッシュに保存', 'info', array(
                'site_id' => $site_id,
                'cache_duration' => $cache_duration
            ));
        }
        
        return $site_data;
    }
    
    /**
     * サイトデータのキャッシュをクリア
     */
    public function clear_site_cache($site_id) {
        $cache_key = self::SITE_CACHE_PREFIX . $site_id;
        delete_transient($cache_key);
        
        $this->debug_manager->log('サイトデータのキャッシュをクリア', 'info', array(
            'site_id' => $site_id
        ));
    }
    
    /**
     * すべてのサイトデータのキャッシュをクリア
     */
    public function clear_all_sites_cache() {
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $site) {
            $this->clear_site_cache($site['id']);
        }
        
        // すべてのサイトデータのキャッシュもクリア
        $cache_key = self::SITE_CACHE_PREFIX . 'all_sites';
        delete_transient($cache_key);
        
        $this->debug_manager->log('すべてのサイトデータのキャッシュをクリア', 'info');
    }

    /**
     * タクソノミー情報を取得（キャッシュ対応）
     */
    public function get_cached_taxonomies($site_id) {
        // 設定からキャッシュの有効/無効を取得
        $config_manager = WP_Cross_Post_Config_Manager::get_settings();
        $enable_cache = isset($config_manager['cache_settings']['enable_cache']) ? 
                        $config_manager['cache_settings']['enable_cache'] : true;
        
        if ($enable_cache) {
            // キャッシュキーの生成
            $cache_key = self::TAXONOMY_CACHE_PREFIX . $site_id;
            
            // キャッシュからタクソノミー情報を取得
            $taxonomies = get_transient($cache_key);
            
            if ($taxonomies !== false) {
                $this->debug_manager->log('タクソノミー情報をキャッシュから取得', 'info', array(
                    'site_id' => $site_id
                ));
                return $taxonomies;
            }
        }
        
        // キャッシュにない場合は、APIから取得
        $site_data = $this->get_site_data($site_id);
        if (!$site_data) {
            return null;
        }
        
        $taxonomies = $this->fetch_taxonomies_from_api($site_data);
        
        if (is_wp_error($taxonomies)) {
            return $taxonomies;
        }
        
        if ($enable_cache) {
            // タクソノミー情報をキャッシュに保存
            $cache_duration = isset($config_manager['cache_settings']['cache_duration']) ? 
                              $config_manager['cache_settings']['cache_duration'] : self::TAXONOMY_CACHE_EXPIRY;
            set_transient($cache_key, $taxonomies, $cache_duration);
            
            $this->debug_manager->log('タクソノミー情報をキャッシュに保存', 'info', array(
                'site_id' => $site_id,
                'cache_duration' => $cache_duration
            ));
        }
        
        return $taxonomies;
    }
    
    /**
     * APIからタクソノミー情報を取得
     */
    private function fetch_taxonomies_from_api($site_data) {
        $this->debug_manager->log('APIからタクソノミー情報を取得開始', 'info', array(
            'site_url' => $site_data['url']
        ));
        
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        // カテゴリー情報の取得
        $categories_response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/categories?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));
        
        if (is_wp_error($categories_response)) {
            $this->debug_manager->log('カテゴリー情報の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $categories_response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($categories_response, 'カテゴリー取得');
        }
        
        $categories = json_decode(wp_remote_retrieve_body($categories_response), true);
        
        // タグ情報の取得
        $tags_response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/tags?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));
        
        if (is_wp_error($tags_response)) {
            $this->debug_manager->log('タグ情報の取得に失敗', 'error', array(
                'site_url' => $site_data['url'],
                'error' => $tags_response->get_error_message()
            ));
            return $this->error_manager->handle_api_error($tags_response, 'タグ取得');
        }
        
        $tags = json_decode(wp_remote_retrieve_body($tags_response), true);
        
        $taxonomies = array(
            'categories' => $categories,
            'tags' => $tags
        );
        
        $this->debug_manager->log('APIからタクソノミー情報を取得完了', 'info', array(
            'site_url' => $site_data['url'],
            'category_count' => count($categories),
            'tag_count' => count($tags)
        ));
        
        return $taxonomies;
    }
    
    /**
     * タクソノミー情報のキャッシュをクリア
     */
    public function clear_taxonomies_cache($site_id) {
        $cache_key = self::TAXONOMY_CACHE_PREFIX . $site_id;
        delete_transient($cache_key);
        
        $this->debug_manager->log('タクソノミー情報のキャッシュをクリア', 'info', array(
            'site_id' => $site_id
        ));
    }
    
    /**
     * すべてのタクソノミー情報のキャッシュをクリア
     */
    public function clear_all_taxonomies_cache() {
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $site) {
            $this->clear_taxonomies_cache($site['id']);
        }
        
        $this->debug_manager->log('すべてのタクソノミー情報のキャッシュをクリア', 'info');
    }

    /**
     * 全サイトのタクソノミーを同期
     */
    public function sync_all_sites_taxonomies() {
        $this->debug_manager->log('全サイトのタクソノミー同期を開始', 'info');
        
        $sites = get_option('wp_cross_post_sites', array());
        $results = array(
            'success_sites' => array(),
            'failed_sites' => array()
        );
        
        // 同期処理のパフォーマンスを監視
        $this->debug_manager->start_performance_monitoring('sync_all_sites_taxonomies');

        foreach ($sites as $site) {
            try {
                $this->debug_manager->log('サイトのタクソノミー同期を開始', 'info', array(
                    'site_id' => $site['id'],
                    'site_name' => $site['name']
                ));
                
                // サイトへの接続テスト
                $test_result = $this->api_handler->test_connection($site);
                if (is_wp_error($test_result)) {
                    throw new Exception('接続テストに失敗: ' . $test_result->get_error_message());
                }

                // カテゴリーとタグの取得
                $taxonomies = $this->get_remote_taxonomies($site);
                if (is_wp_error($taxonomies)) {
                    throw new Exception('タクソノミー情報の取得に失敗: ' . $taxonomies->get_error_message());
                }

                // カテゴリーの同期
                $category_count = 0;
                if (!empty($taxonomies['categories'])) {
                    foreach ($taxonomies['categories'] as $category) {
                        $import_result = $this->import_taxonomy_term($category, 'category', $site['name']);
                        if (!is_wp_error($import_result)) {
                            $category_count++;
                        } else {
                            $this->debug_manager->log('カテゴリーの同期に失敗', 'warning', array(
                                'site_id' => $site['id'],
                                'site_name' => $site['name'],
                                'term_name' => $category['name'],
                                'error' => $import_result->get_error_message()
                            ));
                        }
                    }
                }

                // タグの同期
                $tag_count = 0;
                if (!empty($taxonomies['tags'])) {
                    foreach ($taxonomies['tags'] as $tag) {
                        $import_result = $this->import_taxonomy_term($tag, 'post_tag', $site['name']);
                        if (!is_wp_error($import_result)) {
                            $tag_count++;
                        } else {
                            $this->debug_manager->log('タグの同期に失敗', 'warning', array(
                                'site_id' => $site['id'],
                                'site_name' => $site['name'],
                                'term_name' => $tag['name'],
                                'error' => $import_result->get_error_message()
                            ));
                        }
                    }
                }

                $results['success_sites'][] = array(
                    'site_id' => $site['id'],
                    'name' => $site['name'],
                    'category_count' => $category_count,
                    'tag_count' => $tag_count
                );
                
                $this->debug_manager->log('サイトのタクソノミー同期を完了', 'info', array(
                    'site_id' => $site['id'],
                    'site_name' => $site['name'],
                    'category_count' => $category_count,
                    'tag_count' => $tag_count
                ));

            } catch (Exception $e) {
                $this->debug_manager->log('サイトのタクソノミー同期でエラー', 'error', array(
                    'site_id' => $site['id'],
                    'site_name' => $site['name'],
                    'error' => $e->getMessage()
                ));
                
                $results['failed_sites'][] = array(
                    'site_id' => $site['id'],
                    'name' => $site['name'],
                    'error' => $e->getMessage()
                );
            }
        }
        
        // 同期処理のパフォーマンスを監視終了
        $this->debug_manager->end_performance_monitoring('sync_all_sites_taxonomies');
        
        $this->debug_manager->log('全サイトのタクソノミー同期を完了', 'info', array(
            'success_count' => count($results['success_sites']),
            'failed_count' => count($results['failed_sites'])
        ));

        return $results;
    }

    /**
     * 定期実行用のフック設定
     */
    public function schedule_taxonomies_sync() {
        if (!wp_next_scheduled('wp_cross_post_daily_sync')) {
            wp_schedule_event(strtotime('today 03:00:00'), 'daily', 'wp_cross_post_daily_sync');
        }
    }

    /**
     * 定期実行のフックを解除
     */
    public function unschedule_taxonomies_sync() {
        wp_clear_scheduled_hook('wp_cross_post_daily_sync');
    }

    /**
     * 手動同期用のAJAXハンドラー
     */
    public function ajax_sync_taxonomies() {
        check_ajax_referer('wp_cross_post_taxonomy_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        try {
            $this->debug_manager->start_performance_monitoring('ajax_sync_taxonomies');
            $result = $this->sync_all_sites_taxonomies();
            $this->debug_manager->end_performance_monitoring('ajax_sync_taxonomies');

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            update_option('wp_cross_post_last_sync_time', current_time('mysql'));
            $this->debug_manager->log('wp_cross_post_last_sync_time updated', 'debug');
            
            // 詳細な同期結果をログに出力
            $this->debug_manager->log('タクソノミー同期結果', 'info', $result);
            
            wp_send_json_success(array(
                'message' => 'カテゴリーとタグの同期が完了しました。',
                'type' => 'success',
                'details' => $result
            ));

        } catch (Exception $e) {
            $this->debug_manager->log('タクソノミー同期でエラー: ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'type' => 'error'
            ));
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
    
    /**
     * リモートのタクソノミー情報を取得
     */
    private function get_remote_taxonomies($site) {
        // 設定からキャッシュの有効/無効を取得
        $config_manager = WP_Cross_Post_Config_Manager::get_settings();
        $enable_cache = isset($config_manager['cache_settings']['enable_cache']) ? 
                        $config_manager['cache_settings']['enable_cache'] : true;
        
        if ($enable_cache) {
            // キャッシュキーの生成
            $cache_key = self::TAXONOMY_CACHE_PREFIX . $site['id'];
            
            // キャッシュからタクソノミー情報を取得
            $taxonomies = get_transient($cache_key);
            
            if ($taxonomies !== false) {
                $this->debug_manager->log('タクソノミー情報をキャッシュから取得', 'info', array(
                    'site_id' => $site['id']
                ));
                return $taxonomies;
            }
        }
        
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site);
        
        // カテゴリー情報の取得
        $categories_response = wp_remote_get($site['url'] . '/wp-json/wp/v2/categories?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));
        
        if (is_wp_error($categories_response)) {
            return $categories_response;
        }
        
        $categories = json_decode(wp_remote_retrieve_body($categories_response), true);
        
        // タグ情報の取得
        $tags_response = wp_remote_get($site['url'] . '/wp-json/wp/v2/tags?per_page=100', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));
        
        if (is_wp_error($tags_response)) {
            return $tags_response;
        }
        
        $tags = json_decode(wp_remote_retrieve_body($tags_response), true);
        
        $taxonomies = array(
            'categories' => $categories,
            'tags' => $tags
        );
        
        if ($enable_cache) {
            // タクソノミー情報をキャッシュに保存
            $cache_duration = isset($config_manager['cache_settings']['cache_duration']) ? 
                              $config_manager['cache_settings']['cache_duration'] : self::TAXONOMY_CACHE_EXPIRY;
            set_transient($cache_key, $taxonomies, $cache_duration);
            
            $this->debug_manager->log('タクソノミー情報をキャッシュに保存', 'info', array(
                'site_id' => $site['id'],
                'cache_duration' => $cache_duration
            ));
        }
        
        return $taxonomies;
    }
}