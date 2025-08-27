<?php
/**
 * WP Cross Post 認証マネージャーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post 認証マネージャーインターフェース
 *
 * 認証マネージャーが実装すべきインターフェース
 */
interface WP_Cross_Post_Auth_Manager_Interface extends WP_Cross_Post_Manager_Interface {
    
    /**
     * 認証ヘッダーを取得
     *
     * @param array $site_data サイトデータ
     * @return string 認証ヘッダー
     */
    public function get_auth_header($site_data);
    
    /**
     * 認証情報のサニタイズ
     *
     * @param string $credential 認証情報
     * @return string サニタイズされた認証情報
     */
    public function sanitize_credential($credential);
}