<?php
/**
 * Plugin Name: WP Cross Post
 * Plugin URI: https://github.com/watayoshi1984/WP-Cross-Post.git
 * Description: WordPressサイト間で記事を同期するプラグイン。カテゴリーとタグの自動同期、マテリアルデザインUI、REST API v2対応。
 * Version: 1.2.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: watayoshi
 * Author URI: 
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-cross-post
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// 既に定数が定義されている場合は、再定義しない
if (!defined('WP_CROSS_POST_VERSION')) {
    define('WP_CROSS_POST_VERSION', '1.2.2');
}
if (!defined('WP_CROSS_POST_PLUGIN_DIR')) {
    define('WP_CROSS_POST_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WP_CROSS_POST_PLUGIN_URL')) {
    define('WP_CROSS_POST_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// 既にクラスが定義されている場合は、再定義しない
if (!class_exists('WP_Cross_Post')) {

    require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/class-autoloader.php';
    
    // 必要なクラスファイルを手動で読み込む
    $required_classes = array(
        'WP_Cross_Post_Database_Manager' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-database-manager.php',
        'WP_Cross_Post_Media_Sync_Manager' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-media-sync-manager.php',
        'WP_Cross_Post_Sync_History_Manager' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-sync-history-manager.php',
        'WP_Cross_Post_API_Handler' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-api-handler.php',
        'WP_Cross_Post_Sync_Handler' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-sync-handler.php',
        'WP_Cross_Post_Site_Handler_V2' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-site-handler-v2.php',
        'WP_Cross_Post_Sync_Engine' => WP_CROSS_POST_PLUGIN_DIR . 'includes/class-wp-cross-post-sync-engine.php'
    );
    
    foreach ($required_classes as $class_name => $file_path) {
        if (!class_exists($class_name) && file_exists($file_path)) {
            require_once $file_path;
        }
    }
    
    require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/class-config-manager.php';
    require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/ajax-handlers.php';
    require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/debug/class-debug-manager.php';

    class WP_Cross_Post {
        private $api_handler;
        private $sync_handler;
        public $site_handler;
        private $error_handler;
        private $debug_manager;
        private $auth_manager;
        private $image_manager;
        private $post_data_preparer;
        private $block_content_processor;
        private $error_manager;
        private $rate_limit_manager;
        private $sync_engine;
        private $media_sync_manager;
        private $sync_history_manager;

        public function __construct() {
            // マネージャークラスのインスタンス化
            $this->debug_manager = WP_Cross_Post_Debug_Manager::get_instance();
            $this->auth_manager = WP_Cross_Post_Auth_Manager::get_instance();
            $this->image_manager = WP_Cross_Post_Image_Manager::get_instance();
            $this->post_data_preparer = WP_Cross_Post_Post_Data_Preparer::get_instance();
            $this->block_content_processor = WP_Cross_Post_Block_Content_Processor::get_instance();
            $this->error_manager = WP_Cross_Post_Error_Manager::get_instance();
            $this->rate_limit_manager = WP_Cross_Post_Rate_Limit_Manager::get_instance();
            
            // 新しいマネージャークラスのインスタンス化
            $this->media_sync_manager = new WP_Cross_Post_Media_Sync_Manager($this->debug_manager, $this->error_manager);
            $this->sync_history_manager = new WP_Cross_Post_Sync_History_Manager($this->debug_manager, $this->error_manager);
            
            // 依存関係の設定
            $this->error_manager->set_dependencies($this->debug_manager);
            $this->image_manager->set_dependencies($this->debug_manager, $this->auth_manager, $this->rate_limit_manager);
            $this->post_data_preparer->set_dependencies($this->debug_manager, $this->block_content_processor);
            $this->block_content_processor->set_dependencies($this->debug_manager, $this->image_manager);
            $this->rate_limit_manager->set_dependencies($this->debug_manager, $this->error_manager);
            
            // データベースのアップグレードチェック
            WP_Cross_Post_Database_Manager::maybe_upgrade_database();
            
            // ハンドラークラスのインスタンス化
            // Autoloaderがクラスをロードするのを待つ
            if (!class_exists('WP_Cross_Post_API_Handler')) {
                $this->debug_manager->log('WP_Cross_Post_API_Handler クラスが読み込まれていません。', 'error');
                return;
            }
            
            if (!class_exists('WP_Cross_Post_Site_Handler_V2')) {
                $this->debug_manager->log('WP_Cross_Post_Site_Handler_V2 クラスが読み込まれていません。', 'error');
                return;
            }
            
            if (!class_exists('WP_Cross_Post_Sync_Engine')) {
                $this->debug_manager->log('WP_Cross_Post_Sync_Engine クラスが読み込まれていません。', 'error');
                return;
            }
            
            if (!class_exists('WP_Cross_Post_Sync_Handler')) {
                $this->debug_manager->log('WP_Cross_Post_Sync_Handler クラスが読み込まれていません。', 'error');
                return;
            }
            
            $this->api_handler = new WP_Cross_Post_API_Handler($this->debug_manager, $this->auth_manager, $this->error_manager, $this->rate_limit_manager);
            $this->site_handler = new WP_Cross_Post_Site_Handler_V2($this->debug_manager, $this->auth_manager, $this->error_manager, $this->api_handler, $this->rate_limit_manager);
            $this->sync_engine = new WP_Cross_Post_Sync_Engine($this->auth_manager, $this->image_manager, $this->error_manager, $this->debug_manager, $this->site_handler, $this->api_handler, $this->post_data_preparer, $this->rate_limit_manager, $this->media_sync_manager, $this->sync_history_manager);
            $this->sync_handler = new WP_Cross_Post_Sync_Handler($this->api_handler, $this->debug_manager, $this->site_handler, $this->auth_manager, $this->image_manager, $this->post_data_preparer, $this->error_manager, $this->rate_limit_manager);
            $this->error_handler = new WP_Cross_Post_Error_Handler();

            if ($this->debug_manager->is_debug_mode()) {
                add_action('init', array($this, 'start_performance_monitoring'));
            }

            add_action('init', array($this, 'register_post_types'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
            add_action('add_meta_boxes', array($this, 'add_post_meta_box'));
            add_action('save_post', array($this, 'save_post_meta'));

            // AJAXアクションの登録
            add_action('wp_ajax_wp_cross_post_sync', array($this->sync_handler, 'ajax_sync_post'));
            add_action('wp_ajax_wp_cross_post_sync_taxonomies', array($this->site_handler, 'ajax_sync_taxonomies'));
            add_action('wp_ajax_wp_cross_post_add_site', array($this->site_handler, 'ajax_add_site'));
            add_action('wp_ajax_wp_cross_post_remove_site', array($this->site_handler, 'ajax_remove_site'));
            add_action('wp_ajax_wp_cross_post_refresh_logs', array($this, 'ajax_refresh_logs'));
            add_action('wp_ajax_wp_cross_post_refresh_system_info', array($this, 'ajax_refresh_system_info'));
            add_action('wp_ajax_wp_cross_post_log_js_error', array($this, 'ajax_log_js_error'));
            add_action('wp_ajax_wp_cross_post_export_settings', array($this, 'ajax_export_settings'));
            add_action('wp_ajax_wp_cross_post_import_settings', array($this, 'ajax_import_settings'));
            add_action('wp_ajax_wp_cross_post_update_notification_settings', array($this, 'ajax_update_notification_settings'));
            // Taxonomies fetch for post editor UI
            add_action('wp_ajax_wp_cross_post_get_site_taxonomies', array($this->site_handler, 'ajax_get_site_taxonomies'));

            // 定期実行のフック
            add_action('wp_cross_post_daily_sync', array($this->site_handler, 'sync_all_sites_taxonomies'));
            
            // 非同期処理のフック
            add_action('wp_cross_post_process_async_sync', array($this->sync_engine, 'process_async_sync'), 10, 2);

            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }

        /**
         * 非同期同期タスクをスケジュール
         */
        public function schedule_async_sync($post_id, $site_id) {
            // タスクをスケジュール
            // site_idが配列の場合、各サイトに対して個別にタスクをスケジュール
            if (is_array($site_id)) {
                $task_ids = array();
                foreach ($site_id as $single_site_id) {
                    // WP-Cronが有効かどうかを確認
                    if (!wp_next_scheduled('wp_cross_post_process_async_sync', array($post_id, $single_site_id))) {
                        $task_id = wp_schedule_single_event(time(), 'wp_cross_post_process_async_sync', array($post_id, $single_site_id));
                        if ($task_id) {
                            $task_ids[] = $task_id;
                            $this->debug_manager->log('非同期同期タスクをスケジュールしました', 'info', array(
                                'post_id' => $post_id,
                                'site_id' => $single_site_id,
                                'task_id' => $task_id
                            ));
                        } else {
                            $this->debug_manager->log('非同期同期タスクのスケジュールに失敗しました', 'error', array(
                                'post_id' => $post_id,
                                'site_id' => $single_site_id,
                                'reason' => 'wp_schedule_single_event returned false'
                            ));
                        }
                    } else {
                        $this->debug_manager->log('非同期同期タスクは既にスケジュールされています', 'info', array(
                            'post_id' => $post_id,
                            'site_id' => $single_site_id
                        ));
                    }
                }
                return $task_ids;
            } else {
                // site_idが単一の値の場合
                // WP-Cronが有効かどうかを確認
                if (!wp_next_scheduled('wp_cross_post_process_async_sync', array($post_id, $site_id))) {
                    $task_id = wp_schedule_single_event(time(), 'wp_cross_post_process_async_sync', array($post_id, $site_id));
                    
                    if ($task_id) {
                        $this->debug_manager->log('非同期同期タスクをスケジュールしました', 'info', array(
                            'post_id' => $post_id,
                            'site_id' => $site_id,
                            'task_id' => $task_id
                        ));
                        return $task_id;
                    } else {
                        $this->debug_manager->log('非同期同期タスクのスケジュールに失敗しました', 'error', array(
                            'post_id' => $post_id,
                            'site_id' => $site_id,
                            'reason' => 'wp_schedule_single_event returned false'
                        ));
                        return false;
                    }
                } else {
                    $this->debug_manager->log('非同期同期タスクは既にスケジュールされています', 'info', array(
                        'post_id' => $post_id,
                        'site_id' => $site_id
                    ));
                    return true; // 既にスケジュールされている場合はtrueを返す
                }
            }
        }

        public function register_post_types() {
            // 非同期処理用のカスタムポストタイプを登録
            register_post_type('wp_cross_post_async', array(
                'labels' => array(
                    'name' => 'Async Sync Tasks',
                    'singular_name' => 'Async Sync Task',
                ),
                'public' => false,
                'has_archive' => false,
                'rewrite' => false,
                'show_ui' => false,
                'supports' => array('title'),
            ));
        }

        public function start_performance_monitoring() {
            $this->debug_manager->start_performance_monitoring('plugin_init');
            add_action('shutdown', function() {
                $this->debug_manager->end_performance_monitoring('plugin_init');
            });
        }

        public function ajax_refresh_logs() {
            check_ajax_referer('wp_cross_post_debug', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('権限がありません。');
            }

            ob_start();
            include WP_CROSS_POST_PLUGIN_DIR . 'includes/debug/templates/debug-panel.php';
            $content = ob_get_clean();

            wp_send_json_success(array(
                'logs' => $content
            ));
        }

        public function ajax_refresh_system_info() {
            check_ajax_referer('wp_cross_post_debug', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('権限がありません。');
            }

            $system_info = array(
                'php_version' => phpversion(),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => WP_CROSS_POST_VERSION,
                'memory_usage' => size_format(memory_get_usage(true))
            );

            wp_send_json_success(array(
                'systemInfo' => $system_info
            ));
        }

        public function ajax_log_js_error() {
            check_ajax_referer('wp_cross_post_debug', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('権限がありません。');
            }

            $error = $_POST['error'];
            $this->debug_manager->log(
                sprintf('JavaScript Error: %s at %s:%d', $error['message'], $error['url'], $error['line']),
                'error',
                $error
            );

            wp_send_json_success();
        }

        public function ajax_export_settings() {
            check_ajax_referer('wp_cross_post_export_settings', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('権限がありません。');
            }

            $settings = WP_Cross_Post_Config_Manager::export_settings();
            wp_send_json_success($settings);
        }

        public function ajax_import_settings() {
            check_ajax_referer('wp_cross_post_import_settings', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('権限がありません。');
            }

            $settings = sanitize_text_field($_POST['settings']);
            $result = WP_Cross_Post_Config_Manager::import_settings($settings);

            if ($result) {
                wp_send_json_success('設定をインポートしました。');
            } else {
                wp_send_json_error('設定のインポートに失敗しました。');
            }
        }
        
        public function ajax_update_notification_settings() {
            check_ajax_referer('wp_cross_post_update_notification_settings', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('権限がありません。');
            }

            $settings = array(
                'enabled' => isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false,
                'email' => isset($_POST['email']) ? sanitize_email($_POST['email']) : get_option('admin_email'),
                'threshold' => isset($_POST['threshold']) ? sanitize_text_field($_POST['threshold']) : 'error'
            );
            
            $result = $this->error_manager->update_notification_settings($settings);

            if ($result) {
                wp_send_json_success('通知設定を更新しました。');
            } else {
                wp_send_json_error('通知設定の更新に失敗しました。');
            }
        }

        public function add_admin_menu() {
            add_menu_page(
                'WP Cross Post',
                'Cross Post',
                'manage_options',
                'wp-cross-post',
                array($this, 'render_settings_page'),
                'dashicons-share-alt',
                100
            );

            add_submenu_page(
                'wp-cross-post',
                'サイト管理',
                'サイト管理',
                'manage_options',
                'wp-cross-post-sites',
                array($this, 'render_sites_page')
            );
        }

        public function enqueue_admin_scripts($hook) {
            if ($hook === 'post.php' || $hook === 'post-new.php' || strpos($hook, 'wp-cross-post') !== false) {
                wp_enqueue_style(
                    'material-icons',
                    'https://fonts.googleapis.com/icon?family=Material+Icons',
                    array(),
                    WP_CROSS_POST_VERSION
                );

                wp_enqueue_script(
                    'wp-cross-post-admin',
                    plugins_url('assets/js/admin.js', __FILE__),
                    array('jquery'),
                    WP_CROSS_POST_VERSION,
                    true
                );

                wp_localize_script('wp-cross-post-admin', 'wpCrossPost', array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wp_cross_post_sync'),
                    'taxonomyNonce' => wp_create_nonce('wp_cross_post_taxonomy_sync'),
                    'addSiteNonce' => wp_create_nonce('wp_cross_post_add_site'),
                    'removeSiteNonce' => wp_create_nonce('wp_cross_post_remove_site'),
                    'debugNonce' => wp_create_nonce('wp_cross_post_debug'),
                    'exportSettingsNonce' => wp_create_nonce('wp_cross_post_export_settings'),
                    'importSettingsNonce' => wp_create_nonce('wp_cross_post_import_settings'),
                    'updateNotificationSettingsNonce' => wp_create_nonce('wp_cross_post_update_notification_settings'),
                    'i18n' => array(
                        'syncError' => __('同期に失敗しました。', 'wp-cross-post'),
                        'taxonomySyncError' => __('タクソノミーの同期に失敗しました。', 'wp-cross-post')
                    )
                ));

                if ($hook === 'post.php' || $hook === 'post-new.php') {
                    wp_enqueue_script(
                        'wp-cross-post-post',
                        plugins_url('admin/js/post.js', __FILE__),
                        array('jquery'),
                        WP_CROSS_POST_VERSION,
                        true
                    );

                    // Localize data for post editor script
                    wp_localize_script('wp-cross-post-post', 'wpCrossPostData', array(
                        'ajaxurl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('wp_cross_post_sync'),
                        'taxonomyFetchNonce' => wp_create_nonce('wp_cross_post_taxonomy_fetch')
                    ));
                }
            }
        }

        public function add_post_meta_box() {
            add_meta_box(
                'wp-cross-post-meta-box',
                'WP Cross Post',
                array($this, 'render_post_meta_box'),
                array('post', 'page'),
                'side',
                'default'
            );
        }

        public function render_post_meta_box($post) {
            // 新しいSite_Handler_V2からサイト情報を取得
            $sites = $this->site_handler->get_sites();
            
            // メタボックステンプレートに変数を渡す
            include WP_CROSS_POST_PLUGIN_DIR . 'admin/post-meta-box.php';
        }

        public function save_post_meta($post_id) {
            // 自動保存の場合は何もしない
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // 権限を確認
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            // ノンスを確認
            if (!isset($_POST['wp_cross_post_nonce']) || !wp_verify_nonce($_POST['wp_cross_post_nonce'], 'wp_cross_post_sync')) {
                return;
            }

            // 同期先サイトの保存
            $selected_sites = isset($_POST['wp_cross_post_sites']) ? array_map('sanitize_text_field', (array) $_POST['wp_cross_post_sites']) : array();
            update_post_meta($post_id, '_wp_cross_post_sites', $selected_sites);

            // サイト別設定の保存（status, date, category, tags）
            $site_settings = isset($_POST['wp_cross_post_setting']) && is_array($_POST['wp_cross_post_setting']) ? $_POST['wp_cross_post_setting'] : array();
            $clean = array();
            foreach ($site_settings as $sid => $conf) {
                $sid_clean = sanitize_text_field($sid);
                $item = array();
                if (isset($conf['status'])) { $item['status'] = sanitize_text_field($conf['status']); }
                if (isset($conf['date'])) { $item['date'] = sanitize_text_field($conf['date']); }
                if (isset($conf['category'])) { $item['category'] = sanitize_text_field($conf['category']); }
                if (isset($conf['tags'])) {
                    $tags = is_array($conf['tags']) ? array_map('sanitize_text_field', $conf['tags']) : array();
                    $item['tags'] = $tags;
                }
                $clean[$sid_clean] = $item;
            }
            update_post_meta($post_id, '_wp_cross_post_site_settings', $clean);
        }

        public function render_settings_page() {
            include WP_CROSS_POST_PLUGIN_DIR . 'admin/settings.php';
        }

        public function render_sites_page() {
            include WP_CROSS_POST_PLUGIN_DIR . 'admin/sites.php';
        }

        public function activate() {
            // 出力バッファリングを開始して予期しない出力を防ぐ
            ob_start();
            
            try {
                // カスタムテーブルの作成
                WP_Cross_Post_Database_Manager::create_tables();
                
                // 毎日午前3時にタクソノミーの自動同期をスケジュール
                if (!wp_next_scheduled('wp_cross_post_daily_sync')) {
                    wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', 'wp_cross_post_daily_sync');
                }
            } catch (Exception $e) {
                // エラーログを出力（デバッグモードの場合のみ）
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Cross Post activation error: ' . $e->getMessage());
                }
            } finally {
                // 出力バッファの内容を破棄
                ob_end_clean();
            }
        }

        public function deactivate() {
            // スケジュールされたイベントを削除
            wp_clear_scheduled_hook('wp_cross_post_daily_sync');
            wp_clear_scheduled_hook('wp_cross_post_process_async_sync');
        }
    }

    // クラスのインスタンスを作成
    global $wp_cross_post;
    $wp_cross_post = new WP_Cross_Post();
}