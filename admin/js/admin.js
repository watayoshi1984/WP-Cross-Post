jQuery(document).ready(function($) {
    // サイト追加フォームの送信
    $('#wp-cross-post-add-site-form').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var submitButton = form.find('button[type="submit"]');
        var loadingIndicator = $('<span class="wp-cross-post-loading"></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_cross_post_add_site',
                nonce: $('#wp_cross_post_add_site_nonce').val(),
                site_name: $('#site_name').val(),
                site_url: $('#site_url').val(),
                username: $('#username').val(),
                app_password: $('#app_password').val()
            },
            beforeSend: function() {
                submitButton.prop('disabled', true).after(loadingIndicator);
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('エラー: ' + response.data);
                }
            },
            error: function() {
                alert('サーバーとの通信に失敗しました。');
            },
            complete: function() {
                submitButton.prop('disabled', false);
                loadingIndicator.remove();
            }
        });
    });

    // サイト削除ボタンのクリック
    $('.wp-cross-post-remove-site').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var siteId = button.data('site-id');
        var loadingIndicator = $('<span class="wp-cross-post-loading"></span>');

        if (confirm('このサイトを削除してもよろしいですか？')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_remove_site',
                    nonce: $('#wp_cross_post_remove_site_nonce').val(),
                    site_id: siteId
                },
                beforeSend: function() {
                    button.prop('disabled', true).after(loadingIndicator);
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data);
                        button.closest('tr').remove();
                    } else {
                        alert('エラー: ' + response.data);
                    }
                },
                error: function() {
                    alert('サーバーとの通信に失敗しました。');
                },
                complete: function() {
                    button.prop('disabled', false);
                    loadingIndicator.remove();
                }
            });
        }
    });
});

