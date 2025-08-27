<?php
/**
 * WP Cross Post レート制限マネージャー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-manager.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-rate-limit-manager.php';

/**
 * WP Cross Post レート制限マネージャークラス
 *
 * APIレート制限対策を管理します。
 */
class WP_Cross_Post_Rate_Limit_Manager implements WP_Cross_Post_Rate_Limit_Manager_Interface {

    /**
     * インスタンス
     *
     * @var WP_Cross_Post_Rate_Limit_Manager|null
     */
    private static $instance = null;

    /**
     * デバッグマネージャー
     *
     * @var WP_Cross_Post_Debug_Manager
     */
    private $debug_manager;

    /**
     * エラーマネージャー
     *
     * @var WP_Cross_Post_Error_Manager
     */
    private $error_manager;

    /**
     * レート制限情報
     *
     * @var array
     */
    private $rate_limit_info = array();

    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Rate_Limit_Manager
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
     * @param WP_Cross_Post_Error_Manager $error_manager エラーマネージャー
     */
    public function set_dependencies($debug_manager, $error_manager) {
        $this->debug_manager = $debug_manager;
        $this->error_manager = $error_manager;
    }

    /**
     * レート制限のチェックと待機
     *
     * @param string $site_url サイトURL
     * @return bool|WP_Error 待機が必要な場合はtrue、エラーの場合はWP_Error
     */
    public function check_and_wait_for_rate_limit($site_url) {
        $host = parse_url($site_url, PHP_URL_HOST);
        if (!$host) {
            return $this->error_manager->handle_general_error('無効なサイトURLです。', 'invalid_url');
        }

        // レート制限情報の取得
        if (isset($this->rate_limit_info[$host])) {
            $limit_info = $this->rate_limit_info[$host];
            $current_time = time();
            
            // レート制限期間内であれば待機
            if ($current_time < $limit_info['reset_time']) {
                $wait_time = $limit_info['reset_time'] - $current_time;
                $this->debug_manager->log(sprintf(
                    'レート制限待機中: %d秒待機します',
                    $wait_time
                ), 'info', array(
                    'site_url' => $site_url,
                    'wait_time' => $wait_time
                ));
                
                sleep($wait_time);
                return true;
            }
        }
        
        return false;
    }

    /**
     * レート制限情報の更新
     *
     * @param string $site_url サイトURL
     * @param int $retry_after リトライ待機時間（秒）
     */
    public function update_rate_limit_info($site_url, $retry_after) {
        $host = parse_url($site_url, PHP_URL_HOST);
        if (!$host) {
            return;
        }

        $this->rate_limit_info[$host] = array(
            'reset_time' => time() + $retry_after,
            'retry_after' => $retry_after
        );

        $this->debug_manager->log(sprintf(
            'レート制限情報を更新: %s（%d秒後にリセット）',
            $host,
            $retry_after
        ), 'info', array(
            'site_url' => $site_url,
            'retry_after' => $retry_after,
            'reset_time' => $this->rate_limit_info[$host]['reset_time']
        ));
    }

    /**
     * レート制限の処理
     *
     * @param string $site_url サイトURL
     * @param WP_Error $response APIレスポンス
     * @return WP_Error 処理済みのエラーオブジェクト
     */
    public function handle_rate_limit($site_url, $response) {
        if (is_wp_error($response)) {
            // WP_Errorオブジェクトからレート制限情報を取得
            $error_data = $response->get_error_data();
            if (isset($error_data['retry_after'])) {
                $retry_after = intval($error_data['retry_after']);
                $this->update_rate_limit_info($site_url, $retry_after);
                
                // 待機時間をエラーデータに追加
                $error_data['wait_time'] = $retry_after;
                return new WP_Error($response->get_error_code(), $response->get_error_message(), $error_data);
            }
        } elseif (is_array($response)) {
            // 配列レスポンスからレート制限情報を取得
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                $retry_after = $retry_after ? intval($retry_after) : 60; // デフォルト60秒
                
                $this->update_rate_limit_info($site_url, $retry_after);
                
                return $this->error_manager->handle_general_error(
                    sprintf('レート制限に到達しました。%d秒後に再試行してください。', $retry_after),
                    'rate_limit'
                );
            }
        }
        
        return $response;
    }
}