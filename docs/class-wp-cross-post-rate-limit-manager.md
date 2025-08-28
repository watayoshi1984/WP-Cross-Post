# WP_Cross_Post_Rate_Limit_Manager クラス

## 概要

WP_Cross_Post_Rate_Limit_Managerクラスは、WP Cross PostプラグインのAPIレート制限対策を管理するためのクラスです。このクラスは、レート制限のチェックと待機、レート制限情報の更新、レート制限の処理などの機能を提供します。

## クラス定義

```php
class WP_Cross_Post_Rate_Limit_Manager implements WP_Cross_Post_Rate_Limit_Manager_Interface
```

## プロパティ

### $instance
クラスのシングルトンインスタンスです。
- 型: WP_Cross_Post_Rate_Limit_Manager|null

### $debug_manager
デバッグマネージャーのインスタンスです。
- 型: WP_Cross_Post_Debug_Manager

### $error_manager
エラーマネージャーのインスタンスです。
- 型: WP_Cross_Post_Error_Manager

### $rate_limit_info
レート制限情報です。
- 型: array

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。インスタンスが存在しない場合は新しく作成します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Rate_Limit_Manager
- 説明: クラスのインスタンス

#### 例外
なし

#### 使用例
```php
$rate_limit_manager = WP_Cross_Post_Rate_Limit_Manager::get_instance();
```

### set_dependencies($debug_manager, $error_manager)
依存関係を設定します。

#### 説明
デバッグマネージャーとエラーマネージャーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $error_manager (WP_Cross_Post_Error_Manager): エラーマネージャー

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$rate_limit_manager->set_dependencies($debug_manager, $error_manager);
```

### check_and_wait_for_rate_limit($site_url)
レート制限のチェックと待機を行います。

#### 説明
指定されたサイトURLのレート制限をチェックし、必要に応じて待機します。

#### パラメータ
- $site_url (string): サイトURL

#### 戻り値
- 型: bool|WP_Error
- 説明: 待機が必要な場合はtrue、エラーの場合はWP_Error

#### 例外
なし

#### 使用例
```php
$result = $rate_limit_manager->check_and_wait_for_rate_limit('https://example.com');
```

### update_rate_limit_info($site_url, $retry_after)
レート制限情報を更新します。

#### 説明
指定されたサイトURLのレート制限情報を更新します。

#### パラメータ
- $site_url (string): サイトURL
- $retry_after (int): リトライ待機時間（秒）

#### 戻り値
なし

#### 例外
なし

#### 使用例
```php
$rate_limit_manager->update_rate_limit_info('https://example.com', 60);
```

### handle_rate_limit($site_url, $response)
レート制限を処理します。

#### 説明
APIレスポンスからレート制限を検出し、レート制限情報を更新します。

#### パラメータ
- $site_url (string): サイトURL
- $response (WP_Error): APIレスポンス

#### 戻り値
- 型: WP_Error
- 説明: 処理済みのエラーオブジェクト

#### 例外
なし

#### 使用例
```php
$response = new WP_Error('rate_limit', 'Rate limit exceeded', ['retry_after' => 60]);
$handled_response = $rate_limit_manager->handle_rate_limit('https://example.com', $response);
```