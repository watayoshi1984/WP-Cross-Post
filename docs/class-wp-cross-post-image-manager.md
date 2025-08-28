# WP_Cross_Post_Image_Manager クラス

## 概要

WP_Cross_Post_Image_Managerクラスは、WP Cross Postプラグインの画像処理を管理するためのクラスです。このクラスは、アイキャッチ画像の同期、リモートメディアURLの取得、メディア同期などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Image_Manager implements WP_Cross_Post_Image_Manager_Interface
```

## プロパティ

### $instance
クラスのシングルトンインスタンスです。
- 型: WP_Cross_Post_Image_Manager|null

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $auth_manager
認証マネージャーのインスタンスです。
- 型: WP_Cross_Post_Auth_Manager

### $max_retries
最大リトライ回数です。
- 型: int
- デフォルト値: 3

### $retry_wait_time
リトライ待機時間（秒）です。
- 型: int
- デフォルト値: 2

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。インスタンスが存在しない場合は新しく作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Image_Manager
- 説明: クラスのインスタンス

#### 例外
なし

#### 使用例
```php
$image_manager = WP_Cross_Post_Image_Manager::get_instance();
```

### set_dependencies($debug_manager, $auth_manager)
依存関係を設定します。

#### 説明
デバッグマネージャーと認証マネージャーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $auth_manager (WP_Cross_Post_Auth_Manager): 認証マネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$image_manager->set_dependencies($debug_manager, $auth_manager);
```

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

#### 使用例
```php
$image_manager->set_max_retries(5);
```

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

#### 使用例
```php
$image_manager->set_retry_wait_time(5);
```

### sync_featured_image($site_data, $media_data, $post_id)
アイキャッチ画像を同期します。

#### 説明
指定されたサイトにアイキャッチ画像を同期します。最大リトライ回数まで失敗した場合はnullを返します。

#### パラメータ
- $site_data (array): サイトデータ
- $media_data (array): メディアデータ（'source_url'キーを含む）
- $post_id (int): 投稿ID

#### 戻り値
- 型: array|null
- 説明: 同期されたメディア情報、失敗時はnull

#### 例外
なし

#### 使用例
```php
$site_data = [
    'url' => 'https://example.com',
    'username' => 'user',
    'app_password' => 'password'
];
$media_data = [
    'source_url' => 'https://example.com/image.jpg'
];
$synced_media = $image_manager->sync_featured_image($site_data, $media_data, 123);
```

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

#### 使用例
```php
$media_url = $image_manager->get_remote_media_url(456, $site_data);
```

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

#### 使用例
```php
$synced_media = $image_manager->sync_media($site, $media_items, $download_media_func);
```