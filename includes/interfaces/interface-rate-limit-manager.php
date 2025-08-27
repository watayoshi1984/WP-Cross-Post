<?php
/**
 * WP Cross Post レート制限マネージャーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post レート制限マネージャーインターフェース
 *
 * レート制限マネージャーが実装すべきインターフェース
 */
interface WP_Cross_Post_Rate_Limit_Manager_Interface extends WP_Cross_Post_Manager_Interface {
    
    /**
     * レート制限のチェックと待機
     *
     * @param string $site_url サイトURL
     * @return bool|WP_Error 待機が必要な場合はtrue、エラーの場合はWP_Error
     */
    public function check_and_wait_for_rate_limit($site_url);
    
    /**
     * レート制限情報の更新
     *
     * @param string $site_url サイトURL
     * @param int $retry_after リトライ待機時間（秒）
     */
    public function update_rate_limit_info($site_url, $retry_after);
    
    /**
     * レート制限の処理
     *
     * @param string $site_url サイトURL
     * @param WP_Error $response APIレスポンス
     * @return WP_Error 処理済みのエラーオブジェクト
     */
    public function handle_rate_limit($site_url, $response);
}