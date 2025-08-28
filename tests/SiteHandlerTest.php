<?php

use PHPUnit\Framework\TestCase;

class SiteHandlerTest extends TestCase {
    private $site_handler;
    private $mock_debug_manager;
    private $mock_auth_manager;
    private $mock_error_manager;
    private $mock_api_handler;
    private $mock_rate_limit_manager;
    
    protected function setUp(): void {
        // モックオブジェクトを作成
        $this->mock_debug_manager = new WP_Cross_Post_Mock_Debug_Manager();
        $this->mock_auth_manager = new WP_Cross_Post_Mock_Auth_Manager();
        $this->mock_error_manager = WP_Cross_Post_Error_Manager::create_for_test();
        $this->mock_error_manager->set_dependencies($this->mock_debug_manager);
        $this->mock_api_handler = new WP_Cross_Post_Mock_API_Handler();
        $this->mock_rate_limit_manager = new WP_Cross_Post_Mock_Rate_Limit_Manager();
        
        // サイトハンドラーのインスタンスを作成
        $this->site_handler = new WP_Cross_Post_Site_Handler(
            $this->mock_debug_manager,
            $this->mock_auth_manager,
            $this->mock_error_manager,
            $this->mock_api_handler,
            $this->mock_rate_limit_manager
        );
    }
    
    public function testAddSite() {
        // サイトデータを準備
        $site_data = [
            'name' => 'Test Site',
            'url' => 'https://example.com',
            'username' => 'testuser',
            'app_password' => 'testpassword'
        ];
        
        // サイトを追加
        $result = $this->site_handler->add_site($site_data);
        
        // 結果が文字列（サイトID）であることを確認
        $this->assertIsString($result);
        
        // サイトIDが期待される形式であることを確認
        $this->assertStringStartsWith('site_', $result);
    }
    
    public function testRemoveSite() {
        // サイトデータを準備
        $site_data = [
            'id' => 'site_test123',
            'name' => 'Test Site',
            'url' => 'https://example.com',
            'username' => 'testuser',
            'app_password' => 'testpassword'
        ];
        
        // サイトを追加
        add_option('wp_cross_post_sites', [$site_data]);
        
        // サイトを削除
        $result = $this->site_handler->remove_site('site_test123');
        
        // 削除が成功したことを確認
        $this->assertTrue($result);
        
        // サイトが削除されたことを確認
        $sites = get_option('wp_cross_post_sites', []);
        $this->assertEmpty($sites);
    }
    
    public function testGetSites() {
        // サイトデータを準備
        $sites_data = [
            [
                'id' => 'site_test123',
                'name' => 'Test Site 1',
                'url' => 'https://example1.com',
                'username' => 'testuser1',
                'app_password' => 'testpassword1'
            ],
            [
                'id' => 'site_test456',
                'name' => 'Test Site 2',
                'url' => 'https://example2.com',
                'username' => 'testuser2',
                'app_password' => 'testpassword2'
            ]
        ];
        
        // サイトを追加
        update_option('wp_cross_post_sites', $sites_data);
        
        // サイトを取得
        $sites = $this->site_handler->get_sites();
        
        // サイトが配列であることを確認
        $this->assertIsArray($sites);
        
        // サイト数が期待通りであることを確認
        $this->assertCount(2, $sites);
        
        // 最初のサイトのデータが期待通りであることを確認
        $this->assertEquals('site_test123', $sites[0]['id']);
        $this->assertEquals('Test Site 1', $sites[0]['name']);
    }
    
    public function testSyncTaxonomies() {
        // サイトデータを準備
        $site_data = [
            'id' => 'site_test123',
            'name' => 'Test Site',
            'url' => 'https://example.com',
            'username' => 'testuser',
            'app_password' => 'testpassword'
        ];
        
        // タクソノミーの同期を実行
        $result = $this->site_handler->sync_taxonomies($site_data);
        
        // 結果が真であることを確認（同期が成功したことを示す）
        $this->assertTrue($result);
    }
    
    public function testGetSiteData() {
        // サイトデータを準備
        $site_data = [
            'id' => 'site_test123',
            'name' => 'Test Site',
            'url' => 'https://example.com',
            'username' => 'testuser',
            'app_password' => 'testpassword'
        ];
        
        // サイトを追加
        update_option('wp_cross_post_sites', [$site_data]);
        
        // サイトデータを取得
        $result = $this->site_handler->get_site_data('site_test123');
        
        // 結果が配列であることを確認
        $this->assertIsArray($result);
        
        // サイトデータが期待通りであることを確認
        $this->assertEquals('site_test123', $result['id']);
        $this->assertEquals('Test Site', $result['name']);
    }
    
    public function testClearSiteCache() {
        // サイトデータを準備
        $site_data = [
            'id' => 'site_test123',
            'name' => 'Test Site',
            'url' => 'https://example.com',
            'username' => 'testuser',
            'app_password' => 'testpassword'
        ];
        
        // キャッシュにサイトデータを保存
        set_transient('wp_cross_post_site_site_test123', $site_data, 3600);
        
        // キャッシュが保存されたことを確認
        $cached_data = get_transient('wp_cross_post_site_site_test123');
        $this->assertNotEmpty($cached_data);
        
        // サイトキャッシュをクリア
        $this->site_handler->clear_site_cache('site_test123');
        
        // キャッシュがクリアされたことを確認
        $cached_data = get_transient('wp_cross_post_site_site_test123');
        $this->assertFalse($cached_data);
    }
}