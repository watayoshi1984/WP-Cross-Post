# WP_Cross_Post_Sync_Engine クラス

## 概要

WP_Cross_Post_Sync_Engineクラスは、WP Cross Postプラグインの投稿同期処理を管理するためのクラスです。このクラスは、投稿の同期、並列処理、非同期処理、タクソノミーの全サイト同期などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Sync_Engine implements WP_Cross_Post_Sync_Engine_Interface
```

## プロパティ

### $auth_manager
認証マネージャーのインスタンスです。
- 型: WP_Cross_Post_Auth_Manager

### $image_manager
画像マネージャーのインスタンスです。
- 型: WP_Cross_Post_Image_Manager

### $error_manager
エラーマネージャーのインスタンスです。
- 型: WP_Cross_Post_Error_Manager

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $site_handler
サイトハンドラーのインスタンスです。
- 型: WP_Cross_Post_Site_Handler

### $api_handler
APIハンドラーのインスタンスです。
- 型: WP_Cross_Post_API_Handler

### $post_data_preparer
投稿データ準備クラスのインスタンスです。
- 型: WP_Cross_Post_Post_Data_Preparer

### $rate_limit_manager
レート制限マネージャーのインスタンスです。
- 型: WP_Cross_Post_Rate_Limit_Manager

## メソッド

### __construct($auth_manager, $image_manager, $error_manager, $debug_manager, $site_handler, $api_handler, $post_data_preparer, $rate_limit_manager)
コンストラクタです。

#### 説明
クラスのインスタンスを初期化します。

#### パラメータ
- $auth_manager (WP_Cross_Post_Auth_Manager): 認証マネージャー
- $image_manager (WP_Cross_Post_Image_Manager): 画像マネージャー
- $error_manager (WP_Cross_Post_Error_Manager): エラーマネージャー
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $site_handler (WP_Cross_Post_Site_Handler): サイトハンドラー
- $api_handler (WP_Cross_Post_API_Handler): APIハンドラー
- $post_data_preparer (WP_Cross_Post_Post_Data_Preparer): 投稿データ準備クラス
- $rate_limit_manager (WP_Cross_Post_Rate_Limit_Manager): レート制限マネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$sync_engine = new WP_Cross_Post_Sync_Engine($auth_manager, $image_manager, $error_manager, $debug_manager, $site_handler, $api_handler, $post_data_preparer, $rate_limit_manager);
```

### sync_post($post_id, $selected_sites = array())
投稿を同期します。

#### 説明
指定された投稿IDを、選択されたサイトに同期します。設定に応じて、同期処理または非同期処理で実行されます。

#### パラメータ
- $post_id (int): 投稿ID
- $selected_sites (array): 選択されたサイトIDの配列（オプション）

#### 戻り値
- 型: array|WP_Error
- 説明: 同期結果

#### 例外
なし

#### 使用例
```php
$result = $sync_engine->sync_post(123, ['site1', 'site2']);
```

### sync_taxonomies_to_all_sites()
タクソノミーを全サイトに同期します。

#### 説明
すべてのサイトにカテゴリーとタグを同期します。

#### パラメータ
なし

#### 戻り値
- 型: array
- 説明: 同期結果

#### 例外
なし

#### 使用例
```php
$results = $sync_engine->sync_taxonomies_to_all_sites();
```

### process_async_sync($post_id, $selected_sites)
非同期同期処理を実行します。

#### 説明
非同期処理用のカスタムポストタイプから情報を取得し、実際の同期処理を実行します。

#### パラメータ
- $post_id (int): 投稿ID
- $selected_sites (array): 選択されたサイト

#### 戻り値
- 型: array|WP_Error
- 説明: 同期結果

#### 例外
なし

#### 使用例
```php
// WordPressのスケジュール機能によって自動的に呼び出されます
```