<div class="wrap wp-cross-post-container">
    <h1 class="wp-cross-post-title">WP Cross Post 設定</h1>

    <div class="wp-cross-post-card">
        <h2>プラグイン情報</h2>
        <p>WP Cross Postは、複数のWordPressサイト間でクロス投稿を行うプラグインです。</p>
        <p>バージョン: <?php echo WP_CROSS_POST_VERSION; ?></p>
    </div>

    <div class="wp-cross-post-card">
        <h2>使い方</h2>
        <ol>
            <li>「サイト管理」ページで同期先のサイトを追加します。</li>
            <li>投稿画面で同期先のサイトを選択し、「同期」ボタンをクリックします。</li>
            <li>選択したサイトに投稿が同期されます。</li>
        </ol>
    </div>

    <div class="wp-cross-post-card">
        <h2>トラブルシューティング</h2>
        <p>問題が発生した場合は、以下の手順を試してください：</p>
        <ol>
            <li>サイトの接続設定を確認してください。</li>
            <li>WordPressとプラグインが最新バージョンであることを確認してください。</li>
            <li>エラーログを確認してください。</li>
        </ol>
        <p>それでも問題が解決しない場合は、サポートにお問い合わせください。</p>
    </div>
</div>

// 設定画面拡張
class WP_Cross_Post_Settings {
    public function render_settings_page() {
        echo '<div class="wrap">
            <h1>WP Cross Post 設定</h1>
            <form method="post" action="options.php">';
            
        settings_fields('wp_cross_post_settings');
        do_settings_sections('wp_cross_post');
        
        echo '<table class="form-table">
                <tr>
                    <th>自動同期</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_cross_post_auto_sync" '.checked(1, get_option('wp_cross_post_auto_sync'), false).'>
                            変更を自動的に同期
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>メディア同期</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_cross_post_sync_media" '.checked(1, get_option('wp_cross_post_sync_media'), false).'>
                            画像を含めて同期
                        </label>
                    </td>
                </tr>
              </table>';
        
        submit_button();
        echo '</form></div>';
    }
}

