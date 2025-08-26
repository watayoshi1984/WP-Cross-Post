document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('add-site-form');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const siteName = document.getElementById('siteName').value.trim();

        // ✨ feat: 入力値の検証（サイト名が空の場合はユーザーへ通知）
        if (!siteName) {
            alert('サイト名を入力してください。');
            return;
        }
        
        // 既存のAjax送信処理（WordPressのajaxurlなどを利用）
        jQuery.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'wp_cross_post_add_site',
                siteName: siteName,
                // ... 他の必要なデータを追加 ...
            },
            success: function(response) {
                if (response.success) {
                    alert('サイトが正常に追加されました。');
                } else {
                    alert('サイトの追加に失敗しました。エラー: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                alert('Ajaxエラーが発生しました: ' + error);
            }
        });
    });
}); 