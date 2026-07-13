<?php
/**
 * Compatibility shims for the PARENT Celebration theme on modern WordPress.
 *
 * THE BIG ONE (found in the WP 7.0.1 upgrade rehearsal, and almost certainly
 * the original "WordPress updated itself and broke the site" incident that
 * forced the 6.1.1 downgrade):
 *
 * The parent hooks the `wp_video_shortcode_library` / `wp_audio_shortcode_library`
 * FILTERS with callbacks that call wp_enqueue_script() (functions.php:519/:530).
 * On WP 6.1 those filters only ran while rendering an [audio]/[video] shortcode.
 * Newer WordPress applies them inside wp_default_scripts() — i.e. DURING
 * WP_Scripts construction — so the parent's enqueue re-enters wp_scripts()
 * before the global instance exists, constructing WP_Scripts recursively
 * forever: memory exhaustion, sitewide 500s (verified with an Xdebug trace:
 * functions.php:532 → wp_enqueue_script → wp_scripts → wp_default_scripts →
 * filter → functions.php:532 → …).
 *
 * Fix: unhook the parent's unsafe callbacks and re-add guarded equivalents
 * that keep the exact same behavior when a shortcode actually renders, but
 * do nothing while wp_default_scripts is running. Harmless on old WordPress
 * (the guard never triggers there), so this can ship BEFORE the core upgrade.
 */

if (!defined('ABSPATH')) { exit; }

add_action('after_setup_theme', 'hscc_fix_shortcode_library_recursion', 0);
function hscc_fix_shortcode_library_recursion() {
    global $wp_filter;

    $map = array(
        'wp_video_shortcode_library' => array('boldthemes_wp_video_shortcode_library', 'celebration-video-shortcode', '/js/video_shortcode.js'),
        'wp_audio_shortcode_library' => array('boldthemes_wp_audio_shortcode_library', 'celebration-audio-shortcode', '/js/audio_shortcode.js'),
    );

    foreach ($map as $tag => $spec) {
        list($method, $handle, $file) = $spec;

        // Unhook ANY registered [object, method] callback with the parent's method
        // name — doesn't depend on the parent's global variable name, so a parent
        // refactor can't dodge the fix.
        if (isset($wp_filter[$tag])) {
            foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                foreach ($cbs as $cb) {
                    $fn = isset($cb['function']) ? $cb['function'] : null;
                    if (is_array($fn) && isset($fn[0], $fn[1]) && is_object($fn[0]) && $fn[1] === $method) {
                        remove_filter($tag, $fn, $prio);
                    }
                }
            }
        }

        // Guarded equivalent: identical behavior at shortcode-render time; inert
        // while WP_Scripts is being constructed (the recursion window).
        add_filter($tag, function ($library) use ($handle, $file) {
            if (doing_action('wp_default_scripts')) {
                return $library;
            }
            wp_enqueue_style('wp-mediaelement');
            wp_enqueue_script($handle, get_template_directory_uri() . $file, array('mediaelement'), null, true);
            return 'boldthemes_mejs';
        });
    }
}
