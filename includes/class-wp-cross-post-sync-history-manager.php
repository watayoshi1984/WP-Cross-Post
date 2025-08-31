<?php
/**
 * WP Cross Post 投稿同期履歴管理クラス
 * 独自テーブルを使用した投稿同期履歴管理
 *
 * @package WP_Cross_Post
 */

class WP_Cross_Post_Sync_History_Manager {

    private $debug_manager;
    private $error_manager;
    private static $table_sync_history;

    public function __construct($debug_manager, $error_manager) {
        global $wpdb;
        
        $this->debug_manager = $debug_manager;
        $this->error_manager = $error_manager;
        
        // テーブル名を設定
        self::$table_sync_history = $wpdb->prefix . 'cross_post_sync_history';
    }

    /**
     * 投稿同期レコードの作成
     */
    public function create_sync_record($site_id, $local_post_id, $sync_type = 'create', $post_status = null, $scheduled_date = null, $sync_data = null) {
        global $wpdb;
        
        $this->debug_manager->log('投稿同期レコード作成を開始', 'info', array(
            'site_id' => $site_id,
            'local_post_id' => $local_post_id,
            'sync_type' => $sync_type
        ));
        
        // 既存レコードの確認
        $existing = $this->get_sync_record($site_id, $local_post_id);
        if ($existing) {
            $this->debug_manager->log('既存の同期レコードを更新', 'info', array(
                'record_id' => $existing['id'],
                'current_status' => $existing['sync_status']
            ));
            
            // 既存レコードを更新
            return $this->update_sync_record($existing['id'], array(
                'sync_type' => $sync_type,
                'post_status' => $post_status,
                'scheduled_date' => $scheduled_date,
                'sync_data' => $sync_data ? json_encode($sync_data) : null,
                'sync_status' => 'pending',
                'retry_count' => 0,
                'error_message' => null
            ));
        }
        
        $insert_data = array(
            'site_id' => $site_id,
            'local_post_id' => $local_post_id,
            'sync_type' => $sync_type,
            'post_status' => $post_status,
            'scheduled_date' => $scheduled_date,
            'sync_data' => $sync_data ? json_encode($sync_data) : null,
            'sync_status' => 'pending',
            'retry_count' => 0
        );
        
        $result = $wpdb->insert(
            self::$table_sync_history,
            $insert_data,
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', '投稿同期レコードの作成に失敗: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'create_sync_record');
            return false;
        }
        
        $record_id = $wpdb->insert_id;
        
        $this->debug_manager->log('投稿同期レコードを作成しました', 'info', array(
            'record_id' => $record_id
        ));
        
        return $record_id;
    }

    /**
     * 投稿同期レコードの取得
     */
    public function get_sync_record($site_id, $local_post_id) {
        global $wpdb;
        
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_sync_history . " WHERE site_id = %d AND local_post_id = %d",
                $site_id, $local_post_id
            ),
            ARRAY_A
        );
        
        // sync_dataをデコード
        if ($record && !empty($record['sync_data'])) {
            $record['sync_data'] = json_decode($record['sync_data'], true);
        }
        
        return $record;
    }

    /**
     * 同期レコードの更新
     */
    public function update_sync_record($record_id, $data) {
        global $wpdb;
        
        // sync_dataがある場合はJSON形式にエンコード
        if (isset($data['sync_data']) && is_array($data['sync_data'])) {
            $data['sync_data'] = json_encode($data['sync_data']);
        }
        
        $data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->update(
            self::$table_sync_history,
            $data,
            array('id' => $record_id),
            null, // データ型は自動推定
            array('%d')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', '投稿同期レコードの更新に失敗: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'update_sync_record');
            return false;
        }
        
        return true;
    }

    /**
     * 同期ステータスの更新
     */
    public function update_sync_status($site_id, $local_post_id, $status, $remote_post_id = null, $error_message = null) {
        global $wpdb;
        
        $update_data = array(
            'sync_status' => $status,
            'updated_at' => current_time('mysql')
        );
        
        // 成功時の処理
        if ($status === 'success') {
            $update_data['synced_at'] = current_time('mysql');
            if ($remote_post_id) {
                $update_data['remote_post_id'] = $remote_post_id;
            }
            $update_data['error_message'] = null;
        }
        
        // 失敗時の処理
        if ($status === 'failed') {
            if ($error_message) {
                $update_data['error_message'] = $error_message;
            }
            // リトライ回数を増加
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . self::$table_sync_history . " SET retry_count = retry_count + 1 WHERE site_id = %d AND local_post_id = %d",
                    $site_id, $local_post_id
                )
            );
        }
        
        // 同期中の場合
        if ($status === 'syncing') {
            $update_data['error_message'] = null; // 過去のエラーをクリア
        }
        
        $result = $wpdb->update(
            self::$table_sync_history,
            $update_data,
            array('site_id' => $site_id, 'local_post_id' => $local_post_id),
            null,
            array('%d', '%d')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', '投稿同期ステータスの更新に失敗: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'update_sync_status');
            return false;
        }
        
        $this->debug_manager->log('投稿同期ステータスを更新', 'info', array(
            'site_id' => $site_id,
            'local_post_id' => $local_post_id,
            'new_status' => $status,
            'remote_post_id' => $remote_post_id
        ));
        
        return true;
    }

    /**
     * リモート投稿IDの取得
     */
    public function get_remote_post_id($site_id, $local_post_id) {
        global $wpdb;
        
        $remote_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT remote_post_id FROM " . self::$table_sync_history . " 
                 WHERE site_id = %d AND local_post_id = %d AND sync_status = 'success'",
                $site_id, $local_post_id
            )
        );
        
        return $remote_id ? intval($remote_id) : null;
    }

    /**
     * 同期が必要な投稿の取得
     */
    public function get_pending_posts($site_id, $limit = 10) {
        global $wpdb;
        
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_sync_history . " 
                 WHERE site_id = %d AND sync_status IN ('pending', 'failed') 
                 ORDER BY retry_count ASC, created_at ASC 
                 LIMIT %d",
                $site_id, $limit
            ),
            ARRAY_A
        );
        
        // sync_dataをデコード
        foreach ($posts as &$post) {
            if (!empty($post['sync_data'])) {
                $post['sync_data'] = json_decode($post['sync_data'], true);
            }
        }
        
        return $posts;
    }

    /**
     * 予約投稿の取得
     */
    public function get_scheduled_posts($site_id = null) {
        global $wpdb;
        
        $where_clause = "sync_status = 'pending' AND scheduled_date IS NOT NULL AND scheduled_date <= %s";
        $params = array(current_time('mysql'));
        
        if ($site_id) {
            $where_clause = "site_id = %d AND " . $where_clause;
            array_unshift($params, $site_id);
        }
        
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_sync_history . " 
                 WHERE {$where_clause} 
                 ORDER BY scheduled_date ASC",
                $params
            ),
            ARRAY_A
        );
        
        // sync_dataをデコード
        foreach ($posts as &$post) {
            if (!empty($post['sync_data'])) {
                $post['sync_data'] = json_decode($post['sync_data'], true);
            }
        }
        
        return $posts;
    }

    /**
     * 失敗した投稿同期の再試行
     */
    public function retry_failed_sync($site_id, $max_retries = 3) {
        global $wpdb;
        
        $failed_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_sync_history . " 
                 WHERE site_id = %d AND sync_status = 'failed' AND retry_count < %d 
                 ORDER BY retry_count ASC, created_at ASC",
                $site_id, $max_retries
            ),
            ARRAY_A
        );
        
        foreach ($failed_posts as $post) {
            $this->update_sync_status($site_id, $post['local_post_id'], 'pending');
        }
        
        $this->debug_manager->log('失敗した投稿同期の再試行を設定', 'info', array(
            'site_id' => $site_id,
            'retry_count' => count($failed_posts)
        ));
        
        return count($failed_posts);
    }

    /**
     * サイトの投稿同期統計取得
     */
    public function get_sync_statistics($site_id) {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sync_status = 'success' THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN sync_status = 'syncing' THEN 1 ELSE 0 END) as syncing,
                    SUM(CASE WHEN sync_type = 'create' THEN 1 ELSE 0 END) as creates,
                    SUM(CASE WHEN sync_type = 'update' THEN 1 ELSE 0 END) as updates,
                    SUM(CASE WHEN sync_type = 'delete' THEN 1 ELSE 0 END) as deletes
                 FROM " . self::$table_sync_history . " 
                 WHERE site_id = %d",
                $site_id
            ),
            ARRAY_A
        );
        
        return $stats;
    }

    /**
     * 投稿の同期履歴を取得
     */
    public function get_post_sync_history($local_post_id, $limit = 10) {
        global $wpdb;
        
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sh.*, s.name as site_name, s.url as site_url
                 FROM " . self::$table_sync_history . " sh
                 LEFT JOIN " . $wpdb->prefix . "cross_post_sites s ON sh.site_id = s.id
                 WHERE sh.local_post_id = %d 
                 ORDER BY sh.updated_at DESC 
                 LIMIT %d",
                $local_post_id, $limit
            ),
            ARRAY_A
        );
        
        // sync_dataをデコード
        foreach ($history as &$record) {
            if (!empty($record['sync_data'])) {
                $record['sync_data'] = json_decode($record['sync_data'], true);
            }
        }
        
        return $history;
    }

    /**
     * 同期レコードの削除
     */
    public function delete_sync_record($site_id, $local_post_id) {
        global $wpdb;
        
        $deleted = $wpdb->delete(
            self::$table_sync_history,
            array('site_id' => $site_id, 'local_post_id' => $local_post_id),
            array('%d', '%d')
        );
        
        $this->debug_manager->log('投稿同期レコードを削除', 'info', array(
            'site_id' => $site_id,
            'local_post_id' => $local_post_id,
            'deleted' => $deleted
        ));
        
        return $deleted;
    }

    /**
     * 特定サイトの全同期データを削除
     */
    public function delete_site_sync_history($site_id) {
        global $wpdb;
        
        $deleted = $wpdb->delete(
            self::$table_sync_history,
            array('site_id' => $site_id),
            array('%d')
        );
        
        $this->debug_manager->log('サイトの投稿同期データを削除', 'info', array(
            'site_id' => $site_id,
            'deleted_records' => $deleted
        ));
        
        return $deleted;
    }

    /**
     * 古い同期履歴のクリーンアップ
     */
    public function cleanup_old_records($days = 90) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // 成功した同期のみクリーンアップ（失敗した同期は保持）
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::$table_sync_history . " 
                 WHERE sync_status = 'success' AND synced_at < %s",
                $cutoff_date
            )
        );
        
        $this->debug_manager->log('古い投稿同期履歴をクリーンアップ', 'info', array(
            'deleted_records' => $deleted,
            'cutoff_date' => $cutoff_date
        ));
        
        return $deleted;
    }

    /**
     * 同期中の投稿タイムアウト検出と処理
     */
    public function handle_timeout_sync($timeout_minutes = 15) {
        global $wpdb;
        
        $timeout_date = date('Y-m-d H:i:s', strtotime("-{$timeout_minutes} minutes"));
        
        $timed_out = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::$table_sync_history . " 
                 SET sync_status = 'failed', 
                     error_message = 'Sync timeout detected',
                     retry_count = retry_count + 1,
                     updated_at = %s
                 WHERE sync_status = 'syncing' AND updated_at < %s",
                current_time('mysql'), $timeout_date
            )
        );
        
        if ($timed_out > 0) {
            $this->debug_manager->log('タイムアウトした投稿同期を検出', 'warning', array(
                'timed_out_count' => $timed_out,
                'timeout_minutes' => $timeout_minutes
            ));
        }
        
        return $timed_out;
    }

    /**
     * 同期設定データの更新
     */
    public function update_sync_data($site_id, $local_post_id, $sync_data) {
        global $wpdb;
        
        $result = $wpdb->update(
            self::$table_sync_history,
            array(
                'sync_data' => json_encode($sync_data),
                'updated_at' => current_time('mysql')
            ),
            array('site_id' => $site_id, 'local_post_id' => $local_post_id),
            array('%s', '%s'),
            array('%d', '%d')
        );
        
        return $result !== false;
    }

    /**
     * 全サイトの同期概要統計
     */
    public function get_global_sync_statistics() {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_syncs,
                COUNT(DISTINCT site_id) as active_sites,
                COUNT(DISTINCT local_post_id) as unique_posts,
                SUM(CASE WHEN sync_status = 'success' THEN 1 ELSE 0 END) as total_success,
                SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as total_failed,
                SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                AVG(CASE WHEN sync_status = 'success' AND synced_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(SECOND, created_at, synced_at) 
                    ELSE NULL END) as avg_sync_time_seconds
             FROM " . self::$table_sync_history,
            ARRAY_A
        );
        
        return $stats;
    }
}
