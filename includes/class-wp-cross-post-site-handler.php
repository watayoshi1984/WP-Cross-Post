<?php
class WP_Cross_Post_Site_Handler {
    private $debug_manager;

    public function __construct() {
        $this->debug_manager = WP_Cross_Post_Debug_Manager::get_instance();
    }

    public function add_site($site_data) {
        $sites = get_option('wp_cross_post_sites', array());
        
        // URLの正規化
        $api_handler = new WP_Cross_Post_API_Handler();
        $site_data['url'] = $api_handler->normalize_site_url($site_data['url']);

        // 接続テスト
        $test_result = $api_handler->test_connection($site_data);
        if (is_wp_error($test_result)) {
            return $test_result;
        }

        // サイト名を取得
        $site_name = $this->get_site_name($site_data);
        if (is_wp_error($site_name)) {
            return $site_name;
        }

        // 新しいサイトIDを生成
        $site_data['id'] = uniqid('site_');
        $site_data['name'] = $site_name;

        // タクソノミーの同期
        $sync_result = $this->sync_taxonomies($site_data);
        if (is_wp_error($sync_result)) {
            return $sync_result;
        }

        $sites[] = $site_data;
        update_option('wp_cross_post_sites', $sites);

        return $site_data['id'];
    }

    public function remove_site($site_id) {
        $sites = get_option('wp_cross_post_sites', array());
        foreach ($sites as $key => $site) {
            if ($site['id'] === $site_id) {
                unset($sites[$key]);
                update_option('wp_cross_post_sites', array_values($sites));
                return true;
            }
        }
        error_log('WP Cross Post: Remove site failed - Site not found: ' . $site_id);
        return new WP_Error('site_not_found', 'サイトが見つかりません。');
    }

    public function get_sites() {
        return get_option('wp_cross_post_sites', array());
    }

    private function validate_site_data($site_data) {
        $required_fields = array('name', 'url', 'username', 'app_password');
        foreach ($required_fields as $field) {
            if (empty($site_data[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * サイト名を取得
     */
    private function get_site_name($site_data) {
        $response = wp_remote_get($site_data['url'] . '/wp-json', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($site_data['username'] . ':' . $site_data['app_password'])
            )
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'サイト名の取得に失敗しました: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['name']) ? $data['name'] : parse_url($site_data['url'], PHP_URL_HOST);
    }

    /**
     * 全サイトのタクソノミーを同期
     */
    public function sync_all_sites_taxonomies() {
        $sites = get_option('wp_cross_post_sites', array());
        $results = array(
            'success_sites' => array(),
            'failed_sites' => array()
        );

        foreach ($sites as $site) {
            try {
                // サイトへの接続テスト
                $api_handler = new WP_Cross_Post_API_Handler();
                $test_result = $api_handler->test_connection($site);
                if (is_wp_error($test_result)) {
                    throw new Exception($test_result->get_error_message());
                }

                // カテゴリーとタグの取得
                $taxonomies = $this->get_remote_taxonomies($site);
                if (is_wp_error($taxonomies)) {
                    throw new Exception($taxonomies->get_error_message());
                }

                // カテゴリーの同期
                if (!empty($taxonomies['categories'])) {
                    foreach ($taxonomies['categories'] as $category) {
                        $this->import_taxonomy_term($category, 'category', $site['name']);
                    }
                }

                // タグの同期
                if (!empty($taxonomies['tags'])) {
                    foreach ($taxonomies['tags'] as $tag) {
                        $this->import_taxonomy_term($tag, 'post_tag', $site['name']);
                    }
                }

                $results['success_sites'][] = array(
                    'site_id' => $site['id'],
                    'name' => $site['name']
                );

            } catch (Exception $e) {
                $results['failed_sites'][] = array(
                    'site_id' => $site['id'],
                    'name' => $site['name'],
                    'error' => $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * 定期実行用のフック設定
     */
    public function schedule_taxonomies_sync() {
        if (!wp_next_scheduled('wp_cross_post_daily_sync')) {
            wp_schedule_event(strtotime('today 03:00:00'), 'daily', 'wp_cross_post_daily_sync');
        }
    }

    /**
     * 定期実行のフックを解除
     */
    public function unschedule_taxonomies_sync() {
        wp_clear_scheduled_hook('wp_cross_post_daily_sync');
    }

    /**
     * 手動同期用のAJAXハンドラー
     */
    public function ajax_sync_taxonomies() {
        check_ajax_referer('wp_cross_post_taxonomy_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        try {
            $this->debug_manager->start_performance_monitoring('sync_taxonomies');
            $result = $this->sync_all_sites_taxonomies();
            $this->debug_manager->end_performance_monitoring('sync_taxonomies');

            if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }

        update_option('wp_cross_post_last_sync_time', current_time('mysql'));
        $this->debug_manager->log('wp_cross_post_last_sync_time updated', 'debug');
        wp_send_json_success(array(
            'message' => 'カテゴリーとタグの同期が完了しました。',
            'type' => 'success',
                'details' => $result
            ));

        } catch (Exception $e) {
            $this->debug_manager->log('タクソノミー同期でエラー: ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'type' => 'error'
            ));
        }
    }

    /**
     * タクソノミーの同期
     */
    private function sync_taxonomies($site_data) {
        $this->debug_manager->log(sprintf('サイト "%s" のタクソノミー同期を開始', $site_data['name']), 'info');
        
        try {
            // タクソノミーの取得
            $taxonomies = $this->get_remote_taxonomies($site_data);
            if (is_wp_error($taxonomies)) {
                throw new Exception('タクソノミーの取得に失敗: ' . $taxonomies->get_error_message());
            }

            $imported_categories = 0;
            $imported_tags = 0;

            // カテゴリーの同期
            if (!empty($taxonomies['categories'])) {
                foreach ($taxonomies['categories'] as $category) {
                    $result = $this->import_taxonomy_term($category, 'category', $site_data['name']);
                    if (!is_wp_error($result)) {
                        $imported_categories++;
                    } else {
                        $this->debug_manager->log(sprintf(
                            'カテゴリー "%s" の同期に失敗: %s',
                            $category['name'],
                            $result->get_error_message()
                        ), 'error');
                    }
                }
            }

            // タグの同期
            if (!empty($taxonomies['tags'])) {
                foreach ($taxonomies['tags'] as $tag) {
                    $result = $this->import_taxonomy_term($tag, 'post_tag', $site_data['name']);
                    if (!is_wp_error($result)) {
                        $imported_tags++;
                    } else {
                        $this->debug_manager->log(sprintf(
                            'タグ "%s" の同期に失敗: %s',
                            $tag['name'],
                            $result->get_error_message()
                        ), 'error');
                    }
                }
            }

            $this->debug_manager->log(sprintf(
                'サイト "%s" の同期完了: %d個のカテゴリーと%d個のタグを同期',
                $site_data['name'],
                $imported_categories,
                $imported_tags
            ), 'info');

            return array(
                'categories' => $imported_categories,
                'tags' => $imported_tags
            );

        } catch (Exception $e) {
            $this->debug_manager->log('タクソノミー同期でエラー: ' . $e->getMessage(), 'error');
            return new WP_Error('sync_failed', $e->getMessage());
        }
    }

    /**
     * リモートサイトからタクソノミーを取得
     */
    private function get_remote_taxonomies($site) {
        try {
            // カテゴリーの取得
            $categories_response = wp_remote_get(
                $site['url'] . '/wp-json/wp/v2/categories?per_page=100',
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . $site['app_password'])
                    )
                )
            );

            if (is_wp_error($categories_response)) {
                throw new Exception('カテゴリーの取得に失敗: ' . $categories_response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($categories_response);
            if ($response_code !== 200) {
                throw new Exception('カテゴリーの取得に失敗: HTTPステータス ' . $response_code);
            }

            $categories = json_decode(wp_remote_retrieve_body($categories_response), true);
            if (!is_array($categories)) {
                throw new Exception('カテゴリーの取得に失敗: 無効なレスポンス');
            }

            // タグの取得
            $tags_response = wp_remote_get(
                $site['url'] . '/wp-json/wp/v2/tags?per_page=100',
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . $site['app_password'])
                    )
                )
            );

            if (is_wp_error($tags_response)) {
                throw new Exception('タグの取得に失敗: ' . $tags_response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($tags_response);
            if ($response_code !== 200) {
                throw new Exception('タグの取得に失敗: HTTPステータス ' . $response_code);
            }

            $tags = json_decode(wp_remote_retrieve_body($tags_response), true);
            if (!is_array($tags)) {
                throw new Exception('タグの取得に失敗: 無効なレスポンス');
            }

            $this->debug_manager->log(sprintf(
                'サイト "%s" からタクソノミーを取得: %d個のカテゴリーと%d個のタグ',
                $site['name'],
                count($categories),
                count($tags)
            ), 'info');

            return array(
                'categories' => $categories,
                'tags' => $tags
            );

        } catch (Exception $e) {
            $this->debug_manager->log('タクソノミーの取得でエラー: ' . $e->getMessage(), 'error');
            return new WP_Error('taxonomy_fetch_failed', $e->getMessage());
        }
    }

    /**
     * タクソノミータームをインポート
     */
    private function import_taxonomy_term($term, $taxonomy, $site_name) {
        try {
            // サイト名をプレフィックスとして追加（名前のみ）
            $term_name = $site_name . ' - ' . $term['name'];
            
            // スラッグは元のサイトのものをそのまま使用
            $term_slug = $term['slug'];

            // 既存の用語を検索
            $existing_term = get_term_by('slug', $term_slug, $taxonomy);
            
            $term_args = array(
                'name' => $term_name,
                'slug' => $term_slug,
                'description' => isset($term['description']['rendered']) ? $term['description']['rendered'] : 
                               (isset($term['description']) ? $term['description'] : '')
            );

            // 親カテゴリーの処理
            if ($taxonomy === 'category' && !empty($term['parent'])) {
                $parent_term = get_term($term['parent'], $taxonomy);
                if ($parent_term && !is_wp_error($parent_term)) {
                    $term_args['parent'] = $parent_term->term_id;
                }
            }

            if ($existing_term) {
                // 既存の用語を更新
                $result = wp_update_term($existing_term->term_id, $taxonomy, $term_args);
                $this->debug_manager->log(sprintf(
                    'タクソノミーターム "%s"（スラッグ: %s）を更新 (%s)',
                    $term_name,
                    $term_slug,
                    $taxonomy
                ), 'info');
            } else {
                // 新しい用語を作成
                $result = wp_insert_term($term_name, $taxonomy, $term_args);
                $this->debug_manager->log(sprintf(
                    'タクソノミーターム "%s"（スラッグ: %s）を作成 (%s)',
                    $term_name,
                    $term_slug,
                    $taxonomy
                ), 'info');
            }

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            return $result;

        } catch (Exception $e) {
            $this->debug_manager->log(sprintf(
                'タクソノミーターム "%s" の同期に失敗: %s',
                $term_name,
                $e->getMessage()
            ), 'error');
            return new WP_Error('term_import_failed', $e->getMessage());
        }
    }

    /**
     * サイト追加のAJAXハンドラー
     */
    public function ajax_add_site() {
        check_ajax_referer('wp_cross_post_add_site', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $site_data = array(
            'name' => sanitize_text_field($_POST['site_name']),
            'url' => esc_url_raw($_POST['site_url']),
            'username' => sanitize_text_field($_POST['username']),
            'app_password' => sanitize_text_field($_POST['app_password'])
        );

        if (!$this->validate_site_data($site_data)) {
            wp_send_json_error(array(
                'message' => '必要な情報が不足しています。',
                'type' => 'error'
            ));
        }

        $result = $this->add_site($site_data);
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error'
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'サイトを追加しました。',
                'type' => 'success',
                'site_id' => $result
            ));
        }
    }

    /**
     * サイト削除のAJAXハンドラー
     */
    public function ajax_remove_site() {
        check_ajax_referer('wp_cross_post_remove_site', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '権限がありません。',
                'type' => 'error'
            ));
        }

        $site_id = sanitize_text_field($_POST['site_id']);
        $result = $this->remove_site($site_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'type' => 'error'
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'サイトを削除しました。',
                'type' => 'success'
            ));
        }
    }
}
