# WP_Cross_Post_Post_Data_Preparer クラス

## 概要

WP_Cross_Post_Post_Data_Preparerクラスは、WP Cross Postプラグインの投稿データ準備処理を管理するためのクラスです。このクラスは、投稿データの準備、メタデータの準備、アイキャッチ画像の準備などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Post_Data_Preparer implements WP_Cross_Post_Post_Data_Preparer_Interface
```

## プロパティ

### $instance
クラスのシングルトンインスタンスです。
- 型: WP_Cross_Post_Post_Data_Preparer|null

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $block_content_processor
ブロックコンテンツプロセッサーのインスタンスです。
- 型: WP_Cross_Post_Block_Content_Processor

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。インスタンスが存在しない場合は新しく作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Post_Data_Preparer
- 説明: クラスのインスタンス

#### 例外
なし

#### 使用例
```php
$post_data_preparer = WP_Cross_Post_Post_Data_Preparer::get_instance();
```

### set_dependencies($debug_manager, $block_content_processor)
依存関係を設定します。

#### 説明
デバッグマネージャーとブロックコンテンツプロセッサーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $block_content_processor (WP_Cross_Post_Block_Content_Processor): ブロックコンテンツプロセッサー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$post_data_preparer->set_dependencies($debug_manager, $block_content_processor);
```

### prepare_post_data($post, $selected_sites)
投稿データを準備します。

#### 説明
指定された投稿オブジェクトから、同期に必要なデータを準備します。カテゴリー、タグ、アイキャッチ画像などの情報を含みます。

#### パラメータ
- $post (WP_Post): 投稿オブジェクト
- $selected_sites (array): 選択されたサイト

#### 戻り値
- 型: array|WP_Error
- 説明: 準備された投稿データ、失敗時はエラー

#### 例外
なし

#### 使用例
```php
$post = get_post(123);
$selected_sites = ['site1', 'site2'];
$post_data = $post_data_preparer->prepare_post_data($post, $selected_sites);
```

### prepare_meta_data($post_id)
メタデータを準備します。

#### 説明
指定された投稿IDのメタデータを準備します。除外するメタキーを除いてすべてのメタデータを含みます。

#### パラメータ
- $post_id (int): 投稿ID

#### 戻り値
- 型: array
- 説明: メタデータ

#### 例外
なし

#### 使用例
```php
$meta_data = $post_data_preparer->prepare_meta_data(123);
```

### prepare_featured_image($featured_image_id)
アイキャッチ画像を準備します。

#### 説明
指定されたアイキャッチ画像IDから、画像のURLと代替テキストを準備します。

#### パラメータ
- $featured_image_id (int): アイキャッチ画像ID

#### 戻り値
- 型: array|null
- 説明: アイキャッチ画像データ、失敗時はnull

#### 例外
なし

#### 使用例
```php
$featured_image_data = $post_data_preparer->prepare_featured_image(456);
```