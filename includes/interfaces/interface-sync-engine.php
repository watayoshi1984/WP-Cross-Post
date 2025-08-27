<?php
/**
 * WP Cross Post 同期エンジンインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post 同期エンジンインターフェース
 *
 * 同期エンジンが実装すべきインターフェース
 */
interface WP_Cross_Post_Sync_Engine_Interface {
    
    /**
     * 投稿の同期
     *
     * @param int $post_id 投稿ID
     * @return array|WP_Error 同期結果
     */
    public function sync_post($post_id);
    
    /**
     * タクソノミーの全サイト同期
     *
     * @return array 同期結果
     */
    public function sync_taxonomies_to_all_sites();
}