<?php
/**
 * WP Cross Post APIハンドラー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-api-handler.php';

class WP_Cross_Post_API_Handler implements WP_Cross_Post_API_Handler_Interface {
    private $debug_manager;
    private $auth_manager;
    private $error_manager;
    private $rate_limit_manager;
    private $min_php_version = '7.4.0';
    private $min_wp_version = '6.5.0';

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
    public function normalize_site_url($url) {
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
     * URLの正規化
     */
    private function normalize_url($url) {
        // 末尾のスラッシュを削除
        $url = rtrim($url, '/');
        
        // 小文字に変換
        $url = strtolower($url);
        
        return $url;
    }

    /**
     * ドメインのバリデーション
     */
    private function is_valid_domain($domain) {
        // 基本的なドメインバリデーション
        if (!preg_match('/^[a-z0-9\-\.]+$/', $domain)) {
            return false;
        }
        
        // トップレベルドメインのチェック
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return false;
        }
        
        // 各パートの長さをチェック
        foreach ($parts as $part) {
            if (strlen($part) == 0 || strlen($part) > 63) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * レート制限に基づくリクエスト遅延
     */
    public function maybe_delay_request($preempt, $r, $url) {
        // レート制限のチェックと待機
        $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($url);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }
        
        return $preempt;
    }

    /**
     * 投稿データの同期
     *
     * @param array $site_data サイトデータ
     * @param array $post_data 投稿データ
     * @return array|WP_Error 同期結果
     */
    public function sync_post($site_data, $post_data) {
        try {
            // URLのバリデーションと正規化
            $normalized_url = $this->normalize_site_url($site_data['url']);
            
            // レート制限のチェックと待機
            $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($normalized_url);
            if (is_wp_error($rate_limit_result)) {
                return $rate_limit_result;
            }
            
            // WordPress 5.6以降のアプリケーションパスワード対応
            $auth_header = $this->auth_manager->get_auth_header($site_data);
            
            $this->debug_manager->log('投稿データの同期を開始', 'info', array(
                'site_url' => $normalized_url,
                'post_id' => isset($post_data['id']) ? $post_data['id'] : null
            ));
            
            // 投稿データの準備
            $prepared_data = $this->prepare_post_data($post_data, $site_data);
            
            // APIリクエスト
            $response = wp_remote_post($normalized_url . '/wp-json/wp/v2/posts', array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($prepared_data)
            ));
            
            // レート制限の処理
            $response = $this->rate_limit_manager->handle_rate_limit($normalized_url, $response);
            
            // HTTPステータスコードが201以外の場合には、エラーとして処理
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code !== 201) {
                    $this->debug_manager->log('投稿データの同期に失敗しました。', 'error', array(
                        'site_url' => $normalized_url,
                        'post_id' => isset($post_data['id']) ? $post_data['id'] : null,
                        'status_code' => $status_code,
                        'response_body' => wp_remote_retrieve_body($response)
                    ));
                    return new WP_Error('sync_failed', '投稿データの同期に失敗しました。HTTPステータスコード: ' . $status_code);
                }
            }
            
            $result = $this->error_manager->handle_api_error($response, '投稿データの同期');
            
            $this->debug_manager->log('投稿データの同期を完了', 'info', array(
                'site_url' => $normalized_url,
                'post_id' => isset($post_data['id']) ? $post_data['id'] : null,
                'success' => !is_wp_error($result)
            ));
            
            return $result;
        } catch (Exception $e) {
            $this->debug_manager->log('投稿データの同期中に例外が発生', 'error', array(
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            return new WP_Error('sync_failed', $e->getMessage());
        }
    }

    /**
     * URLのバリデーションと正規化（互換性のためのラッパーメソッド）
     */
    public function validate_and_normalize_url($url) {
        return $this->normalize_site_url($url);
    }

    /**
     * 投稿データの準備
     */
    private function prepare_post_data($post_data) {
        $this->debug_manager->log('投稿データの準備を開始', 'debug', $post_data);
        
        // 必須フィールドの確認
        $required_fields = ['title', 'content', 'status'];
        foreach ($required_fields as $field) {
            if (!isset($post_data[$field])) {
                $this->debug_manager->log("必須フィールドが不足: {$field}", 'error', $post_data);
                throw new Exception("必須フィールドが不足しています: {$field}");
            }
        }
        
        // カテゴリーの処理
        if (isset($post_data['categories']) && is_array($post_data['categories'])) {
            $post_data['categories'] = array_values($post_data['categories']);
        }
        
        // タグの処理 - WordPress REST APIの仕様に合わせて修正
        if (isset($post_data['tags']) && is_array($post_data['tags'])) {
            $post_data['tags'] = array_values($post_data['tags']);
        }
        
        // アイキャッチ画像の処理 - Array to string conversionエラーを修正
        if (isset($post_data['featured_media'])) {
            if (is_array($post_data['featured_media'])) {
                // 配列の場合は'id'キーの値を使用
                if (isset($post_data['featured_media']['id'])) {
                    $post_data['featured_media'] = $post_data['featured_media']['id'];
                } else {
                    // 'id'キーがない場合は、配列の最初の要素を使用
                    $post_data['featured_media'] = reset($post_data['featured_media']);
                }
            } elseif (!is_numeric($post_data['featured_media'])) {
                // 数値でない場合は0に設定
                $post_data['featured_media'] = 0;
            }
        }
        
        // その他のフィールドの処理
        $allowed_fields = [
            'title', 'content', 'excerpt', 'status', 'slug', 'author', 
            'featured_media', 'comment_status', 'ping_status', 'sticky',
            'categories', 'tags_input', 'meta', 'template'
        ];
        
        $filtered_data = array();
        foreach ($allowed_fields as $field) {
            if (isset($post_data[$field])) {
                // 配列を文字列に変換する前に、配列かどうかをチェック
                if (is_array($post_data[$field])) {
                    // 特定のフィールド（例: 'featured_media'）が配列の場合の処理
                    if ($field === 'featured_media') {
                        // すでに上で処理済みなので、ここでは何もしない
                        $filtered_data[$field] = $post_data[$field];
                    } else {
                        // その他の配列フィールドはJSONエンコード
                        $filtered_data[$field] = wp_json_encode($post_data[$field]);
                    }
                } else {
                    $filtered_data[$field] = $post_data[$field];
                }
            }
        }
        
        $this->debug_manager->log('投稿データの準備が完了', 'debug', $filtered_data);
        return $filtered_data;
    }

    /**
     * タクソノミーの同期
     */
    private function sync_taxonomies($site_data, $taxonomy, $terms) {
        $synced_terms = array();
        
        foreach ($terms as $term) {
            $synced_term = $this->sync_single_term($site_data, $taxonomy, $term);
            if ($synced_term && !is_wp_error($synced_term)) {
                $synced_terms[] = $synced_term;
            }
        }
        
        return $synced_terms;
    }

    /**
     * 単一タームの同期
     */
    private function sync_single_term($site_data, $taxonomy, $term) {
        // 既に同期済みのタームかチェック
        $synced_term_id = get_option("wp_cross_post_synced_term_{$site_data['id']}_{$taxonomy}_{$term['id']}", false);
        if ($synced_term_id) {
            return $synced_term_id;
        }
        
        // タームの存在確認
        $response = wp_remote_get($site_data['url'] . "/wp-json/wp/v2/{$taxonomy}?slug={$term['slug']}", array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $this->auth_manager->get_auth_header($site_data)
            )
        ));
        
        $response = $this->rate_limit_manager->handle_rate_limit($site_data['url'], $response);
        $result = $this->error_manager->handle_api_error($response, 'タームの存在確認');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $existing_terms = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($existing_terms)) {
            // 既に存在する場合はIDを保存
            update_option("wp_cross_post_synced_term_{$site_data['id']}_{$taxonomy}_{$term['id']}", $existing_terms[0]['id']);
            return $existing_terms[0]['id'];
        }
        
        // 新規作成
        $response = wp_remote_post($site_data['url'] . "/wp-json/wp/v2/{$taxonomy}", array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $this->auth_manager->get_auth_header($site_data),
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode(array(
                'name' => $term['name'],
                'slug' => $term['slug'],
                'description' => $term['description']
            ))
        ));
        
        $response = $this->rate_limit_manager->handle_rate_limit($site_data['url'], $response);
        $result = $this->error_manager->handle_api_error($response, 'タームの新規作成');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $new_term = json_decode(wp_remote_retrieve_body($response), true);
        update_option("wp_cross_post_synced_term_{$site_data['id']}_{$taxonomy}_{$term['id']}", $new_term['id']);
        return $new_term['id'];
    }

    /**
     * アイキャッチ画像の同期
     */
    private function sync_featured_image($site_data, $media_id) {
        // 既に同期済みのメディアかチェック
        $synced_media_id = get_option("wp_cross_post_synced_media_{$site_data['id']}_{$media_id}", false);
        if ($synced_media_id) {
            return $synced_media_id;
        }
        
        // メディアデータの取得
        $media_data = get_post($media_id);
        if (!$media_data) {
            $this->debug_manager->log('メディアデータが見つかりません', 'error', array(
                'media_id' => $media_id
            ));
            return false;
        }
        
        // 画像ファイルの取得
        $image_url = wp_get_attachment_url($media_id);
        if (!$image_url) {
            $this->debug_manager->log('画像URLが取得できません', 'error', array(
                'media_id' => $media_id
            ));
            return false;
        }
        
        $image_data = wp_remote_get($image_url);
        if (is_wp_error($image_data)) {
            $this->debug_manager->log('画像データの取得に失敗しました', 'error', array(
                'media_id' => $media_id,
                'image_url' => $image_url,
                'error' => $image_data->get_error_message()
            ));
            return $image_data;
        }
        
        // 画像データの準備
        $image_content = wp_remote_retrieve_body($image_data);
        if (empty($image_content)) {
            $this->debug_manager->log('画像コンテンツが空です', 'error', array(
                'media_id' => $media_id,
                'image_url' => $image_url
            ));
            return false;
        }
        
        $filename = basename($image_url);
        $filetype = wp_check_filetype($filename);
        
        if (empty($filetype['type'])) {
            $this->debug_manager->log('ファイルタイプが取得できません', 'error', array(
                'media_id' => $media_id,
                'filename' => $filename
            ));
            return false;
        }
        
        // 画像のアップロード
        $response = wp_remote_post($site_data['url'] . '/wp-json/wp/v2/media', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => $this->auth_manager->get_auth_header($site_data),
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Type' => $filetype['type']
            ),
            'body' => $image_content
        ));
        
        $response = $this->rate_limit_manager->handle_rate_limit($site_data['url'], $response);
        $result = $this->error_manager->handle_api_error($response, 'アイキャッチ画像の同期');
        
        if (is_wp_error($result)) {
            $this->debug_manager->log('アイキャッチ画像の同期に失敗しました', 'error', array(
                'media_id' => $media_id,
                'site_url' => $site_data['url'],
                'error' => $result->get_error_message()
            ));
            return $result;
        }
        
        $new_media = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($new_media['id'])) {
            $this->debug_manager->log('新しいメディアIDが取得できません', 'error', array(
                'media_id' => $media_id,
                'site_url' => $site_data['url'],
                'response' => $new_media
            ));
            return false;
        }
        
        update_option("wp_cross_post_synced_media_{$site_data['id']}_{$media_id}", $new_media['id']);
        
        $this->debug_manager->log('アイキャッチ画像の同期が完了しました', 'info', array(
            'media_id' => $media_id,
            'new_media_id' => $new_media['id'],
            'site_url' => $site_data['url']
        ));
        
        return $new_media['id'];
    }

    /**
     * タクソノミーの全サイト同期
     *
     * @param array $site_data サイトデータ
     * @param string $taxonomy タクソノミー名
     * @return array|WP_Error 同期結果
     */
    public function sync_all_taxonomies($site_data, $taxonomy) {
        try {
            // URLのバリデーションと正規化
            $normalized_url = $this->normalize_site_url($site_data['url']);
            
            // レート制限のチェックと待機
            $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($normalized_url);
            if (is_wp_error($rate_limit_result)) {
                return $rate_limit_result;
            }
            
            // WordPress 5.6以降のアプリケーションパスワード対応
            $auth_header = $this->auth_manager->get_auth_header($site_data);
            
            $this->debug_manager->log("{$taxonomy}の全サイト同期を開始", 'info', array(
                'site_url' => $normalized_url
            ));
            
            // ローカルのタクソノミーを取得
            $local_terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false
            ));
            
            if (is_wp_error($local_terms)) {
                return $local_terms;
            }
            
            $synced_count = 0;
            $error_count = 0;
            
            // 各タームを同期
            foreach ($local_terms as $term) {
                $term_data = array(
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description
                );
                
                $response = wp_remote_post($normalized_url . "/wp-json/wp/v2/{$taxonomy}", array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => $auth_header,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => wp_json_encode($term_data)
                ));
                
                $response = $this->rate_limit_manager->handle_rate_limit($normalized_url, $response);
                $result = $this->error_manager->handle_api_error($response, "{$taxonomy}の同期");
                
                if (is_wp_error($result)) {
                    $error_count++;
                    $this->debug_manager->log("{$taxonomy}の同期に失敗", 'error', array(
                        'term_name' => $term->name,
                        'term_slug' => $term->slug,
                        'taxonomy' => $taxonomy,
                        'error' => $result->get_error_message()
                    ));
                } else {
                    $synced_count++;
                }
            }
            
            $this->debug_manager->log("{$taxonomy}の全サイト同期を完了", 'info', array(
                'site_url' => $normalized_url,
                'synced_count' => $synced_count,
                'error_count' => $error_count
            ));
            
            return array(
                'synced_count' => $synced_count,
                'error_count' => $error_count
            );
        } catch (Exception $e) {
            $this->debug_manager->log("{$taxonomy}の全サイト同期中に例外が発生", 'error', array(
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            return new WP_Error('sync_failed', $e->getMessage());
        }
    }
}