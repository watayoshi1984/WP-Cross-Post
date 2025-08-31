jQuery(document).ready(function($) {
    var syncButton = $('#manual-sync-button');
    var statusContainer = $('#sync-status');

    // per-site controls: status -> show/hide datetime
    $(document).on('change', '.wp-cross-post-status', function() {
        var $wrap = $(this).closest('.site-controls');
        var status = $(this).val();
        var $sched = $wrap.find('.scheduled-time');
        if (status === 'future') {
            $sched.show();
        } else {
            $sched.hide();
            $wrap.find('input[type="datetime-local"]').val('');
        }
    });

    // Load taxonomies for each site on load and when checkbox toggled
    function loadSiteTaxonomies(siteId, $controls) {
        $.ajax({
            url: wpCrossPostData.ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_get_site_taxonomies',
                nonce: wpCrossPostData.taxonomyFetchNonce,
                site_id: siteId
            }
        }).done(function(resp) {
            if (!resp || !resp.success || !resp.data || !resp.data.data) return;
            var cats = resp.data.data.categories || [];
            var tags = resp.data.data.tags || [];
            var $catSel = $controls.find('.wp-cross-post-category');
            var $tagSel = $controls.find('.wp-cross-post-tags');
            var currentCat = $catSel.data('selected');
            var currentTags = $tagSel.data('selected') || [];

            $catSel.empty().append('<option value="">未設定</option>');
            cats.forEach(function(c) {
                var opt = $('<option/>').val(c.id).text(c.name);
                if (String(currentCat) === String(c.id)) opt.attr('selected', 'selected');
                $catSel.append(opt);
            });

            $tagSel.empty();
            tags.forEach(function(t) {
                var opt = $('<option/>').val(t.id).text(t.name);
                if (Array.isArray(currentTags) && currentTags.map(String).indexOf(String(t.id)) !== -1) {
                    opt.attr('selected', 'selected');
                }
                $tagSel.append(opt);
            });
        });
    }

    $('.site-controls').each(function() {
        var $c = $(this);
        var sid = $c.data('site-id');
        loadSiteTaxonomies(sid, $c);
    });

    // Gather per-site selections and sync
    syncButton.on('click', function(e) {
        e.preventDefault();
        var postId = $('#post_ID').val();
        var selectedSites = [];
        var perSite = {};

        $('input[name="wp_cross_post_sites[]"]:checked').each(function() {
            var sid = $(this).val();
            selectedSites.push(sid);
            var $controls = $('.site-controls[data-site-id="' + sid + '"]');
            var status = $controls.find('.wp-cross-post-status').val() || '';
            var dateVal = $controls.find('input[type="datetime-local"]').val() || '';
            var catVal = $controls.find('.wp-cross-post-category').val() || '';
            var tagVals = $controls.find('.wp-cross-post-tags').val() || [];
            perSite[sid] = {
                status: status,
                date: dateVal,
                category: catVal,
                tags: tagVals
            };
        });

        if (selectedSites.length === 0) {
            alert('同期先のサイトを選択してください。');
            return;
        }

        $.ajax({
            url: wpCrossPostData.ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_sync',
                nonce: wpCrossPostData.nonce,
                post_id: postId,
                selected_sites: selectedSites,
                per_site_settings: perSite
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

