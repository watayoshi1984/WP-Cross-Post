<?php
/**
 * PHPUnit bootstrap file
 */

// Composerのautoloadを読み込む
require_once dirname(__DIR__) . '/vendor/autoload.php';

// WordPressのテスト用関数モックを読み込む
require_once dirname(__DIR__) . '/tests/includes/wp-mocks.php';

// プラグインの定数を定義
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('WP_CROSS_POST_PLUGIN_DIR')) {
    define('WP_CROSS_POST_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('WP_CROSS_POST_PLUGIN_URL')) {
    define('WP_CROSS_POST_PLUGIN_URL', 'http://example.com/wp-content/plugins/wp-cross-post/');
}

// プラグインの主要ファイルを読み込む
require_once dirname(__DIR__) . '/my-wp-cross-post.php';
require_once dirname(__DIR__) . '/includes/class-config-manager.php';
require_once dirname(__DIR__) . '/includes/class-wp-cross-post-error-manager.php';
require_once dirname(__DIR__) . '/includes/class-wp-cross-post-site-handler.php';
require_once dirname(__DIR__) . '/includes/class-wp-cross-post-sync-engine.php';

// テスト用のモッククラスを定義
class WP_Cross_Post_Mock_Debug_Manager {
    public function log($message, $level = 'info', $context = array()) {
        // ログ出力のモック
        echo "[$level] $message\n";
    }
    
    public function is_debug_mode() {
        return false;
    }
    
    public function start_performance_monitoring($key) {
        // パフォーマンスモニタリング開始のモック
    }
    
    public function end_performance_monitoring($key) {
        // パフォーマンスモニタリング終了のモック
    }
}

class WP_Cross_Post_Mock_Auth_Manager {
    public function get_auth_header($site_data) {
        return 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']);
    }
}

class WP_Cross_Post_Mock_Image_Manager {
    public function set_dependencies($debug_manager, $auth_manager) {
        // 依存関係設定のモック
    }
    
    public function sync_media($site, $media, $download_callback) {
        // メディア同期のモック
        return array();
    }
    
    public function sync_featured_image($site, $featured_media, $post_id) {
        // アイキャッチ画像同期のモック
        return array('id' => 123);
    }
}

class WP_Cross_Post_Mock_Post_Data_Preparer {
    public function set_dependencies($debug_manager, $block_content_processor) {
        // 依存関係設定のモック
    }
    
    public function prepare_post_data($post, $selected_sites) {
        // 投稿データ準備のモック
        return array(
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status
        );
    }
}

class WP_Cross_Post_Mock_API_Handler {
    public function normalize_site_url($url) {
        return rtrim($url, '/');
    }
    
    public function test_connection($site_data) {
        // 接続テストのモック
        return true;
    }
}

class WP_Cross_Post_Mock_Rate_Limit_Manager {
    public function set_dependencies($debug_manager, $error_manager) {
        // 依存関係設定のモック
    }
    
    public function sync_with_rate_limit($site, $post_data) {
        // レート制限付き同期のモック
        return 456; // リモート投稿ID
    }
}