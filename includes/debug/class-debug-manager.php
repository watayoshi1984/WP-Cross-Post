<?php
/**
 * Debug Manager Class
 * 
 * @package WP_Cross_Post
 * @subpackage Debug
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Cross_Post_Debug_Manager {
    private static $instance = null;
    private $is_debug_mode = false;
    private $log_level = 'error';
    private $performance_monitoring = false;
    private $performance_data = array();

    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->init();
    }

    /**
     * 初期化
     */
    private function init() {
        // 環境設定に基づいてデバッグモードを設定
        $this->is_debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        
<<<<<<< HEAD
        // ログレベルの設定（デフォルトは'error'）
        $this->log_level = defined('WP_CROSS_POST_LOG_LEVEL') ? WP_CROSS_POST_LOG_LEVEL : 'error';
        
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        // デバッグモードが有効な場合のみ高度なログを有効化
        if ($this->is_debug_mode) {
            $this->log_level = 'debug';
            $this->performance_monitoring = true;
            add_action('admin_enqueue_scripts', array($this, 'enqueue_debug_assets'));
            add_action('admin_footer', array($this, 'render_debug_panel'));
        }
    }

    /**
     * デバッグモードの状態を取得
     */
    public function is_debug_mode() {
        return $this->is_debug_mode;
    }

    /**
     * ログレベルを設定
     */
    public function set_log_level($level) {
        $allowed_levels = array('error', 'warning', 'info', 'debug');
        if (in_array($level, $allowed_levels)) {
            $this->log_level = $level;
        }
    }

    /**
     * パフォーマンスモニタリングの有効/無効を設定
     */
    public function set_performance_monitoring($enabled) {
        $this->performance_monitoring = (bool) $enabled;
    }

    /**
     * デバッグ情報をログに記録
     */
    public function log($message, $level = 'debug', $context = array()) {
        if (!$this->should_log($level)) {
            return;
        }

<<<<<<< HEAD
        // ユーザー情報を追加
        $current_user = wp_get_current_user();
        $user_info = array(
            'user_id' => $current_user->ID,
            'user_login' => $current_user->user_login,
            'user_roles' => $current_user->roles
        );

        // リクエスト情報を追加
        $request_info = array(
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
            'remote_addr' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        );

=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
<<<<<<< HEAD
            'user_info' => $user_info,
            'request_info' => $request_info,
=======
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        );

        // WordPressのエラーログに記録
        error_log(sprintf(
<<<<<<< HEAD
            '[WP Cross Post] [%s] %s | User: %s | Request: %s | Context: %s',
            strtoupper($level),
            $message,
            $current_user->user_login,
            isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A',
=======
            '[WP Cross Post] [%s] %s | Context: %s',
            strtoupper($level),
            $message,
>>>>>>> 80b7cb32482b21d9b40c6aa9df99bbc9d47b0be4
            json_encode($context)
        ));

        // デバッグモード時は詳細情報を保存
        if ($this->is_debug_mode) {
            $this->save_debug_log($log_entry);
        }
    }

    /**
     * パフォーマンス計測開始
     */
    public function start_performance_monitoring($label) {
        if (!$this->performance_monitoring) {
            return;
        }

        $this->performance_data[$label] = array(
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
        );
    }

    /**
     * パフォーマンス計測終了
     */
    public function end_performance_monitoring($label) {
        if (!$this->performance_monitoring || !isset($this->performance_data[$label])) {
            return;
        }

        $end_time = microtime(true);
        $end_memory = memory_get_usage();
        $data = $this->performance_data[$label];

        $metrics = array(
            'execution_time' => $end_time - $data['start_time'],
            'memory_usage' => $end_memory - $data['start_memory'],
        );

        $this->log(
            sprintf('Performance metrics for %s: Time: %.4fs, Memory: %s', 
                $label,
                $metrics['execution_time'],
                size_format($metrics['memory_usage'])
            ),
            'info',
            $metrics
        );
    }

    /**
     * デバッグパネルのアセット読み込み
     */
    public function enqueue_debug_assets() {
        wp_enqueue_style(
            'wp-cross-post-debug',
            WP_CROSS_POST_PLUGIN_URL . 'includes/debug/assets/debug-panel.css',
            array(),
            WP_CROSS_POST_VERSION
        );

        wp_enqueue_script(
            'wp-cross-post-debug',
            WP_CROSS_POST_PLUGIN_URL . 'includes/debug/assets/debug-panel.js',
            array('jquery'),
            WP_CROSS_POST_VERSION,
            true
        );
    }

    /**
     * デバッグパネルの表示
     */
    public function render_debug_panel() {
        if (!current_user_can('manage_options')) {
            return;
        }

        include WP_CROSS_POST_PLUGIN_DIR . 'includes/debug/templates/debug-panel.php';
    }

    /**
     * ログを記録すべきかどうかを判定
     */
    private function should_log($level) {
        $levels = array(
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3
        );

        return isset($levels[$level]) && $levels[$level] >= $levels[$this->log_level];
    }

    /**
     * デバッグログを保存
     */
    private function save_debug_log($log_entry) {
        $logs = get_option('wp_cross_post_debug_logs', array());
        array_unshift($logs, $log_entry);
        
        // 最大1000件まで保存
        $logs = array_slice($logs, 0, 1000);
        
        update_option('wp_cross_post_debug_logs', $logs);
    }
} 