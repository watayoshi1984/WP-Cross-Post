# WP_Cross_Post_Post_Data_Preparer_Interface インターフェース

## 概要

WP_Cross_Post_Post_Data_Preparer_Interfaceは、WP Cross Postプラグインの投稿データ準備クラスが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Manager_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Post_Data_Preparer_Interface extends WP_Cross_Post_Manager_Interface
```

## メソッド

### prepare_post_data($post, $selected_sites)
投稿データを準備します。

#### 説明
指定された投稿オブジェクトから、同期に必要なデータを準備します。

#### パラメータ
- $post (WP_Post): 投稿オブジェクト
- $selected_sites (array): 選択されたサイト

#### 戻り値
- 型: array|WP_Error
- 説明: 準備された投稿データ、失敗時はエラー

#### 例外
なし

### set_dependencies($debug_manager, $block_content_processor)
依存関係を設定します。

#### 説明
デバッグマネージャーとブロックコンテンツプロセッサーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $block_content_processor (WP_Cross_Post_Block_Content_Processor): ブロックコンテンツプロセッサー

#### 戻り値
なし

#### 例外
なし