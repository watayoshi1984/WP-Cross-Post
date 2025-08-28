<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wp-cross-post-wrapper">
    <div class="wp-cross-post-header">
        <h1><i class="material-icons">settings</i> サイト管理</h1>
        <div class="wp-cross-post-actions">
            <button type="button" class="button button-primary sync-button" id="sync-taxonomies">
                <i class="material-icons">sync</i>
                カテゴリー・タグを同期
            </button>
            <div class="sync-status" id="sync-status"></div>
        </div>
    </div>

    <div class="wp-cross-post-content">
        <div class="wp-cross-post-card">
            <div class="card-header">
                <h2><i class="material-icons">add_circle</i> サイトの追加</h2>
            </div>
            <div class="card-content">
                <form id="wp-cross-post-add-site-form" method="post" action="">
                    <?php wp_nonce_field('wp_cross_post_add_site', 'wp_cross_post_add_site_nonce'); ?>
                    <div class="form-group">
                        <label for="site_name">サイト名</label>
                        <input type="text" id="site_name" name="site_name" class="regular-text" required>
                    </div>
                    <div class="form-group">
                        <label for="site_url">サイトURL</label>
                        <input type="url" id="site_url" name="site_url" class="regular-text" required>
                        <p class="description">例: https://example.com</p>
                    </div>
                    <div class="form-group">
                        <label for="username">ユーザー名</label>
                        <input type="text" id="username" name="username" class="regular-text" required>
                    </div>
                    <div class="form-group">
                        <label for="app_password">アプリケーションパスワード</label>
                        <input type="password" id="app_password" name="app_password" class="regular-text" required>
                        <p class="description">WordPressの「アプリケーションパスワード」機能で発行したパスワードを入力してください。</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button button-primary">
                            <i class="material-icons">add</i>
                            サイトを追加
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="wp-cross-post-card">
            <div class="card-header">
                <h2><i class="material-icons">list</i> 登録済みサイト</h2>
            </div>
            <div class="card-content">
                <?php
                // WP_Cross_Post_Site_Handler クラスのインスタンスを取得
                global $wp_cross_post;
                if ($wp_cross_post && isset($wp_cross_post->site_handler)) {
                    $site_handler = $wp_cross_post->site_handler;
                    $sites = $site_handler->get_sites();
                } else {
                    // $wp_cross_post が null または site_handler が存在しない場合のエラーハンドリング
                    $sites = array();
                    echo '<div class="notice notice-error"><p>WP Cross Post プラグインが正しく初期化されていません。</p></div>';
                }
                if (!empty($sites)) :
                ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>サイト名</th>
                                <th>URL</th>
                                <th>最終同期</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sites as $site) : ?>
                            <tr>
                                <td><?php echo esc_html($site['name']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($site['url']); ?>" target="_blank">
                                        <?php echo esc_url($site['url']); ?>
                                        <i class="material-icons">open_in_new</i>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $last_sync = get_option('wp_cross_post_last_sync_' . $site['id'], '未同期');
                                    echo esc_html($last_sync);
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-link-delete remove-site" data-site-id="<?php echo esc_attr($site['id']); ?>">
                                        <i class="material-icons">delete</i>
                                        削除
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>登録済みのサイトはありません。</p>
                <?php endif; ?>
                <?php wp_nonce_field('wp_cross_post_remove_site', 'wp_cross_post_remove_site_nonce'); ?>
            </div>
        </div>

        <div class="wp-cross-post-card">
            <div class="card-header">
                <h2><i class="material-icons">schedule</i> 自動同期設定</h2>
            </div>
            <div class="card-content">
                <p>カテゴリーとタグの自動同期は毎日午前3時に実行されます。</p>
                <p>最終同期日時: <?php echo get_option('wp_cross_post_last_sync_time', '未実行'); ?></p>
            </div>
        </div>

        <div class="wp-cross-post-card">
            <div class="card-header">
                <h2><i class="material-icons">history</i> 同期履歴</h2>
            </div>
            <div class="card-content">
                <div class="sync-history">
                    <div class="history-content"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.wp-cross-post-wrapper {
    max-width: 1200px;
    margin: 20px auto;
}

.wp-cross-post-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding: 20px;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.wp-cross-post-header h1 {
    display: flex;
    align-items: center;
    margin: 0;
    font-size: 24px;
}

.wp-cross-post-header h1 .material-icons {
    margin-right: 10px;
    color: #2271b1;
}

.wp-cross-post-actions {
    display: flex;
    align-items: center;
    gap: 20px;
}

.wp-cross-post-card {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.card-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f1;
}

.card-header h2 {
    display: flex;
    align-items: center;
    margin: 0;
    font-size: 18px;
}

.card-header h2 .material-icons {
    margin-right: 8px;
    color: #2271b1;
}

.card-content {
    padding: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
}

.form-group .description {
    margin-top: 4px;
    color: #666;
}

.form-actions {
    margin-top: 24px;
}

.button .material-icons {
    font-size: 18px;
    vertical-align: text-bottom;
    margin-right: 4px;
}

.sync-button {
    display: flex !important;
    align-items: center;
    gap: 8px;
    padding: 8px 16px !important;
    height: auto !important;
}

.sync-button .material-icons {
    font-size: 18px;
}

.sync-button.syncing .material-icons {
    animation: spin 1s linear infinite;
}

.wp-list-table .material-icons {
    font-size: 16px;
    vertical-align: text-bottom;
    margin-left: 4px;
}

.button-link-delete {
    color: #d63638;
}

.button-link-delete:hover {
    color: #d63638;
    opacity: 0.8;
}

@keyframes spin {
    100% { transform: rotate(360deg); }
}
</style>