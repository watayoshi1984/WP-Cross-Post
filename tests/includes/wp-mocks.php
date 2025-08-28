<?php
/**
 * WordPressのテスト用関数モック
 */

// WordPressの定数を定義
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// 基本的なWordPress関数のモック
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return htmlspecialchars(strip_tags($str));
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        // モックのオプションデータ
        $mock_options = [
            'wp_cross_post_settings' => [
                'api_settings' => [
                    'timeout' => 30,
                    'retries' => 3,
                    'batch_size' => 10
                ],
                'sync_settings' => [
                    'parallel_sync' => false,
                    'async_sync' => false,
                    'rate_limit' => true
                ],
                'image_settings' => [
                    'sync_images' => true,
                    'max_image_size' => 5242880,
                    'image_quality' => 80
                ],
                'debug_settings' => [
                    'debug_mode' => false,
                    'log_level' => 'info'
                ],
                'cache_settings' => [
                    'enable_cache' => true,
                    'cache_duration' => 1800
                ],
                'security_settings' => [
                    'verify_ssl' => true,
                    'encrypt_credentials' => true
                ]
            ],
            'wp_cross_post_sites' => [],
            'wp_cross_post_taxonomies' => [],
            'wp_cross_post_error_notification_settings' => [
                'enabled' => false,
                'email' => 'admin@example.com',
                'threshold' => 'error'
            ]
        ];
        
        return isset($mock_options[$option]) ? $mock_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        // モックのオプション更新
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        // モックのオプション削除
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        // モックのトランジェントデータ
        static $transients = [];
        return isset($transients[$transient]) ? $transients[$transient] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        // モックのトランジェントデータ保存
        static $transients = [];
        $transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        // モックのトランジェントデータ削除
        static $transients = [];
        unset($transients[$transient]);
        return true;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        // モックのHTTPリクエスト
        return [
            'response' => [
                'code' => 200,
                'message' => 'OK'
            ],
            'body' => json_encode([
                'name' => 'Test Site',
                'description' => 'A test WordPress site'
            ])
        ];
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        // モックのHTTPリクエスト
        return [
            'response' => [
                'code' => 201,
                'message' => 'Created'
            ],
            'body' => json_encode([
                'id' => 123,
                'title' => 'Test Post',
                'content' => 'Test content'
            ])
        ];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? $response['response']['code'] : 0;
    }
}

if (!function_exists('wp_remote_retrieve_response_message')) {
    function wp_remote_retrieve_response_message($response) {
        return isset($response['response']['message']) ? $response['response']['message'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        return isset($response['headers']) ? $response['headers'] : [];
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header) {
        return isset($response['headers'][$header]) ? $response['headers'][$header] : '';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) {
                return;
            }
            
            $this->errors[$code][] = $message;
        }
        
        public function get_error_codes() {
            return array_keys($this->errors);
        }
        
        public function get_error_code() {
            $codes = $this->get_error_codes();
            return count($codes) > 0 ? $codes[0] : '';
        }
        
        public function get_error_messages($code = '') {
            if (empty($code)) {
                $all_messages = array();
                foreach ($this->errors as $code => $messages) {
                    $all_messages = array_merge($all_messages, $messages);
                }
                return $all_messages;
            }
            
            return isset($this->errors[$code]) ? $this->errors[$code] : array();
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            
            $messages = $this->get_error_messages($code);
            return count($messages) > 0 ? $messages[0] : '';
        }
        
        public function get_error_data($code = '') {
            return '';
        }
    }
}

if (!class_exists('stdClass')) {
    class stdClass {}
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = '') {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r =& $args;
        } else {
            wp_parse_str($args, $r);
        }
        
        if (is_array($defaults)) {
            return array_merge($defaults, $r);
        }
        
        return $r;
    }
}

if (!function_exists('wp_parse_str')) {
    function wp_parse_str($string, &$array) {
        parse_str($string, $array);
        
        // PHP 7.2以降では、parse_str()は配列のキーとして数値文字列を受け入れる
        // 以前のバージョンとの互換性を保つための処理
        if (PHP_VERSION_ID < 70200) {
            $array = _wp_translate_php_numeric_string_keys($array);
        }
    }
}

if (!function_exists('_wp_translate_php_numeric_string_keys')) {
    function _wp_translate_php_numeric_string_keys($array) {
        $new_array = array();
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $new_array[$key] = _wp_translate_php_numeric_string_keys($value);
            } else {
                $new_array[$key] = $value;
            }
        }
        
        return $new_array;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        // モックのメール送信
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        // モックのnonce生成
        return md5($action . microtime());
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        // モックのnonce検証
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        // モックの権限チェック
        return true;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) {
        // モックのAJAX nonceチェック
        return true;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        // モックのJSONレスポンス
        $response = array('success' => true);
        if (isset($data)) {
            $response['data'] = $data;
        }
        echo json_encode($response);
        exit;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) {
        // モックのJSONレスポンス
        $response = array('success' => false);
        if (isset($data)) {
            $response['data'] = $data;
        }
        echo json_encode($response);
        exit;
    }
}

if (!function_exists('get_post')) {
    function get_post($post, $output = OBJECT, $filter = 'raw') {
        // モックの投稿取得
        if (is_numeric($post)) {
            $post = new stdClass();
            $post->ID = $post;
            $post->post_title = 'Test Post ' . $post;
            $post->post_content = 'Test content for post ' . $post;
            $post->post_status = 'publish';
        }
        return $post;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr, $wp_error = false) {
        // モックの投稿作成
        return 123; // モックの投稿ID
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
        // モックの投稿メタデータ更新
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        // モックの投稿メタデータ取得
        $mock_meta = [
            '_wp_cross_post_sites' => ['site_test123'],
            '_wp_cross_post_sync_info' => [
                'site_test123' => [
                    'remote_post_id' => 456,
                    'sync_time' => '2025-01-01 12:00:00',
                    'status' => 'success'
                ]
            ]
        ];
        
        if (empty($key)) {
            return $mock_meta;
        }
        
        return isset($mock_meta[$key]) ? $mock_meta[$key] : ($single ? '' : []);
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
        // モックのスケジュールイベント
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = array()) {
        // モックのスケジュールイベントチェック
        return false;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = array()) {
        // モックのスケジュールイベントクリア
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function) {
        // モックのアクティベーションフック
        return true;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $function) {
        // モックのデアクティベーションフック
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        // モックのアクションフック
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        // モックのフィルターフック
        return true;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        // モックの翻訳関数
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        // モックのHTMLエスケープ
        return htmlspecialchars($text);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        // モックの属性エスケープ
        return htmlspecialchars($text);
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        // モックのプラグインディレクトリパス
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        // モックのプラグインディレクトリURL
        return 'http://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        // モックの管理画面URL
        return 'http://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        // モックのブログ情報
        $mock_info = [
            'version' => '6.5',
            'name' => 'Test Blog',
            'description' => 'Just another WordPress site'
        ];
        
        return isset($mock_info[$show]) ? $mock_info[$show] : '';
    }
}

if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0) {
        // モックのサイズフォーマット
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $decimals) . ' ' . $units[$pow];
    }
}

if (!function_exists('memory_get_usage')) {
    function memory_get_usage($real_usage = false) {
        // モックのメモリ使用量
        return 10485760; // 10MB
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        // モックの現在時間
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }
        return time();
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post($post_id, $force_delete = false) {
        // モックの投稿削除
        return true;
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args = null) {
        // モックの投稿取得
        return [];
    }
}