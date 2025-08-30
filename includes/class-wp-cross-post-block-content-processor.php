<?php
/**
 * WP Cross Post ブロックコンテンツプロセッサー
 *
 * @package WP_Cross_Post
 */

// インターフェースの読み込み
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-manager.php';
require_once WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-block-content-processor.php';

/**
 * WP Cross Post ブロックコンテンツプロセッサークラス
 *
 * ブロックコンテンツ処理を管理します。
 */
class WP_Cross_Post_Block_Content_Processor implements WP_Cross_Post_Block_Content_Processor_Interface {

    /**
     * インスタンス
     *
     * @var WP_Cross_Post_Block_Content_Processor|null
     */
    private static $instance = null;

    /**
     * デバッグマネージャー
     *
     * @var WP_Cross_Post_Debug_Manager
     */
    private $debug_manager;

    /**
     * 画像マネージャー
     *
     * @var WP_Cross_Post_Image_Manager
     */
    private $image_manager;

    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Block_Content_Processor
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
     * @param WP_Cross_Post_Image_Manager $image_manager 画像マネージャー
     */
    public function set_dependencies($debug_manager, $image_manager) {
        $this->debug_manager = $debug_manager;
        $this->image_manager = $image_manager;
    }

    /**
     * ブロックコンテンツの準備
     *
     * @param string $content 投稿コンテンツ
     * @param array|null $site_data サイトデータ
     * @return string 処理されたコンテンツ
     */
    public function prepare_block_content($content, $site_data) {
        if (empty($content)) {
            $this->debug_manager->log('コンテンツが空です', 'debug');
            return $content;
        }
    
        // 画像URLの対応表
        $image_url_map = array();
    
        // ブロックの解析
        $blocks = parse_blocks($content);
        $this->debug_manager->log('ブロックを解析', 'debug', array(
            'block_count' => count($blocks)
        ));
    
        // 各ブロックを処理
        $this->process_blocks($blocks, $image_url_map, $site_data);
    
        // 最終的なコンテンツを生成
        $updated_content = serialize_blocks($blocks);
        $this->debug_manager->log('コンテンツをシリアライズ', 'debug', array(
            'content_length' => strlen($updated_content)
        ));
    
        // 画像URLの置換
        $replace_count = 0;
        foreach ($image_url_map as $original_url => $new_url) {
            $updated_content = str_replace($original_url, $new_url, $updated_content);
            $replace_count++;
        }
        
        $this->debug_manager->log('画像URLを置換', 'debug', array(
            'replace_count' => $replace_count
        ));
    
        return $updated_content;
    }

    /**
     * ブロックの処理
     *
     * @param array &$blocks ブロック配列
     * @param array &$image_url_map 画像URLマップ
     * @param array|null $site_data サイトデータ
     */
    public function process_blocks(&$blocks, &$image_url_map, $site_data) {
        $processed_count = 0;
        foreach ($blocks as &$block) {
            // SWELLテーマのブロックの特別処理
            if (isset($block['blockName']) && strpos($block['blockName'], 'swell') === 0) {
                $block['attrs']['originalContent'] = $block['innerHTML'];
                $this->debug_manager->log('SWELLブロックを処理', 'debug', array(
                    'block_name' => $block['blockName']
                ));
            }
    
            // 画像URLの抽出と置換
            if (!empty($block['innerHTML'])) {
                $updated_inner_html = $this->process_image_urls($block['innerHTML'], $image_url_map, $site_data);
                $block['innerHTML'] = $updated_inner_html;
                $processed_count++;
            }
    
            // インナーブロックを再帰的に処理
            if (!empty($block['innerBlocks'])) {
                $this->process_inner_blocks($block['innerBlocks'], $image_url_map, $site_data);
            }
        }
        
        $this->debug_manager->log('ブロックを処理', 'debug', array(
            'processed_count' => $processed_count
        ));
    }
    
    /**
     * インナーブロックの処理
     *
     * @param array &$blocks ブロック配列
     * @param array &$image_url_map 画像URLマップ
     * @param array|null $site_data サイトデータ
     */
    public function process_inner_blocks(&$blocks, &$image_url_map, $site_data) {
        $processed_count = 0;
        foreach ($blocks as &$block) {
            // SWELLテーマのブロックの特別処理
            if (isset($block['blockName']) && strpos($block['blockName'], 'swell') === 0) {
                $block['attrs']['originalContent'] = $block['innerHTML'];
                $this->debug_manager->log('SWELLインナーブロックを処理', 'debug', array(
                    'block_name' => $block['blockName']
                ));
            }
    
            // 画像URLの抽出と置換
            if (!empty($block['innerHTML'])) {
                $updated_inner_html = $this->process_image_urls($block['innerHTML'], $image_url_map, $site_data);
                $block['innerHTML'] = $updated_inner_html;
                $processed_count++;
            }
    
            // インナーブロックを再帰的に処理
            if (!empty($block['innerBlocks'])) {
                $this->process_inner_blocks($block['innerBlocks'], $image_url_map, $site_data);
            }
        }
        
        $this->debug_manager->log('インナーブロックを処理', 'debug', array(
            'processed_count' => $processed_count
        ));
    }
    
    /**
     * 画像URLの処理
     *
     * @param string $html HTMLコンテンツ
     * @param array &$image_url_map 画像URLマップ
     * @param array|null $site_data サイトデータ
     * @return string 処理されたHTMLコンテンツ
     */
    public function process_image_urls($html, &$image_url_map, $site_data) {
        $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';
    
        $updated_html = preg_replace_callback($pattern, function ($matches) use (&$image_url_map, $site_data) {
            $original_url = $matches[1];
    
            // 既に処理済みの場合はスキップ
            if (isset($image_url_map[$original_url])) {
                $this->debug_manager->log('既に処理済みの画像URLをスキップ', 'debug', array(
                    'url' => $original_url
                ));
                return str_replace($original_url, $image_url_map[$original_url], $matches[0]);
            }
    
            // 新しい画像URLを生成（最大3回まで再試行）
            $media_result = null;
            $retry_count = 0;
            $max_retries = 3;

            while ($retry_count < $max_retries) {
                $media_result = $this->sync_content_image($site_data, $original_url);
                if ($media_result) {
                    break;
                }
                $retry_count++;
                if ($retry_count < $max_retries) {
                    $this->debug_manager->log(sprintf(
                        '画像の同期に失敗。%d回目の再試行を行います。',
                        $retry_count + 1
                    ), 'warning', array(
                        'url' => $original_url,
                        'retry_count' => $retry_count + 1
                    ));
                    sleep(2); // 2秒待機して再試行
                }
            }

            if ($media_result) {
                $image_url_map[$original_url] = $media_result['source_url'];
                $this->debug_manager->log('画像URLをマップに追加', 'debug', array(
                    'original_url' => $original_url,
                    'new_url' => $media_result['source_url']
                ));
                return str_replace($original_url, $media_result['source_url'], $matches[0]);
            } else {
                $this->debug_manager->log('画像の同期に失敗: ' . $original_url, 'error', array(
                    'url' => $original_url
                ));
                return $matches[0]; // 失敗した場合は元のURLを保持
            }
        }, $html);
    
        return $updated_html;
    }

    /**
     * コンテンツ画像の同期
     *
     * @param array $site_data サイトデータ
     * @param string $image_url 画像URL
     * @return array|null 同期されたメディア情報、失敗時はnull
     */
    private function sync_content_image($site_data, $image_url) {
        try {
            $this->debug_manager->log('コンテンツ画像の同期を開始', 'info', array(
                'image_url' => $image_url,
                'site_url' => $site_data['url']
            ));
            
            // メディアのアップロード
            $media_data = array(
                'source_url' => $image_url,
                'alt_text' => ''
            );

            $result = $this->image_manager->sync_featured_image($site_data, $media_data, 0);
            
            $this->debug_manager->log('コンテンツ画像の同期を完了', 'info', array(
                'image_url' => $image_url,
                'site_url' => $site_data['url'],
                'success' => $result !== null
            ));
            
            return $result;
        } catch (Exception $e) {
            $this->debug_manager->log('コンテンツ画像の同期に失敗: ' . $e->getMessage(), 'error', array(
                'image_url' => $image_url,
                'site_url' => $site_data['url'],
                'exception' => $e->getMessage()
            ));
            return null;
        }
    }
}