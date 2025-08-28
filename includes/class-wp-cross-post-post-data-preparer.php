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
     * 投稿データの準備
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

            // カテゴリー情報の取得（スラッグとIDの両方を送信）
            $categories = array();
            $category_terms = wp_get_post_categories( $post->ID, array('fields' => 'all') );
            if ( !is_wp_error( $category_terms ) ) {
                foreach ( $category_terms as $term ) {
                    $categories[] = $term->term_id;
                }
                $this->debug_manager->log('カテゴリー情報を取得: ' . json_encode( $categories ), 'debug', array(
                    'post_id' => $post->ID,
                    'category_count' => count($categories)
                ));
            } else {
                throw new Exception( 'カテゴリーの取得に失敗: ' . $category_terms->get_error_message() );
            }
            $post_data['categories'] = $categories;

            // タグの取得（スラッグとIDの両方を送信）
            $tags = array();
            $tag_terms = wp_get_post_tags( $post->ID, array('fields' => 'all') );
            if ( !is_wp_error( $tag_terms ) ) {
                foreach ( $tag_terms as $term ) {
                    $tags[] = $term->term_id;
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