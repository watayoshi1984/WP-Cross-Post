jQuery(document).ready(function($) {
    var syncButton = $('#wp-cross-post-sync-button');
    var statusContainer = $('#wp-cross-post-status');
    var statusSelect = $('#wp_cross_post_status');
    var dateInput = $('#wp_cross_post_date');

    syncButton.on('click', function(e) {
        e.preventDefault();
        var postId = $('#post_ID').val();
        var selectedSites = [];

        $('input[name="wp_cross_post_sites[]"]:checked').each(function() {
            selectedSites.push($(this).val());
        });

        if (selectedSites.length === 0) {
            alert('同期先のサイトを選択してください。');
            return;
        }

        // 投稿状態と公開日時の取得
        var postStatus = statusSelect.val();
        var postDate = postStatus === 'future' ? dateInput.val() : null;

        $.ajax({
            url: wpCrossPostData.ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync',
                nonce: wpCrossPostData.nonce,
                post_id: postId,
                selected_sites: selectedSites,
                post_status: postStatus,
                post_date: postDate
            },
            beforeSend: function() {
                syncButton.prop('disabled', true);
                statusContainer.html('<p>同期中...</p>');
            },
            success: function(response) {
                if (response.success) {
                    statusContainer.html('<p class="success">' + response.data.message + '</p>');
                    
                    if (response.data.details) {
                        var detailsHtml = '<ul class="sync-details">';
                        
                        if (response.data.details.success_sites) {
                            response.data.details.success_sites.forEach(function(site) {
                                detailsHtml += '<li class="success">✓ ' + site.name + '</li>';
                            });
                        }
                        
                        if (response.data.details.failed_sites) {
                            response.data.details.failed_sites.forEach(function(site) {
                                detailsHtml += '<li class="error">✗ ' + site.name + ': ' + site.error + '</li>';
                            });
                        }
                        
                        detailsHtml += '</ul>';
                        statusContainer.append(detailsHtml);
                    }
                } else {
                    statusContainer.html('<p class="error">エラー: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                statusContainer.html('<p class="error">サーバーとの通信に失敗しました。</p>');
            },
            complete: function() {
                syncButton.prop('disabled', false);
            }
        });
    });
});

