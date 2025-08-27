<?php
/**
 * WP Cross Post サイトハンドラーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post サイトハンドラーインターフェース
 *
 * サイトハンドラーが実装すべきインターフェース
 */
interface WP_Cross_Post_Site_Handler_Interface extends WP_Cross_Post_Handler_Interface {
    
    /**
     * サイトの追加
     *
     * @param array $site_data サイトデータ
     * @return string|WP_Error サイトIDまたはエラー
     */
    public function add_site($site_data);
    
    /**
     * サイトの削除
     *
     * @param string $site_id サイトID
     * @return bool|WP_Error 成功した場合はtrue、失敗した場合はエラー
     */
    public function remove_site($site_id);
    
    /**
     * サイト一覧の取得
     *
     * @return array サイト一覧
     */
    public function get_sites();
    
    /**
     * 全サイトのタクソノミーを同期
     *
     * @return array 同期結果
     */
    public function sync_all_sites_taxonomies();
    
    /**
     * 定期実行用のフック設定
     */
    public function schedule_taxonomies_sync();
    
    /**
     * 定期実行のフックを解除
     */
    public function unschedule_taxonomies_sync();
    
    /**
     * 手動同期用のAJAXハンドラー
     */
    public function ajax_sync_taxonomies();
}