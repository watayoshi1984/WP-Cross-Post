# WP_Cross_Post_API_Handler クラス

## 概要

WP_Cross_Post_API_Handlerクラスは、WP Cross PostプラグインのAPI通信処理を管理するためのクラスです。このクラスは、サイトへの接続テスト、認証ヘッダーの取得、システム要件のチェック、REST APIアクセスの制限、URLのバリデーションと正規化などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_API_Handler implements WP_Cross_Post_API_Handler_Interface
```

## プロパティ

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $min_php_version
最小PHPバージョンです。
- 型: string
- 値: '7.4.0'

### $min_wp_version
最小WordPressバージョンです。
- 型: string
- 値: '6.5.0'

### $auth_manager
認証マネージャーのインスタンスです。
- 型: WP_Cross_Post_Auth_Manager

### $error_manager
エラーマネージャーのインスタンスです。
- 型: WP_Cross_Post_Error_Manager

### $rate_limit_manager
レート制限マネージャーのインスタンスです。
- 型: WP_Cross_Post_Rate_Limit_Manager

## メソッド

### __construct($debug_manager, $auth_manager, $error_manager, $rate_limit_manager)
コンストラクタです。

#### 説明
クラスのインスタンスを初期化し、システム要件をチェックします。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $auth_manager (WP_Cross_Post_Auth_Manager): 認証マネージャー
- $error_manager (WP_Cross_Post_Error_Manager): エラーマネージャー
- $rate_limit_manager (WP_Cross_Post_Rate_Limit_Manager): レート制限マネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$api_handler = new WP_Cross_Post_API_Handler($debug_manager, $auth_manager, $error_manager, $rate_limit_manager);
```

### test_connection($site_data)
サイトへの接続テストを行います。

#### 説明
指定されたサイトデータを使用して、サイトへの接続テストを行います。

#### パラメータ
- $site_data (array): サイトデータ

#### 戻り値
- 型: array|WP_Error
- 説明: 接続テスト結果

#### 例外
なし

#### 使用例
```php
$site_data = [
    'url' => 'https://example.com',
    'username' => 'user',
    'app_password' => 'password'
];
$result = $api_handler->test_connection($site_data);
```

### normalize_site_url($url)
サイトURLを正規化します。

#### 説明
指定されたURLを正規化します。

#### パラメータ
- $url (string): URL

#### 戻り値
- 型: string
- 説明: 正規化されたURL

#### 例外
なし

#### 使用例
```php
$normalized_url = $api_handler->normalize_site_url('https://example.com/');
```