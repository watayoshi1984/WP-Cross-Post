<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="wp-cross-post-debug-panel" class="wp-cross-post-debug-panel">
    <div class="debug-panel-header">
        <h2>WP Cross Post デバッグパネル</h2>
        <button class="toggle-panel">_</button>
    </div>
    
    <div class="debug-panel-content">
        <div class="debug-section">
            <h3>システム情報</h3>
            <table class="debug-info-table">
                <tr>
                    <th>PHP Version</th>
                    <td><?php echo phpversion(); ?></td>
                </tr>
                <tr>
                    <th>WordPress Version</th>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <th>Plugin Version</th>
                    <td><?php echo WP_CROSS_POST_VERSION; ?></td>
                </tr>
                <tr>
                    <th>Debug Mode</th>
                    <td><?php echo WP_DEBUG ? 'Enabled' : 'Disabled'; ?></td>
                </tr>
                <tr>
                    <th>Memory Usage</th>
                    <td><?php echo size_format(memory_get_usage(true)); ?></td>
                </tr>
            </table>
        </div>

        <div class="debug-section">
            <h3>最近のログ</h3>
            <div class="debug-logs">
                <?php
                $logs = get_option('wp_cross_post_debug_logs', array());
                $logs = array_slice($logs, 0, 10); // 最新10件のみ表示
                
                if (empty($logs)) {
                    echo '<p>ログはありません。</p>';
                } else {
                    echo '<table class="debug-log-table">';
                    echo '<tr><th>時刻</th><th>レベル</th><th>メッセージ</th></tr>';
                    foreach ($logs as $log) {
                        $level_class = 'log-level-' . $log['level'];
                        echo sprintf(
                            '<tr class="%s"><td>%s</td><td>%s</td><td>%s</td></tr>',
                            esc_attr($level_class),
                            esc_html($log['timestamp']),
                            esc_html(strtoupper($log['level'])),
                            esc_html($log['message'])
                        );
                    }
                    echo '</table>';
                }
                ?>
            </div>
        </div>

        <div class="debug-section">
            <h3>パフォーマンスメトリクス</h3>
            <div class="performance-metrics">
                <?php
                $metrics = get_option('wp_cross_post_performance_metrics', array());
                if (empty($metrics)) {
                    echo '<p>パフォーマンスデータはありません。</p>';
                } else {
                    echo '<table class="performance-table">';
                    echo '<tr><th>操作</th><th>実行時間</th><th>メモリ使用量</th></tr>';
                    foreach ($metrics as $label => $data) {
                        echo sprintf(
                            '<tr><td>%s</td><td>%.4f秒</td><td>%s</td></tr>',
                            esc_html($label),
                            $data['execution_time'],
                            size_format($data['memory_usage'])
                        );
                    }
                    echo '</table>';
                }
                ?>
            </div>
        </div>
    </div>
</div> 