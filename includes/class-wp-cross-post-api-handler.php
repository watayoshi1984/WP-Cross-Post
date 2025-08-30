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
     * サイトAPIの詳細テスト（診断用）
     *
     * @param array $site_data サイトデータ
     * @return array 詳細テスト結果
     */
    public function detailed_api_test($site_data) {
        $results = array();
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        // 1. 基本接続テスト
        $basic_test = wp_remote_get($site_data['url'] . '/wp-json/', array(
            'timeout' => 10
        ));
        $results['basic_connection'] = array(
            'status' => is_wp_error($basic_test) ? 'failed' : 'success',
            'response_code' => is_wp_error($basic_test) ? null : wp_remote_retrieve_response_code($basic_test),
            'error' => is_wp_error($basic_test) ? $basic_test->get_error_message() : null
        );
        
        // 2. 認証テスト
        $auth_test = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/users/me', array(
            'timeout' => 10,
            'headers' => array('Authorization' => $auth_header)
        ));
        $results['authentication'] = array(
            'status' => is_wp_error($auth_test) ? 'failed' : (wp_remote_retrieve_response_code($auth_test) === 200 ? 'success' : 'failed'),
            'response_code' => is_wp_error($auth_test) ? null : wp_remote_retrieve_response_code($auth_test),
            'error' => is_wp_error($auth_test) ? $auth_test->get_error_message() : null
        );
        
        // 3. カテゴリーAPI テスト
        $category_test = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/categories', array(
            'timeout' => 10,
            'headers' => array('Authorization' => $auth_header)
        ));
        $results['categories_api'] = array(
            'status' => is_wp_error($category_test) ? 'failed' : (wp_remote_retrieve_response_code($category_test) === 200 ? 'success' : 'failed'),
            'response_code' => is_wp_error($category_test) ? null : wp_remote_retrieve_response_code($category_test),
            'error' => is_wp_error($category_test) ? $category_test->get_error_message() : null,
            'available_categories' => null
        );
        
        if ($results['categories_api']['status'] === 'success') {
            $cat_data = json_decode(wp_remote_retrieve_body($category_test), true);
            $results['categories_api']['available_categories'] = is_array($cat_data) ? array_map(function($cat) {
                return array('id' => $cat['id'], 'name' => $cat['name'], 'slug' => $cat['slug']);
            }, $cat_data) : array();
        }
        
        // 4. 投稿権限テスト
        $permission_test = wp_remote_post($site_data['url'] . '/wp-json/wp/v2/posts', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode(array(
                'title' => 'WP Cross Post Permission Test - DELETE ME',
                'content' => 'This is a test post for permission checking. Please delete.',
                'status' => 'draft'
            ))
        ));
        
        $results['post_permission'] = array(
            'status' => is_wp_error($permission_test) ? 'failed' : (in_array(wp_remote_retrieve_response_code($permission_test), [200, 201]) ? 'success' : 'failed'),
            'response_code' => is_wp_error($permission_test) ? null : wp_remote_retrieve_response_code($permission_test),
            'error' => is_wp_error($permission_test) ? $permission_test->get_error_message() : null,
            'response_body' => is_wp_error($permission_test) ? null : wp_remote_retrieve_body($permission_test)
        );
        
        // テスト投稿が作成できた場合は削除
        if ($results['post_permission']['status'] === 'success') {
            $test_post = json_decode(wp_remote_retrieve_body($permission_test), true);
            if (is_array($test_post) && isset($test_post['id'])) {
                wp_remote_request($site_data['url'] . '/wp-json/wp/v2/posts/' . $test_post['id'], array(
                    'method' => 'DELETE',
                    'timeout' => 10,
                    'headers' => array('Authorization' => $auth_header)
                ));
            }
        }
        
        return $results;
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

            // 事前接続テスト（防御的プレチェック）
            $preflight = $this->test_connection($site_data);
            if (is_wp_error($preflight)) {
                return $preflight;
            }
            
            $this->debug_manager->log('投稿データの同期を開始', 'info', array(
                'site_url' => $normalized_url,
                'post_id' => isset($post_data['id']) ? $post_data['id'] : null
            ));
            
            // 投稿データの準備
            $prepared_data = $this->prepare_post_data($post_data);

            // カテゴリー/タグが設定されている場合は必ず反映（ローカル→リモートID確定）
            // ローカル投稿IDが渡っている前提で、未設定や空配列ならローカルタームからリモートIDを解決
            $local_post_id = isset($post_data['id']) ? intval($post_data['id']) : 0;
            if ($local_post_id > 0) {
                // categories
                $needs_categories = !isset($prepared_data['categories']) || empty($prepared_data['categories']);
                $needs_tags = !isset($prepared_data['tags']) || empty($prepared_data['tags']);
                if ($needs_categories) {
                    $local_categories = get_the_terms($local_post_id, 'category');
                    $category_ids = array();
                    if (!is_wp_error($local_categories) && !empty($local_categories)) {
                        foreach ($local_categories as $term_obj) {
                            $term_arr = array(
                                'id' => intval($term_obj->term_id),
                                'slug' => $term_obj->slug,
                                'name' => $term_obj->name,
                                'description' => isset($term_obj->description) ? $term_obj->description : ''
                            );
                            $remote_id = $this->sync_single_term($site_data, 'categories', $term_arr);
                            if ($remote_id && !is_wp_error($remote_id)) {
                                $category_ids[] = intval($remote_id);
                            }
                        }
                    }
                    if (!empty($category_ids)) {
                        $prepared_data['categories'] = $category_ids;
                    }
                }

                // tags
                if ($needs_tags) {
                    $local_tags = get_the_terms($local_post_id, 'post_tag');
                    $tag_ids = array();
                    if (!is_wp_error($local_tags) && !empty($local_tags)) {
                        foreach ($local_tags as $term_obj) {
                            $term_arr = array(
                                'id' => intval($term_obj->term_id),
                                'slug' => $term_obj->slug,
                                'name' => $term_obj->name,
                                'description' => isset($term_obj->description) ? $term_obj->description : ''
                            );
                            $remote_id = $this->sync_single_term($site_data, 'tags', $term_arr);
                            if ($remote_id && !is_wp_error($remote_id)) {
                                $tag_ids[] = intval($remote_id);
                            }
                        }
                    }
                    if (!empty($tag_ids)) {
                        $prepared_data['tags'] = $tag_ids;
                    }
                }
            }
            
            // APIリクエスト（context=edit と _fields で最小限のレスポンスを要求）
            $response = wp_remote_post($normalized_url . '/wp-json/wp/v2/posts?context=edit&_fields=id,slug,link', array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => wp_json_encode($prepared_data)
            ));
            
            // レート制限の処理
            $response = $this->rate_limit_manager->handle_rate_limit($normalized_url, $response);

            // まずAPIエラーを正規処理
            $api_check = $this->error_manager->handle_api_error($response, '投稿データの同期');
            if (is_wp_error($api_check)) {
                return $api_check;
            }

            // 成功とみなすHTTPコード: 201 (作成) または 200 (更新)
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 201 && $status_code !== 200) {
                $this->debug_manager->log('投稿データの同期に失敗しました。', 'error', array(
                    'site_url' => $normalized_url,
                    'post_id' => isset($post_data['id']) ? $post_data['id'] : null,
                    'status_code' => $status_code,
                    'response_body' => wp_remote_retrieve_body($response)
                ));
                return new WP_Error('sync_failed', '投稿データの同期に失敗しました。HTTPステータスコード: ' . $status_code);
            }

            // リモート投稿IDを抽出（フォールバック含む）
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            $remote_post_id = null;

            // 通常: オブジェクトで返る
            if (is_array($decoded) && isset($decoded['id'])) {
                $remote_post_id = (int) $decoded['id'];
            }

            // フォールバック0: 一部環境で配列（リスト）で返るケース
            // 例: [ { id: 123, ... } ] のようなレスポンス
            if ($remote_post_id === null && is_array($decoded) && !empty($decoded)) {
                // 単一オブジェクトではなく配列が返却された場合
                // 先頭要素、またはスラッグ一致優先でIDを抽出
                // まずスラッグ一致を優先、なければ先頭要素を採用
                $picked = null;
                $expected_slug = isset($prepared_data['slug']) ? (string) $prepared_data['slug'] : '';
                foreach (is_array($decoded) ? $decoded : array() as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        if (!empty($expected_slug) && isset($item['slug']) && $item['slug'] === $expected_slug) {
                            $picked = $item; break;
                        }
                        if ($picked === null) {
                            $picked = $item;
                        }
                    }
                }
                if (is_array($picked) && isset($picked['id'])) {
                    $remote_post_id = (int) $picked['id'];
                    $this->debug_manager->log('投稿APIがリストを返却したためフォールバックで採用', 'warning', array(
                        'site_url' => $normalized_url,
                        'picked_id' => $remote_post_id
                    ));
                }
            }

            // フォールバック1: LocationヘッダーからIDを抽出
            if ($remote_post_id === null) {
                $location = wp_remote_retrieve_header($response, 'location');
                if (!empty($location)) {
                    if (preg_match('#/wp-json/[^/]+/v\d+/posts/(\d+)#', $location, $m) || preg_match('#/wp-json/wp/v2/posts/(\d+)#', $location, $m) || preg_match('#/posts/(\d+)#', $location, $m)) {
                        $remote_post_id = (int) $m[1];
                    }
                    // デバッグ用にLocationヘッダーも記録
                    $this->debug_manager->log('Locationヘッダーを検出', 'debug', array(
                        'location' => $location
                    ));
                }
            }

            // 可能ならリモート現在ユーザーIDを取得（検索の精度向上のため）
            $remote_user_id = null;
            if ($remote_post_id === null) {
                $me_resp = wp_remote_get($normalized_url . '/wp-json/wp/v2/users/me', array(
                    'timeout' => 10,
                    'headers' => array(
                        'Authorization' => $auth_header,
                        'Accept' => 'application/json'
                    )
                ));
                if (!is_wp_error($me_resp) && wp_remote_retrieve_response_code($me_resp) === 200) {
                    $me = json_decode(wp_remote_retrieve_body($me_resp), true);
                    if (is_array($me) && isset($me['id'])) {
                        $remote_user_id = (int) $me['id'];
                    }
                }
            }

            // 投稿時に指定したauthor（あれば）— 検索時のauthorフィルタ適用可否の判断材料にする
            $author_sent = isset($prepared_data['author']) ? (int) $prepared_data['author'] : null;

            // フォールバック2: スラッグで検索（権限/実装差に備えて文脈・ステータス指定を可変に）
            if ($remote_post_id === null) {
                $slug_original = isset($prepared_data['slug']) ? (string) $prepared_data['slug'] : '';
                if (!empty($slug_original)) {
                    // スラッグ候補: 元/デコード済み/重複回避で付与されやすい -2..-5
                    $slug_candidates = array_unique(array(
                        $slug_original,
                        rawurldecode($slug_original),
                        $slug_original . '-2',
                        $slug_original . '-3',
                        $slug_original . '-4',
                        $slug_original . '-5'
                    ));
                    // トライ順: author付/無 × context(edit/view) × status指定あり/なし
                    $contexts = array('edit', 'view');
                    $orders = array('date', 'modified');
                    foreach (array(true, false) as $with_author_filter) {
                        if ($remote_post_id !== null) { break; }
                        foreach ($contexts as $ctx) {
                            if ($remote_post_id !== null) { break; }
                            foreach (array(true, false) as $with_status) {
                                if ($remote_post_id !== null) { break; }
                                foreach ($orders as $ord_by) {
                                    if ($remote_post_id !== null) { break; }
                                    foreach ($slug_candidates as $slug_for_query) {
                                        if (empty($slug_for_query)) { continue; }
                                        foreach (array(true, false) as $with_after) {
                                            if ($remote_post_id !== null) { break; }
                                            $slug_args = array(
                                                'slug' => $slug_for_query,
                                                'context' => $ctx,
                                                'per_page' => 1,
                                                'orderby' => $ord_by,
                                                'order' => 'desc'
                                            );
                                            if ($with_status) {
                                                $slug_args['status'] = 'publish,future,draft,private,pending,trash,auto-draft';
                                            }
                                            if ($with_after) {
                                                $slug_args['after'] = gmdate('c', time() - 1200); // 20分
                                            }
                                            if ($with_author_filter && !is_null($remote_user_id)) {
                                                $slug_args['author'] = $remote_user_id;
                                            }
                                            $query = add_query_arg($slug_args, $normalized_url . '/wp-json/wp/v2/posts');

                                            $lookup = wp_remote_get($query, array(
                                                'timeout' => 10,
                                                'headers' => array(
                                                    'Authorization' => $auth_header,
                                                    'Accept' => 'application/json'
                                                )
                                            ));

                                            if (!is_wp_error($lookup) && wp_remote_retrieve_response_code($lookup) === 200) {
                                                $list = json_decode(wp_remote_retrieve_body($lookup), true);
                                                if (is_array($list) && isset($list[0]['id'])) {
                                                    $remote_post_id = (int) $list[0]['id'];
                                                    break 5; // すべてのネストを抜ける
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // フォールバック3: タイトルで検索（権限/実装差に備えて文脈・ステータス指定を可変に）
            if ($remote_post_id === null) {
                $title = isset($prepared_data['title'])
                    ? (is_array($prepared_data['title']) && isset($prepared_data['title']['raw']) ? $prepared_data['title']['raw'] : $prepared_data['title'])
                    : '';
                if (!empty($title)) {
                    $contexts = array('edit', 'view');
                    $orders = array('date', 'modified');
                    foreach (array(true, false) as $with_author_filter) {
                        if ($remote_post_id !== null) { break; }
                        foreach ($contexts as $ctx) {
                            if ($remote_post_id !== null) { break; }
                            foreach (array(true, false) as $with_status) {
                                if ($remote_post_id !== null) { break; }
                                foreach ($orders as $ord_by) {
                                    if ($remote_post_id !== null) { break; }
                                    foreach (array(true, false) as $with_after) {
                                        if ($remote_post_id !== null) { break; }
                                        $args = array(
                                            'search' => wp_strip_all_tags((string) $title),
                                            'context' => $ctx,
                                            'per_page' => 5,
                                            'orderby' => $ord_by,
                                            'order' => 'desc'
                                        );
                                        if ($with_status) {
                                            $args['status'] = 'publish,future,draft,private,pending,trash,auto-draft';
                                        }
                                        if ($with_after) {
                                            $args['after'] = gmdate('c', time() - 1200);
                                        }
                                        if ($with_author_filter && !is_null($remote_user_id)) {
                                            $args['author'] = $remote_user_id;
                                        }
                                        $query = add_query_arg($args, $normalized_url . '/wp-json/wp/v2/posts');

                                        $lookup = wp_remote_get($query, array(
                                            'timeout' => 10,
                                            'headers' => array(
                                                'Authorization' => $auth_header,
                                                'Accept' => 'application/json'
                                            )
                                        ));

                                        if (!is_wp_error($lookup) && wp_remote_retrieve_response_code($lookup) === 200) {
                                            $list = json_decode(wp_remote_retrieve_body($lookup), true);
                                            if (is_array($list) && !empty($list)) {
                                                // スラッグが分かっていれば一致優先、なければタイトル完全一致優先
                                                $slug = isset($prepared_data['slug']) ? $prepared_data['slug'] : '';
                                                $picked = null;
                                                foreach ($list as $item) {
                                                    if (isset($item['id'])) {
                                                        if (!empty($slug) && isset($item['slug']) && $item['slug'] === $slug) {
                                                            $picked = $item; break;
                                                        }
                                                        if ($picked === null) {
                                                            // 完全一致タイトルを優先
                                                            $remote_title = '';
                                                            if (isset($item['title'])) {
                                                                if (is_array($item['title']) && isset($item['title']['rendered'])) {
                                                                    $remote_title = wp_strip_all_tags($item['title']['rendered']);
                                                                } elseif (is_string($item['title'])) {
                                                                    $remote_title = $item['title'];
                                                                }
                                                            }
                                                            if (!empty($remote_title) && wp_strip_all_tags((string) $title) === $remote_title) {
                                                                $picked = $item; // keep but continue in case slug appears later
                                                            }
                                                        }
                                                    }
                                                }
                                                if ($picked === null) {
                                                    $picked = $list[0];
                                                }
                                                if (isset($picked['id'])) {
                                                    $remote_post_id = (int) $picked['id'];
                                                    break 4;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($remote_post_id === null) {
                // フォールバック4: 直近作成の投稿一覧から推定（slug / title / featured_media の優先順）
                $contexts = array('edit', 'view');
                $orders = array('date', 'modified');
                foreach (array(true, false) as $with_author_filter) {
                    if ($remote_post_id !== null) { break; }
                    foreach ($contexts as $ctx) {
                        if ($remote_post_id !== null) { break; }
                        foreach (array(true, false) as $with_status) {
                            if ($remote_post_id !== null) { break; }
                            foreach ($orders as $ord_by) {
                                if ($remote_post_id !== null) { break; }
                                foreach (array(true, false) as $with_after) {
                                    if ($remote_post_id !== null) { break; }
                                    $recent_args = array(
                                        'context' => $ctx,
                                        'per_page' => 5,
                                        'orderby' => $ord_by,
                                        'order' => 'desc'
                                    );
                                    if ($with_status) {
                                        $recent_args['status'] = 'publish,future,draft,private,pending,trash,auto-draft';
                                    }
                                    if ($with_after) {
                                        $recent_args['after'] = gmdate('c', time() - 1200);
                                    }
                                    if ($with_author_filter && !is_null($remote_user_id)) {
                                        $recent_args['author'] = $remote_user_id;
                                    }
                                    $recent_url = add_query_arg($recent_args, $normalized_url . '/wp-json/wp/v2/posts');
                                    $recent = wp_remote_get($recent_url, array(
                                        'timeout' => 10,
                                        'headers' => array(
                                            'Authorization' => $auth_header,
                                            'Accept' => 'application/json'
                                        )
                                    ));
                                    if (!is_wp_error($recent) && wp_remote_retrieve_response_code($recent) === 200) {
                                        $list = json_decode(wp_remote_retrieve_body($recent), true);
                                        if (is_array($list) && !empty($list)) {
                                            $slug_original = isset($prepared_data['slug']) ? (string) $prepared_data['slug'] : '';
                                            $slug_candidates = array_unique(array(
                                                $slug_original,
                                                rawurldecode($slug_original),
                                                $slug_original . '-2',
                                                $slug_original . '-3',
                                                $slug_original . '-4',
                                                $slug_original . '-5'
                                            ));
                                            $title_lookup = isset($prepared_data['title'])
                                                ? (is_array($prepared_data['title']) && isset($prepared_data['title']['raw']) ? $prepared_data['title']['raw'] : $prepared_data['title'])
                                                : '';
                                            $title_stripped = wp_strip_all_tags((string) $title_lookup);
                                            $featured_media_sent = isset($prepared_data['featured_media']) ? intval($prepared_data['featured_media']) : 0;

                                            $picked = null;
                                            foreach ($list as $item) {
                                                if (!isset($item['id'])) { continue; }
                                                // 1) slug 完全一致（候補含む）
                                                if (!empty($slug_original) && isset($item['slug']) && in_array($item['slug'], $slug_candidates, true)) {
                                                    $picked = $item; break;
                                                }
                                                // 2) featured_media 一致
                                                if ($picked === null && $featured_media_sent > 0 && isset($item['featured_media']) && intval($item['featured_media']) === $featured_media_sent) {
                                                    $picked = $item; // keep, continue
                                                }
                                                // 3) タイトル完全一致
                                                if ($picked === null && !empty($title_stripped)) {
                                                    $remote_title = '';
                                                    if (isset($item['title'])) {
                                                        if (is_array($item['title']) && isset($item['title']['rendered'])) {
                                                            $remote_title = wp_strip_all_tags($item['title']['rendered']);
                                                        } elseif (is_string($item['title'])) {
                                                            $remote_title = $item['title'];
                                                        }
                                                    }
                                                    if (!empty($remote_title) && $remote_title === $title_stripped) {
                                                        $picked = $item;
                                                    }
                                                }
                                            }
                                            if ($picked !== null && isset($picked['id'])) {
                                                $remote_post_id = (int) $picked['id'];
                                                break 4;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($remote_post_id === null) {
                // より詳細な診断情報を取得
                $diagnostic_info = $this->diagnose_sync_failure($site_data, $post_data, $response);
                
                $this->debug_manager->log('投稿データの同期レスポンスにIDが含まれていません。', 'error', array(
                    'site_url' => $normalized_url,
                    'post_id' => isset($post_data['id']) ? $post_data['id'] : null,
                    'status_code' => $status_code,
                    'response_body' => $body,
                    'diagnostic_info' => $diagnostic_info,
                    'sent_categories' => isset($prepared_data['categories']) ? $prepared_data['categories'] : 'none',
                    'sent_tags' => isset($prepared_data['tags']) ? $prepared_data['tags'] : 'none'
                ));
                
                // カテゴリー関連の問題を自動修復を試行
                if ($this->is_category_related_error($diagnostic_info)) {
                    $this->debug_manager->log('カテゴリー関連の問題を検出、自動修復を試行', 'warning');
                    $fixed_data = $this->fix_category_issues($site_data, $prepared_data);
                    
                    if ($fixed_data !== $prepared_data) {
                        $this->debug_manager->log('修正されたデータで再試行', 'info', array(
                            'original_categories' => isset($prepared_data['categories']) ? $prepared_data['categories'] : 'none',
                            'fixed_categories' => isset($fixed_data['categories']) ? $fixed_data['categories'] : 'none'
                        ));
                        
                        // 修正されたデータで再試行
                        $retry_response = wp_remote_post($normalized_url . '/wp-json/wp/v2/posts?context=edit&_fields=id,slug,link', array(
                            'timeout' => 30,
                            'headers' => array(
                                'Authorization' => $auth_header,
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json'
                            ),
                            'body' => wp_json_encode($fixed_data)
                        ));
                        
                        $retry_body = wp_remote_retrieve_body($retry_response);
                        $retry_decoded = json_decode($retry_body, true);
                        
                        if (is_array($retry_decoded) && isset($retry_decoded['id'])) {
                            $this->debug_manager->log('カテゴリー修正後の再試行が成功', 'info', array(
                                'remote_post_id' => $retry_decoded['id']
                            ));
                            return (int) $retry_decoded['id'];
                        }
                    }
                }
                
                return new WP_Error('invalid_response', '投稿データの同期レスポンスにIDが含まれていません。診断結果: ' . $diagnostic_info['summary']);
            }

            $this->debug_manager->log('投稿データの同期を完了', 'info', array(
                'site_url' => $normalized_url,
                'post_id' => isset($post_data['id']) ? $post_data['id'] : null,
                'remote_post_id' => $remote_post_id,
                'success' => true
            ));
            
            return $remote_post_id;
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
        
        // カテゴリーの処理 - WordPress REST APIの仕様に合わせて修正
        if (isset($post_data['categories'])) {
            if (is_array($post_data['categories'])) {
                // カテゴリーIDの配列を整数型に変換
                $categories = array();
                foreach ($post_data['categories'] as $category_id) {
                    $categories[] = (int)$category_id;
                }
                $post_data['categories'] = $categories;
            } elseif (is_string($post_data['categories'])) {
                // 文字列で渡ってきた場合（例: "[1,2]"）は配列へ復元
                $raw = trim($post_data['categories']);
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $post_data['categories'] = array_map('intval', $decoded);
                } else {
                    // JSONでなければ数値だけを抽出
                    $matches = array();
                    preg_match_all('/\d+/', $raw, $matches);
                    $post_data['categories'] = isset($matches[0]) ? array_map('intval', $matches[0]) : array();
                }
            } else {
                // 配列/文字列でない場合は空配列
                $post_data['categories'] = array();
            }
        } else {
            // カテゴリーが設定されていない場合は空の配列を設定
            $post_data['categories'] = array();
        }
        
        // タグの処理 - WordPress REST APIの仕様に合わせて修正
        if (isset($post_data['tags'])) {
            if (is_array($post_data['tags'])) {
                // タグIDの配列を整数型に変換
                $tags = array();
                foreach ($post_data['tags'] as $tag_id) {
                    $tags[] = (int)$tag_id;
                }
                $post_data['tags'] = $tags;
            } elseif (is_string($post_data['tags'])) {
                // 文字列で渡ってきた場合（例: "[10,20]")は配列へ復元
                $raw = trim($post_data['tags']);
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $post_data['tags'] = array_map('intval', $decoded);
                } else {
                    $matches = array();
                    preg_match_all('/\d+/', $raw, $matches);
                    $post_data['tags'] = isset($matches[0]) ? array_map('intval', $matches[0]) : array();
                }
            } else {
                // 配列/文字列でない場合は空配列
                $post_data['tags'] = array();
            }
        } else {
            // タグが設定されていない場合は空の配列を設定
            $post_data['tags'] = array();
        }

        // メタの処理 - 文字列で渡ってきた場合は配列へ変換
        if (isset($post_data['meta']) && !is_array($post_data['meta'])) {
            $decoded_meta = json_decode($post_data['meta'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_meta)) {
                $post_data['meta'] = $decoded_meta;
            } elseif (function_exists('is_serialized') && is_serialized($post_data['meta'])) {
                $maybe_unserialized = @unserialize($post_data['meta']);
                $post_data['meta'] = is_array($maybe_unserialized) ? $maybe_unserialized : array();
            } else {
                $post_data['meta'] = array();
            }
        } elseif (!isset($post_data['meta'])) {
            $post_data['meta'] = array();
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
        // REST API 仕様に合わせ、配列はそのまま配列として送る（JSON文字列化しない）
        $allowed_fields = [
            'title', 'content', 'excerpt', 'status', 'slug', 'author',
            'featured_media', 'comment_status', 'ping_status', 'sticky',
            'categories', 'tags', 'meta', 'template', 'date', 'date_gmt'
        ];
        
        $filtered_data = array();
        foreach ($allowed_fields as $field) {
            if (isset($post_data[$field])) {
                $filtered_data[$field] = $post_data[$field];
            }
        }

        // 型の正規化: author は整数に、空や不正なら除去（認証ユーザーに委ねる）
        if (isset($filtered_data['author'])) {
            if (is_numeric($filtered_data['author'])) {
                $filtered_data['author'] = (int) $filtered_data['author'];
            } else {
                unset($filtered_data['author']);
            }
        }

        // タイトル/抜粋の正規化（POSTリクエストでは文字列が期待される）
        if (isset($filtered_data['title']) && is_array($filtered_data['title'])) {
            if (isset($filtered_data['title']['raw'])) {
                $filtered_data['title'] = (string) $filtered_data['title']['raw'];
            } elseif (isset($filtered_data['title']['rendered'])) {
                $filtered_data['title'] = wp_strip_all_tags((string) $filtered_data['title']['rendered']);
            } else {
                $filtered_data['title'] = ''; // 不正な形式の場合は空文字
            }
        }
        if (isset($filtered_data['excerpt']) && is_array($filtered_data['excerpt'])) {
            if (isset($filtered_data['excerpt']['raw'])) {
                $filtered_data['excerpt'] = (string) $filtered_data['excerpt']['raw'];
            } elseif (isset($filtered_data['excerpt']['rendered'])) {
                $filtered_data['excerpt'] = wp_strip_all_tags((string) $filtered_data['excerpt']['rendered']);
            } else {
                $filtered_data['excerpt'] = '';
            }
        }
        
        // 空配列のcategories/tagsは送信しない（oneOfの曖昧一致を回避）
        if (isset($filtered_data['categories']) && empty($filtered_data['categories'])) {
            unset($filtered_data['categories']);
        }
        if (isset($filtered_data['tags']) && empty($filtered_data['tags'])) {
            unset($filtered_data['tags']);
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
        
        // 画像データの取得をリトライ処理で行う
        $max_retries = 3;
        $retry_count = 0;
        $image_data = null;
        
        while ($retry_count < $max_retries) {
            $image_data = wp_remote_get($image_url);
            if (!is_wp_error($image_data)) {
                break;
            }
            
            $retry_count++;
            if ($retry_count < $max_retries) {
                // 5秒待機
                sleep(5);
            }
        }
        
        if (is_wp_error($image_data)) {
            $this->debug_manager->log('画像データの取得に失敗しました（リトライ後）', 'error', array(
                'media_id' => $media_id,
                'image_url' => $image_url,
                'error' => $image_data->get_error_message(),
                'retry_count' => $retry_count
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
        
        // 画像のアップロードをリトライ処理で行う
        $retry_count = 0;
        $response = null;
        
        while ($retry_count < $max_retries) {
            $response = wp_remote_post($site_data['url'] . '/wp-json/wp/v2/media', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => $this->auth_manager->get_auth_header($site_data),
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Type' => $filetype['type']
                ),
                'body' => $image_content
            ));
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code >= 200 && $status_code < 300) {
                    break;
                }
            }
            
            $retry_count++;
            if ($retry_count < $max_retries) {
                // 5秒待機
                sleep(5);
            }
        }
        
        if (is_wp_error($response)) {
            $this->debug_manager->log('アイキャッチ画像のアップロードに失敗しました（リトライ後）', 'error', array(
                'media_id' => $media_id,
                'site_url' => $site_data['url'],
                'error' => $response->get_error_message(),
                'retry_count' => $retry_count
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $this->debug_manager->log('アイキャッチ画像のアップロードに失敗しました（HTTPステータスコードエラー）', 'error', array(
                'media_id' => $media_id,
                'site_url' => $site_data['url'],
                'status_code' => $status_code,
                'response_body' => wp_remote_retrieve_body($response)
            ));
            return false;
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
     * 同期失敗の診断
     *
     * @param array $site_data サイトデータ
     * @param array $post_data 投稿データ
     * @param array|WP_Error $response API レスポンス
     * @return array 診断情報
     */
    private function diagnose_sync_failure($site_data, $post_data, $response) {
        $diagnostic_info = array(
            'summary' => '',
            'issues' => array(),
            'suggestions' => array()
        );
        
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        // カテゴリーの存在確認
        if (isset($post_data['categories']) && !empty($post_data['categories'])) {
            foreach ($post_data['categories'] as $cat_id) {
                $cat_check = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/categories/' . $cat_id, array(
                    'timeout' => 10,
                    'headers' => array('Authorization' => $auth_header)
                ));
                
                if (is_wp_error($cat_check) || wp_remote_retrieve_response_code($cat_check) === 404) {
                    $diagnostic_info['issues'][] = "カテゴリーID {$cat_id} がサブサイトに存在しません";
                    $diagnostic_info['suggestions'][] = "カテゴリーを作成するか、デフォルトカテゴリーを使用してください";
                }
            }
        }
        
        // タグの存在確認
        if (isset($post_data['tags']) && !empty($post_data['tags'])) {
            foreach ($post_data['tags'] as $tag_id) {
                $tag_check = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/tags/' . $tag_id, array(
                    'timeout' => 10,
                    'headers' => array('Authorization' => $auth_header)
                ));
                
                if (is_wp_error($tag_check) || wp_remote_retrieve_response_code($tag_check) === 404) {
                    $diagnostic_info['issues'][] = "タグID {$tag_id} がサブサイトに存在しません";
                    $diagnostic_info['suggestions'][] = "タグを作成するか、タグなしで投稿してください";
                }
            }
        }
        
        // 権限チェック
        $me_check = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/users/me', array(
            'timeout' => 10,
            'headers' => array('Authorization' => $auth_header)
        ));
        
        if (is_wp_error($me_check) || wp_remote_retrieve_response_code($me_check) !== 200) {
            $diagnostic_info['issues'][] = "認証に失敗しました";
            $diagnostic_info['suggestions'][] = "アプリケーションパスワードを確認してください";
        } else {
            $me_data = json_decode(wp_remote_retrieve_body($me_check), true);
            if (is_array($me_data) && isset($me_data['capabilities'])) {
                if (!isset($me_data['capabilities']['publish_posts']) || !$me_data['capabilities']['publish_posts']) {
                    $diagnostic_info['issues'][] = "投稿公開権限がありません";
                    $diagnostic_info['suggestions'][] = "ユーザーに投稿公開権限を付与してください";
                }
            }
        }
        
        // サマリーの生成
        if (empty($diagnostic_info['issues'])) {
            $diagnostic_info['summary'] = "明確な問題が特定できませんでした";
        } else {
            $diagnostic_info['summary'] = implode('; ', $diagnostic_info['issues']);
        }
        
        return $diagnostic_info;
    }
    
    /**
     * カテゴリー関連のエラーかどうかを判定
     *
     * @param array $diagnostic_info 診断情報
     * @return bool カテゴリー関連のエラーかどうか
     */
    private function is_category_related_error($diagnostic_info) {
        foreach ($diagnostic_info['issues'] as $issue) {
            if (strpos($issue, 'カテゴリー') !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * カテゴリーの問題を修正
     *
     * @param array $site_data サイトデータ
     * @param array $post_data 投稿データ
     * @return array 修正された投稿データ
     */
    private function fix_category_issues($site_data, $post_data) {
        $fixed_data = $post_data;
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        // デフォルトカテゴリーを取得
        $default_cat_response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/categories?per_page=1&orderby=id&order=asc', array(
            'timeout' => 10,
            'headers' => array('Authorization' => $auth_header)
        ));
        
        if (!is_wp_error($default_cat_response) && wp_remote_retrieve_response_code($default_cat_response) === 200) {
            $categories = json_decode(wp_remote_retrieve_body($default_cat_response), true);
            if (is_array($categories) && !empty($categories)) {
                $default_category_id = $categories[0]['id'];
                
                // 存在しないカテゴリーをデフォルトカテゴリーに置換
                if (isset($fixed_data['categories']) && !empty($fixed_data['categories'])) {
                    $valid_categories = array();
                    
                    foreach ($fixed_data['categories'] as $cat_id) {
                        $cat_check = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/categories/' . $cat_id, array(
                            'timeout' => 10,
                            'headers' => array('Authorization' => $auth_header)
                        ));
                        
                        if (!is_wp_error($cat_check) && wp_remote_retrieve_response_code($cat_check) === 200) {
                            $valid_categories[] = $cat_id;
                        }
                    }
                    
                    // 有効なカテゴリーがない場合はデフォルトカテゴリーを使用
                    if (empty($valid_categories)) {
                        $fixed_data['categories'] = array($default_category_id);
                    } else {
                        $fixed_data['categories'] = $valid_categories;
                    }
                }
            }
        } else {
            // デフォルトカテゴリーも取得できない場合はカテゴリーを除去
            unset($fixed_data['categories']);
        }
        
        return $fixed_data;
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

    /**
     * 投稿を更新する
     *
     * @param array $site_data サイトデータ
     * @param int $post_id 更新する投稿ID
     * @param array $post_data 更新する投稿データ
     * @return mixed 成功時は更新された投稿データ、失敗時はWP_Error
     */
    public function update_post($site_data, $post_id, $post_data) {
        try {
            // URLのバリデーションと正規化
            $normalized_url = $this->normalize_site_url($site_data['url']);
            
            // レート制限のチェックと待機
            $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($normalized_url);
            if (is_wp_error($rate_limit_result)) {
                return $rate_limit_result;
            }

            // 認証ヘッダー取得
            $auth_header = $this->auth_manager->get_auth_header($site_data);
            
            $this->debug_manager->log('投稿の更新を開始', 'info', array(
                'site_url' => $normalized_url,
                'post_id' => $post_id,
                'post_data' => $post_data
            ));
            
            // APIリクエスト（投稿更新）
            $response = wp_remote_post($normalized_url . '/wp-json/wp/v2/posts/' . $post_id . '?context=edit&_fields=id,slug,link', array(
                'method' => 'POST',
                'timeout' => 60, // アイキャッチ画像設定時の負荷を考慮して増加
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-HTTP-Method-Override' => 'PUT'
                ),
                'body' => wp_json_encode($post_data)
            ));
            
            // レート制限の処理
            $response = $this->rate_limit_manager->handle_rate_limit($normalized_url, $response);

            // APIエラー処理
            $result = $this->error_manager->handle_api_error($response, '投稿の更新');
            
            if (is_wp_error($result)) {
                $this->debug_manager->log('投稿の更新に失敗', 'error', array(
                    'site_url' => $normalized_url,
                    'post_id' => $post_id,
                    'error' => $result->get_error_message(),
                    'response_code' => wp_remote_retrieve_response_code($response),
                    'response_body' => wp_remote_retrieve_body($response)
                ));
                return $result;
            }
            
            $this->debug_manager->log('投稿の更新が完了', 'info', array(
                'site_url' => $normalized_url,
                'post_id' => $post_id,
                'updated_data' => $result
            ));
            
            return $result;
            
        } catch (Exception $e) {
            $this->debug_manager->log('投稿の更新中に例外が発生', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            return new WP_Error('update_failed', $e->getMessage());
        }
    }
}