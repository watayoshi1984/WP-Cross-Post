# WP_Cross_Post_Image_Manager_Interface インターフェース

## 概要

WP_Cross_Post_Image_Manager_Interfaceは、WP Cross Postプラグインの画像マネージャーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Manager_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Image_Manager_Interface extends WP_Cross_Post_Manager_Interface
```

## メソッド

### set_max_retries($max_retries)
最大リトライ回数を設定します。

#### 説明
画像同期処理の最大リトライ回数を設定します。

#### パラメータ
- $max_retries (int): 最大リトライ回数

#### 戻り値
なし

#### 例外
なし

### set_retry_wait_time($retry_wait_time)
リトライ待機時間を設定します。

#### 説明
画像同期処理のリトライ待機時間を設定します。

#### パラメータ
- $retry_wait_time (int): リトライ待機時間（秒）

#### 戻り値
なし

#### 例外
なし

### sync_featured_image($site_data, $media_data, $post_id)
アイキャッチ画像を同期します。

#### 説明
指定されたサイトにアイキャッチ画像を同期します。

#### パラメータ
- $site_data (array): サイトデータ
- $media_data (array): メディアデータ（'source_url'キーを含む）
- $post_id (int): 投稿ID

#### 戻り値
- 型: array|null
- 説明: 同期されたメディア情報、失敗時はnull

#### 例外
なし

### get_remote_media_url($media_id, $site_data)
リモートメディアURLを取得します。

#### 説明
指定されたメディアIDのリモートメディアURLを取得します。

#### パラメータ
- $media_id (int): メディアID
- $site_data (array): サイトデータ

#### 戻り値
- 型: string|WP_Error
- 説明: メディアURL、失敗時はエラー

#### 例外
なし

### sync_media($site, $media_items, $download_media_func)
メディアを同期します。

#### 説明
指定されたメディアアイテムをサイトに同期します。

#### パラメータ
- $site (array): サイト情報
- $media_items (array): メディアアイテム
- $download_media_func (callable): メディアダウンロード関数

#### 戻り値
- 型: array
- 説明: 同期されたメディア

#### 例外
なし