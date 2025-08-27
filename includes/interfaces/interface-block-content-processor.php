<?php
/**
 * WP Cross Post ブロックコンテンツプロセッサーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post ブロックコンテンツプロセッサーインターフェース
 *
 * ブロックコンテンツプロセッサーが実装すべきインターフェース
 */
interface WP_Cross_Post_Block_Content_Processor_Interface extends WP_Cross_Post_Manager_Interface {
    
    /**
     * ブロックコンテンツの準備
     *
     * @param string $content 投稿コンテンツ
     * @param array|null $site_data サイトデータ
     * @return string 処理されたコンテンツ
     */
    public function prepare_block_content($content, $site_data);
    
    /**
     * 依存関係の設定
     *
     * @param WP_Cross_Post_Debug_Manager $debug_manager デバッグマネージャー
     * @param WP_Cross_Post_Image_Manager $image_manager 画像マネージャー
     */
    public function set_dependencies($debug_manager, $image_manager);
}