<?php
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'WP_Cross_Post_') === 0) {
        $file_name = str_replace('_', '-', strtolower($class_name));
        $file_path = WP_CROSS_POST_PLUGIN_DIR . 'includes/class-' . $file_name . '.php';

        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
});
