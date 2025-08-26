<?php
if (!defined('ABSPATH')) exit;

$post_id = get_the_ID();
$sites = $this->site_handler->get_sites();
$sync_info = get_post_meta($post_id, '_wp_cross_post_sync_info', true);

if (empty($sites)) {
    echo '<p>同期先のサイトが設定されていません。</p>';
    return;
}
?>

<div class="wp-cross-post-metabox">
    <div class="sites-selection">
        <h4>同期先サイト</h4>
        <?php foreach ($sites as $site): ?>
            <div class="site-option">
                <label>
                    <input type="checkbox" name="wp_cross_post_sites[]" value="<?php echo esc_attr($site['id']); ?>">
                    <?php echo esc_html($site['name']); ?>
                </label>
                <?php if (!empty($sync_info[$site['id']])): ?>
                    <span class="sync-status">
                        <?php
                        $status = $sync_info[$site['id']]['status'];
                        $icon = $status === 'success' ? '✓' : '✗';
                        $time = $sync_info[$site['id']]['sync_time'];
                        echo sprintf(
                            '%s 最終同期: %s',
                            esc_html($icon),
                            esc_html($time)
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="post-status-selection">
        <h4>投稿状態</h4>
        <select name="wp_cross_post_status" id="wp_cross_post_status">
            <option value="publish">即時公開</option>
            <option value="future">予約投稿</option>
            <option value="draft">下書き</option>
        </select>

        <div id="scheduled-time" style="display: none; margin-top: 10px;">
            <label>公開日時:</label>
            <input type="datetime-local" name="wp_cross_post_date" id="wp_cross_post_date">
        </div>
    </div>

    <div class="sync-actions">
        <button type="button" class="button button-primary" id="wp-cross-post-sync-button">
            同期を実行
        </button>
        <div id="wp-cross-post-status"></div>
    </div>
</div>

<style>
.wp-cross-post-metabox {
    padding: 10px;
}

.sites-selection {
    margin-bottom: 20px;
}

.site-option {
    margin: 8px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sync-status {
    font-size: 0.9em;
    color: #666;
}

.post-status-selection {
    margin-bottom: 20px;
}

.post-status-selection select {
    width: 100%;
    max-width: 200px;
}

.sync-actions {
    margin-top: 20px;
}

#wp-cross-post-status {
    margin-top: 10px;
}
</style>

<script>
jQuery(document).ready(function($) {
    const $statusSelect = $('#wp_cross_post_status');
    const $scheduledTime = $('#scheduled-time');
    const $dateInput = $('#wp_cross_post_date');

    // 現在の投稿の状態を取得して設定
    const currentStatus = '<?php echo esc_js($post->post_status); ?>';
    $statusSelect.val(currentStatus);

    // 予約投稿が選択されている場合、日時選択を表示
    if (currentStatus === 'future') {
        $scheduledTime.show();
        $dateInput.val('<?php echo esc_js(date('Y-m-d\TH:i', strtotime($post->post_date))); ?>');
    }

    // 投稿状態が変更されたときの処理
    $statusSelect.on('change', function() {
        if ($(this).val() === 'future') {
            $scheduledTime.show();
            if (!$dateInput.val()) {
                // デフォルトで1時間後を設定
                const defaultDate = new Date();
                defaultDate.setHours(defaultDate.getHours() + 1);
                $dateInput.val(defaultDate.toISOString().slice(0, 16));
            }
        } else {
            $scheduledTime.hide();
        }
    });
});
</script> 