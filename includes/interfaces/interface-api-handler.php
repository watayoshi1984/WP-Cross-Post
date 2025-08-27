<?php
/**
 * WP Cross Post APIハンドラーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post APIハンドラーインターフェース
 *
 * APIハンドラーが実装すべきインターフェース
 */
interface WP_Cross_Post_API_Handler_Interface extends WP_Cross_Post_Handler_Interface {
    
    /**
     * サイトへの接続テスト
     *
     * @param array $site_data サイトデータ
     * @return array|WP_Error 接続テスト結果
     */
    public function test_connection($site_data);
    
    /**
     * URLのバリデーションと正規化
     *
     * @param string $url URL
     * @return string 正規化されたURL
     */
    public function normalize_site_url($url);
}