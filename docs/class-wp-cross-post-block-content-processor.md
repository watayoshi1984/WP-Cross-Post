# WP_Cross_Post_Block_Content_Processor クラス

## 概要

WP_Cross_Post_Block_Content_Processorクラスは、WP Cross Postプラグインのブロックコンテンツ処理を管理するためのクラスです。このクラスは、ブロックコンテンツの準備、ブロックの処理、インナーブロックの処理、画像URLの処理などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Block_Content_Processor implements WP_Cross_Post_Block_Content_Processor_Interface
```

## プロパティ

### $instance
クラスのシングルトンインスタンスです。
- 型: WP_Cross_Post_Block_Content_Processor|null

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $image_manager
画像マネージャーのインスタンスです。
- 型: WP_Cross_Post_Image_Manager

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。インスタンスが存在しない場合は新しく作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Block_Content_Processor
- 説明: クラスのインスタンス

#### 例外
なし

#### 使用例
```php
$block_content_processor = WP_Cross_Post_Block_Content_Processor::get_instance();
```

### set_dependencies($debug_manager, $image_manager)
依存関係を設定します。

#### 説明
デバッグマネージャーと画像マネージャーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $image_manager (WP_Cross_Post_Image_Manager): 画像マネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$block_content_processor->set_dependencies($debug_manager, $image_manager);
```

### prepare_block_content($content, $site_data)
ブロックコンテンツを準備します。

#### 説明
指定されたコンテンツからブロックを解析し、画像URLを置換して準備します。

#### パラメータ
- $content (string): 投稿コンテンツ
- $site_data (array|null): サイトデータ

#### 戻り値
- 型: string
- 説明: 処理されたコンテンツ

#### 例外
なし

#### 使用例
```php
$processed_content = $block_content_processor->prepare_block_content($content, $site_data);
```

### process_blocks(&$blocks, &$image_url_map, $site_data)
ブロックを処理します。

#### 説明
指定されたブロック配列を処理し、SWELLテーマのブロックの特別処理と画像URLの抽出を行います。

#### パラメータ
- $blocks (array): ブロック配列（参照渡し）
- $image_url_map (array): 画像URLマップ（参照渡し）
- $site_data (array|null): サイトデータ

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$blocks = parse_blocks($content);
$image_url_map = [];
$block_content_processor->process_blocks($blocks, $image_url_map, $site_data);
```

### process_inner_blocks(&$blocks, &$image_url_map, $site_data)
インナーブロックを処理します。

#### 説明
指定されたインナーブロック配列を処理し、SWELLテーマのブロックの特別処理と画像URLの抽出を行います。

#### パラメータ
- $blocks (array): ブロック配列（参照渡し）
- $image_url_map (array): 画像URLマップ（参照渡し）
- $site_data (array|null): サイトデータ

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$inner_blocks = $block['innerBlocks'];
$image_url_map = [];
$block_content_processor->process_inner_blocks($inner_blocks, $image_url_map, $site_data);
```

### process_image_urls($html, &$image_url_map, $site_data)
画像URLを処理します。

#### 説明
指定されたHTMLコンテンツから画像URLを抽出し、リモートサイトに同期します。

#### パラメータ
- $html (string): HTMLコンテンツ
- $image_url_map (array): 画像URLマップ（参照渡し）
- $site_data (array|null): サイトデータ

#### 戻り値
- 型: string
- 説明: 処理されたHTMLコンテンツ

#### 例外
なし

#### 使用例
```php
$html = '<img src="https://example.com/image.jpg" />';
$image_url_map = [];
$processed_html = $block_content_processor->process_image_urls($html, $image_url_map, $site_data);
```

### sync_content_image($site_data, $image_url)
コンテンツ画像を同期します。

#### 説明
指定された画像URLのコンテンツ画像をリモートサイトに同期します。

#### パラメータ
- $site_data (array): サイトデータ
- $image_url (string): 画像URL

#### 戻り値
- 型: array|null
- 説明: 同期されたメディア情報、失敗時はnull

#### 例外
なし

#### 使用例
```php
$synced_media = $block_content_processor->sync_content_image($site_data, 'https://example.com/image.jpg');
```