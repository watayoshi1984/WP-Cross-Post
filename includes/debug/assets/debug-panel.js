(function($) {
    'use strict';

    $(document).ready(function() {
        const $panel = $('#wp-cross-post-debug-panel');
        const $content = $panel.find('.debug-panel-content');
        const $toggleBtn = $panel.find('.toggle-panel');
        
        // パネルの開閉状態を保存/復元
        const isPanelOpen = localStorage.getItem('wpCrossPostDebugPanelOpen') !== 'false';
        if (!isPanelOpen) {
            $content.hide();
            $toggleBtn.text('+');
        }

        // パネルの開閉トグル
        $toggleBtn.on('click', function() {
            $content.slideToggle(200);
            const isOpen = $content.is(':visible');
            $(this).text(isOpen ? '_' : '+');
            localStorage.setItem('wpCrossPostDebugPanelOpen', isOpen);
        });

        // ログの自動更新
        function refreshLogs() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_refresh_logs',
                    nonce: wpCrossPostDebug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.debug-logs').html(response.data.logs);
                        $('.performance-metrics').html(response.data.metrics);
                    }
                }
            });
        }

        // 30秒ごとにログを更新
        setInterval(refreshLogs, 30000);

        // ログレベルでフィルタリング
        $('.log-level-filter').on('change', function() {
            const level = $(this).val();
            if (level === 'all') {
                $('.debug-log-table tr').show();
            } else {
                $('.debug-log-table tr').hide();
                $('.debug-log-table tr:first-child').show(); // ヘッダー行は表示
                $('.log-level-' + level).show();
            }
        });

        // システム情報の更新
        function refreshSystemInfo() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_refresh_system_info',
                    nonce: wpCrossPostDebug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.debug-info-table').html(response.data.systemInfo);
                    }
                }
            });
        }

        // 1分ごとにシステム情報を更新
        setInterval(refreshSystemInfo, 60000);

        // パネルのドラッグ移動
        let isDragging = false;
        let startX, startY, initialX, initialY;

        $panel.find('.debug-panel-header').on('mousedown', function(e) {
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialX = $panel.offset().left;
            initialY = $panel.offset().top;

            $(document).on('mousemove', function(e) {
                if (isDragging) {
                    const deltaX = e.clientX - startX;
                    const deltaY = e.clientY - startY;
                    $panel.css({
                        left: initialX + deltaX,
                        right: 'auto',
                        top: initialY + deltaY,
                        bottom: 'auto'
                    });
                }
            });

            $(document).on('mouseup', function() {
                isDragging = false;
                $(document).off('mousemove mouseup');
            });
        });

        // エラー発生時の通知
        window.onerror = function(msg, url, line, col, error) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_cross_post_log_js_error',
                    nonce: wpCrossPostDebug.nonce,
                    error: {
                        message: msg,
                        url: url,
                        line: line,
                        column: col,
                        stack: error ? error.stack : ''
                    }
                }
            });
            return false;
        };
    });
})(jQuery); 