# WP_Cross_Post_Sync_Handler_Interface インターフェース

## 概要

WP_Cross_Post_Sync_Handler_Interfaceは、WP Cross Postプラグインの同期ハンドラーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Handler_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Sync_Handler_Interface extends WP_Cross_Post_Handler_Interface
```

## メソッド

### ajax_sync_post()
AJAX同期投稿を行います。

#### 説明
AJAXリクエストを使用して、投稿を同期します。

#### パラメータ
なし

#### 戻り値
なし

#### 例外
なし

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