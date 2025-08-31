<?php
/**
 * WP Cross Post Database Manager
 * カスタムテーブルの作成・管理を行うクラス
 */

class WP_Cross_Post_Database_Manager {
    const DB_VERSION = '1.0.0';
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
        
        // タクソノミーマッピングテーブル
        $taxonomy_table = $wpdb->prefix . 'cross_post_taxonomy_mapping';
        $taxonomy_sql = "CREATE TABLE $taxonomy_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            local_taxonomy varchar(50) NOT NULL,
            local_term_id bigint(20) unsigned NOT NULL,
            local_term_name varchar(255) NOT NULL,
            local_term_slug varchar(255) NOT NULL,
            remote_term_id bigint(20) unsigned DEFAULT NULL,
            remote_term_name varchar(255) DEFAULT NULL,
            remote_term_slug varchar(255) DEFAULT NULL,
            sync_status enum('pending', 'synced', 'failed') DEFAULT 'pending',
            last_synced datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_mapping (site_id, local_taxonomy, local_term_id),
            KEY idx_site_taxonomy (site_id, local_taxonomy),
            KEY idx_local_term (local_term_id),
            KEY idx_remote_term (remote_term_id),
            KEY idx_sync_status (sync_status),
            KEY idx_local_slug (local_term_slug),
            CONSTRAINT fk_taxonomy_site_id FOREIGN KEY (site_id) REFERENCES $sites_table(id) ON DELETE CASCADE
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
            KEY idx_remote_media (remote_media_id),
            CONSTRAINT fk_media_site_id FOREIGN KEY (site_id) REFERENCES $sites_table(id) ON DELETE CASCADE
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
            KEY idx_scheduled_date (scheduled_date),
            CONSTRAINT fk_posts_site_id FOREIGN KEY (site_id) REFERENCES $sites_table(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sites_sql);
        dbDelta($taxonomy_sql);
        dbDelta($media_sql);
        dbDelta($posts_sql);
        
        // バージョン情報を保存
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        
        // 初期化後のログ
        error_log('WP Cross Post: Custom tables created successfully');
        
        return true;
    }
    
    /**
     * データベースのアップグレード確認
     */
    public static function maybe_upgrade_database() {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
        
        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            self::create_tables();
        }
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
}
