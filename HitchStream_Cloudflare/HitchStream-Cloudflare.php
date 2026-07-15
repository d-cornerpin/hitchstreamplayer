<?php
/**
 * Plugin Name: HitchStream_CloudFlare
 * Plugin URI: https://www.hitchsteam.com/HitchStream_Cloudflare
 * Description: Custom plugin for controlling HitchStream's Cloudflare streaming interface.
 * Version: 1.0.1
 * Author: David Cliff
 * Author URI: https://davecliff.io
 */

if (defined('ABSPATH') === false) {
    die;
}

// Cache-buster for js/hscf-admin.js + css/hscf-admin.css. One constant so the
// wp-admin page and the standalone /control page can never drift apart.
define('HSCF_ASSET_VER', '2.50');

// ── Boot new architecture ───────────────────────────────────────

require_once __DIR__ . '/src/Plugin.php';
\HS\Plugin::boot();
\HS\Plugin::registerLiveStateEndpoint();

// Activation/deactivation: install the webhook log schema and (un)schedule
// the trim cron. Plugin::boot() also installs idempotently on every load via
// plugins_loaded so existing installs upgrade without reactivation.
register_activation_hook(__FILE__, [\HS\Plugin::class, 'installSchema']);
register_deactivation_hook(__FILE__, [\HS\Plugin::class, 'deactivate']);

// ── Admin script enqueuing ────────────────────────────────────────
// (The legacy hitchstreamcontrols.php template-loading hooks that used to live
// here were dead code — the file never existed in the plugin — and are gone.
// /control is now served by \HS\Admin\ControlPage.)

add_action('admin_enqueue_scripts', function ($hook) {
    wp_enqueue_script('hscf-admin-script', plugin_dir_url(__FILE__) . 'js/hscf-admin.js', ['jquery'], HSCF_ASSET_VER, true);
    wp_localize_script('hscf-admin-script', 'hscf_ajax', [
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('hscf_admin'),
        'player_url' => home_url('/player/'),
    ]);
    // Console styles + dashicons, only on our admin page.
    if ($hook === 'toplevel_page_HitchStream_Cloudflare') {
        wp_enqueue_style('hscf-admin-style', plugin_dir_url(__FILE__) . 'css/hscf-admin.css', ['dashicons'], HSCF_ASSET_VER);
    }
});

add_action('wp_enqueue_scripts', function () {
    if (current_user_can('manage_options')) {
        wp_enqueue_script('hscf-admin-script-frontend', plugin_dir_url(__FILE__) . 'js/hscf-admin.js', ['jquery'], HSCF_ASSET_VER, true);
        wp_localize_script('hscf-admin-script-frontend', 'hscf_ajax', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('hscf_admin'),
            'player_url' => home_url('/player/'),
        ]);
    }
});

// ── Admin UI wrapper ──────────────────────────────────────────────

/**
 * Render the HitchStream Cloudflare admin page.
 * Delegated to Admin\SettingsPage::renderAdminUI().
 */
function HSCF_Admin(): void {
    \HS\Admin\SettingsPage::renderAdminUI();
}
