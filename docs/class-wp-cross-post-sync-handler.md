# WP_Cross_Post_Sync_Handler クラス

## 概要

WP_Cross_Post_Sync_Handlerクラスは、WP Cross Postプラグインの投稿同期処理を管理するためのクラスです。このクラスは、AJAX同期投稿、投稿の同期、並列処理、非同期処理などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Sync_Handler implements WP_Cross_Post_Sync_Handler_Interface
```

## プロパティ

### $api_handler
APIハンドラーのインスタンスです。
- 型: WP_Cross_Post_API_Handler

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $site_handler
サイトハンドラーのインスタンスです。
- 型: WP_Cross_Post_Site_Handler

### $auth_manager
認証マネージャーのインスタンスです。
- 型: WP_Cross_Post_Auth_Manager

### $image_manager
画像マネージャーのインスタンスです。
- 型: WP_Cross_Post_Image_Manager

### $post_data_preparer
投稿データ準備クラスのインスタンスです。
- 型: WP_Cross_Post_Post_Data_Preparer

### $error_manager
エラーマネージャーのインスタンスです。
- 型: WP_Cross_Post_Error_Manager

### $rate_limit_manager
レート制限マネージャーのインスタンスです。
- 型: WP_Cross_Post_Rate_Limit_Manager

## メソッド

### __construct($api_handler, $debug_manager, $site_handler, $auth_manager, $image_manager, $post_data_preparer, $error_manager, $rate_limit_manager)
コンストラクタです。

#### 説明
クラスのインスタンスを初期化します。

#### パラメータ
- $api_handler (WP_Cross_Post_API_Handler): APIハンドラー
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $site_handler (WP_Cross_Post_Site_Handler): サイトハンドラー
- $auth_manager (WP_Cross_Post_Auth_Manager): 認証マネージャー
- $image_manager (WP_Cross_Post_Image_Manager): 画像マネージャー
- $post_data_preparer (WP_Cross_Post_Post_Data_Preparer): 投稿データ準備クラス
- $error_manager (WP_Cross_Post_Error_Manager): エラーマネージャー
- $rate_limit_manager (WP_Cross_Post_Rate_Limit_Manager): レート制限マネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$sync_handler = new WP_Cross_Post_Sync_Handler($api_handler, $debug_manager, $site_handler, $auth_manager, $image_manager, $post_data_preparer, $error_manager, $rate_limit_manager);
```

### ajax_sync_post()
AJAX同期投稿を行います。

#### 説明
AJAXリクエストを使用して、投稿を同期します。設定に応じて、同期処理、並列処理、または非同期処理で実行されます。

#### パラメータ
なし

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
// AJAXリクエストで呼び出されます
```

### sync_post($post_id, $selected_sites)
投稿を同期します。

#### 説明
指定された投稿IDを、選択されたサイトに同期します。

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
$result = $sync_handler->sync_post(123, ['site1', 'site2']);
```

### sync_post_parallel($post_id, $selected_sites)
並列処理で投稿を同期します。

#### 説明
指定された投稿IDを、選択されたサイトに並列処理で同期します。

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
$result = $sync_handler->sync_post_parallel(123, ['site1', 'site2']);
```