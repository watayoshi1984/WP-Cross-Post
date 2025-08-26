<?php

class WP_Cross_Post_Site_Handler {
    private $api_handler;
    
    public function __construct() {
        $this->api_handler = new WP_Cross_Post_API_Handler();
    }
    
    public function add_site( $site_data ) {
        // 🚀 feat: サイト追加処理開始時のデバッグログ出力を追加
        error_log( '[WP Cross Post] サイト追加処理開始。受信データ: ' . print_r( $site_data, true ) );

        // 🚀 feat: 入力データのバリデーションを強化
        if ( empty( $site_data ) || !is_array( $site_data ) ) {
            error_log( '[WP Cross Post] サイトデータが不正です。' );
            throw new Exception( 'サイト情報が正しくありません。' );
        }

        // URLの存在チェック
        if ( empty( $site_data['url'] ) ) {
            error_log( '[WP Cross Post] URLが指定されていません。' );
            throw new Exception( 'URLを指定してください。' );
        }

        // 静的メソッドを使用してURLを正規化
        $normalized_url = WP_Cross_Post_API_Handler::normalizeUrl($site_data['url']);
        
        // ... 既存のサイト追加処理処理 ...
    }
    
    // ... 他のメソッド ...
} 