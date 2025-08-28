# WP_Cross_Post_Error_Manager_Interface インターフェース

## 概要

WP_Cross_Post_Error_Manager_Interfaceは、WP Cross Postプラグインのエラーマネージャーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Manager_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Error_Manager_Interface extends WP_Cross_Post_Manager_Interface
```

## メソッド

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