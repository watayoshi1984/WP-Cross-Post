<?php
/**
 * WP Cross Post 同期ハンドラーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post 同期ハンドラーインターフェース
 *
 * 同期ハンドラーが実装すべきインターフェース
 */
interface WP_Cross_Post_Sync_Handler_Interface extends WP_Cross_Post_Handler_Interface {
    
    /**
     * AJAX同期投稿
     */
    public function ajax_sync_post();
    
    /**
     * 投稿の同期
     *
     * @param int $post_id 投稿ID
     * @param array $selected_sites 選択されたサイト
     * @return array|WP_Error 同期結果
     */
    public function sync_post($post_id, $selected_sites);
}