# WP_Cross_Post_Rate_Limit_Manager_Interface インターフェース

## 概要

WP_Cross_Post_Rate_Limit_Manager_Interfaceは、WP Cross Postプラグインのレート制限マネージャーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Manager_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Rate_Limit_Manager_Interface extends WP_Cross_Post_Manager_Interface
```

## メソッド

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