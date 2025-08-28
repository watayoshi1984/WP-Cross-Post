<?php
/**
 * WP Cross Post エラーマネージャーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post エラーマネージャーインターフェース
 *
 * エラーマネージャーが実装すべきインターフェース
 */
interface WP_Cross_Post_Error_Manager_Interface extends WP_Cross_Post_Manager_Interface {
    
    /**
     * APIエラーの処理
     *
     * @param WP_Error|array $response APIレスポンス
     * @param string $context コンテキスト
     * @return WP_Error エラーオブジェクト
     */
    public function handle_api_error($response, $context = '');
    
    /**
     * 同期エラーの処理
     *
     * @param Exception $e 例外
     * @param string $context コンテキスト
     * @return WP_Error エラーオブジェクト
     */
    public function handle_sync_error($e, $context = '');
    
    /**
     * バリデーションエラーの処理
     *
     * @param string $field フィールド名
     * @param string $message エラーメッセージ
     * @return WP_Error エラーオブジェクト
     */
    public function handle_validation_error($field, $message);
    
    /**
     * 一般的なエラーの処理
     *
     * @param string $message エラーメッセージ
     * @param string $type エラータイプ
     * @return WP_Error エラーオブジェクト
     */
    public function handle_general_error($message, $type = 'general_error');
    
    /**
     * 詳細なエラーログ出力
     *
     * @param string $message エラーメッセージ
     * @param string $type エラータイプ
     * @param array $context コンテキスト情報
     * @param string $file ファイル名
     * @param int $line 行番号
     * @return WP_Error エラーオブジェクト
     */
    public function log_detailed_error($message, $type = 'error', $context = array(), $file = '', $line = 0);
    
    /**
     * エラー通知機能
     *
     * @param string $message エラーメッセージ
     * @param string $type エラータイプ
     * @param array $context コンテキスト情報
     * @return bool 通知が成功したかどうか
     */
    public function notify_error($message, $type = 'error', $context = array());
}