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
     * テスト用のインスタンスを作成
     *
     * @return WP_Cross_Post_Error_Manager
     */
    public static function create_for_test() {
        return new self();
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
            $this->notify_error($error_message, 'error', array(
                'context' => $context,
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
            $this->notify_error($error_message, 'error', array(
                'context' => $context,
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
        $this->notify_error($error_message, 'error', array(
            'context' => $context,
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
        $this->notify_error($error_message, 'error', array(
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
        $this->notify_error($message, 'error', array(
            'error_type' => $type
        ));
        return new WP_Error($type, $message);
    }

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
    public function log_detailed_error($message, $type = 'error', $context = array(), $file = '', $line = 0) {
        // 詳細なコンテキスト情報を追加
        $detailed_context = array_merge($context, array(
            'file' => $file,
            'line' => $line,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10)
        ));
        
        // ログレベルに応じた処理
        switch ($type) {
            case 'error':
                $this->debug_manager->log($message, 'error', $detailed_context);
                $this->notify_error($message, 'error', $detailed_context);
                break;
            case 'warning':
                $this->debug_manager->log($message, 'warning', $detailed_context);
                $this->notify_error($message, 'warning', $detailed_context);
                break;
            case 'notice':
                $this->debug_manager->log($message, 'notice', $detailed_context);
                $this->notify_error($message, 'notice', $detailed_context);
                break;
            default:
                $this->debug_manager->log($message, 'error', $detailed_context);
                $this->notify_error($message, 'error', $detailed_context);
        }
        
        return new WP_Error($type, $message);
    }

    /**
     * エラー通知機能
     *
     * @param string $message エラーメッセージ
     * @param string $type エラータイプ
     * @param array $context コンテキスト情報
     * @return bool 通知が成功したかどうか
     */
    public function notify_error($message, $type = 'error', $context = array()) {
        // エラー通知の設定を取得
        $notification_settings = get_option('wp_cross_post_error_notification_settings', array(
            'enabled' => false,
            'email' => get_option('admin_email'),
            'threshold' => 'error'
        ));
        
        // 通知が無効な場合は何もしない
        if (!$notification_settings['enabled']) {
            return false;
        }
        
        // ログレベルの閾値をチェック
        $log_levels = array('debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4);
        if ($log_levels[$type] < $log_levels[$notification_settings['threshold']]) {
            return false;
        }
        
        // 通知メールを送信
        $subject = sprintf('[WP Cross Post] %s が発生しました', ucfirst($type));
        $body = sprintf(
            "WP Cross Post プラグインで %s が発生しました:\n\n%s\n\nコンテキスト情報:\n%s",
            $type,
            $message,
            print_r($context, true)
        );
        
        $result = wp_mail(
            $notification_settings['email'],
            $subject,
            $body,
            array('Content-Type: text/plain; charset=UTF-8')
        );
        
        if ($result) {
            $this->debug_manager->log('エラー通知を送信しました', 'info', array(
                'type' => $type,
                'recipient' => $notification_settings['email']
            ));
        } else {
            $this->debug_manager->log('エラー通知の送信に失敗しました', 'warning', array(
                'type' => $type,
                'recipient' => $notification_settings['email']
            ));
        }
        
        return $result;
    }
    
    /**
     * エラー通知設定を更新
     *
     * @param array $settings 通知設定
     * @return bool 更新が成功したかどうか
     */
    public function update_notification_settings($settings) {
        $allowed_settings = array('enabled', 'email', 'threshold');
        $validated_settings = array();
        
        foreach ($allowed_settings as $key) {
            if (isset($settings[$key])) {
                switch ($key) {
                    case 'enabled':
                        $validated_settings[$key] = (bool) $settings[$key];
                        break;
                    case 'email':
                        $validated_settings[$key] = sanitize_email($settings[$key]);
                        break;
                    case 'threshold':
                        $allowed_thresholds = array('debug', 'info', 'notice', 'warning', 'error');
                        if (in_array($settings[$key], $allowed_thresholds)) {
                            $validated_settings[$key] = $settings[$key];
                        } else {
                            $validated_settings[$key] = 'error';
                        }
                        break;
                }
            }
        }
        
        return update_option('wp_cross_post_error_notification_settings', $validated_settings);
    }
    
    /**
     * エラー通知設定を取得
     *
     * @return array 通知設定
     */
    public function get_notification_settings() {
        return get_option('wp_cross_post_error_notification_settings', array(
            'enabled' => false,
            'email' => get_option('admin_email'),
            'threshold' => 'error'
        ));
    }
}