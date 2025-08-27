<?php
class WP_Cross_Post_Config_Manager {
    const OPTION_KEY = 'wp_cross_post_settings';
    const DEFAULT_SETTINGS = [
        'api_settings' => [
            'timeout' => 30,
            'retries' => 3,
            'batch_size' => 10
        ],
        'sync_settings' => [
            'parallel_sync' => false,
            'async_sync' => false,
            'rate_limit' => true
        ],
        'image_settings' => [
            'sync_images' => true,
            'max_image_size' => 5242880, // 5MB
            'image_quality' => 80
        ],
        'debug_settings' => [
            'debug_mode' => false,
            'log_level' => 'info'
        ],
        'cache_settings' => [
            'enable_cache' => true,
            'cache_duration' => 1800 // 30分
        ],
        'security_settings' => [
            'verify_ssl' => true,
            'encrypt_credentials' => true
        ]
    ];

    /**
     * 設定を取得
     */
    public static function get_settings() {
        $settings = get_option(self::OPTION_KEY, []);
        return wp_parse_args($settings, self::DEFAULT_SETTINGS);
    }

    /**
     * 特定の設定グループを取得
     */
    public static function get_setting_group($group) {
        $settings = self::get_settings();
        return isset($settings[$group]) ? $settings[$group] : [];
    }

    /**
     * 特定の設定値を取得
     */
    public static function get_setting($group, $key, $default = null) {
        $settings = self::get_settings();
        return isset($settings[$group][$key]) ? $settings[$group][$key] : $default;
    }

    /**
     * 設定を更新
     */
    public static function update_settings($new_settings) {
        $current_settings = self::get_settings();
        $updated_settings = self::merge_settings($current_settings, $new_settings);
        $validated_settings = self::validate_settings($updated_settings);
        
        update_option(self::OPTION_KEY, $validated_settings);
        return $validated_settings;
    }

    /**
     * 特定の設定グループを更新
     */
    public static function update_setting_group($group, $group_settings) {
        $current_settings = self::get_settings();
        $current_settings[$group] = $group_settings;
        $validated_settings = self::validate_settings($current_settings);
        
        update_option(self::OPTION_KEY, $validated_settings);
        return $validated_settings;
    }

    /**
     * 設定をマージ
     */
    private static function merge_settings($current, $new) {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
                $current[$key] = self::merge_settings($current[$key], $value);
            } else {
                $current[$key] = $value;
            }
        }
        return $current;
    }

    /**
     * 設定のバリデーション
     */
    private static function validate_settings($settings) {
        // API設定のバリデーション
        if (isset($settings['api_settings'])) {
            $api_settings = $settings['api_settings'];
            $api_settings['timeout'] = max(1, min(300, intval($api_settings['timeout'])));
            $api_settings['retries'] = max(0, min(10, intval($api_settings['retries'])));
            $api_settings['batch_size'] = max(1, min(100, intval($api_settings['batch_size'])));
            $settings['api_settings'] = $api_settings;
        }

        // 画像設定のバリデーション
        if (isset($settings['image_settings'])) {
            $image_settings = $settings['image_settings'];
            $image_settings['max_image_size'] = max(1048576, min(52428800, intval($image_settings['max_image_size']))); // 1MB to 50MB
            $image_settings['image_quality'] = max(10, min(100, intval($image_settings['image_quality'])));
            $settings['image_settings'] = $image_settings;
        }

        // キャッシュ設定のバリデーション
        if (isset($settings['cache_settings'])) {
            $cache_settings = $settings['cache_settings'];
            $cache_settings['cache_duration'] = max(60, min(86400, intval($cache_settings['cache_duration']))); // 1分 to 1日
            $settings['cache_settings'] = $cache_settings;
        }

        return $settings;
    }

    /**
     * 設定をリセット
     */
    public static function reset_settings() {
        update_option(self::OPTION_KEY, self::DEFAULT_SETTINGS);
        return self::DEFAULT_SETTINGS;
    }

    /**
     * 設定をエクスポート
     */
    public static function export_settings() {
        $settings = self::get_settings();
        // 機密情報はエクスポートしない
        unset($settings['security_settings']);
        return base64_encode(json_encode($settings));
    }

    /**
     * 設定をインポート
     */
    public static function import_settings($encoded_settings) {
        $settings_json = base64_decode($encoded_settings);
        $settings = json_decode($settings_json, true);
        
        if (is_array($settings)) {
            // 機密情報はインポートしない
            unset($settings['security_settings']);
            return self::update_settings($settings);
        }
        
        return false;
    }
}