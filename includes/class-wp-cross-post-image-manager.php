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
     * リトライ待機時間（秒）
     *
     * @var int
     */
    private $retry_wait_time = 5;

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
     */
    public function set_dependencies($debug_manager, $auth_manager) {
        $this->debug_manager = $debug_manager;
        $this->auth_manager = $auth_manager;
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
        $this->retry_wait_time = $retry_wait_time;
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
        
        // 画像ファイルの取得
        $image_data = file_get_contents($media_data['source_url']);
        if ($image_data === false) {
            $this->debug_manager->log('アイキャッチ画像の取得に失敗しました。', 'error', array(
                'site_url' => $site_data['url'],
                'post_id' => $post_id,
                'media_url' => $media_data['source_url']
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
        
        // 最大リトライ回数まで試行
        for ($attempt = 0; $attempt < $this->max_retries; $attempt++) {
            $response = wp_remote_post($site_data['url'] . '/wp-json/wp/v2/media', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => $auth_header,
                    'Content-Disposition' => 'attachment; filename="' . basename($media_data['source_url']) . '"'
                ),
                'body' => $image_data
            ));

            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 201) {
                    $media = json_decode(wp_remote_retrieve_body($response), true);
                    
                    // 同期先のサイトでアイキャッチ画像が適切に設定されているかを確認
                    $media_check_response = wp_remote_get($site_data['url'] . '/wp-json/wp/v2/media/' . $media['id'], array(
                        'timeout' => 30,
                        'headers' => array(
                            'Authorization' => $auth_header
                        )
                    ));
                    
                    if (!is_wp_error($media_check_response) && wp_remote_retrieve_response_code($media_check_response) === 200) {
                        $media_check = json_decode(wp_remote_retrieve_body($media_check_response), true);
                        if (isset($media_check['source_url']) && $media_check['source_url'] === $media['source_url']) {
                            $this->debug_manager->log(sprintf(
                                'アイキャッチ画像の同期が成功しました。（試行回数: %d/%d）',
                                $attempt + 1,
                                $this->max_retries
                            ), 'info', array(
                                'media_id' => $media['id'],
                                'media_url' => $media['source_url'],
                                'site_url' => $site_data['url'],
                                'post_id' => $post_id
                            ));
                            return array(
                                'id' => $media['id'],
                                'source_url' => $media['source_url'],
                                'alt_text' => $media['alt_text']
                            );
                        }
                    }
                }
            }

            // 最大リトライ回数に達していない場合は待機
            if ($attempt < $this->max_retries - 1) {
                $this->debug_manager->log(sprintf(
                    'アイキャッチ画像の同期に失敗。%d秒後に再試行します。（試行回数: %d/%d）',
                    $this->retry_wait_time,
                    $attempt + 1,
                    $this->max_retries
                ), 'warning', array(
                    'site_url' => $site_data['url'],
                    'post_id' => $post_id,
                    'response' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response),
                    'status_code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response)
                ));
                sleep($this->retry_wait_time);
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