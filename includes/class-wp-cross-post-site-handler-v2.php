<?php
/**
 * WP Cross Post サイトハンドラー V2 (独自テーブル対応版)
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-handler.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-site-handler.php';

/**
 * WP Cross Post サイトハンドラークラス V2
 *
 * 独自テーブルを使用したサイト管理処理を管理します。
 */
class WP_Cross_Post_Site_Handler_V2 implements WP_Cross_Post_Site_Handler_Interface {

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
    
    // テーブル名
    private static $table_sites;
    private static $table_taxonomy_mapping;
    private static $table_media_sync;
    private static $table_sync_history;

    public function __construct($debug_manager, $auth_manager, $error_manager, $api_handler, $rate_limit_manager) {
        global $wpdb;
        
        $this->debug_manager = $debug_manager;
        $this->auth_manager = $auth_manager;
        $this->error_manager = $error_manager;
        $this->api_handler = $api_handler;
        $this->rate_limit_manager = $rate_limit_manager;
        
        // テーブル名を設定
        self::$table_sites = $wpdb->prefix . 'cross_post_sites';
        self::$table_taxonomy_mapping = $wpdb->prefix . 'cross_post_taxonomy_mapping';
        self::$table_media_sync = $wpdb->prefix . 'cross_post_media_sync';
        self::$table_sync_history = $wpdb->prefix . 'cross_post_sync_history';
    }

    /**
     * サイトの追加
     */
    public function add_site($site_data) {
        global $wpdb;
        
        $this->debug_manager->log('サイト追加を開始', 'info', array(
            'site_name' => isset($site_data['name']) ? $site_data['name'] : 'unknown',
            'site_url' => isset($site_data['url']) ? $site_data['url'] : 'unknown'
        ));
        
        // データのバリデーション
        if (!$this->validate_site_data($site_data)) {
            $error = new WP_Error('invalid_site_data', 'サイトデータが不正です。');
            $this->error_manager->handle_error($error, 'add_site');
            return $error;
        }
        
        // URLの正規化
        $site_data['url'] = $this->api_handler->normalize_site_url($site_data['url']);

        // 接続テスト
        $test_result = $this->api_handler->test_connection($site_data);
        
        if (is_wp_error($test_result)) {
            $this->debug_manager->log('接続テストが失敗しました', 'error', array(
                'error' => $test_result->get_error_message()
            ));
            $this->error_manager->handle_error($test_result, 'add_site');
            return $test_result;
        }
        
        // サイトキーの生成（ユニークキー）
        $site_key = $this->generate_site_key($site_data['name'], $site_data['url']);
        
        // データベースに挿入
        $insert_data = array(
            'site_key' => $site_key,
            'name' => sanitize_text_field($site_data['name']),
            'url' => esc_url_raw($site_data['url']),
            'username' => sanitize_text_field($site_data['username']),
            'app_password' => $this->encrypt_password($site_data['app_password']),
            'status' => 'active'
        );
        
        $result = $wpdb->insert(
            self::$table_sites,
            $insert_data,
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', 'サイトの追加に失敗しました: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'add_site');
            return $error;
        }
        
        $site_id = $wpdb->insert_id;
        
        // キャッシュをクリア
        $this->clear_sites_cache();
        
        // タクソノミーを同期
        $this->sync_site_taxonomies($site_id);
        
        $this->debug_manager->log('サイトが正常に追加されました', 'info', array(
            'site_id' => $site_id,
            'site_key' => $site_key
        ));
        
        return $site_id;
    }

    /**
     * サイトの削除
     */
    public function remove_site($site_id) {
        global $wpdb;
        
        $this->debug_manager->log('サイト削除を開始', 'info', array('site_id' => $site_id));
        
        // 外部キー制約により関連データも自動削除される
        $result = $wpdb->delete(
            self::$table_sites,
            array('id' => $site_id),
            array('%d')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', 'サイトの削除に失敗しました: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'remove_site');
            return $error;
        }
        
        if ($result === 0) {
            $error = new WP_Error('site_not_found', '指定されたサイトが見つかりませんでした。');
            $this->error_manager->handle_error($error, 'remove_site');
            return $error;
        }
        
        // キャッシュをクリア
        $this->clear_sites_cache();
        $this->clear_site_cache($site_id);
        $this->clear_taxonomies_cache($site_id);
        
        $this->debug_manager->log('サイトが正常に削除されました', 'info', array('site_id' => $site_id));
        
        return true;
    }

    /**
     * サイト一覧の取得
     */
    public function get_sites() {
        global $wpdb;
        
        // キャッシュから取得を試行
        $cache_key = self::SITE_CACHE_PREFIX . 'all_sites';
        $sites = wp_cache_get($cache_key);
        
        if ($sites !== false) {
            $this->debug_manager->log('サイトデータをキャッシュから取得', 'info');
            return $sites;
        }
        
        // データベースから取得
        $sites = $wpdb->get_results(
            "SELECT id, site_key, name, url, username, status, created_at, updated_at 
             FROM " . self::$table_sites . " 
             WHERE status = 'active' 
             ORDER BY name ASC",
            ARRAY_A
        );
        
        if ($sites === null) {
            $error = new WP_Error('database_error', 'サイト取得に失敗しました: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'get_sites');
            return array();
        }
        
        // app_passwordはセキュリティ上除外（必要な時は別メソッドで取得）
        foreach ($sites as &$site) {
            unset($site['app_password']);
        }
        
        // キャッシュに保存
        wp_cache_set($cache_key, $sites, '', self::SITE_CACHE_EXPIRY);
        
        $this->debug_manager->log('サイトデータをデータベースから取得してキャッシュに保存', 'info', array(
            'sites_count' => count($sites)
        ));
        
        return $sites;
    }

    /**
     * 特定のサイトデータを取得
     */
    public function get_site_data($site_id, $include_password = false) {
        global $wpdb;
        
        $cache_key = self::SITE_CACHE_PREFIX . $site_id;
        $site_data = wp_cache_get($cache_key);
        
        if ($site_data !== false && !$include_password) {
            return $site_data;
        }
        
        $fields = 'id, site_key, name, url, username, status, created_at, updated_at';
        if ($include_password) {
            $fields .= ', app_password';
        }
        
        $site_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$fields} FROM " . self::$table_sites . " WHERE id = %d",
                $site_id
            ),
            ARRAY_A
        );
        
        if (!$site_data) {
            return null;
        }
        
        // パスワードの復号化
        if ($include_password && isset($site_data['app_password'])) {
            $site_data['app_password'] = $this->decrypt_password($site_data['app_password']);
        }
        
        // パスワードなしのデータはキャッシュ
        if (!$include_password) {
            wp_cache_set($cache_key, $site_data, '', self::SITE_CACHE_EXPIRY);
        }
        
        return $site_data;
    }

    /**
     * サイトキーの生成
     */
    private function generate_site_key($name, $url) {
        $base_key = sanitize_title($name);
        $hash = substr(md5($url . time()), 0, 8);
        return $base_key . '_' . $hash;
    }

    /**
     * パスワードの暗号化
     */
    private function encrypt_password($password) {
        // WordPressの認証saltを使用した簡易暗号化
        if (function_exists('wp_salt')) {
            $salt = wp_salt('auth');
            return base64_encode($password . '|' . $salt);
        }
        return base64_encode($password);
    }

    /**
     * パスワードの復号化
     */
    private function decrypt_password($encrypted_password) {
        $decoded = base64_decode($encrypted_password);
        if (strpos($decoded, '|') !== false) {
            list($password, $salt) = explode('|', $decoded, 2);
            return $password;
        }
        return $decoded;
    }

    /**
     * サイトデータのバリデーション
     */
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
     * タクソノミーマッピングの取得
     */
    public function get_taxonomy_mapping($site_id, $taxonomy = null) {
        global $wpdb;
        
        $cache_key = self::TAXONOMY_CACHE_PREFIX . $site_id . '_' . ($taxonomy ? $taxonomy : 'all');
        $mapping = wp_cache_get($cache_key);
        
        if ($mapping !== false) {
            return $mapping;
        }
        
        $where_clause = "site_id = %d";
        $params = array($site_id);
        
        if ($taxonomy) {
            $where_clause .= " AND local_taxonomy = %s";
            $params[] = $taxonomy;
        }
        
        $mapping = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_taxonomy_mapping . " WHERE {$where_clause} ORDER BY local_term_name ASC",
                $params
            ),
            ARRAY_A
        );
        
        if ($mapping === null) {
            $mapping = array();
        }
        
        wp_cache_set($cache_key, $mapping, '', self::TAXONOMY_CACHE_EXPIRY);
        
        return $mapping;
    }

    /**
     * タクソノミーマッピングの保存
     */
    public function save_taxonomy_mapping($site_id, $taxonomy, $local_term_id, $local_term_data, $remote_term_data = null) {
        global $wpdb;
        
        $mapping_data = array(
            'site_id' => $site_id,
            'local_taxonomy' => $taxonomy,
            'local_term_id' => $local_term_id,
            'local_term_name' => $local_term_data['name'],
            'local_term_slug' => $local_term_data['slug'],
            'sync_status' => $remote_term_data ? 'synced' : 'pending'
        );
        
        if ($remote_term_data) {
            $mapping_data['remote_term_id'] = $remote_term_data['id'];
            $mapping_data['remote_term_name'] = $remote_term_data['name'];
            $mapping_data['remote_term_slug'] = $remote_term_data['slug'];
            $mapping_data['last_synced'] = current_time('mysql');
        }
        
        // 既存レコードの確認
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM " . self::$table_taxonomy_mapping . " WHERE site_id = %d AND local_taxonomy = %s AND local_term_id = %d",
                $site_id, $taxonomy, $local_term_id
            )
        );
        
        if ($existing) {
            // 更新
            $result = $wpdb->update(
                self::$table_taxonomy_mapping,
                $mapping_data,
                array('id' => $existing->id),
                array('%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            // 挿入
            $result = $wpdb->insert(
                self::$table_taxonomy_mapping,
                $mapping_data,
                array('%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
        }
        
        if ($result !== false) {
            // キャッシュをクリア
            $this->clear_taxonomies_cache($site_id);
        }
        
        return $result !== false;
    }

    /**
     * 全サイトのタクソノミーを同期
     */
    public function sync_all_sites_taxonomies() {
        $sites = $this->get_sites();
        $results = array();
        
        foreach ($sites as $site) {
            $results[$site['id']] = $this->sync_site_taxonomies($site['id']);
        }
        
        return $results;
    }

    /**
     * 特定サイトのタクソノミーを同期
     */
    public function sync_site_taxonomies($site_id) {
        $site_data = $this->get_site_data($site_id, true);
        
        if (!$site_data) {
            return new WP_Error('site_not_found', 'サイトが見つかりません');
        }
        
        $taxonomies = array('category', 'post_tag');
        $results = array();
        
        foreach ($taxonomies as $taxonomy) {
            $results[$taxonomy] = $this->sync_taxonomy_for_site($site_id, $taxonomy);
        }
        
        return $results;
    }

    /**
     * 特定のタクソノミーを同期
     */
    private function sync_taxonomy_for_site($site_id, $taxonomy) {
        // ローカルのタームを取得
        $local_terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ));
        
        if (is_wp_error($local_terms)) {
            return $local_terms;
        }
        
        $synced_count = 0;
        
        foreach ($local_terms as $term) {
            $local_term_data = array(
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description
            );
            
            // マッピングを保存（リモート同期は別途実装）
            if ($this->save_taxonomy_mapping($site_id, $taxonomy, $term->term_id, $local_term_data)) {
                $synced_count++;
            }
        }
        
        return array('synced' => $synced_count, 'total' => count($local_terms));
    }

    /**
     * キャッシュクリア関連メソッド
     */
    public function clear_sites_cache() {
        wp_cache_delete(self::SITE_CACHE_PREFIX . 'all_sites');
    }

    public function clear_site_cache($site_id) {
        wp_cache_delete(self::SITE_CACHE_PREFIX . $site_id);
    }

    public function clear_taxonomies_cache($site_id) {
        // 複数のキャッシュキーをクリア
        wp_cache_delete(self::TAXONOMY_CACHE_PREFIX . $site_id . '_all');
        wp_cache_delete(self::TAXONOMY_CACHE_PREFIX . $site_id . '_category');
        wp_cache_delete(self::TAXONOMY_CACHE_PREFIX . $site_id . '_post_tag');
    }

    public function clear_all_cache() {
        $this->clear_sites_cache();
        
        $sites = $this->get_sites();
        foreach ($sites as $site) {
            $this->clear_site_cache($site['id']);
            $this->clear_taxonomies_cache($site['id']);
        }
    }

    /**
     * 定期実行用のフック設定
     */
    public function schedule_taxonomies_sync() {
        if (!wp_next_scheduled('wp_cross_post_daily_sync')) {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', 'wp_cross_post_daily_sync');
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
        check_ajax_referer('wp_cross_post_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
        
        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
        
        if ($site_id > 0) {
            $result = $this->sync_site_taxonomies($site_id);
        } else {
            $result = $this->sync_all_sites_taxonomies();
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX サイト追加
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
            'name' => sanitize_text_field($_POST['name']),
            'url' => esc_url_raw($_POST['url']),
            'username' => sanitize_text_field($_POST['username']),
            'app_password' => sanitize_text_field($_POST['app_password'])
        );

        $result = $this->add_site($site_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error'
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'サイトが正常に追加されました。',
                'type' => 'success',
                'site_id' => $result
            ));
        }
    }

    /**
     * AJAX サイト削除
     */
    public function ajax_remove_site() {
        check_ajax_referer('wp_cross_post_remove_site', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $site_id = intval($_POST['site_id']);

        if (empty($site_id)) {
            wp_send_json_error(array(
                'message' => 'サイトIDが不正です。',
                'type' => 'error'
            ));
        }

        $result = $this->remove_site($site_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error'
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'サイトが正常に削除されました。',
                'type' => 'success'
            ));
        }
    }

    /**
     * AJAX タクソノミー取得（投稿画面UI用）
     */
    public function ajax_get_site_taxonomies() {
        check_ajax_referer('wp_cross_post_taxonomy_fetch', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;

        if (empty($site_id)) {
            wp_send_json_error(array(
                'message' => 'サイトIDが不正です。',
                'type' => 'error'
            ));
        }

        $taxonomies = array();
        
        // カテゴリーのマッピングを取得
        $categories = $this->get_taxonomy_mapping($site_id, 'category');
        $taxonomies['categories'] = array();
        foreach ($categories as $category) {
            $taxonomies['categories'][] = array(
                'id' => $category['remote_term_id'] ?: $category['local_term_id'],
                'name' => $category['remote_term_name'] ?: $category['local_term_name'],
                'local_id' => $category['local_term_id'],
                'remote_id' => $category['remote_term_id'],
                'sync_status' => $category['sync_status']
            );
        }

        // タグのマッピングを取得
        $tags = $this->get_taxonomy_mapping($site_id, 'post_tag');
        $taxonomies['tags'] = array();
        foreach ($tags as $tag) {
            $taxonomies['tags'][] = array(
                'id' => $tag['remote_term_id'] ?: $tag['local_term_id'],
                'name' => $tag['remote_term_name'] ?: $tag['local_term_name'],
                'local_id' => $tag['local_term_id'],
                'remote_id' => $tag['remote_term_id'],
                'sync_status' => $tag['sync_status']
            );
        }

        wp_send_json_success($taxonomies);
    }
}
