<?php
/**
 * Standalone control page — the full HS CloudFlare console at /control,
 * outside wp-admin.
 *
 * Intercepts the request at template_redirect (before the theme loads), gates
 * it behind login + manage_options, and renders SettingsPage::renderAdminUI()
 * inside a minimal admin-styled shell: WP core admin stylesheets + dashicons +
 * the plugin's own CSS/JS, and nothing from the (heavy) wedding theme. All the
 * interactive parts already work outside wp-admin — every AJAX endpoint checks
 * a nonce + capability, and the Settings API forms post to admin's options.php
 * by absolute URL — so the same markup drives both surfaces.
 *
 * The URL replaces the long-defunct "HitchStream Controls" theme template that
 * used to live at the same slug. Because this runs before the page template
 * resolves, it works whether or not the old WP page row still exists.
 */

namespace HS\Admin;

class ControlPage {

    /** URL path (relative to the site root) that serves the console. */
    const PATH = 'control';

    public static function register(): void {
        // Priority 1: ahead of redirect_canonical (10), so a stale/trashed
        // /control page row can never redirect or 404 us first.
        add_action('template_redirect', [__CLASS__, 'maybeRender'], 1);
    }

    /** Serve the console when the request is for /control, else do nothing. */
    public static function maybeRender(): void {
        if (!self::isControlRequest()) {
            return;
        }

        // Never let a page cache serve (or store) this — it's per-admin.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();

        if (!is_user_logged_in()) {
            auth_redirect(); // wp-login with redirect_to back here; exits
        }
        if (!current_user_can('manage_options')) {
            wp_die(
                'Sorry, you are not allowed to access this page.',
                'HitchStream Control',
                ['response' => 403]
            );
        }

        // If the old /control page row was trashed, the main query 404'd and
        // already queued that status — we're serving real content, so say so.
        status_header(200);

        self::render();
        exit;
    }

    /** Does the request path (minus any home subpath) equal /control ? */
    private static function isControlRequest(): bool {
        $req = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        // Subdirectory installs: strip the home path prefix before comparing.
        $home = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');
        if ($home !== '' && stripos($req . '/', $home . '/') === 0) {
            $req = trim(substr($req, strlen($home)), '/');
        }
        return strcasecmp($req, self::PATH) === 0;
    }

    /** Output the standalone shell around the shared admin UI. */
    private static function render(): void {
        // The Settings API render helpers (settings_fields, do_settings_fields,
        // submit_button, add_settings_section/field) live in an admin include,
        // and the field registrations normally run on admin_init — neither
        // happens on a front-end request, so pull both in by hand.
        require_once ABSPATH . 'wp-admin/includes/template.php';
        SettingsPage::registerCloudflareSettings();
        SettingsPage::registerWebhookSettings();
        SettingsPage::registerPlayerSettings();
        SettingsPage::registerStreamerSettings();
        SettingsPage::registerAlertSettings();
        SettingsPage::registerLiveUSettings();

        // Assets, registered by hand: wp_enqueue_scripts never fires because we
        // exit before the theme's wp_head(). Same handles + version constant as
        // the wp-admin enqueue, so the two surfaces can't drift.
        $base_url = plugin_dir_url(dirname(__DIR__)); // .../plugins/HitchStream_Cloudflare/
        wp_register_style('hscf-admin-style', $base_url . 'css/hscf-admin.css', ['dashicons'], HSCF_ASSET_VER);
        wp_enqueue_style('hscf-admin-style');
        wp_register_script('hscf-admin-script', $base_url . 'js/hscf-admin.js', ['jquery'], HSCF_ASSET_VER, true);
        wp_localize_script('hscf-admin-script', 'hscf_ajax', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('hscf_admin'),
            'player_url' => home_url('/player/'),
        ]);
        wp_enqueue_script('hscf-admin-script');

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>HitchStream Control — <?php echo esc_html(get_bloginfo('name')); ?></title>
<?php
        // WP core admin styles give us .wrap/.nav-tab/.button/.form-table/.notice
        // exactly as wp-admin renders them; the plugin stylesheet (last, so it
        // wins on source order) brings dashicons along as a dependency.
        wp_print_styles(['common', 'forms', 'buttons', 'hscf-admin-style']);
?>
<style>
/* Shell corrections: core common.css assumes the full admin chrome. */
/* The admin colour-scheme stylesheet doesn't load outside wp-admin, so pin the
   theme-colour vars to the default scheme — otherwise buttons/links fall back
   to generic indigo (#3858e9) instead of the admin blue the console uses. */
:root {
    --wp-admin-theme-color: #2271b1;
    --wp-admin-theme-color-darker-10: #135e96;
    --wp-admin-theme-color-darker-20: #0a4b78;
}
body.hscf-control-page { min-width: 0; padding: 0 20px 60px; box-sizing: border-box; background: #f0f0f1; }
body.hscf-control-page .hscf-admin { max-width: 1200px; margin: 0 auto; }
@media (max-width: 640px) { body.hscf-control-page { padding: 0 10px 40px; } }
</style>
</head>
<body class="wp-admin wp-core-ui js hscf-control-page">
<?php SettingsPage::renderAdminUI(true); ?>
<?php wp_print_scripts(['hscf-admin-script']); // prints jQuery (dependency) too ?>
</body>
</html>
<?php
    }
}
