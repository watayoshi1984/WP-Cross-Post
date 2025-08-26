<?php
/**
 * Plugin Name: WP Cross Post
 * Plugin URI: https://yamaoku-seo.com/wp-cross-post
 * Description: WordPressサイト間で記事を同期するプラグイン。カテゴリーとタグの自動同期、マテリアルデザインUI、REST API v2対応。
 * Version: 1.0.9
 * Requires at least: 6.4
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

define('WP_CROSS_POST_VERSION', '1.0.9');
define('WP_CROSS_POST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_CROSS_POST_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/class-autoloader.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/debug/class-debug-manager.php';

class WP_Cross_Post {
    private $api_handler;
    private $sync_handler;
    private $site_handler;
    private $error_handler;
    private $debug_manager;

    public function __construct() {
        $this->api_handler = new WP_Cross_Post_API_Handler();
        $this->sync_handler = new WP_Cross_Post_Sync_Handler($this->api_handler);
        $this->site_handler = new WP_Cross_Post_Site_Handler();
        $this->error_handler = new WP_Cross_Post_Error_Handler();
        $this->debug_manager = WP_Cross_Post_Debug_Manager::get_instance();

        if ($this->debug_manager->is_debug_mode()) {
            add_action('init', array($this, 'start_performance_monitoring'));
        }

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

        // 定期実行のフック
        add_action('wp_cross_post_daily_sync', array($this->site_handler, 'sync_all_sites_taxonomies'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
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
                'syncingText' => '同期中...',
                'syncCompleteText' => '同期完了',
                'syncErrorText' => '同期エラー',
                'confirmSync' => 'タクソノミーの同期を開始しますか？',
                'i18n' => array(
                    'syncSuccess' => '同期が完了しました。',
                    'syncError' => '同期中にエラーが発生しました。',
                    'confirmManualSync' => 'タクソノミーの手動同期を開始しますか？\n※同期には時間がかかる場合があります。'
                )
            ));

            wp_enqueue_style(
                'wp-cross-post-admin',
                plugins_url('assets/css/admin.css', __FILE__),
                array('material-icons'),
                WP_CROSS_POST_VERSION
            );
        }
    }

    public function render_settings_page() {
        include WP_CROSS_POST_PLUGIN_DIR . 'admin/settings.php';
    }

    public function render_sites_page() {
        include WP_CROSS_POST_PLUGIN_DIR . 'admin/sites.php';
    }

    public function add_post_meta_box() {
        add_meta_box(
            'wp-cross-post-meta-box',
            'クロス投稿設定',
            array($this, 'render_post_meta_box'),
            'post',
            'side',
            'high'
        );
    }

    public function render_post_meta_box($post) {
        include WP_CROSS_POST_PLUGIN_DIR . 'admin/post-meta-box.php';
    }

    public function save_post_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['wp_cross_post_sites'])) {
            $selected_sites = array_map('sanitize_text_field', $_POST['wp_cross_post_sites']);
            update_post_meta($post_id, '_wp_cross_post_sites', $selected_sites);
        } else {
            delete_post_meta($post_id, '_wp_cross_post_sites');
        }
    }

    /**
     * プラグインの有効化時の処理
     */
    public function activate() {
        $this->site_handler->schedule_taxonomies_sync();
        if (!wp_next_scheduled('wp_cross_post_daily_sync')) {
            wp_schedule_event(strtotime('03:00:00'), 'daily', 'wp_cross_post_daily_sync');
        }
    }

    /**
     * プラグインの無効化時の処理
     */
    public function deactivate() {
        $this->site_handler->unschedule_taxonomies_sync();
    }
}

add_action('wp_cross_post_daily_sync', function() {
    $sync_engine = new WP_Cross_Post_Sync_Engine();
    $sync_engine->sync_taxonomies_to_all_sites();
});

add_filter('cron_schedules', function($schedules) {
    $schedules['every_15min'] = [
        'interval' => 900,
        'display'  => __('Every 15 Minutes')
    ];
    return $schedules;
});

new WP_Cross_Post();
