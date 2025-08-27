jQuery(document).ready($ => {
    const $manualSyncButton = $('#manual-sync-button');
    const $syncTaxonomiesButton = $('#sync-taxonomies');
    const $syncStatus = $('#sync-status');
    const $addSiteForm = $('#wp-cross-post-add-site-form');
    const debug = window.wpCrossPostDebug;
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
                                    ${site.name}
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
                                    ${site.name}
                                    （エラー: ${site.error}）
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                `;
            }
        }

        $syncStatus.html(statusHtml);
    }

    // サイトの追加
    $addSiteForm.on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'wp_cross_post_add_site');
        formData.append('nonce', wpCrossPost.addSiteNonce);

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $addSiteForm.find('button[type="submit"]').prop('disabled', true);
                updateSyncStatus({
                    message: 'サイトを追加中...',
                    type: 'info'
                });
            },
            success: function(response) {
                if (response.success) {
                    updateSyncStatus({
                        message: 'サイトを追加しました。',
                        type: 'success'
                    });
                    location.reload();
                } else {
                    updateSyncStatus({
                        message: response.data.message || 'サイトの追加に失敗しました。',
                        type: 'error'
                    });
                }
            },
            error: function() {
                updateSyncStatus({
                    message: 'サイトの追加に失敗しました。',
                    type: 'error'
                });
            },
            complete: function() {
                $addSiteForm.find('button[type="submit"]').prop('disabled', false);
            }
        });
    });

    // サイトの削除
    $('.remove-site').on('click', function() {
        const siteId = $(this).data('site-id');
        const siteName = $(this).closest('tr').find('td:first').text();

        if (!confirm(`サイト「${siteName}」を削除してもよろしいですか？`)) {
            return;
        }

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_remove_site',
                nonce: wpCrossPost.removeSiteNonce,
                site_id: siteId
            },
            beforeSend: function() {
                updateSyncStatus({
                    message: 'サイトを削除中...',
                    type: 'info'
                });
            },
            success: function(response) {
                if (response.success) {
                    updateSyncStatus({
                        message: 'サイトを削除しました。',
                        type: 'success'
                    });
                    location.reload();
                } else {
                    updateSyncStatus({
                        message: response.data.message || 'サイトの削除に失敗しました。',
                        type: 'error'
                    });
                }
            },
            error: function() {
                updateSyncStatus({
                    message: 'サイトの削除に失敗しました。',
                    type: 'error'
                });
            }
        });
    });

    $manualSyncButton.on('click', function() {
        if (isSyncing) return;

        const postId = $(this).data('post-id');
        const selectedSites = [];
        
        $('input[name="wp_cross_post_sites[]"]:checked').each(function() {
            selectedSites.push($(this).val());
        });

        if (selectedSites.length === 0) {
            updateSyncStatus({
                message: '同期先のサイトが選択されていません。',
                type: 'error'
            });
            return;
        }

        if (!confirm('選択したサイトに同期しますか？')) {
            return;
        }

        isSyncing = true;
        $manualSyncButton.addClass('syncing');
        updateSyncStatus({
            message: '同期を開始しました...',
            type: 'info'
        });

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync',
                nonce: wpCrossPost.nonce,
                post_id: postId,
                selected_sites: selectedSites
            },
            success: function(response) {
                if (response.success) {
                    updateSyncStatus(response.data);
                } else {
                    const errorData = {
                        message: response.data.message || wpCrossPost.i18n.syncError,
                        type: 'error',
                        details: response.data.details
                    };
                    updateSyncStatus(errorData);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                const errorData = handleSyncError(jqXHR);
                if (errorData.details.error_code === 401) {
                    errorData.message += '（認証エラー: パスワードを確認してください）';
                } else if (errorData.details.error_code === 404) {
                    errorData.message += '（エンドポイントが見つかりません）';
                }
                updateSyncStatus(errorData);
            },
            complete: function() {
                isSyncing = false;
                $manualSyncButton.removeClass('syncing');
            }
        });
    });

    $syncTaxonomiesButton.on('click', function() {
        if (isSyncing) return;

        if (!confirm('全サイトのカテゴリーとタグを同期しますか？')) {
            return;
        }

        isSyncing = true;
        $syncTaxonomiesButton.addClass('syncing');
        updateSyncStatus({
            message: 'カテゴリーとタグの同期を開始しました...',
            type: 'info'
        });

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync_taxonomies',
                nonce: wpCrossPost.taxonomyNonce
            },
            success: function(response) {
                if (response.success) {
                    updateSyncStatus(response.data);
                } else {
                    const errorData = {
                        message: response.data.message || 'カテゴリーとタグの同期に失敗しました。',
                        type: 'error',
                        details: response.data.details
                    };
                    updateSyncStatus(errorData);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                const errorData = handleSyncError(jqXHR);
                if (errorData.details.error_code === 401) {
                    errorData.message += '（認証エラー: パスワードを確認してください）';
                } else if (errorData.details.error_code === 404) {
                    errorData.message += '（エンドポイントが見つかりません）';
                }
                updateSyncStatus(errorData);
            },
            complete: function() {
                isSyncing = false;
                $syncTaxonomiesButton.removeClass('syncing');
            }
        });
    });

    $('#sync-button').on('click', function() {
        const postId = $('#post_ID').val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync',
                post_id: postId,
                nonce: wpCrossPost.nonce
            },
            beforeSend: function() {
                $('#sync-status').html('同期中...');
            },
            success: function(response) {
                if (response.success) {
                    $('#sync-status').html(
                        `同期成功: ${response.data.success_count}サイト`
                    );
                } else {
                    $('#sync-status').html(
                        `エラー: ${response.data.error}`
                    );
                }
            }
        });
    });

    // 更新されたエラーハンドリング
    function handleSyncError(error) {
        let errorMessage = '不明なエラーが発生しました';
        
        if (error.responseJSON) {
            errorMessage = error.responseJSON.data.error || errorMessage;
            if (error.responseJSON.data.trace) {
                console.error('エラートレース:', error.responseJSON.data.trace);
            }
        } else if (error.statusText) {
            errorMessage = `${error.status}: ${error.statusText}`;
        }
        
        return {
            message: `同期失敗: ${errorMessage}`,
            type: 'error',
            details: {
                error_code: error.status || 'unknown',
                timestamp: new Date().toISOString()
            }
        };
    }

    // リアルタイムログ表示
    setInterval(() => {
        debug.logs.getRecent().then(logs => {
            $('#debug-logs').html(logs.map(log => 
                `<div class="log-entry ${log.type}">[${log.timestamp}] ${log.message}</div>`
            ));
        });
    }, 5000);
});

function showError(message) {
    $('#error-message').html(`<div class="error notice">${message}</div>`);
} 