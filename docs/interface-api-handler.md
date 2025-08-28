# WP_Cross_Post_API_Handler_Interface インターフェース

## 概要

WP_Cross_Post_API_Handler_Interfaceは、WP Cross PostプラグインのAPIハンドラーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Handler_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_API_Handler_Interface extends WP_Cross_Post_Handler_Interface
```

## メソッド

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