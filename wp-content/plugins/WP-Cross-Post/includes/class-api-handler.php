<?php

/**
 * API Handler Class
 */
class WP_Cross_Post_API_Handler {
    /**
     * URLを正規化する静的メソッド
     * 
     * @param string $url 正規化するURL
     * @return string 正規化されたURL
     */
    public static function normalizeUrl($url) {
        if (empty($url)) {
            return '';
        }
        return rtrim($url, '/') . '/';
    }
} 