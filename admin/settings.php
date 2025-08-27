<?php
// 設定が保存された場合の処理
if ($_POST && isset($_POST['wp_cross_post_settings_nonce'])) {
    if (wp_verify_nonce($_POST['wp_cross_post_settings_nonce'], 'wp_cross_post_save_settings')) {
        // 設定を保存
        $settings = array(
            'api_settings' => array(
                'timeout' => intval($_POST['api_timeout']),
                'retries' => intval($_POST['api_retries']),
                'batch_size' => intval($_POST['api_batch_size'])
            ),
            'sync_settings' => array(
                'parallel_sync' => isset($_POST['sync_parallel_sync']),
                'async_sync' => isset($_POST['sync_async_sync']),
                'rate_limit' => isset($_POST['sync_rate_limit'])
            ),
            'image_settings' => array(
                'sync_images' => isset($_POST['image_sync_images']),
                'max_image_size' => intval($_POST['image_max_size']),
                'image_quality' => intval($_POST['image_quality'])
            ),
            'debug_settings' => array(
                'debug_mode' => isset($_POST['debug_mode']),
                'log_level' => sanitize_text_field($_POST['log_level'])
            ),
            'cache_settings' => array(
                'enable_cache' => isset($_POST['cache_enable']),
                'cache_duration' => intval($_POST['cache_duration'])
            ),
            'security_settings' => array(
                'verify_ssl' => isset($_POST['security_verify_ssl']),
                'encrypt_credentials' => isset($_POST['security_encrypt_credentials'])
            )
        );
        
        WP_Cross_Post_Config_Manager::update_settings($settings);
        
        // 設定保存成功メッセージ
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
        });
    }
}

// 現在の設定を取得
$settings = WP_Cross_Post_Config_Manager::get_settings();
?>

<div class="wrap wp-cross-post-container">
    <h1 class="wp-cross-post-title">WP Cross Post 設定</h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('wp_cross_post_save_settings', 'wp_cross_post_settings_nonce'); ?>
        
        <!-- API設定 -->
        <div class="wp-cross-post-card">
            <h2>API設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">タイムアウト</th>
                    <td>
                        <input type="number" name="api_timeout" value="<?php echo esc_attr($settings['api_settings']['timeout']); ?>" min="1" max="300" />
                        <p class="description">APIリクエストのタイムアウト時間（秒）</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">リトライ回数</th>
                    <td>
                        <input type="number" name="api_retries" value="<?php echo esc_attr($settings['api_settings']['retries']); ?>" min="0" max="10" />
                        <p class="description">APIリクエスト失敗時のリトライ回数</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">バッチサイズ</th>
                    <td>
                        <input type="number" name="api_batch_size" value="<?php echo esc_attr($settings['api_settings']['batch_size']); ?>" min="1" max="100" />
                        <p class="description">一度に処理するアイテム数</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- 同期設定 -->
        <div class="wp-cross-post-card">
            <h2>同期設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">並列同期</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sync_parallel_sync" <?php checked($settings['sync_settings']['parallel_sync']); ?> />
                            並列処理で同期する
                        </label>
                        <p class="description">複数サイトへの同期を並列で実行します</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">非同期同期</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sync_async_sync" <?php checked($settings['sync_settings']['async_sync']); ?> />
                            非同期処理で同期する
                        </label>
                        <p class="description">同期処理をバックグラウンドで実行します</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">レート制限</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sync_rate_limit" <?php checked($settings['sync_settings']['rate_limit']); ?> />
                            レート制限を有効にする
                        </label>
                        <p class="description">APIのレート制限を考慮して同期します</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- 画像設定 -->
        <div class="wp-cross-post-card">
            <h2>画像設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">画像同期</th>
                    <td>
                        <label>
                            <input type="checkbox" name="image_sync_images" <?php checked($settings['image_settings']['sync_images']); ?> />
                            画像を同期する
                        </label>
                        <p class="description">投稿に含まれる画像を同期します</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">最大画像サイズ</th>
                    <td>
                        <input type="number" name="image_max_size" value="<?php echo esc_attr($settings['image_settings']['max_image_size']); ?>" />
                        <p class="description">同期する画像の最大サイズ（バイト）</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">画像品質</th>
                    <td>
                        <input type="number" name="image_quality" value="<?php echo esc_attr($settings['image_settings']['image_quality']); ?>" min="10" max="100" />
                        <p class="description">JPEG画像の品質（10-100）</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- デバッグ設定 -->
        <div class="wp-cross-post-card">
            <h2>デバッグ設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">デバッグモード</th>
                    <td>
                        <label>
                            <input type="checkbox" name="debug_mode" <?php checked($settings['debug_settings']['debug_mode']); ?> />
                            デバッグモードを有効にする
                        </label>
                        <p class="description">デバッグ情報をログに出力します</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">ログレベル</th>
                    <td>
                        <select name="log_level">
                            <option value="debug" <?php selected($settings['debug_settings']['log_level'], 'debug'); ?>>Debug</option>
                            <option value="info" <?php selected($settings['debug_settings']['log_level'], 'info'); ?>>Info</option>
                            <option value="warning" <?php selected($settings['debug_settings']['log_level'], 'warning'); ?>>Warning</option>
                            <option value="error" <?php selected($settings['debug_settings']['log_level'], 'error'); ?>>Error</option>
                        </select>
                        <p class="description">ログの詳細度</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- キャッシュ設定 -->
        <div class="wp-cross-post-card">
            <h2>キャッシュ設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">キャッシュ有効化</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cache_enable" <?php checked($settings['cache_settings']['enable_cache']); ?> />
                            キャッシュを有効にする
                        </label>
                        <p class="description">サイト情報やタクソノミー情報をキャッシュします</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">キャッシュ期間</th>
                    <td>
                        <input type="number" name="cache_duration" value="<?php echo esc_attr($settings['cache_settings']['cache_duration']); ?>" min="60" max="86400" />
                        <p class="description">キャッシュの有効期間（秒）</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- セキュリティ設定 -->
        <div class="wp-cross-post-card">
            <h2>セキュリティ設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">SSL検証</th>
                    <td>
                        <label>
                            <input type="checkbox" name="security_verify_ssl" <?php checked($settings['security_settings']['verify_ssl']); ?> />
                            SSL証明書を検証する
                        </label>
                        <p class="description">APIリクエスト時にSSL証明書を検証します</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">資格情報の暗号化</th>
                    <td>
                        <label>
                            <input type="checkbox" name="security_encrypt_credentials" <?php checked($settings['security_settings']['encrypt_credentials']); ?> />
                            認証情報を暗号化する
                        </label>
                        <p class="description">サイトの認証情報を暗号化して保存します</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <?php submit_button('設定を保存'); ?>
    </form>
    
    <div class="wp-cross-post-card">
        <h2>設定のインポート/エクスポート</h2>
        <table class="form-table">
            <tr>
                <th scope="row">設定のエクスポート</th>
                <td>
                    <button type="button" class="button" id="export-settings">設定をエクスポート</button>
                    <p class="description">現在の設定をファイルにエクスポートします</p>
                </td>
            </tr>
            <tr>
                <th scope="row">設定のインポート</th>
                <td>
                    <input type="file" id="import-settings-file" accept=".json" />
                    <button type="button" class="button" id="import-settings">設定をインポート</button>
                    <p class="description">エクスポートした設定ファイルをインポートします</p>
                </td>
            </tr>
        </table>
    </div>
    
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

<script>
jQuery(document).ready(function($) {
    // 設定のエクスポート
    $('#export-settings').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_export_settings',
                nonce: '<?php echo wp_create_nonce('wp_cross_post_export_settings'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // ダウンロードリンクを作成
                    var element = document.createElement('a');
                    element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(response.data));
                    element.setAttribute('download', 'wp-cross-post-settings.json');
                    element.style.display = 'none';
                    document.body.appendChild(element);
                    element.click();
                    document.body.removeChild(element);
                } else {
                    alert('設定のエクスポートに失敗しました。');
                }
            }
        });
    });
    
    // 設定のインポート
    $('#import-settings').on('click', function() {
        var fileInput = $('#import-settings-file')[0];
        if (fileInput.files.length === 0) {
            alert('インポートするファイルを選択してください。');
            return;
        }
        
        var file = fileInput.files[0];
        var reader = new FileReader();
        
        reader.onload = function(e) {
            var settings = e.target.result;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_import_settings',
                    nonce: '<?php echo wp_create_nonce('wp_cross_post_import_settings'); ?>',
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        alert('設定をインポートしました。');
                        location.reload();
                    } else {
                        alert('設定のインポートに失敗しました。');
                    }
                }
            });
        };
        
        reader.readAsText(file);
    });
});
</script>