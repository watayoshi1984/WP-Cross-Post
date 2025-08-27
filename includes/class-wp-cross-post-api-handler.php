/**
 * WP Cross Post APIハンドラー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-handler.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-api-handler.php';

/**
 * WP Cross Post APIハンドラークラス
 *
 * API通信処理を管理します。
 */
class WP_Cross_Post_API_Handler implements WP_Cross_Post_API_Handler_Interface {

    private $debug_manager;
    private $min_php_version = '7.4.0';
    private $min_wp_version = '6.5.0';
    private $auth_manager;
    private $error_manager;
    private $rate_limit_manager;

    public function __construct($debug_manager, $auth_manager, $error_manager, $rate_limit_manager) {
        $this->debug_manager = $debug_manager;
        $this->auth_manager = $auth_manager;
        $this->error_manager = $error_manager;
        $this->rate_limit_manager = $rate_limit_manager;
        
        // バージョンチェック
        $this->check_requirements();
        
        // レート制限対策のためのディレイを追加
        add_filter('pre_http_request', array($this, 'maybe_delay_request'), 10, 3);
        
        // REST APIフィルター
        add_filter('rest_authentication_errors', array($this, 'check_rest_api_access'));
    }

    /**
     * サイトへの接続テスト
     */
    public function test_connection($site_data) {
        // レート制限のチェックと待機
        $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($site_data['url']);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }
        
        // WordPress 5.6以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        $this->debug_manager->log('サイトへの接続テストを開始', 'info', array(
            'site_url' => $site_data['url']
        ));
        
        $response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/users/me', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth_header
            )
        ));

        // レート制限の処理
        $response = $this->rate_limit_manager->handle_rate_limit($site_data['url'], $response);
        $result = $this->error_manager->handle_api_error($response, '接続テスト');
        
        $this->debug_manager->log('サイトへの接続テストを完了', 'info', array(
            'site_url' => $site_data['url'],
            'success' => !is_wp_error($result)
        ));
        
        return $result;
    }

    /**
     * システム要件のチェック
     */
    private function check_requirements() {
        if (version_compare(PHP_VERSION, $this->min_php_version, '<')) {
            $this->debug_manager->log(
                sprintf('PHPバージョン要件を満たしていません。必要: %s、現在: %s', 
                    $this->min_php_version, 
                    PHP_VERSION
                ),
                'error',
                array(
                    'required_version' => $this->min_php_version,
                    'current_version' => PHP_VERSION
                )
            );
        }

        global $wp_version;
        if (version_compare($wp_version, $this->min_wp_version, '<')) {
            $this->debug_manager->log(
                sprintf('WordPressバージョン要件を満たしていません。必要: %s、現在: %s',
                    $this->min_wp_version,
                    $wp_version
                ),
                'error',
                array(
                    'required_version' => $this->min_wp_version,
                    'current_version' => $wp_version
                )
            );
        }
    }

    /**
     * REST APIアクセスの制限
     */
    public function check_rest_api_access($access) {
        if (!is_user_logged_in()) {
            $this->debug_manager->log('認証されていないREST APIアクセスを検出', 'warning');
            return new WP_Error(
                'rest_not_logged_in',
                '認証されていないアクセスは許可されていません。',
                array('status' => 401)
            );
        }
        return $access;
    }

    /**
     * URLのバリデーションと正規化
     */
    private function validate_and_normalize_url($url) {
        // 基本的なURLバリデーション
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->debug_manager->log('無効なURL形式', 'error', array(
                'url' => $url
            ));
            throw new Exception('無効なURL形式です。');
        }

        // 許可されたスキームのチェック
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            $this->debug_manager->log('無効なURLスキーム', 'error', array(
                'url' => $url,
                'scheme' => isset($parsed['scheme']) ? $parsed['scheme'] : 'none'
            ));
            throw new Exception('無効なURLスキームです。');
        }

        // ホストのバリデーション
        if (!isset($parsed['host']) || !$this->is_valid_domain($parsed['host'])) {
            $this->debug_manager->log('無効なドメイン', 'error', array(
                'url' => $url,
                'host' => isset($parsed['host']) ? $parsed['host'] : 'none'
            ));
            throw new Exception('無効なドメインです。');
        }

        $normalized_url = $this->normalize_url($url);
        $this->debug_manager->log('URLを正規化', 'debug', array(
            'original_url' => $url,
            'normalized_url' => $normalized_url
        ));
        
        return $normalized_url;
    }

    /**
     * ドメインの妥当性チェック
     */
    private function is_valid_domain($domain) {
        // 基本的なドメインバリデーション
        if (empty($domain)) {
            return false;
        }

        // 禁止文字のチェック
        if (preg_match('/[^a-zA-Z0-9.-]/', $domain)) {
            // 日本語ドメインの場合はPunycodeに変換してチェック
            if (function_exists('idn_to_ascii')) {
                $domain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($domain === false) {
                    return false;
                }
                return !preg_match('/[^a-zA-Z0-9.-]/', $domain);
            }
            return false;
        }

        return true;
    }

    /**
     * URLを正規化して安全な形式に変換
     */
    private function normalize_url($url) {
        // URLが既にパーセントエンコードされている場合は一旦デコード
        $decoded_url = urldecode($url);
        
        // URLをパースして各部分を取得
        $parsed_url = parse_url($decoded_url);
        if (!$parsed_url) {
            $this->debug_manager->log('URLのパースに失敗', 'warning', array(
                'url' => $url
            ));
            return $url; // パース失敗時は元のURLを返す
        }

        // ホスト名をPunycodeに変換（日本語ドメイン対応）
        if (isset($parsed_url['host'])) {
            if (function_exists('idn_to_ascii')) {
                $original_host = $parsed_url['host'];
                $parsed_url['host'] = idn_to_ascii(
                    $parsed_url['host'],
                    IDNA_NONTRANSITIONAL_TO_ASCII,
                    INTL_IDNA_VARIANT_UTS46
                );
                if ($original_host !== $parsed_url['host']) {
                    $this->debug_manager->log('ホスト名をPunycodeに変換', 'debug', array(
                        'original_host' => $original_host,
                        'punycode_host' => $parsed_url['host']
                    ));
                }
            }
        }

        // パスが存在する場合、適切にエンコード
        if (isset($parsed_url['path'])) {
            // パスの各セグメントを個別にエンコード
            $path_segments = explode('/', $parsed_url['path']);
            $encoded_segments = array_map(function($segment) {
                return rawurlencode($segment);
            }, $path_segments);
            $parsed_url['path'] = implode('/', $encoded_segments);
        }

        // クエリ文字列が存在する場合、適切にエンコード
        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            $parsed_url['query'] = http_build_query($query_params);
        }

        // URLを再構築
        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        $normalized_url = $scheme . $host . $port . $path . $query . $fragment;
        
        $this->debug_manager->log('URL正規化: ' . $url . ' -> ' . $normalized_url, 'debug', array(
            'original_url' => $url,
            'normalized_url' => $normalized_url
        ));
        
        return $normalized_url;
    }

    /**
     * 公開用のURL正規化メソッド
     */
    public function normalize_site_url($url) {
        try {
            return $this->validate_and_normalize_url($url);
        } catch (Exception $e) {
            $this->debug_manager->log('URL正規化に失敗: ' . $e->getMessage(), 'error', array(
                'url' => $url,
                'exception' => $e->getMessage()
            ));
            return $url;
        }
    }

    /**
     * レート制限の検出と対応
     */
    private function handle_rate_limit($response, $url) {
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        
        // レート制限のチェック
        if ($status_code === 429) {
            $retry_after = isset($headers['retry-after']) ? $headers['retry-after'] : 2;
            $this->debug_manager->log('レート制限を検出', 'warning', array(
                'url' => $url,
                'retry_after' => $retry_after
            ));
            sleep($retry_after);
            return true;
        }
        
        return false;
    }

    /**
     * レート制限によるリクエスト遅延
     */
    public function maybe_delay_request($preempt, $r, $url) {
        // 直近のレート制限時間をチェック
        $last_request_time = get_transient('wp_cross_post_last_request_time');
        $current_time = microtime(true);
        
        if ($last_request_time && ($current_time - $last_request_time) < 1) {
            $delay = 1 - ($current_time - $last_request_time);
            $this->debug_manager->log('リクエストを遅延', 'debug', array(
                'url' => $url,
                'delay' => $delay
            ));
            usleep($delay * 1000000); // マイクロ秒で待機
        }
        
        set_transient('wp_cross_post_last_request_time', $current_time, 10);
        return $preempt;
    }
}