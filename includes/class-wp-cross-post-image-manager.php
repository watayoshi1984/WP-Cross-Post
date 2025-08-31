<?php
/**
 * WP Cross Post 画像マネージャー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-manager.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-image-manager.php';

/**
 * WP Cross Post 画像マネージャークラス
 *
 * 画像同期処理と画像URL処理を管理します。
 */
class WP_Cross_Post_Image_Manager implements WP_Cross_Post_Image_Manager_Interface {

    /**
     * インスタンス
     *
     * @var WP_Cross_Post_Image_Manager|null
     */
    private static $instance = null;

    /**
     * デバッグマネージャー
     *
     * @var WP_Cross_Post_Debug_Manager
     */
    private $debug_manager;

    /**
     * 認証マネージャー
     *
     * @var WP_Cross_Post_Auth_Manager
     */
    private $auth_manager;

    /**
     * 最大リトライ回数
     *
     * @var int
     */
    private $max_retries = 5;

    /**
     * 基本リトライ待機時間（秒）
     *
     * @var int
     */
    private $base_retry_wait_time = 10;
    
    /**
     * レート制限マネージャー
     *
     * @var WP_Cross_Post_Rate_Limit_Manager
     */
    private $rate_limit_manager;

    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Image_Manager
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
     * @param WP_Cross_Post_Auth_Manager $auth_manager 認証マネージャー
     * @param WP_Cross_Post_Rate_Limit_Manager $rate_limit_manager レート制限マネージャー
     */
    public function set_dependencies($debug_manager, $auth_manager, $rate_limit_manager = null) {
        $this->debug_manager = $debug_manager;
        $this->auth_manager = $auth_manager;
        $this->rate_limit_manager = $rate_limit_manager;
    }

    /**
     * 最大リトライ回数の設定
     *
     * @param int $max_retries 最大リトライ回数
     */
    public function set_max_retries($max_retries) {
        $this->max_retries = $max_retries;
    }

    /**
     * リトライ待機時間の設定
     *
     * @param int $retry_wait_time リトライ待機時間（秒）
     */
    public function set_retry_wait_time($retry_wait_time) {
        $this->base_retry_wait_time = $retry_wait_time;
    }

    /**
     * 基本リトライ待機時間の設定
     *
     * @param int $base_retry_wait_time 基本リトライ待機時間（秒）
     */
    public function set_base_retry_wait_time($base_retry_wait_time) {
        $this->base_retry_wait_time = $base_retry_wait_time;
    }
    
    /**
     * コンテンツサイズに基づいてタイムアウト値を計算
     *
     * @param string $image_data 画像データ
     * @return int タイムアウト値（秒）
     */
    private function calculate_timeout($image_data) {
        $size_mb = strlen($image_data) / (1024 * 1024);
        
        // 基本タイムアウト: 60秒
        // 追加タイムアウト: 1MBあたり30秒
        // 最大タイムアウト: 300秒（5分）
        $timeout = 60 + (int) ($size_mb * 30);
        $timeout = min($timeout, 300);
        
        $this->debug_manager->log('アップロードタイムアウトを計算', 'debug', array(
            'image_size_mb' => round($size_mb, 2),
            'calculated_timeout' => $timeout
        ));
        
        return $timeout;
    }

    /**
     * エクスポネンシャルバックオフによる待機時間を計算
     *
     * @param int $attempt 試行回数（0から開始）
     * @param int $status_code HTTPステータスコード
     * @param array $response_headers レスポンスヘッダー
     * @return int 待機時間（秒）
     */
    private function calculate_backoff_time($attempt, $status_code = 0, $response_headers = array()) {
        // HTTP 429の場合、Retry-Afterヘッダーを優先
        if ($status_code === 429 && isset($response_headers['retry-after'])) {
            $retry_after = intval($response_headers['retry-after']);
            if ($retry_after > 0 && $retry_after <= 300) { // 最大5分
                $this->debug_manager->log('Retry-Afterヘッダーに従って待機時間を設定', 'info', array(
                    'retry_after' => $retry_after,
                    'attempt' => $attempt
                ));
                return $retry_after;
            }
        }
        
        // HTTP 500エラーの場合は長めの待機時間
        $multiplier = ($status_code >= 500 && $status_code < 600) ? 2 : 1;
        
        // エクスポネンシャルバックオフ: base_time * 2^attempt * multiplier + jitter
        $backoff_time = $this->base_retry_wait_time * pow(2, $attempt) * $multiplier;
        
        // 最大待機時間の制限（5分）
        $backoff_time = min($backoff_time, 300);
        
        // ジッター（±20%）を追加してThundering Herd問題を軽減
        $jitter = mt_rand(-20, 20) / 100;
        $backoff_time = (int) round($backoff_time * (1 + $jitter));
        
        $this->debug_manager->log('エクスポネンシャルバックオフによる待機時間を計算', 'debug', array(
            'attempt' => $attempt,
            'status_code' => $status_code,
            'base_time' => $this->base_retry_wait_time,
            'multiplier' => $multiplier,
            'calculated_time' => $backoff_time
        ));
        
        return max($backoff_time, 1); // 最低1秒
    }

    /**
     * アイキャッチ画像の同期処理
     *
     * @param array $site_data サイトデータ
     * @param array $media_data メディアデータ
     * @param int $post_id 投稿ID
     * @return array|null 同期されたメディア情報、失敗時はnull
     */
    public function sync_featured_image($site_data, $media_data, $post_id) {
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->auth_manager->get_auth_header($site_data);
        
        // 画像URLの決定（source_url 優先、なければ url）
        $image_url = '';
        if (is_array($media_data)) {
            if (!empty($media_data['source_url'])) {
                $image_url = (string) $media_data['source_url'];
            } elseif (!empty($media_data['url'])) {
                $image_url = (string) $media_data['url'];
            }
        }

        if (empty($image_url)) {
            $this->debug_manager->log('アイキャッチ画像URLが存在しません。', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'media_data_keys' => is_array($media_data) ? implode(',', array_keys($media_data)) : 'not_array'
            ));
            return null;
        }

        // 画像ファイルの取得（HTTP経由、バリデーション付き）
        $get_response = wp_remote_get($image_url, array(
            'timeout' => 30,
        ));
        if (is_wp_error($get_response)) {
            $this->debug_manager->log('アイキャッチ画像の取得に失敗しました（HTTPエラー）。', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'media_url' => $image_url,
                'error' => $get_response->get_error_message()
            ));
            return null;
        }
        $resp_code = wp_remote_retrieve_response_code($get_response);
        if ($resp_code !== 200) {
            $this->debug_manager->log('アイキャッチ画像の取得に失敗しました（HTTPステータス）。', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'media_url' => $image_url,
                'status_code' => $resp_code
            ));
            return null;
        }
        $image_data = wp_remote_retrieve_body($get_response);
        if (empty($image_data)) {
            $this->debug_manager->log('アイキャッチ画像データが空です。', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'media_url' => $image_url
            ));
            return null;
        }
        
        // 画像データの検証
        if (empty($image_data)) {
            $this->debug_manager->log('アイキャッチ画像データが空です。', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'media_url' => $media_data['source_url']
            ));
            return null;
        }
        
        // ファイル名/コンテンツタイプを判定
        $parsed_path = parse_url($image_url, PHP_URL_PATH);
        $file_name = basename(is_string($parsed_path) ? $parsed_path : '');
        if ($file_name === '' || $file_name === false) {
            $file_name = 'featured-' . $post_id . '.jpg';
        }
        $file_type = wp_check_filetype($file_name);
        $content_type = !empty($file_type['type']) ? $file_type['type'] : 'image/jpeg';
        
        // 動的タイムアウト計算
        $timeout = $this->calculate_timeout($image_data);
        
        // レート制限チェック
        if ($this->rate_limit_manager) {
            $rate_limit_result = $this->rate_limit_manager->check_and_wait_for_rate_limit($site_data['url']);
            if (is_wp_error($rate_limit_result)) {
                $this->debug_manager->log('レート制限により同期を中断', 'warning', array(
                    'site_url' => $site_data['url'],
                    'post_id' => $post_id,
                    'error' => $rate_limit_result->get_error_message()
                ));
                return null;
            }
        }
        
        // 最大リトライ回数まで試行
        for ($attempt = 0; $attempt < $this->max_retries; $attempt++) {
            $response = wp_remote_post($site_data['url'] . '/wp-json/wp/v2/media', array(
                'timeout' => $timeout,
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Content-Disposition' => 'attachment; filename="' . $file_name . '"',
                    'Content-Type' => $content_type,
                    'Accept' => 'application/json'
                ),
                'body' => $image_data
            ));

            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 201 || $status_code === 200) {
                    $body = wp_remote_retrieve_body($response);
                    $media = json_decode($body, true);

                    // 正常（単一オブジェクト）
                    if (is_array($media) && isset($media['id'])) {
                        $this->debug_manager->log(sprintf(
                            'アイキャッチ画像の同期が成功しました。（試行回数: %d/%d）',
                            $attempt + 1,
                            $this->max_retries
                        ), 'info', array(
                            'media_id' => $media['id'],
                            'media_url' => isset($media['source_url']) ? $media['source_url'] : '',
                            'site_url' => $site_data['url'],
                            'post_id' => $post_id
                        ));
                        return array(
                            'id' => $media['id'],
                            'source_url' => isset($media['source_url']) ? $media['source_url'] : '',
                            'alt_text' => isset($media['alt_text']) ? $media['alt_text'] : ''
                        );
                    }

                    // 例外（配列で返却された場合: 一覧が返る環境へのフォールバック）
                    if (is_array($media) && isset($media[0]) && is_array($media[0])) {
                        // ファイル名ベースで一致を探索
                        $base = pathinfo($file_name, PATHINFO_FILENAME);
                        $picked = null;
                        foreach ($media as $item) {
                            if (isset($item['id'])) {
                                if (isset($item['source_url']) && (stripos($item['source_url'], $base) !== false)) {
                                    $picked = $item;
                                    break;
                                }
                                // 次善策として最初の要素
                                if ($picked === null) {
                                    $picked = $item;
                                }
                            }
                        }
                        if (is_array($picked) && isset($picked['id'])) {
                            $this->debug_manager->log('メディアAPIがリストを返却したためフォールバックで採用', 'warning', array(
                                'site_url' => $site_data['url'],
                                'picked_id' => $picked['id']
                            ));
                            return array(
                                'id' => $picked['id'],
                                'source_url' => isset($picked['source_url']) ? $picked['source_url'] : '',
                                'alt_text' => isset($picked['alt_text']) ? $picked['alt_text'] : ''
                            );
                        }

                        // さらにフォールバック: ファイル名で検索
                        $search = rawurlencode($base);
                        $lookup = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/media?search=' . $search . '&per_page=5&orderby=date&order=desc', array(
                            'headers' => array(
                                'Authorization' => $auth_header
                            )
                        ));
                        if (!is_wp_error($lookup) && wp_remote_retrieve_response_code($lookup) === 200) {
                            $list = json_decode(wp_remote_retrieve_body($lookup), true);
                            if (is_array($list) && isset($list[0]['id'])) {
                                $this->debug_manager->log('メディアAPI検索により既存メディアを採用', 'warning', array(
                                    'site_url' => $site_data['url'],
                                    'picked_id' => $list[0]['id']
                                ));
                                return array(
                                    'id' => $list[0]['id'],
                                    'source_url' => isset($list[0]['source_url']) ? $list[0]['source_url'] : '',
                                    'alt_text' => isset($list[0]['alt_text']) ? $list[0]['alt_text'] : ''
                                );
                            }
                        }
                    }
                }
            }

            // 最大リトライ回数に達していない場合は待機
            if ($attempt < $this->max_retries - 1) {
                $status_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
                $response_headers = is_wp_error($response) ? array() : wp_remote_retrieve_headers($response);
                $wait_time = $this->calculate_backoff_time($attempt, $status_code, $response_headers);
                
                // レート制限マネージャーによる追加制御
                if ($this->rate_limit_manager && $status_code === 429) {
                    $this->rate_limit_manager->handle_rate_limit($site_data['url'], $response);
                }
                
                $this->debug_manager->log(sprintf(
                    'アイキャッチ画像の同期に失敗。%d秒後に再試行します。（試行回数: %d/%d）',
                    $wait_time,
                    $attempt + 1,
                    $this->max_retries
                ), 'warning', array(
                    'site_url' => $site_data['url'],
                    'post_id' => $post_id,
                    'status_code' => $status_code,
                    'wait_time' => $wait_time,
                    'error_type' => $status_code === 429 ? 'rate_limit' : ($status_code >= 500 ? 'server_error' : 'unknown'),
                    'response_body' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response)
                ));
                sleep($wait_time);
            } else {
                // 最大リトライ回数に達した場合の詳細なエラーログ出力
                $this->debug_manager->log('アイキャッチ画像の同期に失敗しました。最大リトライ回数に達しました。', 'error', array(
                    'site_url' => $site_data['url'],
                    'post_id' => $post_id,
                    'media_url' => $media_data['source_url'],
                    'status_code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response),
                    'response_body' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response)
                ));
            }
        }

        // すべての試行が失敗した場合
        $this->debug_manager->log('アイキャッチ画像の同期に失敗しました。', 'error', array(
            'site_url' => $site_data['url'],
            'post_id' => $post_id,
            'media_url' => $media_data['source_url']
        ));
        return null;
    }

    /**
     * リモートメディアURLの取得
     *
     * @param int $media_id メディアID
     * @param array $site_data サイトデータ
     * @return string|WP_Error メディアURL、失敗時はエラー
     */
    public function get_remote_media_url($media_id, $site_data) {
        try {
            $this->debug_manager->log('リモートメディアURLの取得を開始: ID ' . $media_id, 'info', array(
                'media_id' => $media_id,
                'site_url' => $site_data['url']
            ));

            // メディア情報の取得
            $response = wp_remote_get(
                $site_data['url'] . '/wp-json/wp/v2/media/' . $media_id,
                array(
                    'headers' => array(
                        'Authorization' => $this->auth_manager->get_auth_header($site_data)
                    )
                )
            );

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                throw new Exception('メディア情報の取得に失敗（HTTP ' . $response_code . '）');
            }

            $media_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($media_data['source_url'])) {
                throw new Exception('メディアURLが見つかりません');
            }

            $this->debug_manager->log('リモートメディアURLの取得が完了: ' . $media_data['source_url'], 'info', array(
                'media_id' => $media_id,
                'media_url' => $media_data['source_url'],
                'site_url' => $site_data['url']
            ));
            return $media_data['source_url'];
        } catch (Exception $e) {
            $this->debug_manager->log('リモートメディアURLの取得に失敗: ' . $e->getMessage(), 'error', array(
                'media_id' => $media_id,
                'site_url' => $site_data['url'],
                'exception' => $e->getMessage()
            ));
            return new WP_Error('media_url_error', $e->getMessage());
        }
    }

    /**
     * メディア同期処理
     *
     * @param array $site サイト情報
     * @param array $media_items メディアアイテム
     * @param callable $download_media_func メディアダウンロード関数
     * @return array 同期されたメディア
     */
    public function sync_media($site, $media_items, $download_media_func) {
        $synced_media = [];
        foreach ($media_items as $media) {
            $file = $download_media_func($media['url']);
            // WordPress 6.5以降のアプリケーションパスワード対応
            $auth_header = $this->auth_manager->get_auth_header($site);
            
            $response = wp_remote_post($site['url'] . '/wp-json/wp/v2/media', [
                'headers' => [
                    'Content-Disposition' => 'attachment; filename="' . basename($file) . '"',
                    'Authorization' => $auth_header
                ],
                'body' => file_get_contents($file)
            ]);
            
            $this->debug_manager->log('メディア同期処理', 'info', array(
                'site_url' => $site['url'],
                'media_url' => $media['url'],
                'response_code' => wp_remote_retrieve_response_code($response)
            ));
            
            $synced_media[$media['id']] = json_decode(wp_remote_retrieve_body($response))->id;
        }
        return $synced_media;
    }
}