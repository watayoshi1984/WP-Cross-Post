# WP_Cross_Post_Site_Handler クラス

## 概要

WP_Cross_Post_Site_Handlerクラスは、WP Cross Postプラグインのサイト管理処理を管理するためのクラスです。このクラスは、サイトの追加、削除、取得、タクソノミーの同期、キャッシュ管理などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Site_Handler implements WP_Cross_Post_Site_Handler_Interface
```

## 定数

### SITE_CACHE_PREFIX
サイトキャッシュのプレフィックスです。
- 型: string
- 値: 'wp_cross_post_site_'

### TAXONOMY_CACHE_PREFIX
タクソノミーキャッシュのプレフィックスです。
- 型: string
- 値: 'wp_cross_post_taxonomies_'

### SITE_CACHE_EXPIRY
サイトキャッシュの有効期限（秒）です。
- 型: int
- 値: 30 * MINUTE_IN_SECONDS

### TAXONOMY_CACHE_EXPIRY
タクソノミーキャッシュの有効期限（秒）です。
- 型: int
- 値: HOUR_IN_SECONDS

## プロパティ

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $auth_manager
認証マネージャーのインスタンスです。
- 型: WP_Cross_Post_Auth_Manager

### $error_manager
エラーマネージャーのインスタンスです。
- 型: WP_Cross_Post_Error_Manager

### $api_handler
APIハンドラーのインスタンスです。
- 型: WP_Cross_Post_API_Handler

### $rate_limit_manager
レート制限マネージャーのインスタンスです。
- 型: WP_Cross_Post_Rate_Limit_Manager

## メソッド

### __construct($debug_manager, $auth_manager, $error_manager, $api_handler, $rate_limit_manager)
コンストラクタです。

#### 説明
クラスのインスタンスを初期化します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $auth_manager (WP_Cross_Post_Auth_Manager): 認証マネージャー
- $error_manager (WP_Cross_Post_Error_Manager): エラーマネージャー
- $api_handler (WP_Cross_Post_API_Handler): APIハンドラー
- $rate_limit_manager (WP_Cross_Post_Rate_Limit_Manager): レート制限マネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler = new WP_Cross_Post_Site_Handler($debug_manager, $auth_manager, $error_manager, $api_handler, $rate_limit_manager);
```

### add_site($site_data)
サイトを追加します。

#### 説明
指定されたサイトデータを使用して、新しいサイトを追加します。

#### パラメータ
- $site_data (array): サイトデータ

#### 戻り値
- 型: string|WP_Error
- 説明: サイトIDまたはエラー

#### 例外
なし

#### 使用例
```php
$site_data = [
    'name' => 'Example Site',
    'url' => 'https://example.com',
    'username' => 'user',
    'app_password' => 'password'
];
$site_id = $site_handler->add_site($site_data);
```

### remove_site($site_id)
サイトを削除します。

#### 説明
指定されたサイトIDのサイトを削除します。

#### パラメータ
- $site_id (string): サイトID

#### 戻り値
- 型: bool|WP_Error
- 説明: 成功した場合はtrue、失敗した場合はエラー

#### 例外
なし

#### 使用例
```php
$removed = $site_handler->remove_site('site_1234567890');
```

### get_sites()
サイト一覧を取得します。

#### 説明
登録されているすべてのサイトの一覧を取得します。

#### パラメータ
なし

#### 戻り値
- 型: array
- 説明: サイト一覧

#### 例外
なし

#### 使用例
```php
$sites = $site_handler->get_sites();
```

### get_site_data($site_id)
サイトデータを取得します。

#### 説明
指定されたサイトIDのサイトデータを取得します。

#### パラメータ
- $site_id (string): サイトID

#### 戻り値
- 型: array|null
- 説明: サイトデータ、見つからない場合はnull

#### 例外
なし

#### 使用例
```php
$site_data = $site_handler->get_site_data('site_1234567890');
```

### clear_site_cache($site_id)
サイトデータのキャッシュをクリアします。

#### 説明
指定されたサイトIDのサイトデータのキャッシュをクリアします。

#### パラメータ
- $site_id (string): サイトID

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler->clear_site_cache('site_1234567890');
```

### clear_all_sites_cache()
すべてのサイトデータのキャッシュをクリアします。

#### 説明
すべてのサイトデータのキャッシュをクリアします。

#### パラメータ
なし

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler->clear_all_sites_cache();
```

### get_cached_taxonomies($site_id)
キャッシュされたタクソノミー情報を取得します。

#### 説明
指定されたサイトIDのキャッシュされたタクソノミー情報を取得します。

#### パラメータ
- $site_id (string): サイトID

#### 戻り値
- 型: array|null
- 説明: タクソノミー情報、見つからない場合はnull

#### 例外
なし

#### 使用例
```php
$taxonomies = $site_handler->get_cached_taxonomies('site_1234567890');
```

### clear_taxonomies_cache($site_id)
タクソノミー情報のキャッシュをクリアします。

#### 説明
指定されたサイトIDのタクソノミー情報のキャッシュをクリアします。

#### パラメータ
- $site_id (string): サイトID

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler->clear_taxonomies_cache('site_1234567890');
```

### clear_all_taxonomies_cache()
すべてのタクソノミー情報のキャッシュをクリアします。

#### 説明
すべてのタクソノミー情報のキャッシュをクリアします。

#### パラメータ
なし

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler->clear_all_taxonomies_cache();
```

### sync_all_sites_taxonomies()
全サイトのタクソノミーを同期します。

#### 説明
すべてのサイトのカテゴリーとタグを同期します。

#### パラメータ
なし

#### 戻り値
- 型: array
- 説明: 同期結果

#### 例外
なし

#### 使用例
```php
$results = $site_handler->sync_all_sites_taxonomies();
```

### schedule_taxonomies_sync()
定期実行用のフックを設定します。

#### 説明
タクソノミー同期の定期実行用のフックを設定します。

#### パラメータ
なし

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler->schedule_taxonomies_sync();
```

### unschedule_taxonomies_sync()
定期実行のフックを解除します。

#### 説明
タクソノミー同期の定期実行用のフックを解除します。

#### パラメータ
なし

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$site_handler->unschedule_taxonomies_sync();
```

### ajax_sync_taxonomies()
手動同期用のAJAXハンドラーです。

#### 説明
AJAXリクエストを使用して、手動でタクソノミーを同期します。

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

### ajax_add_site()
サイト追加のAJAXハンドラーです。

#### 説明
AJAXリクエストを使用して、新しいサイトを追加します。

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

### ajax_remove_site()
サイト削除のAJAXハンドラーです。

#### 説明
AJAXリクエストを使用して、サイトを削除します。

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