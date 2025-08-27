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

    $site_handler = new WP_Cross_Post_Site_Handler();
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

    $site_handler = new WP_Cross_Post_Site_Handler();
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

    $site_handler = new WP_Cross_Post_Site_Handler();
    $result = $site_handler->sync_all_sites_taxonomies();

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        update_option('wp_cross_post_last_sync_time', current_time('mysql'));
        wp_send_json_success('カテゴリーとタグの同期が完了しました。');
    }
}
add_action('wp_ajax_wp_cross_post_sync_taxonomies', 'wp_cross_post_sync_taxonomies');