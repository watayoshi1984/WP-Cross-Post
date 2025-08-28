# WP_Cross_Post_Manager_Interface インターフェース

## 概要

WP_Cross_Post_Manager_Interfaceは、WP Cross Postプラグインのすべてのマネージャークラスが実装すべき共通インターフェースです。

## インターフェース定義

```php
interface WP_Cross_Post_Manager_Interface
```

## メソッド

### get_instance()
クラスのシングルトンインスタンスを取得します。

#### 説明
クラスの唯一のインスタンスを取得します。

#### パラメータ
なし

#### 戻り値
- 型: WP_Cross_Post_Manager_Interface
- 説明: クラスのインスタンス

#### 例外
なし