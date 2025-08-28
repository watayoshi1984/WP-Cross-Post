# WP_Cross_Post_Site_Handler_Interface インターフェース

## 概要

WP_Cross_Post_Site_Handler_Interfaceは、WP Cross Postプラグインのサイトハンドラーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Handler_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Site_Handler_Interface extends WP_Cross_Post_Handler_Interface
```

## メソッド

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