<?php
/**
 * WP Cross Post 投稿データ準備クラス
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-manager.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-post-data-preparer.php';

/**
 * WP Cross Post 投稿データ準備クラス
 *
 * 投稿データ準備処理を管理します。
 */
class WP_Cross_Post_Post_Data_Preparer implements WP_Cross_Post_Post_Data_Preparer_Interface {

    /**
     * インスタンス
     *
     * @var WP_Cross_Post_Post_Data_Preparer|null
     */
    private static $instance = null;

    /**
     * デバッグマネージャー
     *
     * @var WP_Cross_Post_Debug_Manager
     */
    private $debug_manager;

    /**
     * ブロックコンテンツプロセッサー
     *
     * @var WP_Cross_Post_Block_Content_Processor
     */
    private $block_content_processor;

    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Post_Data_Preparer
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
     * @param WP_Cross_Post_Block_Content_Processor $block_content_processor ブロックコンテンツプロセッサー
     */
    public function set_dependencies($debug_manager, $block_content_processor) {
        $this->debug_manager = $debug_manager;
        $this->block_content_processor = $block_content_processor;
    }

    /**
     * サイト別設定を考慮した投稿データの準備
     *
     * @param WP_Post $post 投稿オブジェクト
     * @param string $site_id 対象サイトID
     * @param array $site_settings サイト別設定
     * @return array|WP_Error 準備された投稿データ、失敗時はエラー
     */
    public function prepare_post_data_for_site($post, $site_id, $site_settings = array()) {
        try {
            $site_data = $this->get_site_data($site_id);

            // 基本的な投稿データの準備
            $post_data = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $this->block_content_processor->prepare_block_content($post->post_content, $site_data),
                'excerpt' => $post->post_excerpt,
                'slug' => $post->post_name,
                'comment_status' => $post->comment_status,
                'ping_status' => $post->ping_status,
                'post_format' => get_post_format($post->ID) ?: 'standard',
                'meta' => $this->prepare_meta_data($post->ID)
            );

            // サイト別設定から投稿ステータスを設定
            if (!empty($site_settings['status'])) {
                $post_data['status'] = $site_settings['status'];
                
                // 予約投稿の場合は日時も設定
                if ($site_settings['status'] === 'future' && !empty($site_settings['date'])) {
                    $scheduled_date = date('Y-m-d H:i:s', strtotime($site_settings['date']));
                    $post_data['date'] = $scheduled_date;
                    $post_data['date_gmt'] = get_gmt_from_date($scheduled_date);
                } else {
                    // その他の場合はメインサイトの日時を使用
                    $post_data['date'] = $post->post_date;
                    $post_data['date_gmt'] = $post->post_date_gmt;
                }
            } else {
                // サイト別設定がない場合はメインサイトの設定を使用
                $post_data['status'] = $post->post_status;
                $post_data['date'] = $post->post_date;
                $post_data['date_gmt'] = $post->post_date_gmt;
            }

            // カテゴリー情報の取得（サイト設定で指定されたものを使用）
            $categories = array();
            if (isset($site_settings['category']) && !empty($site_settings['category'])) {
                $categories[] = intval($site_settings['category']);
                $this->debug_manager->log('サイト設定からカテゴリーを取得: ' . $site_settings['category'], 'debug', array(
                    'post_id' => $post->ID,
                    'site_id' => $site_id,
                    'selected_category' => $site_settings['category']
                ));
            }
            $post_data['categories'] = $categories;

            // タグ情報の取得（サイト設定で指定されたものを使用）
            $tags = array();
            if (isset($site_settings['tags']) && is_array($site_settings['tags'])) {
                foreach ($site_settings['tags'] as $tag_id) {
                    $tags[] = intval($tag_id);
                }
                $this->debug_manager->log('サイト設定からタグを取得', 'debug', array(
                    'post_id' => $post->ID,
                    'site_id' => $site_id,
                    'selected_tags' => $site_settings['tags']
                ));
            }
            $post_data['tags'] = $tags;

            // アイキャッチ画像の処理
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($thumbnail_id) {
                $post_data['featured_media'] = $thumbnail_id;
                $this->debug_manager->log('Thumbnail ID in prepare_post_data: ' . $thumbnail_id, 'info');
            }

            $this->debug_manager->log('投稿データの準備を開始', 'debug', $post_data);
            $this->debug_manager->log('投稿データの準備が完了', 'debug', $post_data);

            return $post_data;

        } catch (Exception $e) {
            $this->debug_manager->log('投稿データ準備エラー: ' . $e->getMessage(), 'error', array(
                'post_id' => $post->ID,
                'site_id' => $site_id,
                'exception' => $e->getTraceAsString()
            ));
            return new WP_Error('post_data_prepare_error', '投稿データの準備に失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * 投稿データの準備（従来版、後方互換性のため保持）
     *
     * @param WP_Post $post 投稿オブジェクト
     * @param array $selected_sites 選択されたサイト
     * @return array|WP_Error 準備された投稿データ、失敗時はエラー
     */
    public function prepare_post_data($post, $selected_sites) {
        try {
            $site_data = null;
            if (!empty($selected_sites)) {
                $first_site_id = reset($selected_sites);
                $site_data = $this->get_site_data($first_site_id);
            }

            // 基本的な投稿データの準備
            $post_data = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $this->block_content_processor->prepare_block_content($post->post_content, $site_data),
                'excerpt' => $post->post_excerpt,
                'status' => $post->post_status,
                'date' => $post->post_date,
                'date_gmt' => $post->post_date_gmt,
                'slug' => $post->post_name,
                'comment_status' => $post->comment_status,
                'ping_status' => $post->ping_status,
                'post_format' => get_post_format($post->ID) ?: 'standard',
                'meta' => $this->prepare_meta_data($post->ID)
            );

            // 投稿状態の処理
            // 予約投稿の場合
            if ($post->post_status === 'future') {
                $post_data['status'] = 'future';
                $post_data['date'] = $post->post_date;
                $post_data['date_gmt'] = $post->post_date_gmt;
            } 
            // 下書きの場合
            else if ($post->post_status === 'draft') {
                $post_data['status'] = 'draft';
            }
            // 非公開の場合
            else if ($post->post_status === 'private') {
                $post_data['status'] = 'private';
            }
            // その他の状態（公開など）
            else {
                $post_data['status'] = $post->post_status;
            }

            // スラッグの処理
            if ( empty( $post_data['slug'] ) ) {
                $post_data['slug'] = sanitize_title( $post->post_title );
                $this->debug_manager->log('スラッグを自動生成: ' . $post_data['slug'], 'info', array(
                    'post_id' => $post->ID,
                    'original_title' => $post->post_title
                ));
            }

            // カテゴリー情報の取得（サイト設定で指定されたものを使用）
            $categories = array();
            if (isset($site_settings['category']) && !empty($site_settings['category'])) {
                $categories[] = intval($site_settings['category']);
                $this->debug_manager->log('サイト設定からカテゴリーを取得: ' . $site_settings['category'], 'debug', array(
                    'post_id' => $post->ID,
                    'selected_category' => $site_settings['category']
                ));
            }
            $post_data['categories'] = $categories;

            // タグの取得（ローカルIDを取得し、リモートIDにマッピング）
            $tags = array();
            $tag_terms = wp_get_post_tags( $post->ID, array('fields' => 'all') );
            if ( !is_wp_error( $tag_terms ) ) {
                foreach ( $tag_terms as $term ) {
                    $local_id = (int)$term->term_id;
                    // サイト設定で指定されたタグIDを使用
                    if (isset($site_settings['tags']) && is_array($site_settings['tags']) && in_array($local_id, $site_settings['tags'])) {
                        $tags[] = $local_id;
                    }
                }
            } else {
                // サイト設定で指定されたタグを直接使用
                if (isset($site_settings['tags']) && is_array($site_settings['tags'])) {
                    foreach ($site_settings['tags'] as $tag_id) {
                        $tags[] = intval($tag_id);
                    }
                }
            }
            $post_data['tags'] = $tags;
            $this->debug_manager->log('タグ情報を取得', 'debug', array(
                'post_id' => $post->ID,
                'tag_count' => count($tags)
            ));

            // アイキャッチ画像の処理を追加
            $thumbnail_id = get_post_thumbnail_id( $post->ID );
            $this->debug_manager->log('Thumbnail ID in prepare_post_data: ' . $thumbnail_id, 'info', array(
                'post_id' => $post->ID
            ));
            if ( $thumbnail_id ) {
                $image_data = $this->prepare_featured_image( $thumbnail_id );
                if ( $image_data ) {
                    $post_data['featured_media'] = $image_data;
                    $this->debug_manager->log('アイキャッチ画像データを準備', 'info', array(
                        'post_id' => $post->ID,
                        'thumbnail_id' => $thumbnail_id
                    ));
                }
            }

            // 追加のメタデータを含める（コメント設定など）
            $post_data['meta']['_ping_status'] = get_post_meta($post->ID, '_ping_status', true);
            $post_data['meta']['_comment_status'] = get_post_meta($post->ID, '_comment_status', true);
            
            // コメント設定
            $post_data['comment_status'] = $post->comment_status;
            $post_data['ping_status'] = $post->ping_status;
            
            // 投稿フォーマット
            $post_format = get_post_format($post->ID);
            if ($post_format) {
                $post_data['meta']['_post_format'] = 'post-format-' . $post_format;
            }

            $this->debug_manager->log('投稿データの準備が完了', 'info', array(
                'post_id' => $post->ID,
                'post_title' => $post->post_title,
                'post_status' => $post->post_status
            ));
            return $post_data;

        } catch (Exception $e) {
            $this->debug_manager->log('投稿データの準備に失敗: ' . $e->getMessage(), 'error', array(
                'post_id' => $post->ID,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            return new WP_Error('prepare_post_data_failed', $e->getMessage());
        }
    }

    /**
     * ローカルタームIDをリモートIDへマッピング
     *
     * @param array|null $site_data サイト情報
     * @param string $taxonomy 'categories' | 'tags'
     * @param int $local_term_id ローカルタームID
     * @return int|null リモートタームID（見つからない場合はnull）
     */
    private function map_remote_term_id($site_data, $taxonomy, $local_term_id) {
        if (empty($site_data) || empty($site_data['id'])) {
            return null;
        }
        $option_key = sprintf('wp_cross_post_synced_term_%s_%s_%d', $site_data['id'], $taxonomy, $local_term_id);
        $remote_id = get_option($option_key, null);
        if ($remote_id !== null && $remote_id !== false) {
            return (int)$remote_id;
        }
        return null;
    }

    /**
     * サイトIDに基づいてサイト情報を取得
     *
     * @param string $site_id サイトID
     * @return array|null サイト情報、見つからない場合はnull
     */
    private function get_site_data($site_id) {
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $site) {
            if ($site['id'] === $site_id) {
                return $site;
            }
        }
        return null;
    }

    /**
     * メタデータの準備
     *
     * @param int $post_id 投稿ID
     * @return array メタデータ
     */
    private function prepare_meta_data($post_id) {
        $meta_data = array();
        $meta_keys = get_post_meta($post_id);
        
        foreach ($meta_keys as $key => $values) {
            // 除外するメタキー
            $excluded_keys = array('_edit_lock', '_edit_last');
            if (in_array($key, $excluded_keys)) {
                continue;
            }
            
            $meta_data[$key] = $values[0];
        }
        
        $this->debug_manager->log('メタデータを準備', 'debug', array(
            'post_id' => $post_id,
            'meta_count' => count($meta_data)
        ));
        
        return $meta_data;
    }

    /**
     * アイキャッチ画像の準備
     *
     * @param int $featured_image_id アイキャッチ画像ID
     * @return array|null アイキャッチ画像データ、失敗時はnull
     */
    private function prepare_featured_image($featured_image_id) {
        $image_src = wp_get_attachment_image_src($featured_image_id, 'full');
        if (!$image_src) {
            $this->debug_manager->log('アイキャッチ画像のソースを取得できませんでした', 'warning', array(
                'featured_image_id' => $featured_image_id
            ));
            return null;
        }
        
        $alt_text = get_post_meta($featured_image_id, '_wp_attachment_image_alt', true);
        $this->debug_manager->log('アイキャッチ画像データを準備', 'info', array(
            'featured_image_id' => $featured_image_id,
            'image_url' => $image_src[0],
            'alt_text' => $alt_text
        ));
        
        return array(
            'id' => $featured_image_id,
            'source_url' => $image_src[0],
            'alt_text' => $alt_text
        );
    }
}