<?php
/**
 * Plugin bootstrap — wires up all new classes and registers hooks.
 *
 * Requires:
 *   - Autoloader for HS\ namespace (§B3.2)
 *   - src/BackwardCompat.php (backward-compat shims, §B4.10)
 *   - src/Admin/SettingsPage.php (admin UI, §B4.3)
 *   - src/Admin/AjaxController.php (ajax dispatcher, §B4.2)
 */

namespace HS;

require_once __DIR__ . '/Admin/AjaxController.php';
require_once __DIR__ . '/Admin/SettingsPage.php';
require_once __DIR__ . '/Admin/ActivityPage.php';
require_once __DIR__ . '/BackwardCompat.php';

class Plugin {

    /** Register all hooks: autoloader, ajax, admin menu, notices. */
    public static function boot(): void {
        self::registerAutoloader();
        Admin\AjaxController::register();
        // registerMenu() calls add_menu_page(), which only exists in admin
        // context and must run on admin_menu — hook it, don't call it inline
        // (calling it at load fatals on every non-admin / wp-cli request).
        add_action('admin_menu', [Admin\SettingsPage::class, 'registerMenu']);
        Admin\SettingsPage::register();
        Admin\ActivityPage::register();
        add_action('admin_notices', [Admin\SettingsPage::class, 'showConfigNotices']);
        // Idempotently ensure the webhook log table exists. dbDelta no-ops
        // when the schema already matches. This covers the existing-install
        // upgrade path where register_activation_hook never fires.
        add_action('plugins_loaded', [self::class, 'installSchemaIdempotent']);
        add_action('hs_webhook_log_trim', [self::class, 'trimWebhookLog']);
    }

    /** Create the hs_webhook_log table if missing. dbDelta is idempotent. */
    public static function installSchema(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'hs_webhook_log';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL,
            input_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            raw_body_hash CHAR(64) NOT NULL,
            normalized_state VARCHAR(32) DEFAULT NULL,
            error_code VARCHAR(64) DEFAULT NULL,
            signature_ok TINYINT(1) NOT NULL DEFAULT 0,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            correlation_id VARCHAR(36) DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_received_at (received_at),
            KEY idx_input_id (input_id),
            KEY idx_signature_ok (signature_ok)
        ) {$charset};";
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        if (!wp_next_scheduled('hs_webhook_log_trim')) {
            wp_schedule_event(time(), 'weekly', 'hs_webhook_log_trim');
        }
    }

    /** Idempotent install — runs on every load but only does work the first time. */
    public static function installSchemaIdempotent(): void {
        // Cheap check: did we install in this WP install already? Use a
        // version flag so we can re-run on schema changes.
        $installed = get_option('hs_webhook_log_schema_v', '');
        if ($installed === 'v1') return;
        self::installSchema();
        update_option('hs_webhook_log_schema_v', 'v1');
    }

    public static function trimWebhookLog(): void {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hs_webhook_log WHERE received_at <= %s",
            $cutoff
        ));
    }

    /** Plugin deactivation: unschedule the trim cron. */
    public static function deactivate(): void {
        $ts = wp_next_scheduled('hs_webhook_log_trim');
        if ($ts) wp_unschedule_event($ts, 'hs_webhook_log_trim');
    }

    private static function registerAutoloader(): void {
        spl_autoload_register(function (string $class): void {
            if (strpos($class, 'HS\\') !== 0) {
                return;
            }
            // The src/ tree is split: HS\Admin\* and HS\Services\* live directly
            // under src/, while HS\Config, HS\LiveState\*, HS\Webhook\* live under
            // src/HS/. Try both so every HS\ class resolves regardless of layout.
            $rel = str_replace('\\', '/', substr($class, 3)); // drop leading "HS\"
            foreach ([__DIR__ . '/' . $rel . '.php', __DIR__ . '/HS/' . $rel . '.php'] as $file) {
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }

    /** Register the live-state REST endpoint (§B2). */
    public static function registerLiveStateEndpoint(): void {
        \HS\LiveState\Endpoint::register();
    }
}
