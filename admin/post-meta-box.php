<?php
wp_nonce_field('wp_cross_post_sync', 'wp_cross_post_nonce');

$sites = get_option('wp_cross_post_sites', array());
$selected_sites = get_post_meta($post->ID, '_wp_cross_post_sites', true);
if (!is_array($selected_sites)) {
    $selected_sites = array();
}

if (empty($sites)): ?>
    <p>同期先のサイトが設定されていません。</p>
<?php else: ?>
    <div class="wp-cross-post-meta-box">
        <div class="sites-list">
            <?php foreach ($sites as $site): ?>
                <label class="site-item">
                    <input type="checkbox" 
                           name="wp_cross_post_sites[]" 
                           value="<?php echo esc_attr($site['id']); ?>"
                           <?php checked(in_array($site['id'], $selected_sites)); ?>>
                    <?php echo esc_html($site['name']); ?>
                    <span class="site-url"><?php echo esc_html($site['url']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="sync-controls">
            <button type="button" 
                    class="button button-primary sync-button" 
                    id="manual-sync-button"
                    data-post-id="<?php echo esc_attr($post->ID); ?>">
                <i class="material-icons">sync</i>
                手動同期
            </button>
            <div class="sync-status" id="sync-status"></div>
        </div>
    </div>

    <style>
    .wp-cross-post-meta-box {
        margin: -6px -12px -12px;
    }
    .sites-list {
        margin-bottom: 12px;
        padding: 0 12px;
    }
    .site-item {
        display: block;
        margin: 8px 0;
    }
    .site-url {
        display: block;
        margin-left: 20px;
        color: #666;
        font-size: 12px;
    }
    .sync-controls {
        padding: 12px;
        background: #f8f9fa;
        border-top: 1px solid #ddd;
    }
    .sync-button {
        display: flex !important;
        align-items: center;
        gap: 8px;
    }
    .sync-button .material-icons {
        font-size: 16px;
    }
    .sync-button.syncing .material-icons {
        animation: spin 1s linear infinite;
    }
    .sync-status {
        margin-top: 8px;
    }
    .status-message {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        border-radius: 4px;
        background: #f0f0f1;
        margin-top: 8px;
    }
    .status-message.success {
        background: #edfaef;
        color: #0a5624;
    }
    .status-message.error {
        background: #fcf0f1;
        color: #cc1818;
    }
    @keyframes spin {
        100% { transform: rotate(360deg); }
    }
    </style>
<?php endif; ?>

