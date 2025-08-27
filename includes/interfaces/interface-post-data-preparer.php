<?php
/**
 * WP Cross Post 投稿データ準備インターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post 投稿データ準備インターフェース
 *
 * 投稿データ準備クラスが実装すべきインターフェース
 */
interface WP_Cross_Post_Post_Data_Preparer_Interface extends WP_Cross_Post_Manager_Interface {
    
    /**
     * 投稿データの準備
     *
     * @param WP_Post $post 投稿オブジェクト
     * @param array $selected_sites 選択されたサイト
     * @return array|WP_Error 準備された投稿データ、失敗時はエラー
     */
    public function prepare_post_data($post, $selected_sites);
    
    /**
     * 依存関係の設定
     *
     * @param WP_Cross_Post_Debug_Manager $debug_manager デバッグマネージャー
     * @param WP_Cross_Post_Block_Content_Processor $block_content_processor ブロックコンテンツプロセッサー
     */
    public function set_dependencies($debug_manager, $block_content_processor);
}