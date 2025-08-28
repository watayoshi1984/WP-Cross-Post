<?php

use PHPUnit\Framework\TestCase;

class ErrorManagerTest extends TestCase {
    private $error_manager;
    private $mock_debug_manager;
    
    protected function setUp(): void {
        // モックデバッグマネージャーを作成
        $this->mock_debug_manager = new WP_Cross_Post_Mock_Debug_Manager();
        
        // エラーマネージャーのインスタンスをテスト用に作成
        $this->error_manager = WP_Cross_Post_Error_Manager::create_for_test();
        $this->error_manager->set_dependencies($this->mock_debug_manager);
    }
    
    public function testHandleApiErrorWithWPError() {
        // WP_Errorオブジェクトを作成
        $wp_error = new WP_Error('test_error', 'Test error message');
        
        // APIエラーを処理
        $result = $this->error_manager->handle_api_error($wp_error, 'Test context');
        
        // 結果がWP_Errorオブジェクトであることを確認
        $this->assertInstanceOf(WP_Error::class, $result);
        
        // エラーコードが期待通りであることを確認
        $this->assertEquals('api_error', $result->get_error_code());
    }
    
    public function testHandleApiErrorWithRateLimit() {
        // レート制限のレスポンスを模倣
        $response = [
            'response' => [
                'code' => 429,
                'message' => 'Too Many Requests'
            ],
            'headers' => [
                'retry-after' => '5'
            ]
        ];
        
        // APIエラーを処理
        $result = $this->error_manager->handle_api_error($response, 'Test context');
        
        // 結果がWP_Errorオブジェクトであることを確認
        $this->assertInstanceOf(WP_Error::class, $result);
        
        // エラーコードが期待通りであることを確認
        $this->assertEquals('rate_limit', $result->get_error_code());
    }
    
    public function testHandleSyncError() {
        // 例外を作成
        $exception = new Exception('Test exception message', 123);
        
        // 同期エラーを処理
        $result = $this->error_manager->handle_sync_error($exception, 'Test context');
        
        // 結果がWP_Errorオブジェクトであることを確認
        $this->assertInstanceOf(WP_Error::class, $result);
        
        // エラーコードが期待通りであることを確認
        $this->assertEquals('sync_error', $result->get_error_code());
    }
    
    public function testHandleValidationError() {
        // バリデーションエラーを処理
        $result = $this->error_manager->handle_validation_error('test_field', 'Test validation message');
        
        // 結果がWP_Errorオブジェクトであることを確認
        $this->assertInstanceOf(WP_Error::class, $result);
        
        // エラーコードが期待通りであることを確認
        $this->assertEquals('validation_error', $result->get_error_code());
    }
    
    public function testHandleGeneralError() {
        // 一般的なエラーを処理
        $result = $this->error_manager->handle_general_error('Test general error message', 'test_type');
        
        // 結果がWP_Errorオブジェクトであることを確認
        $this->assertInstanceOf(WP_Error::class, $result);
        
        // エラーコードが期待通りであることを確認
        $this->assertEquals('test_type', $result->get_error_code());
    }
    
    public function testLogDetailedError() {
        // 詳細なエラーをログ出力
        $result = $this->error_manager->log_detailed_error(
            'Test detailed error message',
            'error',
            ['test_key' => 'test_value'],
            'test-file.php',
            123
        );
        
        // 結果がWP_Errorオブジェクトであることを確認
        $this->assertInstanceOf(WP_Error::class, $result);
        
        // エラーコードが期待通りであることを確認
        $this->assertEquals('error', $result->get_error_code());
    }
    
    public function testUpdateNotificationSettings() {
        // 通知設定を準備
        $settings = [
            'enabled' => true,
            'email' => 'test@example.com',
            'threshold' => 'warning'
        ];
        
        // 通知設定を更新
        $result = $this->error_manager->update_notification_settings($settings);
        
        // 更新が成功したことを確認
        $this->assertTrue($result);
        
        // 通知設定を取得
        $notification_settings = $this->error_manager->get_notification_settings();
        
        // 設定が期待通りであることを確認
        $this->assertTrue($notification_settings['enabled']);
        $this->assertEquals('test@example.com', $notification_settings['email']);
        $this->assertEquals('warning', $notification_settings['threshold']);
    }
}