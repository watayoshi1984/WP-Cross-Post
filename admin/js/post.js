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
                var type = (response && response.data && response.data.type) ? response.data.type : (response && response.success ? 'success' : 'error');
                var cssClass = type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'success');
                var message = (response && response.data && response.data.message) ? response.data.message : (response && response.success ? '同期が完了しました。' : 'エラーが発生しました。');

                statusContainer.html('<p class="' + cssClass + '">' + message + '</p>');

                if (response && response.data && response.data.details) {
                    var detailsHtml = '<ul class="sync-details">';

                    if (response.data.details.success_sites) {
                        response.data.details.success_sites.forEach(function(site) {
                            var display = (site.site_label || site.site_name || site.name || site.site_id || site.id || 'Unknown Site');
                            detailsHtml += '<li class="success">✓ ' + display + '</li>';
                        });
                    }

                    if (response.data.details.failed_sites) {
                        response.data.details.failed_sites.forEach(function(site) {
                            var display = (site.site_label || site.site_name || site.name || site.site_id || site.id || 'Unknown Site');
                            detailsHtml += '<li class="error">✗ ' + display + ': ' + site.error + '</li>';
                        });
                    }

                    detailsHtml += '</ul>';
                    statusContainer.append(detailsHtml);
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

