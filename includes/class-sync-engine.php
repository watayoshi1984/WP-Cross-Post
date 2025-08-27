class WP_Cross_Post_Sync_Engine {
    public function sync_post($post_id) {
        add_filter('https_ssl_verify', '__return_false'); // 暫定SSL対策
        add_filter('http_request_timeout', function() { return 30; });

        try {
            $config = WP_Cross_Post_Config::get_config();
            
            // メイン投稿の存在確認を強化
            $main_post = get_post($post_id);
            if (!$main_post || is_wp_error($main_post)) {
                throw new Exception('メイン投稿が見つかりません');
            }

            // カテゴリー・タグ事前同期
            $this->sync_taxonomies_to_all_sites();

            // サブサイトの接続テストを追加
            $results = [];
            foreach ($config['sub_sites'] as $site) {
                $test_result = $this->test_site_connection($site);
                if (!$test_result['success']) {
                    $results[] = [
                        'success' => false,
                        'error' => '接続失敗: ' . $test_result['error']
                    ];
                    continue;
                }

                // 実際の投稿処理
                $processed_content = $this->process_content($main_post->post_content);
                $media_ids = $this->sync_media($site, $processed_content['media']);
                
                // WordPress 6.5以降のアプリケーションパスワード対応
                $auth_header = $this->get_auth_header($site);
                
                $response = wp_remote_post($site['url'] . '/wp-json/wp/v2/posts', [
                    'headers' => [
                        'Authorization' => $auth_header
                    ],
                    'body' => [
                        'title' => $main_post->post_title,
                        'content' => $this->replace_media_urls($processed_content['html'], $media_ids),
                        'slug' => $this->generate_unique_slug($site, $main_post->post_name),
                        'status' => 'publish',
                        'categories' => $this->map_categories($site, $post_id),
                        'tags' => $this->map_tags($site, $post_id)
                    ]
                ]);

                $results[] = $this->handle_response($response);
            }

            return $this->process_results($results);

        } catch (Exception $e) {
            // エラー詳細をログに記録
            error_log('Sync Error: ' . $e->getMessage());
            
            return new WP_Error('sync_failed', $e->getMessage());
        }
    }
    
    /**
     * 認証ヘッダーを取得
     */
    private function get_auth_header($site) {
        // WordPress 5.6以降のアプリケーションパスワード対応
        if (version_compare(get_bloginfo('version'), '5.6', '>=')) {
            // アプリケーションパスワードの形式で認証
            return 'Basic ' . base64_encode($site['username'] . ':' . $site['password']);
        } else {
            // 従来のBasic認証
            return 'Basic ' . base64_encode($site['username'] . ':' . $site['password']);
        }
    }
    
    private function test_site_connection($site) {
        // WordPress 6.5以降のアプリケーションパスワード対応
        $auth_header = $this->get_auth_header($site);
        
        $test_url = $site['url'] . '/wp-json/';
        $response = wp_remote_get($test_url, [
            'timeout' => 5,
            'headers' => [
                'Authorization' => $auth_header
            ]
        ]);

        return [
            'success' => !is_wp_error($response) && 
                        wp_remote_retrieve_response_code($response) === 200,
            'error' => is_wp_error($response) ? 
                     $response->get_error_message() : 
                     wp_remote_retrieve_body($response)
        ];
    }

    // メディア同期処理
    private function sync_media($site, $media_items) {
        $synced_media = [];
        foreach ($media_items as $media) {
            $file = $this->download_media($media['url']);
            // WordPress 6.5以降のアプリケーションパスワード対応
            $auth_header = $this->get_auth_header($site);
            
            $response = wp_remote_post($site['url'] . '/wp-json/wp/v2/media', [
                'headers' => [
                    'Content-Disposition' => 'attachment; filename="' . basename($file) . '"',
                    'Authorization' => $auth_header
                ],
                'body' => file_get_contents($file)
            ]);
            $synced_media[$media['id']] = json_decode(wp_remote_retrieve_body($response))->id;
        }
        return $synced_media;
    }
}