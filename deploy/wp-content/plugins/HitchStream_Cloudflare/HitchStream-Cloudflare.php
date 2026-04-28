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

// ── Boot new architecture ───────────────────────────────────────

require_once __DIR__ . '/src/Plugin.php';
\HS\Plugin::boot();
\HS\Plugin::registerLiveStateEndpoint();

// Activation/deactivation: install the webhook log schema and (un)schedule
// the trim cron. Plugin::boot() also installs idempotently on every load via
// plugins_loaded so existing installs upgrade without reactivation.
register_activation_hook(__FILE__, [\HS\Plugin::class, 'installSchema']);
register_deactivation_hook(__FILE__, [\HS\Plugin::class, 'deactivate']);

// ── Template loading (theme system hooks) ─────────────────────────

add_filter('theme_template_compat', function ($post_type) {
    if ('page' === $post_type) {
        return ['hitchstreamcontrols.php'];
    }
    return [];
});

add_action('init', function () {
    $filepath = plugin_dir_path(__FILE__) . 'hitchstreamcontrols.php';
    if (is_readable($filepath)) {
        locate_template($filepath, true, false);
    }
});

// ── Admin script enqueuing ────────────────────────────────────────

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script('hscf-admin-script', plugin_dir_url(__FILE__) . 'js/hscf-admin.js', ['jquery'], null, true);
    wp_localize_script('hscf-admin-script', 'hscf_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('hscf_admin'),
    ]);
});

add_action('wp_enqueue_scripts', function () {
    if (current_user_can('manage_options')) {
        wp_enqueue_script('hscf-admin-script-frontend', plugin_dir_url(__FILE__) . 'js/hscf-admin.js', ['jquery'], null, true);
        wp_localize_script('hscf-admin-script-frontend', 'hscf_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('hscf_admin'),
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
