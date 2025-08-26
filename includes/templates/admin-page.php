<div class="wrap wp-cross-post-wrapper">
    <div class="wp-cross-post-header">
        <h1><i class="material-icons">share</i> WP Cross Post</h1>
        <div class="wp-cross-post-actions">
            <button type="button" class="button button-primary sync-button" id="sync-taxonomies">
                <i class="material-icons">sync</i>
                タクソノミーを同期
            </button>
            <div class="sync-status" id="sync-status"></div>
        </div>
    </div>

    <div class="wp-cross-post-content">
        <div class="wp-cross-post-card">
            <div class="card-header">
                <h2><i class="material-icons">settings</i> 設定</h2>
            </div>
            <div class="card-content">
                <div class="sync-history" id="sync-history">
                    <h3>同期履歴</h3>
                    <div class="history-content"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const $button = $('#sync-taxonomies');
    const $status = $('#sync-status');
    const $history = $('.history-content');
    let isSyncing = false;

    function updateSyncStatus(data) {
        const { message, type, details } = data;
        const icon = type === 'error' ? 'error' : (type === 'success' ? 'check_circle' : 'info');
        
        let statusHtml = `
            <div class="status-message ${type}">
                <i class="material-icons">${icon}</i>
                <span>${message}</span>
            </div>
        `;

        if (details) {
            if (details.success_sites && details.success_sites.length > 0) {
                statusHtml += `
                    <div class="status-details success">
                        <h4>成功したサイト:</h4>
                        <ul>
                            ${details.success_sites.map(site => `
                                <li>
                                    <i class="material-icons">check_circle</i>
                                    サイトID: ${site.site_id}
                                    ${site.remote_post_id ? `（投稿ID: ${site.remote_post_id}）` : ''}
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                `;
            }

            if (details.failed_sites && details.failed_sites.length > 0) {
                statusHtml += `
                    <div class="status-details error">
                        <h4>失敗したサイト:</h4>
                        <ul>
                            ${details.failed_sites.map(site => `
                                <li>
                                    <i class="material-icons">error</i>
                                    サイトID: ${site.site_id}
                                    （エラー: ${site.error}）
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                `;
            }
        }

        $status.html(statusHtml);
    }

    function addHistoryEntry(data) {
        const { message, type, details } = data;
        const now = new Date().toLocaleString();
        const icon = type === 'error' ? 'error' : (type === 'success' ? 'check_circle' : 'info');
        
        let historyHtml = `
            <div class="history-entry ${type}">
                <i class="material-icons">${icon}</i>
                <div class="entry-content">
                    <div class="entry-message">${message}</div>
                    <div class="entry-time">${now}</div>
                `;

        if (details) {
            if (details.success_sites && details.success_sites.length > 0) {
                historyHtml += `
                    <div class="entry-details success">
                        <span>成功: ${details.success_sites.length}サイト</span>
                    </div>
                `;
            }
            if (details.failed_sites && details.failed_sites.length > 0) {
                historyHtml += `
                    <div class="entry-details error">
                        <span>失敗: ${details.failed_sites.length}サイト</span>
                    </div>
                `;
            }
        }

        historyHtml += `
                </div>
            </div>
        `;

        $history.prepend(historyHtml);
    }

    $button.on('click', function() {
        if (isSyncing) return;
        if (!confirm(wpCrossPost.i18n.confirmManualSync)) return;

        isSyncing = true;
        $button.addClass('syncing');
        updateSyncStatus({
            message: '同期を開始しました...',
            type: 'info'
        });

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync_taxonomies',
                nonce: wpCrossPost.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateSyncStatus(response.data);
                    addHistoryEntry(response.data);
                } else {
                    const errorData = {
                        message: response.data.message || wpCrossPost.i18n.syncError,
                        type: 'error',
                        details: response.data.details
                    };
                    updateSyncStatus(errorData);
                    addHistoryEntry(errorData);
                }
            },
            error: function() {
                const errorData = {
                    message: wpCrossPost.i18n.syncError,
                    type: 'error'
                };
                updateSyncStatus(errorData);
                addHistoryEntry(errorData);
            },
            complete: function() {
                isSyncing = false;
                $button.removeClass('syncing');
            }
        });
    });
});
</script>

<style>
.wp-cross-post-wrapper {
    max-width: 1200px;
    margin: 20px auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.wp-cross-post-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 30px;
    padding: 20px;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
}

.wp-cross-post-header h1 {
    display: flex;
    align-items: center;
    margin: 0;
    font-size: 24px;
    color: #1e1e1e;
}

.wp-cross-post-header h1 .material-icons {
    margin-right: 10px;
    color: #2271b1;
}

.wp-cross-post-actions {
    display: flex;
    align-items: center;
    gap: 20px;
}

.sync-button {
    display: flex !important;
    align-items: center;
    gap: 8px;
    padding: 8px 16px !important;
    height: auto !important;
    transition: all 0.3s ease !important;
}

.sync-button .material-icons {
    font-size: 18px;
}

.sync-button.syncing .material-icons {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    100% { transform: rotate(360deg); }
}

.sync-status {
    min-width: 200px;
}

.status-message {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 4px;
    background: #f0f0f1;
}

.status-message.success {
    background: #edfaef;
    color: #0a5624;
}

.status-message.error {
    background: #fcf0f1;
    color: #cc1818;
}

.wp-cross-post-card {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    margin-bottom: 20px;
}

.card-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f1;
}

.card-header h2 {
    display: flex;
    align-items: center;
    margin: 0;
    font-size: 18px;
    color: #1e1e1e;
}

.card-header h2 .material-icons {
    margin-right: 8px;
    color: #2271b1;
}

.card-content {
    padding: 20px;
}

.sync-history {
    max-height: 400px;
    overflow-y: auto;
}

.history-entry {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px;
    border-bottom: 1px solid #f0f0f1;
}

.history-entry:last-child {
    border-bottom: none;
}

.history-entry .material-icons {
    color: #2271b1;
}

.history-entry.success .material-icons {
    color: #0a5624;
}

.history-entry.error .material-icons {
    color: #cc1818;
}

.entry-content {
    flex: 1;
}

.entry-message {
    margin-bottom: 4px;
    color: #1e1e1e;
}

.entry-time {
    font-size: 12px;
    color: #757575;
}

/* レスポンシブ対応 */
@media screen and (max-width: 782px) {
    .wp-cross-post-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }

    .wp-cross-post-actions {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }

    .sync-status {
        min-width: 100%;
    }
}

.status-details, .entry-details {
    margin-top: 10px;
    padding: 10px;
    border-radius: 4px;
    background: #f8f9fa;
}

.status-details h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
}

.status-details ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.status-details li {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
}

.status-details li:last-child {
    margin-bottom: 0;
}

.status-details.success {
    background: #f0f9ff;
}

.status-details.error {
    background: #fff5f5;
}

.entry-details {
    font-size: 12px;
    margin-top: 5px;
    padding: 5px 8px;
}

.entry-details.success {
    color: #0a5624;
    background: #edfaef;
}

.entry-details.error {
    color: #cc1818;
    background: #fcf0f1;
}
</style> 