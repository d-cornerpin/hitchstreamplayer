<?php
/**
 * ActivityPage — Tools → HitchStream Activity.
 *
 * Renders the last 200 rows of the hs_webhook_log table with filter-by-inputId
 * and CSV export. Two-click workflow: open page, filter by input ID, scan.
 */

namespace HS\Admin;

class ActivityPage {

    private const TABLE = 'hs_webhook_log';
    private const PER_PAGE = 200;

    /** Register the Tools submenu page. */
    public static function register(): void {
        add_action('admin_menu', function () {
            add_management_page(
                'HitchStream Activity',
                'HitchStream Activity',
                'manage_options',
                'hitchstream-activity',
                [__CLASS__, 'render']
            );
        });
        add_action('admin_init', [__CLASS__, 'handleCsvExport']);
    }

    /** Neutralize CSV/formula injection: prefix a leading =,+,-,@,tab,CR with '. */
    private static function csvSafe($value): string {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /** Handle CSV export submission. */
    public static function handleCsvExport(): void {
        if (!isset($_GET['hscf_export_csv']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('hscf_activity_csv');

        $input_id = isset($_GET['input_id']) ? sanitize_text_field($_GET['input_id']) : '';
        $rows = self::fetchRows($input_id);

        $filename = 'hitchstream-activity-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['received_at', 'input_id', 'event_type', 'normalized_state', 'error_code', 'signature_ok', 'correlation_id']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['received_at'],
                self::csvSafe($row['input_id']),
                self::csvSafe($row['event_type']),
                self::csvSafe($row['normalized_state'] ?? ''),
                self::csvSafe($row['error_code'] ?? ''),
                $row['signature_ok'] ? 'yes' : 'no',
                self::csvSafe($row['correlation_id']),
            ]);
        }
        fclose($fp);
        exit;
    }

    /** Render the activity page. */
    public static function render(): void {
        $input_id = isset($_GET['input_id']) ? sanitize_text_field($_GET['input_id']) : '';
        $rows = self::fetchRows($input_id);

        // Count total. wpdb requires prepare() for the placeholder; passing
        // bind params as a second arg to get_var() is not supported.
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($input_id !== '') {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE input_id = %s", $input_id)
            );
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }
    ?>
<div class="wrap">
    <h1>HitchStream Activity</h1>
    <p>
        Last <?php echo self::PER_PAGE; ?> of <?php echo number_format($total); ?> webhook log entries.
        The table is auto-trimmed weekly (rows older than 30 days).
    </p>

    <form method="get" action="">
        <input type="hidden" name="page" value="hitchstream-activity">
        <label for="input_id">Filter by Input ID:</label>
        <input type="text" id="input_id" name="input_id" value="<?php echo esc_attr($input_id); ?>"
               style="width:300px;" placeholder="e.g. abc123...">
        <input type="submit" class="button" value="Filter">
        <?php if ($input_id): ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=hitchstream-activity')); ?>">Clear</a>
        <?php endif; ?>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=hitchstream-activity&hscf_export_csv=1' . ($input_id ? '&input_id=' . urlencode($input_id) : '')), 'hscf_activity_csv')); ?>"
           style="color:#06b6d4;">Export CSV</a>
    </form>

    <?php if (empty($rows)): ?>
        <p>No entries found.</p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Received At</th>
                    <th>Input ID</th>
                    <th>Event Type</th>
                    <th>Normalized State</th>
                    <th>Error Code</th>
                    <th>Signature OK</th>
                    <th>Correlation ID</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo esc_html($row['received_at']); ?></td>
                    <td><?php echo esc_html($row['input_id']); ?></td>
                    <td><code><?php echo esc_html($row['event_type']); ?></code></td>
                    <td><?php echo esc_html($row['normalized_state'] ?? '—'); ?></td>
                    <td><?php echo esc_html($row['error_code'] ?? '—'); ?></td>
                    <td style="text-align:center;">
                        <span style="color:<?php echo $row['signature_ok'] ? 'green' : 'red'; ?>;">
                            <?php echo $row['signature_ok'] ? '✓' : '✗'; ?>
                        </span>
                    </td>
                    <td><code><?php echo esc_html($row['correlation_id']); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
    <?php
    }

    /** Fetch rows from the webhook log table. PER_PAGE is a class constant
     *  so it can be inlined; input_id must go through prepare(). */
    private static function fetchRows(string $input_id = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $limit = (int) self::PER_PAGE;

        $columns = 'received_at, input_id, event_type, normalized_state, error_code, signature_ok, correlation_id';

        if ($input_id !== '') {
            $sql = $wpdb->prepare(
                "SELECT {$columns} FROM {$table} WHERE input_id = %s ORDER BY received_at DESC LIMIT {$limit}",
                $input_id
            );
        } else {
            $sql = "SELECT {$columns} FROM {$table} ORDER BY received_at DESC LIMIT {$limit}";
        }

        $results = $wpdb->get_results($sql, ARRAY_A);
        return is_array($results) ? $results : [];
    }
}
