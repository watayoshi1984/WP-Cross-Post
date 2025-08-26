<?php
class WP_Cross_Post_API_Handler {
    private $debug_manager;
    private $min_php_version = '7.4.0';
    private $min_wp_version = '5.8.0';

    public function __construct() {
        $this->debug_manager = WP_Cross_Post_Debug_Manager::get_instance();
        
        // バージョンチェック
        $this->check_requirements();
        
        // レート制限対策のためのディレイを追加
        add_filter('pre_http_request', array($this, 'maybe_delay_request'), 10, 3);
        
        // REST APIフィルター
        add_filter('rest_authentication_errors', array($this, 'check_rest_api_access'));
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
                'error'
            );
        }

        global $wp_version;
        if (version_compare($wp_version, $this->min_wp_version, '<')) {
            $this->debug_manager->log(
                sprintf('WordPressバージョン要件を満たしていません。必要: %s、現在: %s',
                    $this->min_wp_version,
                    $wp_version
                ),
                'error'
            );
        }
    }

    /**
     * REST APIアクセスの制限
     */
    public function check_rest_api_access($access) {
        if (!is_user_logged_in()) {
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
            throw new Exception('無効なURL形式です。');
        }

        // 許可されたスキームのチェック
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            throw new Exception('無効なURLスキームです。');
        }

        // ホストのバリデーション
        if (!isset($parsed['host']) || !$this->is_valid_domain($parsed['host'])) {
            throw new Exception('無効なドメインです。');
        }

        return $this->normalize_url($url);
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
            return $url; // パース失敗時は元のURLを返す
        }

        // ホスト名をPunycodeに変換（日本語ドメイン対応）
        if (isset($parsed_url['host'])) {
            if (function_exists('idn_to_ascii')) {
                $parsed_url['host'] = idn_to_ascii(
                    $parsed_url['host'],
                    IDNA_NONTRANSITIONAL_TO_ASCII,
                    INTL_IDNA_VARIANT_UTS46
                );
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
        
        $this->debug_manager->log('URL正規化: ' . $url . ' -> ' . $normalized_url, 'debug');
        
        return $normalized_url;
    }

    /**
     * 公開用のURL正規化メソッド
     */
    public function normalize_site_url($url) {
        return $this->normalize_url($url);
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

        // レート制限の検出
        if ($status_code === 429) {
            $retry_after = isset($headers['retry-after']) ? intval($headers['retry-after']) : 60;
            $this->debug_manager->log(
                sprintf('レート制限を検出しました。%d秒後に再試行します。URL: %s', $retry_after, $url),
                'warning'
            );
            return new WP_Error('rate_limit', 'レート制限に達しました。', array(
                'retry_after' => $retry_after,
                'url' => $url
            ));
        }

        return $response;
    }

    /**
     * バックオフ戦略の実装
     */
    private function calculate_backoff($attempt) {
        // 指数バックオフ: 2^attempt * 1000ms
        $base_delay = 3000; // 基本遅延を3秒に増加
        $max_delay = 60000; // 最大遅延を60秒に増加
        $delay = min($base_delay * pow(2, $attempt), $max_delay);
        
        // ジッターを追加（0-20%）
        $jitter = rand(0, intval($delay * 0.2));
        return $delay + $jitter;
    }

    /**
     * リトライロジックの実装
     */
    private function retry_request($request_func, $args, $max_attempts = 5) { // 最大試行回数を5回に増加
        $attempt = 0;
        $last_error = null;

        while ($attempt < $max_attempts) {
            // リクエスト前に一定時間待機
            if ($attempt > 0) {
                $backoff = $this->calculate_backoff($attempt);
                $this->debug_manager->log(
                    sprintf('リクエスト前に%dms待機します（試行回数: %d/%d）', 
                        $backoff, 
                        $attempt + 1, 
                        $max_attempts
                    ),
                    'info'
                );
                usleep($backoff * 1000);
            }

            $response = call_user_func($request_func, $args);

            // レート制限のチェック
            if (is_wp_error($response) && $response->get_error_code() === 'rate_limit') {
                $retry_after = $response->get_error_data()['retry_after'];
                $this->debug_manager->log(
                    sprintf('レート制限による待機: %d秒', $retry_after),
                    'warning'
                );
                sleep($retry_after);
                $attempt++;
                continue;
            }

            // その他のエラーの場合
            if (is_wp_error($response)) {
                $last_error = $response;
                $attempt++;
                
                if ($attempt < $max_attempts) {
                    $this->debug_manager->log(
                        sprintf('リクエスト失敗。%d回目の試行。エラー: %s', 
                            $attempt + 1, 
                            $response->get_error_message()
                        ),
                        'warning'
                    );
                    continue;
                }
            }

            return $response;
        }

        return $last_error;
    }

    /**
     * メディアアップロードの最適化
     */
    public function upload_media($site_data, $file_path, $file_name) {
        try {
            if (!file_exists($file_path)) {
                throw new Exception('ファイルが見つかりません。');
            }

            // ファイルサイズのチェック
            $file_size = filesize($file_path);
            if ($file_size > 10 * MB_IN_BYTES) { // 10MB制限
                throw new Exception('ファイルサイズが大きすぎます。');
            }

            // ファイルタイプの検証
            $file_type = wp_check_filetype($file_name);
            if (!$file_type['type']) {
                throw new Exception('無効なファイルタイプです。');
            }

            // リクエストの準備
            $boundary = wp_generate_password(24);
            $headers = array(
                'Authorization' => 'Basic ' . base64_encode(
                    $this->sanitize_credential($site_data['username']) . ':' . 
                    $this->sanitize_credential($site_data['app_password'])
                ),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary
            );

            // ファイルデータの準備
            $payload = '';
            $payload .= '--' . $boundary;
            $payload .= "\r\n";
            $payload .= 'Content-Disposition: form-data; name="file"; filename="' . $file_name . '"' . "\r\n";
            $payload .= 'Content-Type: ' . $file_type['type'] . "\r\n\r\n";
            $payload .= file_get_contents($file_path);
            $payload .= "\r\n";
            $payload .= '--' . $boundary . '--';

            $args = array(
                'method' => 'POST',
                'timeout' => 60,
                'headers' => $headers,
                'body' => $payload,
                'sslverify' => $this->should_verify_ssl($site_data['url'])
            );

            // リトライロジックを使用してリクエストを実行
            $response = $this->retry_request(
                function($args) use ($site_data) {
                    return wp_remote_post($site_data['url'] . '/wp-json/wp/v2/media', $args);
                },
                $args
            );

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body) || !isset($body['source_url'])) {
                throw new Exception('メディアのアップロードに失敗しました。');
            }

            return $body;

        } catch (Exception $e) {
            $this->debug_manager->log('メディアアップロードエラー: ' . $e->getMessage(), 'error');
            return new WP_Error('media_upload_error', $e->getMessage());
        }
    }

    /**
     * リクエストの遅延処理
     */
    public function maybe_delay_request($pre, $parsed_args, $url) {
        static $last_request_time = 0;

        // WordPressのループバックリクエストの場合は処理をスキップ
        if (strpos($url, get_site_url()) !== false) {
            return $pre;
        }

        // 最後のリクエストから1秒以上経過していない場合は待機
        $current_time = microtime(true);
        $time_since_last_request = $current_time - $last_request_time;
        
        if ($time_since_last_request < 1.0) {
            $wait_time = (1.0 - $time_since_last_request) * 1000000; // マイクロ秒に変換
            usleep($wait_time);
            $this->debug_manager->log(
                sprintf('リクエスト間隔を調整: %.2f秒待機', 1.0 - $time_since_last_request),
                'debug'
            );
        }
        
        $last_request_time = microtime(true);
        return $pre;
    }

    public function test_connection($site_data) {
        try {
            // データのバリデーション
            if (empty($site_data['url']) || empty($site_data['username']) || empty($site_data['app_password'])) {
                throw new Exception('必須パラメータが不足しています。');
            }

            // URLの検証と正規化
            $site_data['url'] = $this->validate_and_normalize_url($site_data['url']);

            // 認証情報の検証
            if (!ctype_alnum(str_replace(['_', '-'], '', $site_data['username']))) {
                throw new Exception('ユーザー名に無効な文字が含まれています。');
            }

            // ヘッダーの準備
            $headers = array(
                'Authorization' => 'Basic ' . base64_encode(
                    $this->sanitize_credential($site_data['username']) . ':' . 
                    $this->sanitize_credential($site_data['app_password'])
                )
            );

            // 同一サイトへのリクエストの場合、追加のヘッダーを設定
            if (strpos($site_data['url'], $this->normalize_url(get_site_url())) !== false) {
                $headers['X-WP-Nonce'] = wp_create_nonce('wp_rest');
            }

            // SSL証明書の検証設定
            $ssl_verify = $this->should_verify_ssl($site_data['url']);

            $response = wp_remote_get($site_data['url'] . '/wp-json/', array(
                'timeout' => 30,
                'headers' => $headers,
                'sslverify' => $ssl_verify,
                'user-agent' => 'WP-Cross-Post/' . WP_CROSS_POST_VERSION
            ));

            // レスポンスの処理
            return $this->handle_api_response($response, 'connection_test');

        } catch (Exception $e) {
            $this->debug_manager->log('API接続テストエラー: ' . $e->getMessage(), 'error');
            return new WP_Error('api_connection_error', $e->getMessage());
        }
    }

    /**
     * SSL証明書の検証が必要かどうかを判断
     */
    private function should_verify_ssl($url) {
        // ローカル開発環境の場合は検証をスキップ
        if (strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {
            return false;
        }

        // 本番環境では常に検証を行う
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production') {
            return true;
        }

        // デフォルトは検証を行う
        return true;
    }

    /**
     * 認証情報のサニタイズ
     */
    private function sanitize_credential($credential) {
        // 基本的なサニタイズ
        $credential = sanitize_text_field($credential);
        
        // 危険な文字を除去
        $credential = preg_replace('/[^\w\-\.]/', '', $credential);
        
        return $credential;
    }

    /**
     * APIレスポンスの共通ハンドリング
     */
    private function handle_api_response($response, $context = '') {
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->debug_manager->log($context . ' エラー: ' . $error_message, 'error');
            return new WP_Error('api_error', $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        // レート制限のチェック
        if ($response_code === 429) {
            $this->debug_manager->log('レート制限に到達しました。', 'warning');
            $retry_after = wp_remote_retrieve_header($response, 'retry-after');
            $wait_time = $retry_after ? intval($retry_after) : 2;
            sleep($wait_time);
            return false; // 呼び出し元で再試行を判断
        }

        // その他のエラーコードの処理
        if ($response_code >= 400) {
            $error_message = sprintf(
                '%s失敗（HTTP %d）: %s',
                $context,
                $response_code,
                wp_remote_retrieve_response_message($response)
            );
            $this->debug_manager->log($error_message, 'error');
            return new WP_Error('api_error', $error_message);
        }

        return $response;
    }

    public function create_post($site_data, $post_data) {
        // URLを正規化
        $site_data['url'] = $this->normalize_url($site_data['url']);

        $this->debug_manager->log('投稿の同期を開始: ' . json_encode($post_data), 'info');

        try {
            // ヘッダーの準備
            $headers = array(
                'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                'Content-Type' => 'application/json'
            );

            // 同一サイトへのリクエストの場合、追加のヘッダーを設定
            if (strpos($site_data['url'], $this->normalize_url(get_site_url())) !== false) {
                $headers['X-WP-Nonce'] = wp_create_nonce('wp_rest');
            }

            // カテゴリーとタグの事前作成
            $categories = $this->ensure_taxonomies($site_data, $post_data['categories'], 'categories');
            $tags = $this->ensure_taxonomies($site_data, $post_data['tags'], 'tags');

            // 投稿データの整形
            $formatted_post_data = array(
                'title' => array(
                    'raw' => $post_data['title'],
                    'rendered' => $post_data['title']
                ),
                'content' => array(
                    'raw' => $post_data['content'],
                    'rendered' => $post_data['content']
                ),
                'status' => $post_data['status'],
                'date' => $post_data['date'],
                'date_gmt' => $post_data['date_gmt'],
                'categories' => $categories,
                'tags' => $tags,
                'slug' => $post_data['slug'],
                'meta' => $post_data['meta'],
                'comment_status' => isset($post_data['comment_status']) ? $post_data['comment_status'] : 'open',
                'ping_status' => isset($post_data['ping_status']) ? $post_data['ping_status'] : 'open',
                'format' => isset($post_data['format']) ? $post_data['format'] : 'standard'
            );

            // アイキャッチ画像がある場合
            if (isset($post_data['featured_media']) && is_numeric($post_data['featured_media'])) {
                $formatted_post_data['featured_media'] = intval($post_data['featured_media']);
            }

            // excerptが設定されている場合
            if (isset($post_data['excerpt']) && !empty($post_data['excerpt'])) {
                if (is_array($post_data['excerpt'])) {
                    $formatted_post_data['excerpt'] = $post_data['excerpt'];
                } else {
                    $formatted_post_data['excerpt'] = array(
                        'raw' => $post_data['excerpt'],
                        'rendered' => $post_data['excerpt']
                    );
                }
            }

            $this->debug_manager->log('APIリクエストを送信: ' . json_encode($formatted_post_data), 'debug');

            // POSTリクエストの実行
            $response = wp_remote_post(
                $site_data['url'] . '/wp-json/wp/v2/posts',
                array(
                    'timeout' => 60,
                    'headers' => $headers,
                    'body' => json_encode($formatted_post_data),
                    'sslverify' => $this->should_verify_ssl($site_data['url'])
                )
            );

            // レスポンスの処理
            if (is_wp_error($response)) {
                throw new Exception('API request failed: ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 429) {
                // レート制限に達した場合、少し待ってから再試行
                sleep(2);
                return $this->create_post($site_data, $post_data);
            }

            if ($response_code >= 400) {
                $error_body = wp_remote_retrieve_body($response);
                $error_data = json_decode($error_body, true);
                throw new Exception(
                    sprintf(
                        '投稿の作成に失敗しました（HTTP %d）: %s',
                        $response_code,
                        isset($error_data['message']) ? $error_data['message'] : wp_remote_retrieve_response_message($response)
                    )
                );
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (empty($data) || !isset($data['id'])) {
                throw new Exception('無効なAPIレスポンス');
            }

            // アイキャッチ画像の設定（別ステップで処理）
            if (isset($post_data['featured_media']) && is_array($post_data['featured_media']) && isset($post_data['featured_media']['url'])) {
                $this->sync_featured_image($site_data, $data['id'], $post_data['featured_media']);
            }

            // メタデータの同期
            if (!empty($post_data['meta'])) {
                $this->sync_post_meta($site_data, $data['id'], $post_data['meta']);
            }

            $this->debug_manager->log(sprintf(
                '投稿の同期が完了しました。投稿ID: %d、スラッグ: %s',
                $data['id'],
                $data['slug']
            ), 'info');

            return $data['id'];

        } catch (Exception $e) {
            $this->debug_manager->log('投稿の同期に失敗: ' . $e->getMessage(), 'error');
            return new WP_Error('sync_failed', $e->getMessage());
        }
    }

    /**
     * 既存の投稿を更新
     *
     * @param array $site_data サイト情報
     * @param int $post_id 更新する投稿ID
     * @param array $post_data 更新するデータ
     * @return int|WP_Error 成功時は投稿ID、失敗時はエラー
     */
    public function update_post($site_data, $post_id, $post_data) {
        // URLを正規化
        $site_data['url'] = $this->normalize_url($site_data['url']);

        $this->debug_manager->log('投稿の更新を開始: ID ' . $post_id, 'info');
        $this->debug_manager->log('更新データ: ' . json_encode($post_data), 'debug');

        try {
            // ヘッダーの準備
            $headers = array(
                'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                'Content-Type' => 'application/json'
            );

            // 同一サイトへのリクエストの場合、追加のヘッダーを設定
            if (strpos($site_data['url'], $this->normalize_url(get_site_url())) !== false) {
                $headers['X-WP-Nonce'] = wp_create_nonce('wp_rest');
            }

            // POSTリクエストの実行
            $response = wp_remote_post(
                $site_data['url'] . '/wp-json/wp/v2/posts/' . $post_id,
                array(
                    'method' => 'POST',
                    'timeout' => 30,
                    'headers' => $headers,
                    'body' => json_encode($post_data),
                    'sslverify' => $this->should_verify_ssl($site_data['url'])
                )
            );

            // レスポンスの処理
            if (is_wp_error($response)) {
                throw new Exception('API request failed: ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 429) {
                // レート制限に達した場合、少し待ってから再試行
                sleep(2);
                return $this->update_post($site_data, $post_id, $post_data);
            }

            if ($response_code < 200 || $response_code >= 300) {
                $error_body = wp_remote_retrieve_body($response);
                $error_data = json_decode($error_body, true);
                throw new Exception(
                    sprintf(
                        '投稿の更新に失敗しました（HTTP %d）: %s',
                        $response_code,
                        isset($error_data['message']) ? $error_data['message'] : wp_remote_retrieve_response_message($response)
                    )
                );
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (empty($data) || !isset($data['id'])) {
                throw new Exception('無効なAPIレスポンス');
            }

            $this->debug_manager->log(sprintf(
                '投稿の更新が完了しました。投稿ID: %d',
                $data['id']
            ), 'info');

            return $data['id'];

        } catch (Exception $e) {
            $this->debug_manager->log('投稿の更新に失敗: ' . $e->getMessage(), 'error');
            return new WP_Error('update_failed', $e->getMessage());
        }
    }

    private function sync_post_meta($site_data, $post_id, $meta_data) {
        foreach ($meta_data as $key => $value) {
            $response = wp_remote_post(
                $site_data['url'] . '/wp-json/wp/v2/posts/' . $post_id . '/meta',
                array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode(array(
                        'key' => $key,
                        'value' => $value
                    ))
                )
            );

            if (is_wp_error($response)) {
                $this->debug_manager->log(
                    sprintf('メタデータの同期に失敗（%s）: %s', $key, $response->get_error_message()),
                    'error'
                );
            }
        }
    }

    /**
     * スラッグを準備
     */
    private function prepare_slug($site_data, $slug) {
        // スラッグが空の場合は一意のスラッグを生成
        if (empty($slug)) {
            $slug = uniqid('post-');
            $this->debug_manager->log('空のスラッグに対して一意のスラッグを生成: ' . $slug, 'info');
            return $slug;
        }

        // スラッグをサニタイズ
        $slug = sanitize_title($slug);
        
        // スラッグの重複をチェック
        $slug_availability = $this->check_slug_availability($site_data, $slug);
        if ($slug_availability !== true) {
            return $slug_availability;
        }

        return $slug;
    }

    /**
     * スラッグの重複をチェック
     */
    private function check_slug_availability($site_data, $slug) {
        $response = wp_remote_get(
            add_query_arg(
                array('slug' => $slug),
                $site_data['url'] . '/wp-json/wp/v2/posts'
            ),
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password'])
                )
            )
        );

        if (is_wp_error($response)) {
            $this->debug_manager->log('スラッグの確認に失敗: ' . $response->get_error_message(), 'warning');
            return $slug;
        }

        $existing_posts = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($existing_posts)) {
            // スラッグが重複している場合、一意のスラッグを生成
            $new_slug = $slug . '-' . substr(uniqid(), -5);
            $this->debug_manager->log(
                sprintf('スラッグ "%s" は既に使用されています。新しいスラッグ: %s', $slug, $new_slug),
                'warning'
            );
            return $new_slug;
        }

        return true;
    }

    private function ensure_taxonomies($site_data, $terms, $taxonomy_type) {
        if (empty($terms)) {
            return array();
        }

        $this->debug_manager->log(
            sprintf('%sの同期を開始: %s', $taxonomy_type, json_encode($terms)),
            'info'  // debugからinfoに変更して可視性を高める
        );

        $taxonomy_endpoint = $taxonomy_type === 'categories' ? 'categories' : 'tags';
        $term_ids = array();

        foreach ($terms as $term) {
            // ターム名が指定されていることを確認
            if (empty($term['name'])) {
                $this->debug_manager->log('タームの名前が指定されていません: ' . json_encode($term), 'warning');
                continue;
            }

            // スラッグでの検索を優先
            $search_params = array();
            if (isset($term['slug']) && !empty($term['slug'])) {
                $search_params['slug'] = sanitize_title($term['slug']);
                $this->debug_manager->log('スラッグで検索: ' . $search_params['slug'], 'debug');
            } else {
                $search_params['search'] = urlencode($term['name']);
                $this->debug_manager->log('名前で検索: ' . $search_params['search'], 'debug');
            }

            $search_url = add_query_arg(
                $search_params,
                rtrim($site_data['url'], '/') . '/wp-json/wp/v2/' . $taxonomy_endpoint
            );

            $this->debug_manager->log('タクソノミー検索URL: ' . $search_url, 'debug');

            $search_response = wp_remote_get($search_url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password'])
                ),
                'timeout' => 30
            ));

            // レスポンスの処理
            if (is_wp_error($search_response)) {
                $this->debug_manager->log(
                    sprintf('%sの検索に失敗: %s', $taxonomy_type, $search_response->get_error_message()),
                    'error'
                );
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($search_response);
            if ($response_code !== 200) {
                $this->debug_manager->log(
                    sprintf('%sの検索に失敗（HTTP %d）: %s', 
                        $taxonomy_type, 
                        $response_code,
                        wp_remote_retrieve_body($search_response)
                    ),
                    'error'
                );
                continue;
            }

            $existing_terms = json_decode(wp_remote_retrieve_body($search_response), true);
            $this->debug_manager->log('検索結果: ' . json_encode($existing_terms), 'debug');

            // 既存のタームが見つかった場合
            if (!empty($existing_terms)) {
                $term_ids[] = $existing_terms[0]['id'];
                $this->debug_manager->log(
                    sprintf('既存の%sを使用: %s (ID: %d)', 
                        $taxonomy_type, 
                        $term['name'], 
                        $existing_terms[0]['id']
                    ),
                    'info'
                );
                continue;
            }

            // 存在しない場合は新規作成
            $this->debug_manager->log('新規タームを作成します: ' . $term['name'], 'info');
            
            $new_term_data = array(
                'name' => $term['name']
            );

            // スラッグが指定されている場合は使用
            if (isset($term['slug']) && !empty($term['slug'])) {
                $new_term_data['slug'] = sanitize_title($term['slug']);
            }

            // 説明が指定されている場合は使用
            if (isset($term['description']) && !empty($term['description'])) {
                $new_term_data['description'] = $term['description'];
            }

            // 親カテゴリーの処理（カテゴリーの場合のみ）
            if ($taxonomy_type === 'categories' && !empty($term['parent'])) {
                $parent_id = $this->ensure_parent_category($site_data, $term['parent']);
                if ($parent_id && !is_wp_error($parent_id)) {
                    $new_term_data['parent'] = $parent_id;
                    $this->debug_manager->log('親カテゴリーを設定: ID ' . $parent_id, 'debug');
                }
            }

            $this->debug_manager->log('作成するタームデータ: ' . json_encode($new_term_data), 'debug');

            $create_response = wp_remote_post(
                rtrim($site_data['url'], '/') . '/wp-json/wp/v2/' . $taxonomy_endpoint,
                array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode($new_term_data),
                    'timeout' => 30
                )
            );

            // レスポンスの処理
            if (is_wp_error($create_response)) {
                $this->debug_manager->log(
                    sprintf('新規%s作成に失敗: %s', $taxonomy_type, $create_response->get_error_message()),
                    'error'
                );
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($create_response);
            if ($response_code < 200 || $response_code >= 300) {
                $this->debug_manager->log(
                    sprintf('新規%s作成に失敗（HTTP %d）: %s', 
                        $taxonomy_type, 
                        $response_code,
                        wp_remote_retrieve_body($create_response)
                    ),
                    'error'
                );
                continue;
            }

            $new_term = json_decode(wp_remote_retrieve_body($create_response), true);
            if (!empty($new_term) && isset($new_term['id'])) {
                $term_ids[] = $new_term['id'];
                $this->debug_manager->log(
                    sprintf('新規%sを作成成功: %s (ID: %d)', 
                        $taxonomy_type, 
                        $new_term_data['name'], 
                        $new_term['id']
                    ),
                    'info'
                );
            } else {
                $this->debug_manager->log(
                    '新規ターム作成後のIDが取得できません: ' . wp_remote_retrieve_body($create_response),
                    'error'
                );
            }
        }

        $this->debug_manager->log(
            sprintf('%sの同期が完了: %d件', $taxonomy_type, count($term_ids)),
            'info'
        );
        $this->debug_manager->log('最終的なターム ID リスト: ' . json_encode($term_ids), 'debug');

        return $term_ids;
    }

    /**
     * 親カテゴリーの存在確認と作成
     */
    private function ensure_parent_category($site_data, $parent_id) {
        $this->debug_manager->log('親カテゴリーの確認: ID ' . $parent_id, 'debug');
        
        // ローカルの親カテゴリー情報を取得
        $parent_term = get_term($parent_id, 'category');
        if (is_wp_error($parent_term) || !$parent_term) {
            $this->debug_manager->log('親カテゴリーの取得に失敗: ' . ($parent_term->get_error_message() ?? 'カテゴリーが見つかりません'), 'error');
            return false;
        }
        
        // リモートサイトで親カテゴリーをチェック／作成
        $parent_data = array(
            'name' => $parent_term->name,
            'slug' => $parent_term->slug,
            'description' => $parent_term->description
        );
        
        if ($parent_term->parent > 0) {
            // 親カテゴリーに親がある場合は再帰的に処理
            $grandparent_id = $this->ensure_parent_category($site_data, $parent_term->parent);
            if ($grandparent_id) {
                $parent_data['parent'] = $grandparent_id;
            }
        }
        
        // 親カテゴリーを検索
        $search_response = wp_remote_get(
            add_query_arg(
                array('slug' => $parent_term->slug),
                rtrim($site_data['url'], '/') . '/wp-json/wp/v2/categories'
            ),
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password'])
                ),
                'timeout' => 30
            )
        );
        
        if (is_wp_error($search_response)) {
            $this->debug_manager->log('親カテゴリーの検索に失敗: ' . $search_response->get_error_message(), 'error');
            return false;
        }
        
        $existing_parents = json_decode(wp_remote_retrieve_body($search_response), true);
        
        // 親カテゴリーが見つかった場合はそのIDを返す
        if (!empty($existing_parents)) {
            $remote_parent_id = $existing_parents[0]['id'];
            $this->debug_manager->log('既存の親カテゴリーを使用: ' . $parent_term->name . ' (リモートID: ' . $remote_parent_id . ')', 'debug');
            return $remote_parent_id;
        }
        
        // 親カテゴリーが見つからない場合は作成
        $this->debug_manager->log('親カテゴリーを作成: ' . $parent_term->name, 'info');
        
        $create_response = wp_remote_post(
            rtrim($site_data['url'], '/') . '/wp-json/wp/v2/categories',
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($parent_data),
                'timeout' => 30
            )
        );
        
        if (is_wp_error($create_response)) {
            $this->debug_manager->log('親カテゴリーの作成に失敗: ' . $create_response->get_error_message(), 'error');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($create_response);
        if ($response_code < 200 || $response_code >= 300) {
            $this->debug_manager->log(
                '親カテゴリーの作成に失敗（HTTP ' . $response_code . '）: ' . wp_remote_retrieve_body($create_response),
                'error'
            );
            return false;
        }
        
        $new_parent = json_decode(wp_remote_retrieve_body($create_response), true);
        
        if (!empty($new_parent) && isset($new_parent['id'])) {
            $remote_parent_id = $new_parent['id'];
            $this->debug_manager->log('親カテゴリーを作成成功: ' . $parent_term->name . ' (リモートID: ' . $remote_parent_id . ')', 'info');
            return $remote_parent_id;
        }
        
        $this->debug_manager->log('親カテゴリー作成後のIDが取得できません', 'error');
        return false;
    }

    /**
     * アイキャッチ画像を同期する
     */
    private function sync_featured_image($site_data, $post_id, $image_data) {
        $this->debug_manager->log('アイキャッチ画像の同期: ' . json_encode($image_data), 'info');
        
        try {
            // 画像をアップロード
            $temp_file = download_url($image_data['url']);
            if (is_wp_error($temp_file)) {
                throw new Exception('画像のダウンロードに失敗: ' . $temp_file->get_error_message());
            }
            
            $file_name = basename($image_data['url']);
            
            // メディアをアップロード
            $media_id = $this->upload_media($site_data, $temp_file, $file_name);
            
            // 一時ファイルを削除
            @unlink($temp_file);
            
            if (is_wp_error($media_id)) {
                throw new Exception('画像のアップロードに失敗: ' . $media_id->get_error_message());
            }
            
            // 投稿にアイキャッチ画像を設定
            $update_response = wp_remote_post(
                $site_data['url'] . '/wp-json/wp/v2/posts/' . $post_id,
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password']),
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode(array(
                        'featured_media' => $media_id
                    ))
                )
            );
            
            if (is_wp_error($update_response)) {
                throw new Exception('アイキャッチ画像の設定に失敗: ' . $update_response->get_error_message());
            }
            
            $this->debug_manager->log('アイキャッチ画像の設定完了: メディアID ' . $media_id, 'info');
            return $media_id;
            
        } catch (Exception $e) {
            $this->debug_manager->log('アイキャッチ画像の同期に失敗: ' . $e->getMessage(), 'error');
            return new WP_Error('featured_image_sync_failed', $e->getMessage());
        }
    }

    public function handle_add_site() {
        check_ajax_referer('wp_cross_post_add_site', 'nonce');

        $required = ['site_name', 'site_url', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error([
                    'message' => 'すべての必須項目を入力してください',
                    'field' => $field
                ]);
            }
        }

        $new_site = [
            'id' => uniqid(),
            'name' => sanitize_text_field($_POST['site_name']),
            'url' => esc_url_raw($_POST['site_url']),
            'username' => sanitize_user($_POST['username']),
            'password' => $_POST['password'] // 暗号化が必要
        ];

        $config = WP_Cross_Post_Config::get_config();
        $config['sub_sites'][] = $new_site;
        WP_Cross_Post_Config::update_config($config);

        wp_send_json_success([
            'message' => 'サイトを追加しました',
            'site' => $new_site
        ]);
    }

    // 拡張APIハンドリング
    public function handle_sync_request(WP_REST_Request $request) {
        $params = $request->get_params();
        
        try {
            $post_id = $params['post_id'];
            $sync_engine = new WP_Cross_Post_Sync_Engine();
            $results = $sync_engine->sync_post($post_id);
            
            return new WP_REST_Response([
                'success' => true,
                'synced_sites' => count($results),
                'details' => $results
            ]);
            
        } catch (Exception $e) {
            return new WP_Error(
                'sync_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    // カテゴリー・タグ同期エンドポイント
    public function handle_sync_taxonomies() {
        $taxonomies = ['category', 'post_tag'];
        $results = [];
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            $results[$taxonomy] = $this->sync_to_all_sites($terms);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'synced_taxonomies' => $results
        ]);
    }

    /**
     * URLを正規化する静的メソッド
     * 
     * @param string $url 正規化するURL
     * @return string 正規化されたURL
     */
    public static function normalizeUrl($url) {
        if (empty($url)) {
            return '';
        }
        return rtrim($url, '/') . '/';
    }

    /**
     * メディアIDからURLを取得
     *
     * @param int $media_id メディアID
     * @return string|WP_Error メディアのURL、またはエラー
     */
    public function get_media_url($media_id) {
        try {
            $this->debug_manager->log('メディアURLの取得を開始: ID ' . $media_id, 'info');

            // メディア情報の取得
            $media = get_post($media_id);
            if (!$media || $media->post_type !== 'attachment') {
                throw new Exception('無効なメディアID');
            }

            // メディアURLの取得
            $media_url = wp_get_attachment_url($media_id);
            if (!$media_url) {
                throw new Exception('メディアURLの取得に失敗');
            }

            $this->debug_manager->log('メディアURLの取得が完了: ' . $media_url, 'info');
            return $media_url;

        } catch (Exception $e) {
            $this->debug_manager->log('メディアURLの取得に失敗: ' . $e->getMessage(), 'error');
            return new WP_Error('media_url_error', $e->getMessage());
        }
    }
}
