<?php
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'WP_Cross_Post_') === 0) {
        $file_path = null;
        
        // Check if it's an interface
        if (strpos($class_name, '_Interface') !== false) {
            // Handle interface files
            $file_name = str_replace('_', '-', strtolower(str_replace('_Interface', '', $class_name)));
            $file_path = WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-' . $file_name . '.php';
            
            // Load parent interfaces first if needed
            if ($class_name === 'WP_Cross_Post_API_Handler_Interface') {
                // Load the parent interface first
                $parent_interface = WP_CROSS_POST_PLUGIN_DIR . 'includes/interfaces/interface-handler.php';
                if (file_exists($parent_interface)) {
                    require_once $parent_interface;
                }
            }
        } else {
            // Handle class files
            $file_name = str_replace('_', '-', strtolower($class_name));
            $file_path = WP_CROSS_POST_PLUGIN_DIR . 'includes/class-' . $file_name . '.php';
        }

        if ($file_path && file_exists($file_path)) {
            require_once $file_path;
        }
    }
});