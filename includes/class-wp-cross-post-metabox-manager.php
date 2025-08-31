<?php
/**
 * WP Cross Post Meta Box Manager
 * 
 * 投稿編集画面のメタボックス機能を管理
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Cross_Post_Metabox_Manager {
    
    /**
     * デバッグマネージャー
     */
    private $debug_manager;

    /**
     * サイトハンドラー
     */
    private $site_handler;

    /**
     * コンストラクタ
     */
    public function __construct($debug_manager = null, $site_handler = null) {
        $this->debug_manager = $debug_manager;
        $this->site_handler = $site_handler;
        
        // WordPress フック
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_post_meta'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * 依存関係を設定
     */
    public function set_dependencies($debug_manager, $site_handler) {
        $this->debug_manager = $debug_manager;
        $this->site_handler = $site_handler;
    }

    /**
     * メタボックスを追加
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wp-cross-post-settings',
            'クロス投稿設定',
            array($this, 'render_meta_box'),
            'post',
            'normal',
            'high'
        );
        
        if ($this->debug_manager) {
            $this->debug_manager->log('クロス投稿メタボックスを登録しました', 'debug');
        }
    }

    /**
     * メタボックスを描画
     */
    public function render_meta_box($post) {
        // セキュリティ用のnonce
        wp_nonce_field('wp_cross_post_meta_box', 'wp_cross_post_meta_box_nonce');
        
        // 現在の設定を取得
        $selected_sites = get_post_meta($post->ID, '_wp_cross_post_selected_sites', true);
        $site_settings = get_post_meta($post->ID, '_wp_cross_post_site_settings', true);
        
        if (!is_array($selected_sites)) {
            $selected_sites = array();
        }
        if (!is_array($site_settings)) {
            $site_settings = array();
        }
        
        // サイト一覧を取得
        $sites = array();
        if ($this->site_handler) {
            $sites = $this->site_handler->get_sites();
        }
        
        // テンプレートファイルをインクルード
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'admin/post-meta-box.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<p>メタボックステンプレートが見つかりません。</p>';
        }
    }

    /**
     * 投稿保存時の処理
     */
    public function save_post_meta($post_id, $post) {
        // 自動保存時は処理しない
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // nonce確認
        if (!isset($_POST['wp_cross_post_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['wp_cross_post_meta_box_nonce'], 'wp_cross_post_meta_box')) {
            return;
        }

        // 権限確認
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // リビジョンの場合は処理しない
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // 投稿タイプ確認
        if ($post->post_type !== 'post') {
            return;
        }

        // 選択されたサイトを保存
        $selected_sites = isset($_POST['wp_cross_post_sites']) ? 
                         array_map('sanitize_text_field', $_POST['wp_cross_post_sites']) : 
                         array();
        update_post_meta($post_id, '_wp_cross_post_selected_sites', $selected_sites);

        // サイト別設定を保存
        $site_settings = array();
        if (isset($_POST['wp_cross_post_setting']) && is_array($_POST['wp_cross_post_setting'])) {
            foreach ($_POST['wp_cross_post_setting'] as $site_id => $settings) {
                $site_settings[sanitize_text_field($site_id)] = array(
                    'status' => isset($settings['status']) ? sanitize_text_field($settings['status']) : '',
                    'date' => isset($settings['date']) ? sanitize_text_field($settings['date']) : '',
                    'category' => isset($settings['category']) ? intval($settings['category']) : '',
                    'tags' => isset($settings['tags']) && is_array($settings['tags']) ? 
                             array_map('intval', $settings['tags']) : array()
                );
            }
        }
        update_post_meta($post_id, '_wp_cross_post_site_settings', $site_settings);

        if ($this->debug_manager) {
            $this->debug_manager->log('クロス投稿メタボックス設定を保存しました', 'info', array(
                'post_id' => $post_id,
                'selected_sites' => $selected_sites,
                'site_settings' => $site_settings
            ));
        }
    }

    /**
     * 管理画面用スクリプトとスタイルを読み込み
     */
    public function enqueue_scripts($hook) {
        // 投稿編集画面のみ
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        // 投稿タイプ確認
        global $post;
        if (!$post || $post->post_type !== 'post') {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));
        
        // CSS
        $css_path = plugin_dir_path(dirname(__FILE__)) . 'admin/css/admin.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'wp-cross-post-admin',
                $plugin_url . 'admin/css/admin.css',
                array(),
                filemtime($css_path)
            );
        }

        // JavaScript
        $js_path = plugin_dir_path(dirname(__FILE__)) . 'admin/js/post.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'wp-cross-post-metabox',
                $plugin_url . 'admin/js/post.js',
                array('jquery'),
                filemtime($js_path),
                true
            );

            // AJAX用の設定を渡す
            wp_localize_script('wp-cross-post-metabox', 'wpCrossPost', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_cross_post_taxonomy_fetch'),
                'strings' => array(
                    'loading' => 'データを読み込み中...',
                    'error' => 'エラーが発生しました',
                    'noData' => 'データが見つかりません'
                )
            ));
        }

        if ($this->debug_manager) {
            $this->debug_manager->log('クロス投稿メタボックス用スクリプトを読み込みました', 'debug', array(
                'hook' => $hook
            ));
        }
    }

    /**
     * 投稿のサイト別設定を取得
     */
    public function get_post_site_settings($post_id) {
        $selected_sites = get_post_meta($post_id, '_wp_cross_post_selected_sites', true);
        $site_settings = get_post_meta($post_id, '_wp_cross_post_site_settings', true);
        
        return array(
            'selected_sites' => is_array($selected_sites) ? $selected_sites : array(),
            'site_settings' => is_array($site_settings) ? $site_settings : array()
        );
    }

    /**
     * 特定サイトの設定を取得
     */
    public function get_site_specific_settings($post_id, $site_id) {
        $all_settings = $this->get_post_site_settings($post_id);
        $site_settings = $all_settings['site_settings'];
        
        if (isset($site_settings[$site_id])) {
            return $site_settings[$site_id];
        }
        
        return array(
            'status' => '',
            'date' => '',
            'category' => '',
            'tags' => array()
        );
    }
}
