# WP_Cross_Post_Block_Content_Processor_Interface インターフェース

## 概要

WP_Cross_Post_Block_Content_Processor_Interfaceは、WP Cross Postプラグインのブロックコンテンツプロセッサーが実装すべきインターフェースです。このインターフェースは、WP_Cross_Post_Manager_Interfaceを継承しています。

## インターフェース定義

```php
interface WP_Cross_Post_Block_Content_Processor_Interface extends WP_Cross_Post_Manager_Interface
```

## メソッド

### prepare_block_content($content, $site_data)
ブロックコンテンツを準備します。

#### 説明
指定されたコンテンツからブロックを解析し、画像URLを置換して準備します。

#### パラメータ
- $content (string): 投稿コンテンツ
- $site_data (array|null): サイトデータ

#### 戻り値
- 型: string
- 説明: 処理されたコンテンツ

#### 例外
なし

### set_dependencies($debug_manager, $image_manager)
依存関係を設定します。

#### 説明
デバッグマネージャーと画像マネージャーの依存関係を設定します。

#### パラメータ
- $debug_manager (WP_Cross_Post_Debug_Manager): デバッグマネージャー
- $image_manager (WP_Cross_Post_Image_Manager): 画像マネージャー

#### 戻り値
なし

#### 例外
なし