# WP_Cross_Post_Auth_Manager_Interface インターフェース

## 概要

WP_Cross_Post_Auth_Manager_Interfaceは、WP Cross Postプラグインの認証マネージャーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Manager_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Auth_Manager_Interface extends WP_Cross_Post_Manager_Interface
```

## メソッド

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