<?php
wp_nonce_field('wp_cross_post_sync', 'wp_cross_post_nonce');

// $sites変数はrender_post_meta_box()メソッドから渡される
$selected_sites = get_post_meta($post->ID, '_wp_cross_post_sites', true);
if (!is_array($selected_sites)) {
    $selected_sites = array();
}

// 既存のサイト別設定（カテゴリー/タグ/ステータス/日時）を読み込み
$site_settings = get_post_meta($post->ID, '_wp_cross_post_site_settings', true);
if (!is_array($site_settings)) {
    $site_settings = array();
}

if (empty($sites)): ?>
    <p>同期先のサイトが設定されていません。</p>
<?php else: ?>
    <div class="wp-cross-post-meta-box">
        <div class="sites-list">
            <?php foreach ($sites as $site): ?>
                <?php $sid = $site['id']; $conf = isset($site_settings[$sid]) && is_array($site_settings[$sid]) ? $site_settings[$sid] : array(); ?>
                <div class="site-item">
                    <label>
                        <input type="checkbox" 
                               name="wp_cross_post_sites[]" 
                               value="<?php echo esc_attr($sid); ?>"
                               <?php checked(in_array($sid, $selected_sites)); ?>>
                        <?php echo esc_html($site['name']); ?>
                        <span class="site-url"><?php echo esc_html($site['url']); ?></span>
                    </label>

                    <div class="site-controls" data-site-id="<?php echo esc_attr($sid); ?>">
                        <label>
                            投稿状態
                            <select name="wp_cross_post_setting[<?php echo esc_attr($sid); ?>][status]" class="wp-cross-post-status">
                                <option value="">未設定</option>
                                <option value="publish" <?php selected(isset($conf['status']) && $conf['status']==='publish'); ?>>公開</option>
                                <option value="draft" <?php selected(isset($conf['status']) && $conf['status']==='draft'); ?>>下書き</option>
                                <option value="future" <?php selected(isset($conf['status']) && $conf['status']==='future'); ?>>予約投稿</option>
                            </select>
                        </label>

                        <label class="scheduled-time" style="display: <?php echo (isset($conf['status']) && $conf['status']==='future') ? 'block' : 'none'; ?>;">
                            投稿日時
                            <input type="datetime-local" name="wp_cross_post_setting[<?php echo esc_attr($sid); ?>][date]" value="<?php echo isset($conf['date']) ? esc_attr($conf['date']) : ''; ?>">
                        </label>

                        <label>
                            カテゴリー
                            <select name="wp_cross_post_setting[<?php echo esc_attr($sid); ?>][category]" 
                                    class="wp-cross-post-category"
                                    data-selected="<?php echo isset($conf['category']) ? esc_attr($conf['category']) : ''; ?>">
                                <option value="">未設定</option>
                                <!-- 動的にAJAXで追加 -->
                            </select>
                        </label>

                        <label>
                            タグ
                            <select name="wp_cross_post_setting[<?php echo esc_attr($sid); ?>][tags][]" 
                                    class="wp-cross-post-tags" 
                                    multiple
                                    data-selected="<?php echo isset($conf['tags']) && is_array($conf['tags']) ? esc_attr(json_encode($conf['tags'])) : '[]'; ?>">
                                <!-- 動的にAJAXで追加 -->
                            </select>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sync-options">
            <label class="parallel-sync-option">
                <input type="checkbox" 
                       id="parallel-sync"
                       name="parallel_sync" 
                       value="1">
                並列処理で同期する
            </label>
            <label class="async-sync-option">
                <input type="checkbox" 
                       id="async-sync"
                       name="async_sync" 
                       value="1">
                非同期処理で同期する
            </label>
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
    .site-item > label { display:flex; flex-direction:column; }
    .site-controls { margin:8px 0 12px 20px; display:flex; flex-direction:column; gap:8px; }
    .site-url {
        display: block;
        margin-left: 20px;
        color: #666;
        font-size: 12px;
    }
    .sync-options {
        padding: 0 12px 12px;
        border-bottom: 1px solid #ddd;
    }
    .parallel-sync-option, .async-sync-option {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
    }
    .async-sync-option {
        margin-top: 8px;
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
    .status-message.info {
        background: #f0f6fc;
        color: #0a4b78;
    }
    @keyframes spin {
        100% { transform: rotate(360deg); }
    }
    </style>
<?php endif; ?>

