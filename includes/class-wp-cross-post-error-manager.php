<?php
/**
 * WP Cross Post エラーマネージャー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-manager.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-error-manager.php';

/**
 * WP Cross Post エラーマネージャークラス
 *
 * エラーハンドリングとログ出力を管理します。
 */
class WP_Cross_Post_Error_Manager implements WP_Cross_Post_Error_Manager_Interface {

    /**
     * インスタンス
     *
     * @var WP_Cross_Post_Error_Manager|null
     */
    private static $instance = null;

    /**
     * デバッグマネージャー
     *
     * @var WP_Cross_Post_Debug_Manager
     */
    private $debug_manager;

    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Error_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        // シングルトンパターンのため、直接インスタンス化を防ぐ
    }

    /**
     * 依存関係の設定
     *
     * @param WP_Cross_Post_Debug_Manager $debug_manager デバッグマネージャー
     */
    public function set_dependencies($debug_manager) {
        $this->debug_manager = $debug_manager;
    }

    /**
     * APIエラーの処理
     *
     * @param WP_Error|array $response APIレスポンス
     * @param string $context コンテキスト
     * @return WP_Error エラーオブジェクト
     */
    public function handle_api_error($response, $context = '') {
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->debug_manager->log($context . ' エラー: ' . $error_message, 'error', array(
                'error_code' => $response->get_error_code(),
                'error_data' => $response->get_error_data()
            ));
            return new WP_Error('api_error', $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        // レート制限のチェック
        if ($response_code === 429) {
            $retry_after = wp_remote_retrieve_header($response, 'retry-after');
            $wait_time = $retry_after ? intval($retry_after) : 2;
            $this->debug_manager->log('レート制限に到達しました。', 'warning', array(
                'retry_after' => $retry_after,
                'wait_time' => $wait_time
            ));
            sleep($wait_time);
            return new WP_Error('rate_limit', 'レート制限に到達しました。');
        }

        // その他のエラーコードの処理
        if ($response_code >= 400) {
            $error_message = sprintf(
                '%s失敗（HTTP %d）: %s',
                $context,
                $response_code,
                wp_remote_retrieve_response_message($response)
            );
            $this->debug_manager->log($error_message, 'error', array(
                'response_code' => $response_code,
                'response_body' => wp_remote_retrieve_body($response)
            ));
            return new WP_Error('api_error', $error_message);
        }

        return $response;
    }

    /**
     * 同期エラーの処理
     *
     * @param Exception $e 例外
     * @param string $context コンテキスト
     * @return WP_Error エラーオブジェクト
     */
    public function handle_sync_error($e, $context = '') {
        $error_message = $context . ' 同期エラー: ' . $e->getMessage();
        $this->debug_manager->log($error_message, 'error', array(
            'exception_class' => get_class($e),
            'exception_code' => $e->getCode(),
            'exception_trace' => $e->getTraceAsString()
        ));
        return new WP_Error('sync_error', $error_message);
    }

    /**
     * バリデーションエラーの処理
     *
     * @param string $field フィールド名
     * @param string $message エラーメッセージ
     * @return WP_Error エラーオブジェクト
     */
    public function handle_validation_error($field, $message) {
        $error_message = sprintf('バリデーションエラー（%s）: %s', $field, $message);
        $this->debug_manager->log($error_message, 'error', array(
            'field' => $field,
            'validation_message' => $message
        ));
        return new WP_Error('validation_error', $error_message);
    }

    /**
     * 一般的なエラーの処理
     *
     * @param string $message エラーメッセージ
     * @param string $type エラータイプ
     * @return WP_Error エラーオブジェクト
     */
    public function handle_general_error($message, $type = 'general_error') {
        $this->debug_manager->log($message, 'error', array(
            'error_type' => $type
        ));
        return new WP_Error($type, $message);
    }
}