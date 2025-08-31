<?php
/**
 * WP Cross Post - Uninstall Script
 * プラグイン削除時にデータベースをクリーンアップ
 */

// WordPressによる直接のアンインストール処理かチェック
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// データベースマネージャーをロード
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-cross-post-database-manager.php';

// オプション設定によってテーブルを削除するかどうか判断
$delete_data = get_option('wp_cross_post_delete_data_on_uninstall', false);

if ($delete_data) {
    // カスタムテーブルの削除
    WP_Cross_Post_Database_Manager::drop_tables();
    
    // プラグイン関連のオプションも削除
    $options_to_delete = [
        'wp_cross_post_settings',
        'wp_cross_post_sites', // 念のため残っていた場合に削除
        'wp_cross_post_taxonomies', // 念のため残っていた場合に削除
        'wp_cross_post_last_sync_time',
        'wp_cross_post_error_notification_settings',
        'wp_cross_post_debug_logs',
        'wp_cross_post_performance_metrics',
        'wp_cross_post_delete_data_on_uninstall',
        'wp_cross_post_db_version'
    ];
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // パターンマッチで削除（古い形式のオプション）
    global $wpdb;
    
    // 同期履歴オプションを削除（パターンマッチ）
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_cross_post_synced_term_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_cross_post_synced_media_%'");
    
    // トランジェントの削除
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_cross_post_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_cross_post_%'");
    
    error_log('WP Cross Post: All plugin data has been removed during uninstall');
} else {
    error_log('WP Cross Post: Plugin uninstalled but data was preserved');
}
