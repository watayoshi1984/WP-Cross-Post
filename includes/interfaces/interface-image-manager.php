<?php
/**
 * WP Cross Post 画像マネージャーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post 画像マネージャーインターフェース
 *
 * 画像マネージャーが実装すべきインターフェース
 */
interface WP_Cross_Post_Image_Manager_Interface extends WP_Cross_Post_Manager_Interface {
    
    /**
     * 最大リトライ回数の設定
     *
     * @param int $max_retries 最大リトライ回数
     */
    public function set_max_retries($max_retries);
    
    /**
     * リトライ待機時間の設定
     *
     * @param int $retry_wait_time リトライ待機時間（秒）
     */
    public function set_retry_wait_time($retry_wait_time);
    
    /**
     * アイキャッチ画像の同期処理
     *
     * @param array $site_data サイトデータ
     * @param array $media_data メディアデータ
     * @param int $post_id 投稿ID
     * @return array|null 同期されたメディア情報、失敗時はnull
     */
    public function sync_featured_image($site_data, $media_data, $post_id);
    
    /**
     * リモートメディアURLの取得
     *
     * @param int $media_id メディアID
     * @param array $site_data サイトデータ
     * @return string|WP_Error メディアURL、失敗時はエラー
     */
    public function get_remote_media_url($media_id, $site_data);
    
    /**
     * メディア同期処理
     *
     * @param array $site サイト情報
     * @param array $media_items メディアアイテム
     * @param callable $download_media_func メディアダウンロード関数
     * @return array 同期されたメディア
     */
    public function sync_media($site, $media_items, $download_media_func);
}