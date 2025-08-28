<?php
function wp_cross_post_add_site() {
    check_ajax_referer('wp_cross_post_add_site', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません。');
    }

    $site_data = array(
        'name' => sanitize_text_field($_POST['site_name']),
        'url' => esc_url_raw($_POST['site_url']),
        'username' => sanitize_text_field($_POST['username']),
        'app_password' => sanitize_text_field($_POST['app_password']),
    );

    global $wp_cross_post;
    $site_handler = $wp_cross_post->site_handler;
    $result = $site_handler->add_site($site_data);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('サイトが正常に追加されました。');
    }
}
add_action('wp_ajax_wp_cross_post_add_site', 'wp_cross_post_add_site');

function wp_cross_post_remove_site() {
    check_ajax_referer('wp_cross_post_remove_site', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません。');
    }

    $site_id = sanitize_text_field($_POST['site_id']);

    global $wp_cross_post;
    $site_handler = $wp_cross_post->site_handler;
    $result = $site_handler->remove_site($site_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('サイトが正常に削除されました。');
    }
}
add_action('wp_ajax_wp_cross_post_remove_site', 'wp_cross_post_remove_site');

function wp_cross_post_sync_taxonomies() {
    check_ajax_referer('wp_cross_post_taxonomy_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません。');
    }

    global $wp_cross_post;
    $site_handler = $wp_cross_post->site_handler;
    $result = $site_handler->sync_all_sites_taxonomies();

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        update_option('wp_cross_post_last_sync_time', current_time('mysql'));
        wp_send_json_success('カテゴリーとタグの同期が完了しました。');
    }
}
add_action('wp_ajax_wp_cross_post_sync_taxonomies', 'wp_cross_post_sync_taxonomies');

/**
 * 単一サイトへの投稿同期処理
 */
function wp_cross_post_sync_single_site() {
    check_ajax_referer('wp_cross_post_sync', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('権限がありません。');
    }

    $post_id = intval($_POST['post_id']);
    $site_id = sanitize_text_field($_POST['site_id']);

    // 同期ハンドラーのインスタンス化
    global $wp_cross_post;
    $api_handler = $wp_cross_post->api_handler;
    $sync_handler = $wp_cross_post->sync_handler;
    
    // 単一サイトへの同期を実行
    $result = $sync_handler->sync_to_single_site($post_id, $site_id);

    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message(),
            'type' => 'error',
            'details' => $result->get_error_data()
        ));
    } else {
        wp_send_json_success(array(
            'message' => 'サイトへの同期が完了しました。',
            'type' => 'success',
            'details' => array(
                'site_id' => $site_id,
                'remote_post_id' => $result
            )
        ));
    }
}
add_action('wp_ajax_wp_cross_post_sync_single_site', 'wp_cross_post_sync_single_site');

/**
 * 設定のエクスポート
 */
function wp_cross_post_export_settings() {
    check_ajax_referer('wp_cross_post_export_settings', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません。');
    }

    $settings = WP_Cross_Post_Config_Manager::export_settings();
    wp_send_json_success($settings);
}
add_action('wp_ajax_wp_cross_post_export_settings', 'wp_cross_post_export_settings');

/**
 * 設定のインポート
 */
function wp_cross_post_import_settings() {
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
add_action('wp_ajax_wp_cross_post_import_settings', 'wp_cross_post_import_settings');