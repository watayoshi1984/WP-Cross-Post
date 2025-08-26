<?php
class WP_Cross_Post_Error_Handler {
    public function log_error($message, $data = array()) {
        error_log('WP Cross Post Error: ' . $message);
        if (!empty($data)) {
            error_log('Error Data: ' . print_r($data, true));
        }
    }

    public function display_admin_notice($message, $type = 'error') {
        add_action('admin_notices', function() use ($message, $type) {
            $class = 'notice notice-' . $type;
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        });
    }

    public function handle_wp_error($wp_error) {
        if (is_wp_error($wp_error)) {
            $this->log_error($wp_error->get_error_message(), $wp_error->get_error_data());
            $this->display_admin_notice($wp_error->get_error_message());
        }
    }
}

