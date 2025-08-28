<?php

use PHPUnit\Framework\TestCase;

class SyncEngineTest extends TestCase {
    private $sync_engine;
    private $mock_auth_manager;
    private $mock_image_manager;
    private $mock_error_manager;
    private $mock_debug_manager;
    private $mock_site_handler;
    private $mock_api_handler;
    private $mock_post_data_preparer;
    private $mock_rate_limit_manager;
    
    protected function setUp(): void {
        // モックオブジェクトを作成
        $this->mock_auth_manager = new WP_Cross_Post_Mock_Auth_Manager();
        $this->mock_image_manager = new WP_Cross_Post_Mock_Image_Manager();
        $this->mock_error_manager = WP_Cross_Post_Error_Manager::create_for_test();
        $this->mock_error_manager->set_dependencies(new WP_Cross_Post_Mock_Debug_Manager());
        $this->mock_debug_manager = new WP_Cross_Post_Mock_Debug_Manager();
        $this->mock_site_handler = new WP_Cross_Post_Site_Handler(
            $this->mock_debug_manager,
            $this->mock_auth_manager,
            $this->mock_error_manager,
            new WP_Cross_Post_Mock_API_Handler(),
            new WP_Cross_Post_Mock_Rate_Limit_Manager()
        );
        $this->mock_api_handler = new WP_Cross_Post_Mock_API_Handler();
        $this->mock_post_data_preparer = new WP_Cross_Post_Mock_Post_Data_Preparer();
        $this->mock_rate_limit_manager = new WP_Cross_Post_Mock_Rate_Limit_Manager();
        
        // 同期エンジンのインスタンスを作成
        $this->sync_engine = new WP_Cross_Post_Sync_Engine(
            $this->mock_auth_manager,
            $this->mock_image_manager,
            $this->mock_error_manager,
            $this->mock_debug_manager,
            $this->mock_site_handler,
            $this->mock_api_handler,
            $this->mock_post_data_preparer,
            $this->mock_rate_limit_manager
        );
    }
    
    public function testSyncPost() {
        // モックの投稿オブジェクトを作成
        $post = new stdClass();
        $post->ID = 123;
        $post->post_title = 'Test Post';
        $post->post_content = 'Test content';
        $post->post_status = 'publish';
        
        // 投稿をモック
        if (!function_exists('get_post')) {
            function get_post($post_id) {
                $post = new stdClass();
                $post->ID = $post_id;
                $post->post_title = 'Test Post ' . $post_id;
                $post->post_content = 'Test content for post ' . $post_id;
                $post->post_status = 'publish';
                return $post;
            }
        }
        
        // サイトデータを準備
        $sites_data = [
            [
                'id' => 'site_test123',
                'name' => 'Test Site',
                'url' => 'https://example.com',
                'username' => 'testuser',
                'app_password' => 'testpassword'
            ]
        ];
        
        // サイトをモック
        if (!function_exists('get_option')) {
            function get_option($option, $default = []) {
                if ($option === 'wp_cross_post_sites') {
                    return [
                        [
                            'id' => 'site_test123',
                            'name' => 'Test Site',
                            'url' => 'https://example.com',
                            'username' => 'testuser',
                            'app_password' => 'testpassword'
                        ]
                    ];
                }
                return $default;
            }
        }
        
        // 同期を実行
        $result = $this->sync_engine->sync_post(123, ['site_test123']);
        
        // 結果が配列であることを確認
        $this->assertIsArray($result);
        
        // 結果にサイトIDが含まれていることを確認
        $this->assertArrayHasKey('site_test123', $result);
    }
    
    public function testSyncPostAsync() {
        // モックの投稿オブジェクトを作成
        $post = new stdClass();
        $post->ID = 123;
        $post->post_title = 'Test Post';
        $post->post_content = 'Test content';
        $post->post_status = 'publish';
        
        // 投稿をモック
        if (!function_exists('get_post')) {
            function get_post($post_id) {
                $post = new stdClass();
                $post->ID = $post_id;
                $post->post_title = 'Test Post ' . $post_id;
                $post->post_content = 'Test content for post ' . $post_id;
                $post->post_status = 'publish';
                return $post;
            }
        }
        
        // wp_insert_postをモック
        if (!function_exists('wp_insert_post')) {
            function wp_insert_post($postarr, $wp_error = false) {
                return 456;
            }
        }
        
        // update_post_metaをモック
        if (!function_exists('update_post_meta')) {
            function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
                return true;
            }
        }
        
        // wp_schedule_single_eventをモック
        if (!function_exists('wp_schedule_single_event')) {
            function wp_schedule_single_event($timestamp, $hook, $args = array()) {
                return true;
            }
        }
        
        // 非同期同期を実行
        $result = $this->sync_engine->sync_post(123, ['site_test123']);
        
        // 結果が配列であることを確認
        $this->assertIsArray($result);
    }
    
    public function testProcessAsyncSync() {
        // モックの投稿オブジェクトを作成
        $post = new stdClass();
        $post->ID = 123;
        $post->post_title = 'Test Post';
        $post->post_content = 'Test content';
        $post->post_status = 'publish';
        
        // 投稿をモック
        if (!function_exists('get_post')) {
            function get_post($post_id) {
                $post = new stdClass();
                $post->ID = $post_id;
                $post->post_title = 'Test Post ' . $post_id;
                $post->post_content = 'Test content for post ' . $post_id;
                $post->post_status = 'publish';
                return $post;
            }
        }
        
        // get_postsをモック
        if (!function_exists('get_posts')) {
            function get_posts($args = null) {
                return [];
            }
        }
        
        // 同期を実行
        $result = $this->sync_engine->process_async_sync(123, ['site_test123']);
        
        // 結果が配列であることを確認
        $this->assertIsArray($result);
    }
    
    public function testSyncTaxonomiesToAllSites() {
        // サイトデータを準備
        $sites_data = [
            [
                'id' => 'site_test123',
                'name' => 'Test Site',
                'url' => 'https://example.com',
                'username' => 'testuser',
                'app_password' => 'testpassword'
            ]
        ];
        
        // サイトをモック
        if (!function_exists('get_option')) {
            function get_option($option, $default = []) {
                if ($option === 'wp_cross_post_sites') {
                    return [
                        [
                            'id' => 'site_test123',
                            'name' => 'Test Site',
                            'url' => 'https://example.com',
                            'username' => 'testuser',
                            'app_password' => 'testpassword'
                        ]
                    ];
                }
                return $default;
            }
        }
        
        // タクソノミーの全サイト同期を実行
        $result = $this->sync_engine->sync_taxonomies_to_all_sites();
        
        // 結果が配列であることを確認
        $this->assertIsArray($result);
        
        // 成功したサイト数が1であることを確認
        $this->assertCount(1, $result['success_sites']);
    }
}