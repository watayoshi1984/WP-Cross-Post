<?php
class WP_Cross_Post_Config {
    const OPTION_KEY = 'wp_cross_post_settings';

    public static function get_config() {
        $defaults = [
            'main_site' => get_site_url(),
            'sub_sites' => [],
            'api_path' => '/wp-json/wp/v2/posts',
            'auth_type' => 'basic'
        ];
        
        return wp_parse_args(
            get_option(self::OPTION_KEY, []),
            $defaults
        );
    }

    public static function update_config(array $config) {
        update_option(self::OPTION_KEY, $config);
    }
}
