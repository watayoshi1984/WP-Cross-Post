<?php
/**
 * WP Cross Post 認証マネージャー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-manager.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-auth-manager.php';

/**
 * WP Cross Post 認証マネージャークラス
 *
 * 認証ヘッダーの生成処理とWordPressバージョンによる認証方式の切り替え処理を管理します。
 */
class WP_Cross_Post_Auth_Manager implements WP_Cross_Post_Auth_Manager_Interface {

    /**
     * インスタンス
     *
     * @var WP_Cross_Post_Auth_Manager|null
     */
    private static $instance = null;

    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Auth_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        // シングルトンパターンのため、直接インスタンス化を防ぐ
    }

    /**
     * 認証ヘッダーを取得
     *
     * @param array $site_data サイトデータ
     * @return string 認証ヘッダー
     */
    public function get_auth_header($site_data) {
        // WordPress 5.6以降のアプリケーションパスワード対応
        if (version_compare(get_bloginfo('version'), '5.6', '>=')) {
            // アプリケーションパスワードの形式で認証
            return 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']);
        } else {
            // 従来のBasic認証
            return 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']);
        }
    }

    /**
     * 認証情報のサニタイズ
     *
     * @param string $credential 認証情報
     * @return string サニタイズされた認証情報
     */
    public function sanitize_credential($credential) {
        // 基本的なサニタイズ
        $credential = sanitize_text_field($credential);
        
        // 危険な文字を除去
        $credential = preg_replace('/[^\w\-\.]/', '', $credential);
        
        return $credential;
    }
}