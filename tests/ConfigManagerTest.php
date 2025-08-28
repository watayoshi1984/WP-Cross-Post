<?php

use PHPUnit\Framework\TestCase;

class ConfigManagerTest extends TestCase {
    public function testGetSettings() {
        // デフォルト設定を取得
        $settings = WP_Cross_Post_Config_Manager::get_settings();
        
        // 設定が配列であることを確認
        $this->assertIsArray($settings);
        
        // 必要な設定グループが存在することを確認
        $this->assertArrayHasKey('api_settings', $settings);
        $this->assertArrayHasKey('sync_settings', $settings);
        $this->assertArrayHasKey('image_settings', $settings);
        $this->assertArrayHasKey('debug_settings', $settings);
        $this->assertArrayHasKey('cache_settings', $settings);
        $this->assertArrayHasKey('security_settings', $settings);
    }
    
    public function testGetSettingGroup() {
        // API設定グループを取得
        $api_settings = WP_Cross_Post_Config_Manager::get_setting_group('api_settings');
        
        // API設定が配列であることを確認
        $this->assertIsArray($api_settings);
        
        // 必要なAPI設定が存在することを確認
        $this->assertArrayHasKey('timeout', $api_settings);
        $this->assertArrayHasKey('retries', $api_settings);
        $this->assertArrayHasKey('batch_size', $api_settings);
    }
    
    public function testGetSetting() {
        // 個別の設定値を取得
        $timeout = WP_Cross_Post_Config_Manager::get_setting('api_settings', 'timeout', 30);
        
        // タイムアウト値が整数であることを確認
        $this->assertIsInt($timeout);
        
        // デフォルト値が正しく適用されていることを確認
        $this->assertEquals(30, $timeout);
    }
    
    public function testUpdateSettings() {
        // 現在の設定を取得
        $current_settings = WP_Cross_Post_Config_Manager::get_settings();
        
        // 新しい設定を準備
        $new_settings = $current_settings;
        $new_settings['api_settings']['timeout'] = 60;
        
        // 設定を更新
        $updated_settings = WP_Cross_Post_Config_Manager::update_settings($new_settings);
        
        // 更新された設定が期待通りであることを確認
        $this->assertEquals(60, $updated_settings['api_settings']['timeout']);
    }
    
    public function testValidateSettings() {
        // 不正な設定を準備
        $invalid_settings = [
            'api_settings' => [
                'timeout' => -1, // 無効なタイムアウト値
                'retries' => 15, // 無効なリトライ回数
                'batch_size' => 0 // 無効なバッチサイズ
            ]
        ];
        
        // 設定を検証
        $validated_settings = WP_Cross_Post_Config_Manager::validate_settings($invalid_settings);
        
        // 検証後の設定が期待通りに修正されていることを確認
        $this->assertEquals(1, $validated_settings['api_settings']['timeout']); // 最小値に修正
        $this->assertEquals(10, $validated_settings['api_settings']['retries']); // 最大値に修正
        $this->assertEquals(1, $validated_settings['api_settings']['batch_size']); // 最小値に修正
    }
    
    public function testSanitizeSettings() {
        // サニタイズが必要な設定を準備
        $dirty_settings = [
            'api_settings' => [
                'timeout' => '30abc', // 文字列が含まれる
                'retries' => '3xyz', // 文字列が含まれる
                'batch_size' => '10test' // 文字列が含まれる
            ],
            'sync_settings' => [
                'parallel_sync' => 'true', // 文字列の真偽値
                'async_sync' => 'false', // 文字列の真偽値
                'rate_limit' => '1' // 文字列の真偽値
            ]
        ];
        
        // 設定をサニタイズ
        $sanitized_settings = WP_Cross_Post_Config_Manager::sanitize_settings($dirty_settings);
        
        // サニタイズ後の設定が期待通りであることを確認
        $this->assertIsInt($sanitized_settings['api_settings']['timeout']);
        $this->assertIsInt($sanitized_settings['api_settings']['retries']);
        $this->assertIsInt($sanitized_settings['api_settings']['batch_size']);
        $this->assertIsBool($sanitized_settings['sync_settings']['parallel_sync']);
        $this->assertIsBool($sanitized_settings['sync_settings']['async_sync']);
        $this->assertIsBool($sanitized_settings['sync_settings']['rate_limit']);
    }
}