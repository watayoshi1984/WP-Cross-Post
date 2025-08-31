<?php
/**
 * WP Cross Post メディア同期管理クラス
 * 独自テーブルを使用したメディア同期履歴管理
 *
 * @package WP_Cross_Post
 */

class WP_Cross_Post_Media_Sync_Manager {

    private $debug_manager;
    private $error_manager;
    private static $table_media_sync;

    public function __construct($debug_manager, $error_manager) {
        global $wpdb;
        
        $this->debug_manager = $debug_manager;
        $this->error_manager = $error_manager;
        
        // テーブル名を設定
        self::$table_media_sync = $wpdb->prefix . 'cross_post_media_sync';
    }

    /**
     * メディア同期レコードの作成
     */
    public function create_sync_record($site_id, $local_media_id, $local_file_url, $file_size = null, $mime_type = null) {
        global $wpdb;
        
        $this->debug_manager->log('メディア同期レコード作成を開始', 'info', array(
            'site_id' => $site_id,
            'local_media_id' => $local_media_id,
            'local_file_url' => $local_file_url
        ));
        
        // 既存レコードの確認
        $existing = $this->get_sync_record($site_id, $local_media_id);
        if ($existing) {
            $this->debug_manager->log('既存の同期レコードが見つかりました', 'info', array(
                'record_id' => $existing['id'],
                'current_status' => $existing['sync_status']
            ));
            return $existing['id'];
        }
        
        $insert_data = array(
            'site_id' => $site_id,
            'local_media_id' => $local_media_id,
            'local_file_url' => $local_file_url,
            'file_size' => $file_size,
            'mime_type' => $mime_type,
            'sync_status' => 'pending',
            'retry_count' => 0
        );
        
        $result = $wpdb->insert(
            self::$table_media_sync,
            $insert_data,
            array('%d', '%d', '%s', '%d', '%s', '%s', '%d')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', 'メディア同期レコードの作成に失敗: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'create_sync_record');
            return false;
        }
        
        $record_id = $wpdb->insert_id;
        
        $this->debug_manager->log('メディア同期レコードを作成しました', 'info', array(
            'record_id' => $record_id
        ));
        
        return $record_id;
    }

    /**
     * メディア同期レコードの取得
     */
    public function get_sync_record($site_id, $local_media_id) {
        global $wpdb;
        
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_media_sync . " WHERE site_id = %d AND local_media_id = %d",
                $site_id, $local_media_id
            ),
            ARRAY_A
        );
        
        return $record;
    }

    /**
     * 同期ステータスの更新
     */
    public function update_sync_status($site_id, $local_media_id, $status, $remote_media_id = null, $remote_file_url = null, $error_message = null) {
        global $wpdb;
        
        $update_data = array(
            'sync_status' => $status,
            'updated_at' => current_time('mysql')
        );
        
        // 成功時の処理
        if ($status === 'success') {
            $update_data['synced_at'] = current_time('mysql');
            if ($remote_media_id) {
                $update_data['remote_media_id'] = $remote_media_id;
            }
            if ($remote_file_url) {
                $update_data['remote_file_url'] = $remote_file_url;
            }
            // エラーメッセージをクリア
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
                    "UPDATE " . self::$table_media_sync . " SET retry_count = retry_count + 1 WHERE site_id = %d AND local_media_id = %d",
                    $site_id, $local_media_id
                )
            );
        }
        
        // アップロード中の場合
        if ($status === 'uploading') {
            $update_data['error_message'] = null; // 過去のエラーをクリア
        }
        
        $result = $wpdb->update(
            self::$table_media_sync,
            $update_data,
            array('site_id' => $site_id, 'local_media_id' => $local_media_id),
            array('%s', '%s', '%d', '%s', '%s'),
            array('%d', '%d')
        );
        
        if ($result === false) {
            $error = new WP_Error('database_error', 'メディア同期ステータスの更新に失敗: ' . $wpdb->last_error);
            $this->error_manager->handle_error($error, 'update_sync_status');
            return false;
        }
        
        $this->debug_manager->log('メディア同期ステータスを更新', 'info', array(
            'site_id' => $site_id,
            'local_media_id' => $local_media_id,
            'new_status' => $status,
            'remote_media_id' => $remote_media_id
        ));
        
        return true;
    }

    /**
     * リモートメディアIDの取得
     */
    public function get_remote_media_id($site_id, $local_media_id) {
        global $wpdb;
        
        $remote_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT remote_media_id FROM " . self::$table_media_sync . " 
                 WHERE site_id = %d AND local_media_id = %d AND sync_status = 'success'",
                $site_id, $local_media_id
            )
        );
        
        return $remote_id ? intval($remote_id) : null;
    }

    /**
     * 同期が必要なメディアの取得
     */
    public function get_pending_media($site_id, $limit = 10) {
        global $wpdb;
        
        $media = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_media_sync . " 
                 WHERE site_id = %d AND sync_status IN ('pending', 'failed') 
                 ORDER BY retry_count ASC, created_at ASC 
                 LIMIT %d",
                $site_id, $limit
            ),
            ARRAY_A
        );
        
        return $media;
    }

    /**
     * 失敗したメディア同期の再試行
     */
    public function retry_failed_sync($site_id, $max_retries = 3) {
        global $wpdb;
        
        // 最大リトライ回数に達していない失敗したメディアを取得
        $failed_media = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_media_sync . " 
                 WHERE site_id = %d AND sync_status = 'failed' AND retry_count < %d 
                 ORDER BY retry_count ASC, created_at ASC",
                $site_id, $max_retries
            ),
            ARRAY_A
        );
        
        foreach ($failed_media as $media) {
            // ステータスをpendingに戻す
            $this->update_sync_status($site_id, $media['local_media_id'], 'pending');
        }
        
        $this->debug_manager->log('失敗したメディア同期の再試行を設定', 'info', array(
            'site_id' => $site_id,
            'retry_count' => count($failed_media)
        ));
        
        return count($failed_media);
    }

    /**
     * サイトのメディア同期統計取得
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
                    SUM(CASE WHEN sync_status = 'uploading' THEN 1 ELSE 0 END) as uploading,
                    SUM(CASE WHEN sync_status = 'success' THEN file_size ELSE 0 END) as total_synced_size
                 FROM " . self::$table_media_sync . " 
                 WHERE site_id = %d",
                $site_id
            ),
            ARRAY_A
        );
        
        return $stats;
    }

    /**
     * 古い同期履歴のクリーンアップ
     */
    public function cleanup_old_records($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::$table_media_sync . " 
                 WHERE sync_status = 'success' AND synced_at < %s",
                $cutoff_date
            )
        );
        
        $this->debug_manager->log('古いメディア同期履歴をクリーンアップ', 'info', array(
            'deleted_records' => $deleted,
            'cutoff_date' => $cutoff_date
        ));
        
        return $deleted;
    }

    /**
     * 特定サイトの全メディア同期データを削除
     */
    public function delete_site_media_sync($site_id) {
        global $wpdb;
        
        $deleted = $wpdb->delete(
            self::$table_media_sync,
            array('site_id' => $site_id),
            array('%d')
        );
        
        $this->debug_manager->log('サイトのメディア同期データを削除', 'info', array(
            'site_id' => $site_id,
            'deleted_records' => $deleted
        ));
        
        return $deleted;
    }

    /**
     * メディア同期の詳細履歴を取得
     */
    public function get_sync_history($site_id, $limit = 50, $offset = 0) {
        global $wpdb;
        
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ms.*, p.post_title 
                 FROM " . self::$table_media_sync . " ms
                 LEFT JOIN {$wpdb->posts} p ON ms.local_media_id = p.ID
                 WHERE ms.site_id = %d 
                 ORDER BY ms.updated_at DESC 
                 LIMIT %d OFFSET %d",
                $site_id, $limit, $offset
            ),
            ARRAY_A
        );
        
        return $history;
    }

    /**
     * 同期中のメディアタイムアウト検出と処理
     */
    public function handle_timeout_media($timeout_minutes = 10) {
        global $wpdb;
        
        $timeout_date = date('Y-m-d H:i:s', strtotime("-{$timeout_minutes} minutes"));
        
        // タイムアウトしたアップロード中のメディアを失敗状態に変更
        $timed_out = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::$table_media_sync . " 
                 SET sync_status = 'failed', 
                     error_message = 'Upload timeout detected',
                     retry_count = retry_count + 1,
                     updated_at = %s
                 WHERE sync_status = 'uploading' AND updated_at < %s",
                current_time('mysql'), $timeout_date
            )
        );
        
        if ($timed_out > 0) {
            $this->debug_manager->log('タイムアウトしたメディア同期を検出', 'warning', array(
                'timed_out_count' => $timed_out,
                'timeout_minutes' => $timeout_minutes
            ));
        }
        
        return $timed_out;
    }

    /**
     * バッチでのメディア同期ステータス更新
     */
    public function batch_update_status($updates) {
        global $wpdb;
        
        $success_count = 0;
        
        foreach ($updates as $update) {
            $result = $this->update_sync_status(
                $update['site_id'],
                $update['local_media_id'],
                $update['status'],
                isset($update['remote_media_id']) ? $update['remote_media_id'] : null,
                isset($update['remote_file_url']) ? $update['remote_file_url'] : null,
                isset($update['error_message']) ? $update['error_message'] : null
            );
            
            if ($result) {
                $success_count++;
            }
        }
        
        $this->debug_manager->log('バッチでメディア同期ステータスを更新', 'info', array(
            'total_updates' => count($updates),
            'successful_updates' => $success_count
        ));
        
        return $success_count;
    }
}
