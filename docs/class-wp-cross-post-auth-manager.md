# WP_Cross_Post_Auth_Manager クラス

## 概要

WP_Cross_Post_Auth_Managerクラスは、WP Cross Postプラグインの認証処理を管理するためのクラスです。このクラスは、WordPressのバージョンに応じた認証ヘッダーの生成と認証情報のサニタイズ機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Auth_Manager implements WP_Cross_Post_Auth_Manager_Interface
```

## プロパティ

### $instance
クラスのシングルトンインスタンスです。
- 型: WP_Cross_Post_Auth_Manager|null

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。インスタンスが存在しない場合は新しく作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Auth_Manager
- 説明: クラスのインスタンス

#### 例外
なし

#### 使用例
```php
$auth_manager = WP_Cross_Post_Auth_Manager::get_instance();
```

### get_auth_header($site_data)
認証ヘッダーを生成します。

#### 説明
WordPressのバージョンに応じて、Basic認証またはアプリケーションパスワード認証のヘッダーを生成します。

#### パラメータ
- $site_data (array): サイトデータ（'username'と'app_password'キーを含む）

#### 戻り値
- 型: string
- 説明: 認証ヘッダー

#### 例外
なし

#### 使用例
```php
$site_data = [
    'username' => 'user',
    'app_password' => 'password'
];
$auth_header = $auth_manager->get_auth_header($site_data);
```

### sanitize_credential($credential)
認証情報をサニタイズします。

#### 説明
認証情報から危険な文字を除去し、安全な形式にサニタイズします。

#### パラメータ
- $credential (string): 認証情報

#### 戻り値
- 型: string
- 説明: サニタイズされた認証情報

#### 例外
なし

#### 使用例
```php
$sanitized_credential = $auth_manager->sanitize_credential('user@name!');
```