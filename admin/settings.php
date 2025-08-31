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

// サイトが追加された場合の処理
if ($_POST && isset($_POST['wp_cross_post_add_site_nonce'])) {
    if (wp_verify_nonce($_POST['wp_cross_post_add_site_nonce'], 'wp_cross_post_add_site')) {
        $site_data = array(
            'name' => sanitize_text_field($_POST['site_name']),
            'url' => esc_url_raw($_POST['site_url']),
            'username' => sanitize_text_field($_POST['username']),
            'app_password' => sanitize_text_field($_POST['app_password'])
        );
        
        global $wp_cross_post;
        $site_handler = $wp_cross_post->site_handler;
        $result = $site_handler->add_site($site_data);
        
        if (!is_wp_error($result)) {
            // サイト追加成功メッセージ
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>サイトを追加しました。</p></div>';
            });
        } else {
            // サイト追加失敗メッセージ
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            });
        }
    }
}

// サイトが削除された場合の処理
if ($_POST && isset($_POST['wp_cross_post_remove_site_nonce'])) {
    if (wp_verify_nonce($_POST['wp_cross_post_remove_site_nonce'], 'wp_cross_post_remove_site')) {
        $site_id = sanitize_text_field($_POST['site_id']);
        
        global $wp_cross_post;
        $site_handler = $wp_cross_post->site_handler;
        $result = $site_handler->remove_site($site_id);
        
        if ($result) {
            // サイト削除成功メッセージ
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>サイトを削除しました。</p></div>';
            });
        } else {
            // サイト削除失敗メッセージ
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>サイトの削除に失敗しました。</p></div>';
            });
        }
    }
}

// エラー通知設定が保存された場合の処理
if ($_POST && isset($_POST['wp_cross_post_notification_settings_nonce'])) {
    if (wp_verify_nonce($_POST['wp_cross_post_notification_settings_nonce'], 'wp_cross_post_save_notification_settings')) {
        // エラー通知設定を保存
        $notification_settings = array(
            'enabled' => isset($_POST['notification_enabled']),
            'email' => sanitize_email($_POST['notification_email']),
            'threshold' => sanitize_text_field($_POST['notification_threshold'])
        );
        
        $error_manager = WP_Cross_Post_Error_Manager::get_instance();
        $error_manager->update_notification_settings($notification_settings);
        
        // 設定保存成功メッセージ
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>エラー通知設定を保存しました。</p></div>';
        });
    }
}

// 強制キャッシュクリア処理
if ($_POST && isset($_POST['wp_cross_post_force_cache_clear_nonce'])) {
    if (wp_verify_nonce($_POST['wp_cross_post_force_cache_clear_nonce'], 'wp_cross_post_force_cache_clear')) {
        global $wp_cross_post;
        
        // 全キャッシュをクリア
        wp_cache_flush();
        
        // WP Cross Post関連のキャッシュキーをクリア
        $cache_keys = array(
            'wp_cross_post_all_sites',
            'wp_cross_post_settings',
            'wp_cross_post_taxonomy_mapping'
        );
        
        foreach ($cache_keys as $key) {
            wp_cache_delete($key);
        }
        
        // トランジェントキャッシュもクリア
        delete_transient('wp_cross_post_site_list');
        delete_transient('wp_cross_post_taxonomy_cache');
        delete_transient('wp_cross_post_media_cache');
        
        // Site_Handler V2のキャッシュもクリア
        if (method_exists($wp_cross_post->site_handler, 'clear_all_cache')) {
            $wp_cross_post->site_handler->clear_all_cache();
        }
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>全キャッシュをクリアしました。</p></div>';
        });
    }
}

// ゴーストサイト強制削除処理
if ($_POST && isset($_POST['wp_cross_post_remove_ghost_sites_nonce'])) {
    if (wp_verify_nonce($_POST['wp_cross_post_remove_ghost_sites_nonce'], 'wp_cross_post_remove_ghost_sites')) {
        global $wpdb, $wp_cross_post;
        
        // データベースから全サイトを取得
        $sites_table = $wpdb->prefix . 'cross_post_sites';
        $sites = $wpdb->get_results("SELECT * FROM $sites_table", ARRAY_A);
        
        $removed_count = 0;
        $failed_sites = array();
        
        foreach ($sites as $site) {
            // 接続テストを実行
            $test_result = $wp_cross_post->api_handler->test_connection($site);
            
            if (is_wp_error($test_result)) {
                // 接続できないサイトは削除対象
                $result = $wpdb->delete(
                    $sites_table,
                    array('id' => $site['id']),
                    array('%d')
                );
                
                if ($result !== false) {
                    $removed_count++;
                } else {
                    $failed_sites[] = $site['name'];
                }
            }
        }
        
        // キャッシュをクリア
        wp_cache_flush();
        
        if ($removed_count > 0) {
            add_action('admin_notices', function() use ($removed_count, $failed_sites) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>ゴーストサイト ' . $removed_count . ' 件を削除しました。</p>';
                if (!empty($failed_sites)) {
                    echo '<p>削除に失敗したサイト: ' . implode(', ', $failed_sites) . '</p>';
                }
                echo '</div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-info is-dismissible"><p>削除対象のゴーストサイトは見つかりませんでした。</p></div>';
            });
        }
    }
}

// 現在の設定を取得
$settings = WP_Cross_Post_Config_Manager::get_settings();
$error_manager = WP_Cross_Post_Error_Manager::get_instance();
$notification_settings = $error_manager->get_notification_settings();
$sites = get_option('wp_cross_post_sites', array());
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
                        <p class="description">APIリクエストの結果をキャッシュします</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">キャッシュ期間</th>
                    <td>
                        <input type="number" name="cache_duration" value="<?php echo esc_attr($settings['cache_settings']['cache_duration']); ?>" min="1" max="86400" />
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
                    <th scope="row">認証情報の暗号化</th>
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
        
        <!-- サイト管理 -->
        <div class="wp-cross-post-card">
            <h2>サイト管理</h2>
            
            <!-- サイトの追加 -->
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
            
            <!-- 登録済みサイト -->
            <div class="card-content">
                <h3>登録済みサイト</h3>
                <?php if (!empty($sites)) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>サイト名</th>
                                <th>URL</th>
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
                                    <form method="post" action="" style="display: inline;">
                                        <?php wp_nonce_field('wp_cross_post_remove_site', 'wp_cross_post_remove_site_nonce'); ?>
                                        <input type="hidden" name="site_id" value="<?php echo esc_attr($site['id']); ?>">
                                        <button type="submit" class="button button-link-delete">
                                            <i class="material-icons">delete</i>
                                            削除
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>登録済みのサイトはありません。</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- エラー通知設定 -->
        <div class="wp-cross-post-card">
            <h2>エラー通知設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">通知有効化</th>
                    <td>
                        <label>
                            <input type="checkbox" name="notification_enabled" <?php checked($notification_settings['enabled']); ?> />
                            エラー通知を有効にする
                        </label>
                        <p class="description">エラー発生時に通知を送信します</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">通知先メールアドレス</th>
                    <td>
                        <input type="email" name="notification_email" value="<?php echo esc_attr($notification_settings['email']); ?>" class="regular-text" />
                        <p class="description">エラー通知を送信するメールアドレス</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">通知閾値</th>
                    <td>
                        <select name="notification_threshold">
                            <option value="error" <?php selected($notification_settings['threshold'], 'error'); ?>>Error</option>
                            <option value="warning" <?php selected($notification_settings['threshold'], 'warning'); ?>>Warning</option>
                            <option value="info" <?php selected($notification_settings['threshold'], 'info'); ?>>Info</option>
                        </select>
                        <p class="description">通知を送信するエラーの重要度</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <?php submit_button('設定を保存'); ?>
    </form>

    <!-- メンテナンス機能 -->
    <div class="wp-cross-post-card">
        <h2>🔧 メンテナンス機能</h2>
        <p class="description">キャッシュのクリアや不正なサイトデータの削除を行えます。</p>
        
        <!-- 強制キャッシュクリア -->
        <div class="maintenance-section">
            <h3>強制キャッシュクリア</h3>
            <p class="description">プラグインの全キャッシュ（WordPress オブジェクトキャッシュ、トランジェント、プラグイン固有キャッシュ）をクリアします。データが正しく表示されない場合に実行してください。</p>
            <form method="post" action="" style="display: inline;">
                <?php wp_nonce_field('wp_cross_post_force_cache_clear', 'wp_cross_post_force_cache_clear_nonce'); ?>
                <button type="submit" class="button button-secondary" onclick="return confirm('全キャッシュをクリアしますか？この操作は元に戻せません。');">
                    🗑️ 全キャッシュをクリア
                </button>
            </form>
        </div>

        <hr style="margin: 20px 0;">

        <!-- ゴーストサイト削除 -->
        <div class="maintenance-section">
            <h3>ゴーストサイト強制削除</h3>
            <p class="description">接続できないサイト（ゴーストサイト）を自動検出して削除します。古いサイトデータが残っている場合に実行してください。</p>
            <p class="description" style="color: #d63384;"><strong>⚠️ 注意:</strong> この操作は接続テストに失敗したサイトを全て削除します。一時的にサイトがダウンしている場合も削除される可能性があります。</p>
            <form method="post" action="" style="display: inline;">
                <?php wp_nonce_field('wp_cross_post_remove_ghost_sites', 'wp_cross_post_remove_ghost_sites_nonce'); ?>
                <button type="submit" class="button button-danger" onclick="return confirm('接続できないサイトを全て削除しますか？この操作は元に戻せません。\\n\\n※一時的にダウンしているサイトも削除される可能性があります。');" style="background-color: #dc3545; border-color: #dc3545; color: white;">
                    ⚠️ ゴーストサイトを削除
                </button>
            </form>
        </div>

        <hr style="margin: 20px 0;">

        <!-- 統計情報 -->
        <div class="maintenance-section">
            <h3>統計情報</h3>
            <?php
            global $wpdb;
            $sites_table = $wpdb->prefix . 'cross_post_sites';
            $total_sites = $wpdb->get_var("SELECT COUNT(*) FROM $sites_table");
            $active_sites = $wpdb->get_var("SELECT COUNT(*) FROM $sites_table WHERE status = 'active'");
            ?>
            <p><strong>登録サイト数:</strong> <?php echo esc_html($total_sites); ?> サイト</p>
            <p><strong>有効サイト数:</strong> <?php echo esc_html($active_sites); ?> サイト</p>
            <p><strong>データベースバージョン:</strong> <?php echo esc_html(get_option('wp_cross_post_db_version', '未設定')); ?></p>
        </div>
    </div>
</div>

<style>
.wp-cross-post-container {
    max-width: 1200px;
    margin: 20px auto;
}

.wp-cross-post-title {
    font-size: 24px;
    margin-bottom: 20px;
}

.wp-cross-post-card {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    padding: 20px;
}

.wp-cross-post-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
}

.form-group .regular-text {
    width: 100%;
    max-width: 400px;
}

.form-group .description {
    margin-top: 5px;
    color: #666;
    font-size: 13px;
}

.form-actions {
    margin-top: 20px;
}

.card-content {
    margin-top: 20px;
}

.card-content h3 {
    margin-top: 0;
}

.maintenance-section {
    margin-bottom: 20px;
    padding: 15px;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    background-color: #fafafa;
}

.maintenance-section h3 {
    margin-top: 0;
    margin-bottom: 10px;
    color: #333;
}

.maintenance-section .description {
    margin-bottom: 15px;
    line-height: 1.4;
}

.button-danger {
    background-color: #dc3545 !important;
    border-color: #dc3545 !important;
    color: white !important;
}

.button-danger:hover {
    background-color: #c82333 !important;
    border-color: #bd2130 !important;
}

.maintenance-section p {
    margin: 8px 0;
}

.maintenance-section strong {
    color: #2c3e50;
}
</style>