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
        Admin\SettingsPage::registerMenu();
        Admin\ActivityPage::register();
        add_action('admin_notices', [Admin\SettingsPage::class, 'showWebhookSecretNotice']);
    }

    private static function registerAutoloader(): void {
        spl_autoload_register(function (string $class): void {
            if (strpos($class, 'HS\\') !== 0) {
                return;
            }
            $relative = str_replace('HS\\', '', $class);
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        });
    }

    /** Register the live-state REST endpoint (§B2). */
    public static function registerLiveStateEndpoint(): void {
        \HS\LiveState\Endpoint::register();
    }
}
