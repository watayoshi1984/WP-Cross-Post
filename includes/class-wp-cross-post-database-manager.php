<?php
/**
 * WP Cross Post Database Manager
 * カスタムテーブルの作成・管理を行うクラス
 */

class WP_Cross_Post_Database_Manager {
    const DB_VERSION = '1.0.1';
    const DB_VERSION_OPTION = 'wp_cross_post_db_version';
    
    /**
     * データベースの初期化
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // サイト情報テーブル
        $sites_table = $wpdb->prefix . 'cross_post_sites';
        $sites_sql = "CREATE TABLE $sites_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_key varchar(50) NOT NULL UNIQUE,
            name varchar(255) NOT NULL,
            url varchar(255) NOT NULL,
            username varchar(100) NOT NULL,
            app_password text NOT NULL,
            status enum('active', 'inactive') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_site_key (site_key),
            KEY idx_status (status),
            KEY idx_name (name)
        ) $charset_collate;";
        
        // サブサイトタクソノミーデータテーブル
        $taxonomy_table = $wpdb->prefix . 'cross_post_site_taxonomies';
        $taxonomy_sql = "CREATE TABLE $taxonomy_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            taxonomy_type enum('category', 'post_tag') NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            term_name varchar(255) NOT NULL,
            term_slug varchar(255) NOT NULL,
            term_description text DEFAULT NULL,
            parent_term_id bigint(20) unsigned DEFAULT NULL,
            term_count int(11) DEFAULT 0,
            term_data longtext DEFAULT NULL COMMENT 'JSON format term metadata',
            last_synced datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_site_term (site_id, taxonomy_type, term_id),
            KEY idx_site_taxonomy (site_id, taxonomy_type),
            KEY idx_term_name (term_name),
            KEY idx_term_slug (term_slug),
            KEY idx_parent_term (parent_term_id),
            KEY idx_last_synced (last_synced)
        ) $charset_collate;";
        
        // メディア同期履歴テーブル
        $media_table = $wpdb->prefix . 'cross_post_media_sync';
        $media_sql = "CREATE TABLE $media_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            local_media_id bigint(20) unsigned NOT NULL,
            local_file_url varchar(500) NOT NULL,
            remote_media_id bigint(20) unsigned DEFAULT NULL,
            remote_file_url varchar(500) DEFAULT NULL,
            file_size bigint(20) unsigned DEFAULT NULL,
            mime_type varchar(100) DEFAULT NULL,
            sync_status enum('pending', 'uploading', 'success', 'failed') DEFAULT 'pending',
            synced_at datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_media_sync (site_id, local_media_id),
            KEY idx_site_id (site_id),
            KEY idx_local_media (local_media_id),
            KEY idx_sync_status (sync_status),
            KEY idx_remote_media (remote_media_id)
        ) $charset_collate;";
        
        // 投稿同期履歴テーブル
        $posts_table = $wpdb->prefix . 'cross_post_sync_history';
        $posts_sql = "CREATE TABLE $posts_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            local_post_id bigint(20) unsigned NOT NULL,
            remote_post_id bigint(20) unsigned DEFAULT NULL,
            sync_status enum('pending', 'syncing', 'success', 'failed') DEFAULT 'pending',
            sync_type enum('create', 'update', 'delete') DEFAULT 'create',
            post_status varchar(20) DEFAULT NULL,
            scheduled_date datetime DEFAULT NULL,
            synced_at datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count int(11) DEFAULT 0,
            sync_data longtext DEFAULT NULL COMMENT 'JSON format sync configuration',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_post_sync (site_id, local_post_id),
            KEY idx_site_id (site_id),
            KEY idx_local_post (local_post_id),
            KEY idx_remote_post (remote_post_id),
            KEY idx_sync_status (sync_status),
            KEY idx_sync_type (sync_type),
            KEY idx_scheduled_date (scheduled_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // まずテーブル構造を作成（外部キー制約なし）
        dbDelta($sites_sql);
        dbDelta($taxonomy_sql);
        dbDelta($media_sql);
        dbDelta($posts_sql);
        
        // 外部キー制約を追加（テーブル作成後）
        self::add_foreign_key_constraints();
        
        // バージョン情報を保存
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        
        // 初期化後のログ（WP_DEBUGが有効な場合のみ）
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Cross Post: Custom tables created successfully');
        }
        
        return true;
    }
    
    /**
     * 外部キー制約の追加
     */
    private static function add_foreign_key_constraints() {
        global $wpdb;
        
        $sites_table = $wpdb->prefix . 'cross_post_sites';
        $taxonomy_table = $wpdb->prefix . 'cross_post_taxonomy_mapping';
        $media_table = $wpdb->prefix . 'cross_post_media_sync';
        $posts_table = $wpdb->prefix . 'cross_post_sync_history';
        
        // 外部キー制約を追加する前に、既存の制約を確認
        $foreign_keys = array(
            array(
                'table' => $taxonomy_table,
                'constraint' => 'fk_taxonomy_site_id',
                'column' => 'site_id',
                'reference' => $sites_table . '(id)'
            ),
            array(
                'table' => $media_table,
                'constraint' => 'fk_media_site_id',
                'column' => 'site_id',
                'reference' => $sites_table . '(id)'
            ),
            array(
                'table' => $posts_table,
                'constraint' => 'fk_posts_site_id',
                'column' => 'site_id',
                'reference' => $sites_table . '(id)'
            )
        );
        
        foreach ($foreign_keys as $fk) {
            // 制約が既に存在するかチェック
            $constraint_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                     WHERE CONSTRAINT_SCHEMA = %s 
                     AND TABLE_NAME = %s 
                     AND CONSTRAINT_NAME = %s 
                     AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                    DB_NAME,
                    $fk['table'],
                    $fk['constraint']
                )
            );
            
            if (!$constraint_exists) {
                $sql = sprintf(
                    "ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s ON DELETE CASCADE",
                    $fk['table'],
                    $fk['constraint'],
                    $fk['column'],
                    $fk['reference']
                );
                
                $result = $wpdb->query($sql);
                if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Cross Post: Failed to add foreign key constraint {$fk['constraint']}: " . $wpdb->last_error);
                }
            }
        }
    }
    
    /**
     * データベースのアップグレード確認
     */
    public static function maybe_upgrade_database() {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
        
        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            self::create_tables();
            
            // 既存データの移行
            self::migrate_existing_data();
        }
    }
    
    /**
     * 既存のwp_optionsデータをカスタムテーブルに移行
     */
    public static function migrate_existing_data() {
        global $wpdb;
        
        // 既存のサイトデータを移行
        $existing_sites = get_option('wp_cross_post_sites', array());
        if (!empty($existing_sites) && is_array($existing_sites)) {
            foreach ($existing_sites as $site) {
                if (!isset($site['id']) || !isset($site['name']) || !isset($site['url'])) {
                    continue;
                }
                
                // サイトキーの生成
                $site_key = self::generate_migration_site_key($site['name'], $site['url']);
                
                // カスタムテーブルに既に存在するかチェック
                $existing = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}cross_post_sites WHERE site_key = %s",
                        $site_key
                    )
                );
                
                if (!$existing) {
                    // 新しいレコードを挿入
                    $insert_data = array(
                        'site_key' => $site_key,
                        'name' => sanitize_text_field($site['name']),
                        'url' => esc_url_raw($site['url']),
                        'username' => sanitize_text_field($site['username'] ?? ''),
                        'app_password' => self::encrypt_migration_password($site['app_password'] ?? ''),
                        'status' => 'active'
                    );
                    
                    $result = $wpdb->insert(
                        $wpdb->prefix . 'cross_post_sites',
                        $insert_data,
                        array('%s', '%s', '%s', '%s', '%s', '%s')
                    );
                    
                    if ($result !== false) {
                        $new_site_id = $wpdb->insert_id;
                        
                        // タクソノミーマッピングデータの移行
                        self::migrate_taxonomy_mappings($site['id'], $new_site_id);
                        
                        error_log("WP Cross Post: Migrated site '{$site['name']}' with new ID: {$new_site_id}");
                    }
                }
            }
            
            error_log('WP Cross Post: Existing data migration completed');
        }
    }
    
    /**
     * タクソノミーマッピングの移行
     */
    private static function migrate_taxonomy_mappings($old_site_id, $new_site_id) {
        global $wpdb;
        
        $taxonomies = get_option('wp_cross_post_taxonomies', array());
        if (isset($taxonomies[$old_site_id])) {
            $site_taxonomies = $taxonomies[$old_site_id];
            
            // カテゴリーの移行
            if (isset($site_taxonomies['categories']) && is_array($site_taxonomies['categories'])) {
                foreach ($site_taxonomies['categories'] as $category) {
                    if (isset($category['id']) && isset($category['name'])) {
                        // ローカルカテゴリーIDの取得
                        $local_term = get_term_by('name', $category['name'], 'category');
                        
                        if ($local_term) {
                            $mapping_data = array(
                                'site_id' => $new_site_id,
                                'local_taxonomy' => 'category',
                                'local_term_id' => $local_term->term_id,
                                'local_term_name' => $category['name'],
                                'local_term_slug' => $category['slug'] ?? $local_term->slug,
                                'remote_term_id' => $category['id'],
                                'remote_term_name' => $category['name'],
                                'remote_term_slug' => $category['slug'] ?? '',
                                'sync_status' => 'synced',
                                'last_synced' => current_time('mysql')
                            );
                            
                            $wpdb->insert(
                                $wpdb->prefix . 'cross_post_taxonomy_mapping',
                                $mapping_data,
                                array('%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
                            );
                        }
                    }
                }
            }
            
            // タグの移行
            if (isset($site_taxonomies['tags']) && is_array($site_taxonomies['tags'])) {
                foreach ($site_taxonomies['tags'] as $tag) {
                    if (isset($tag['id']) && isset($tag['name'])) {
                        // ローカルタグIDの取得
                        $local_term = get_term_by('name', $tag['name'], 'post_tag');
                        
                        if ($local_term) {
                            $mapping_data = array(
                                'site_id' => $new_site_id,
                                'local_taxonomy' => 'post_tag',
                                'local_term_id' => $local_term->term_id,
                                'local_term_name' => $tag['name'],
                                'local_term_slug' => $tag['slug'] ?? $local_term->slug,
                                'remote_term_id' => $tag['id'],
                                'remote_term_name' => $tag['name'],
                                'remote_term_slug' => $tag['slug'] ?? '',
                                'sync_status' => 'synced',
                                'last_synced' => current_time('mysql')
                            );
                            
                            $wpdb->insert(
                                $wpdb->prefix . 'cross_post_taxonomy_mapping',
                                $mapping_data,
                                array('%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
                            );
                        }
                    }
                }
            }
        }
    }
    
    /**
     * マイグレーション用サイトキー生成
     */
    private static function generate_migration_site_key($name, $url) {
        $base_key = sanitize_title($name);
        $hash = substr(md5($url), 0, 8);
        return $base_key . '_' . $hash;
    }
    
    /**
     * マイグレーション用パスワード暗号化
     */
    private static function encrypt_migration_password($password) {
        if (function_exists('wp_salt')) {
            $salt = wp_salt('auth');
            return base64_encode($password . '|' . $salt);
        }
        return base64_encode($password);
    }
    
    /**
     * テーブルの削除（プラグイン削除時）
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'cross_post_sync_history',
            $wpdb->prefix . 'cross_post_media_sync',
            $wpdb->prefix . 'cross_post_taxonomy_mapping',
            $wpdb->prefix . 'cross_post_sites'
        ];
        
        // 外部キー制約があるため逆順で削除
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        delete_option(self::DB_VERSION_OPTION);
        
        error_log('WP Cross Post: Custom tables dropped successfully');
    }
    
    /**
     * テーブルの存在確認
     */
    public static function tables_exist() {
        global $wpdb;
        
        $sites_table = $wpdb->prefix . 'cross_post_sites';
        $result = $wpdb->get_var("SHOW TABLES LIKE '$sites_table'");
        
        return $result === $sites_table;
    }
    
    /**
     * データベース統計情報の取得
     */
    public static function get_database_stats() {
        global $wpdb;
        
        $stats = [];
        
        // 各テーブルのレコード数を取得
        $tables = [
            'sites' => $wpdb->prefix . 'cross_post_sites',
            'taxonomy_mapping' => $wpdb->prefix . 'cross_post_taxonomy_mapping', 
            'media_sync' => $wpdb->prefix . 'cross_post_media_sync',
            'sync_history' => $wpdb->prefix . 'cross_post_sync_history'
        ];
        
        foreach ($tables as $key => $table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $stats[$key] = intval($count);
        }
        
        return $stats;
    }
    
    /**
     * データベース接続のテスト
     */
    public static function test_database_connection() {
        global $wpdb;
        
        try {
            $result = $wpdb->get_var("SELECT 1");
            return $result === '1';
        } catch (Exception $e) {
            error_log('WP Cross Post Database connection test failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * デバッグ情報の取得
     */
    public static function get_debug_info() {
        global $wpdb;
        
        $debug_info = array();
        
        // バージョン情報
        $debug_info['database_version'] = get_option(self::DB_VERSION_OPTION, 'not_set');
        $debug_info['current_version'] = self::DB_VERSION;
        
        // テーブル存在確認
        $tables = array(
            'sites' => $wpdb->prefix . 'cross_post_sites',
            'taxonomy_mapping' => $wpdb->prefix . 'cross_post_taxonomy_mapping',
            'media_sync' => $wpdb->prefix . 'cross_post_media_sync',
            'sync_history' => $wpdb->prefix . 'cross_post_sync_history'
        );
        
        foreach ($tables as $key => $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            $debug_info['tables'][$key] = array(
                'name' => $table,
                'exists' => $exists,
                'count' => $exists ? intval($wpdb->get_var("SELECT COUNT(*) FROM $table")) : 0
            );
        }
        
        // 旧データの確認
        $old_sites = get_option('wp_cross_post_sites', array());
        $old_taxonomies = get_option('wp_cross_post_taxonomies', array());
        
        $debug_info['legacy_data'] = array(
            'sites_count' => is_array($old_sites) ? count($old_sites) : 0,
            'taxonomies_count' => is_array($old_taxonomies) ? count($old_taxonomies) : 0
        );
        
        // 最新のサイトデータを取得
        if ($debug_info['tables']['sites']['exists']) {
            $latest_sites = $wpdb->get_results(
                "SELECT id, site_key, name, url, status, created_at FROM {$tables['sites']} ORDER BY created_at DESC LIMIT 10",
                ARRAY_A
            );
            $debug_info['latest_sites'] = $latest_sites ?: array();
        }
        
        return $debug_info;
    }
    
    /**
     * デバッグ情報をログに出力
     */
    public static function log_debug_info() {
        $debug_info = self::get_debug_info();
        
        error_log('=== WP Cross Post Debug Info ===');
        error_log('Database Version: ' . $debug_info['database_version']);
        error_log('Current Version: ' . $debug_info['current_version']);
        
        foreach ($debug_info['tables'] as $key => $table) {
            error_log("Table {$key}: " . ($table['exists'] ? 'EXISTS' : 'NOT EXISTS') . " (Count: {$table['count']})");
        }
        
        error_log('Legacy Sites: ' . $debug_info['legacy_data']['sites_count']);
        error_log('Legacy Taxonomies: ' . $debug_info['legacy_data']['taxonomies_count']);
        
        if (!empty($debug_info['latest_sites'])) {
            error_log('Latest Sites:');
            foreach ($debug_info['latest_sites'] as $site) {
                error_log("  - ID: {$site['id']}, Key: {$site['site_key']}, Name: {$site['name']}");
            }
        }
        
        error_log('=== End Debug Info ===');
    }
}
