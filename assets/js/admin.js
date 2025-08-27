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
            
            if (details.scheduled_tasks && details.scheduled_tasks.length > 0) {
                statusHtml += `
                    <div class="status-details info">
                        <h4>スケジュールされたタスク:</h4>
                        <ul>
                            ${details.scheduled_tasks.map(task => `
                                <li>
                                    <i class="material-icons">schedule</i>
                                    タスクID: ${task}
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

        // 並列処理と非同期処理のオプションを確認
        const parallelSync = $('#parallel-sync').is(':checked');
        const asyncSync = $('#async-sync').is(':checked');

        let confirmMessage = '';
        if (asyncSync) {
            confirmMessage = '選択したサイトに非同期で同期しますか？';
        } else if (parallelSync) {
            confirmMessage = '選択したサイトに並列で同期しますか？';
        } else {
            confirmMessage = '選択したサイトに同期しますか？';
        }

        if (!confirm(confirmMessage)) {
            return;
        }

        isSyncing = true;
        $manualSyncButton.addClass('syncing');
        updateSyncStatus({
            message: asyncSync ? '非同期同期を開始しました...' : 
                   parallelSync ? '並列同期を開始しました...' : 
                   '同期を開始しました...',
            type: 'info'
        });

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync',
                nonce: wpCrossPost.nonce,
                post_id: postId,
                selected_sites: selectedSites,
                parallel_sync: parallelSync, // 並列処理オプションを追加
                async_sync: asyncSync // 非同期処理オプションを追加
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
            error: function(xhr, status, error) {
                updateSyncStatus({
                    message: wpCrossPost.i18n.syncError,
                    type: 'error'
                });
                if (debug) {
                    console.error('Sync error:', error);
                    console.log('Response:', xhr.responseText);
                }
            },
            complete: function() {
                isSyncing = false;
                $manualSyncButton.removeClass('syncing');
            }
        });
    });

    // タクソノミーの同期
    $syncTaxonomiesButton.on('click', function() {
        const $button = $(this);
        const originalText = $button.text();

        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync_taxonomies',
                nonce: wpCrossPost.taxonomyNonce
            },
            beforeSend: function() {
                $button.prop('disabled', true).text('同期中...');
            },
            success: function(response) {
                if (response.success) {
                    updateSyncStatus({
                        message: response.data,
                        type: 'success'
                    });
                } else {
                    updateSyncStatus({
                        message: response.data.message || wpCrossPost.i18n.taxonomySyncError,
                        type: 'error'
                    });
                }
            },
            error: function() {
                updateSyncStatus({
                    message: wpCrossPost.i18n.taxonomySyncError,
                    type: 'error'
                });
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    // 設定のエクスポート
    $('#export-settings').on('click', function() {
        $.ajax({
            url: wpCrossPost.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_export_settings',
                nonce: wpCrossPost.exportSettingsNonce
            },
            success: function(response) {
                if (response.success) {
                    // ダウンロードリンクを作成
                    var element = document.createElement('a');
                    element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(response.data));
                    element.setAttribute('download', 'wp-cross-post-settings.json');
                    element.style.display = 'none';
                    document.body.appendChild(element);
                    element.click();
                    document.body.removeChild(element);
                } else {
                    alert('設定のエクスポートに失敗しました。');
                }
            },
            error: function() {
                alert('設定のエクスポートに失敗しました。');
            }
        });
    });
    
    // 設定のインポート
    $('#import-settings').on('click', function() {
        var fileInput = $('#import-settings-file')[0];
        if (fileInput.files.length === 0) {
            alert('インポートするファイルを選択してください。');
            return;
        }
        
        var file = fileInput.files[0];
        var reader = new FileReader();
        
        reader.onload = function(e) {
            var settings = e.target.result;
            
            $.ajax({
                url: wpCrossPost.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_import_settings',
                    nonce: wpCrossPost.importSettingsNonce,
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        alert('設定をインポートしました。');
                        location.reload();
                    } else {
                        alert('設定のインポートに失敗しました。');
                    }
                },
                error: function() {
                    alert('設定のインポートに失敗しました。');
                }
            });
        };
        
        reader.readAsText(file);
    });

    // デバッグパネルの更新
    if (debug) {
        setInterval(() => {
            $.ajax({
                url: wpCrossPost.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_refresh_logs',
                    nonce: wpCrossPost.debugNonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#debug-logs').html(response.data.logs);
                    }
                }
            });
        }, 5000);
    }
});