# WP_Cross_Post_Config_Manager クラス

## 概要

WP_Cross_Post_Config_Managerクラスは、WP Cross Postプラグインの設定を管理するためのクラスです。このクラスは、設定の取得、更新、バリデーション、エクスポート、インポートなどの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Config_Manager
```

## 定数

### OPTION_KEY
設定を保存するためのオプションキーです。
- 型: string
- 値: 'wp_cross_post_settings'

### DEFAULT_SETTINGS
プラグインのデフォルト設定です。
- 型: array
- 値:
  ```php
  [
      'api_settings' => [
          'timeout' => 30,
          'retries' => 3,
          'batch_size' => 10
      ],
      'sync_settings' => [
          'parallel_sync' => false,
          'async_sync' => false,
          'rate_limit' => true
      ],
      'image_settings' => [
          'sync_images' => true,
          'max_image_size' => 5242880, // 5MB
          'image_quality' => 80
      ],
      'debug_settings' => [
          'debug_mode' => false,
          'log_level' => 'info'
      ],
      'cache_settings' => [
          'enable_cache' => true,
          'cache_duration' => 1800 // 30分
      ],
      'security_settings' => [
          'verify_ssl' => true,
          'encrypt_credentials' => true
      ]
  ]
  ```

## メソッド

### get_settings()
設定を取得します。

#### 説明
データベースから設定を取得し、デフォルト設定とマージして返します。

#### パラメータ
なし

#### 戻り値
- 型: array
- 説明: 設定の配列

#### 例外
なし

#### 使用例
```php
$settings = WP_Cross_Post_Config_Manager::get_settings();
```

### get_setting_group($group)
特定の設定グループを取得します。

#### 説明
指定されたグループの設定を取得します。

#### パラメータ
- $group (string): 設定グループ名

#### 戻り値
- 型: array
- 説明: 指定されたグループの設定

#### 例外
なし

#### 使用例
```php
$api_settings = WP_Cross_Post_Config_Manager::get_setting_group('api_settings');
```

### get_setting($group, $key, $default = null)
特定の設定値を取得します。

#### 説明
指定されたグループとキーの設定値を取得します。

#### パラメータ
- $group (string): 設定グループ名
- $key (string): 設定キー
- $default (mixed): デフォルト値（オプション）

#### 戻り値
- 型: mixed
- 説明: 設定値

#### 例外
なし

#### 使用例
```php
$timeout = WP_Cross_Post_Config_Manager::get_setting('api_settings', 'timeout', 30);
```

### update_settings($new_settings)
設定を更新します。

#### 説明
新しい設定をデータベースに保存します。

#### パラメータ
- $new_settings (array): 新しい設定の配列

#### 戻り値
- 型: array
- 説明: 更新された設定

#### 例外
なし

#### 使用例
```php
$new_settings = [
    'api_settings' => [
        'timeout' => 60
    ]
];
$updated_settings = WP_Cross_Post_Config_Manager::update_settings($new_settings);
```

### update_setting_group($group, $group_settings)
特定の設定グループを更新します。

#### 説明
指定されたグループの設定を更新します。

#### パラメータ
- $group (string): 設定グループ名
- $group_settings (array): グループの新しい設定

#### 戻り値
- 型: array
- 説明: 更新された設定

#### 例外
なし

#### 使用例
```php
$api_settings = [
    'timeout' => 60,
    'retries' => 5
];
$updated_settings = WP_Cross_Post_Config_Manager::update_setting_group('api_settings', $api_settings);
```

### validate_settings($settings)
設定のバリデーションを行います。

#### 説明
設定値のバリデーションとサニタイズを行います。

#### パラメータ
- $settings (array): バリデーション対象の設定

#### 戻り値
- 型: array
- 説明: バリデーション済みの設定

#### 例外
なし

#### 使用例
```php
$settings = [
    'api_settings' => [
        'timeout' => 500
    ]
];
$validated_settings = WP_Cross_Post_Config_Manager::validate_settings($settings);
```

### reset_settings()
設定をリセットします。

#### 説明
設定をデフォルト値にリセットします。

#### パラメータ
なし

#### 戻り値
- 型: array
- 説明: デフォルト設定

#### 例外
なし

#### 使用例
```php
$default_settings = WP_Cross_Post_Config_Manager::reset_settings();
```

### export_settings()
設定をエクスポートします。

#### 説明
設定をJSON形式でエクスポートします。セキュリティ設定は含まれません。

#### パラメータ
なし

#### 戻り値
- 型: string
- 説明: エクスポートされた設定（base64エンコードされたJSON）

#### 例外
なし

#### 使用例
```php
$exported_settings = WP_Cross_Post_Config_Manager::export_settings();
```

### import_settings($encoded_settings)
設定をインポートします。

#### 説明
エクスポートされた設定をインポートします。セキュリティ設定はインポートされません。

#### パラメータ
- $encoded_settings (string): エクスポートされた設定（base64エンコードされたJSON）

#### 戻り値
- 型: array|bool
- 説明: インポートされた設定、失敗した場合はfalse

#### 例外
なし

#### 使用例
```php
$imported = WP_Cross_Post_Config_Manager::import_settings($exported_settings);
```

### sanitize_settings($settings)
設定のサニタイズを行います。

#### 説明
設定値をサニタイズします。

#### パラメータ
- $settings (array): サニタイズ対象の設定

#### 戻り値
- 型: array
- 説明: サニタイズ済みの設定

#### 例外
なし

#### 使用例
```php
$settings = [
    'api_settings' => [
        'timeout' => '30'
    ]
];
$sanitized_settings = WP_Cross_Post_Config_Manager::sanitize_settings($settings);
```