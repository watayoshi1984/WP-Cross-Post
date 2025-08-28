# WP_Cross_Post_Error_Manager クラス

## 概要

WP_Cross_Post_Error_Managerクラスは、WP Cross Postプラグインのエラーハンドリングとログ出力を管理するためのクラスです。このクラスは、APIエラー、同期エラー、バリデーションエラー、一般的なエラーの処理、詳細なエラーログ出力、エラー通知などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Error_Manager implements WP_Cross_Post_Error_Manager_Interface
```

## プロパティ

### $instance
クラスのシングルトンインスタンスです。
- 型: WP_Cross_Post_Error_Manager|null

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。インスタンスが存在しない場合は新しく作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Error_Manager
- 説明: クラスのインスタンス

#### 例外
なし

#### 使用例
```php
$error_manager = WP_Cross_Post_Error_Manager::get_instance();
```

### create_for_test()
テスト用のインスタンスを作成します。

#### 説明
テスト用に新しいインスタンスを作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Error_Manager
- 説明: 新しいインスタンス

#### 例外
なし

#### 使用例
```php
$test_error_manager = WP_Cross_Post_Error_Manager::create_for_test();
```

### set_dependencies($debug_manager)
依存関係を設定します。

#### 説明
デバッグマネージャーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$error_manager->set_dependencies($debug_manager);
```

### handle_api_error($response, $context = '')
APIエラーを処理します。

#### 説明
APIレスポンスからエラーを検出し、ログに出力し、エラー通知を行います。

#### パラメータ
- $response (WP_Error|array): APIレスポンス
- $context (string): コンテキスト（オプション）

#### 戻り値
- 型: WP_Error
- 説明: エラーオブジェクト

#### 例外
なし

#### 使用例
```php
$response = wp_remote_get('https://example.com/api');
$error = $error_manager->handle_api_error($response, 'API呼び出し');
```

### handle_sync_error($e, $context = '')
同期エラーを処理します。

#### 説明
同期処理中の例外を処理し、ログに出力し、エラー通知を行います。

#### パラメータ
- $e (Exception): 例外
- $context (string): コンテキスト（オプション）

#### 戻り値
- 型: WP_Error
- 説明: エラーオブジェクト

#### 例外
なし

#### 使用例
```php
try {
    // 同期処理
} catch (Exception $e) {
    $error = $error_manager->handle_sync_error($e, '投稿同期');
}
```

### handle_validation_error($field, $message)
バリデーションエラーを処理します。

#### 説明
バリデーションエラーを処理し、ログに出力し、エラー通知を行います。

#### パラメータ
- $field (string): フィールド名
- $message (string): エラーメッセージ

#### 戻り値
- 型: WP_Error
- 説明: エラーオブジェクト

#### 例外
なし

#### 使用例
```php
$error = $error_manager->handle_validation_error('username', 'ユーザー名は必須です。');
```

### handle_general_error($message, $type = 'general_error')
一般的なエラーを処理します。

#### 説明
一般的なエラーを処理し、ログに出力し、エラー通知を行います。

#### パラメータ
- $message (string): エラーメッセージ
- $type (string): エラータイプ（オプション、デフォルト: 'general_error'）

#### 戻り値
- 型: WP_Error
- 説明: エラーオブジェクト

#### 例外
なし

#### 使用例
```php
$error = $error_manager->handle_general_error('予期しないエラーが発生しました。');
```

### log_detailed_error($message, $type = 'error', $context = array(), $file = '', $line = 0)
詳細なエラーログを出力します。

#### 説明
詳細なコンテキスト情報を含むエラーログを出力し、エラー通知を行います。

#### パラメータ
- $message (string): エラーメッセージ
- $type (string): エラータイプ（オプション、デフォルト: 'error'）
- $context (array): コンテキスト情報（オプション）
- $file (string): ファイル名（オプション）
- $line (int): 行番号（オプション）

#### 戻り値
- 型: WP_Error
- 説明: エラーオブジェクト

#### 例外
なし

#### 使用例
```php
$error = $error_manager->log_detailed_error(
    'データベース接続エラー',
    'error',
    ['host' => 'localhost'],
    __FILE__,
    __LINE__
);
```

### notify_error($message, $type = 'error', $context = array())
エラーを通知します。

#### 説明
エラー通知の設定に従って、エラーをメール通知します。

#### パラメータ
- $message (string): エラーメッセージ
- $type (string): エラータイプ（オプション、デフォルト: 'error'）
- $context (array): コンテキスト情報（オプション）

#### 戻り値
- 型: bool
- 説明: 通知が成功したかどうか

#### 例外
なし

#### 使用例
```php
$notified = $error_manager->notify_error('エラーが発生しました。', 'error', ['details' => '詳細情報']);
```

### update_notification_settings($settings)
エラー通知設定を更新します。

#### 説明
エラー通知の設定を更新します。

#### パラメータ
- $settings (array): 通知設定

#### 戻り値
- 型: bool
- 説明: 更新が成功したかどうか

#### 例外
なし

#### 使用例
```php
$settings = [
    'enabled' => true,
    'email' => 'admin@example.com',
    'threshold' => 'warning'
];
$updated = $error_manager->update_notification_settings($settings);
```

### get_notification_settings()
エラー通知設定を取得します。

#### 説明
現在のエラー通知設定を取得します。

#### パラメータ
なし

#### 戻り値
- 型: array
- 説明: 通知設定

#### 例外
なし

#### 使用例
```php
$settings = $error_manager->get_notification_settings();
```