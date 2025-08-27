<?php
/**
 * WP Cross Post マネージャーインターフェース
 *
 * @package WP_Cross_Post
 */

/**
 * WP Cross Post マネージャーインターフェース
 *
 * すべてのマネージャークラスが実装すべき共通インターフェース
 */
interface WP_Cross_Post_Manager_Interface {
    
    /**
     * インスタンスの取得
     *
     * @return WP_Cross_Post_Manager_Interface
     */
    public static function get_instance();
}