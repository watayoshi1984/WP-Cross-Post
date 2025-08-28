# WP_Cross_Post_Sync_Engine_Interface インターフェース

## 概要

WP_Cross_Post_Sync_Engine_Interfaceは、WP Cross Postプラグインの同期エンジンが実装すべきインターフェースです。

## インターフェース定義

```php
interface WP_Cross_Post_Sync_Engine_Interface
```

## メソッド

### sync_post($post_id)
投稿を同期します。

#### 説明
指定された投稿IDを同期します。

#### パラメータ
- $post_id (int): 投稿ID

#### 戻り値
- 型: array|WP_Error
- 説明: 同期結果

#### 例外
なし

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