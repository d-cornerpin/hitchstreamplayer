<?php
/**
 * Plugin Name: HitchStream_CloudFlare
 * Plugin URI: https://www.hitchsteam.com/HitchStream_Cloudflare
 * Description: Custom plugin for controlling HitchStream's Cloudflare streaming interface.
 * Version: 1.0.1
 * Author: David Cliff
 * Author URI: https://davecliff.io
 */

// ── Autoloader for HS\ namespace classes ───────────────────────

require_once __DIR__ . '/src/HS/ConfigError.php';
spl_autoload_register(function ($class) {
    if ($class !== 'HS\ConfigError' && strpos($class, 'HS\\') !== 0) {
        return;
    }
    $relative = str_replace('HS\\', '', $class);
    $file = __DIR__ . '/src/HS/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load LiveState components
require_once __DIR__ . '/src/HS/LiveState/Endpoint.php';
require_once __DIR__ . '/src/HS/LiveState/StateWriter.php';

function get_max_execution_time() {
    $max_execution_time = ini_get('max_execution_time'); // Fetches the max execution time in seconds

    // Format the time for display
    if ($max_execution_time > 60) {
        $minutes = floor($max_execution_time / 60);
        $seconds = $max_execution_time % 60;
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ($seconds > 0 ? ' and ' . $seconds . ' seconds' : '');
    } else {
        return $max_execution_time . ' seconds';
    }
}

function HSCF_list_cloudflare_videos() {
    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->listVideos();
        $data = json_decode($result['body'], true);
        if ($result['success'] && isset($data['success']) && $data['success']) {
            return $data['result'];
        }
        return "Failed to fetch videos.";
    } catch (\HS\ConfigError $e) {
        return "Cloudflare credentials not configured: " . $e->getMessage();
    } catch (\Throwable $e) {
        return "Error fetching videos: " . $e->getMessage();
    }
}



function get_formatted_max_upload_size() {
    $max_upload = ini_get('upload_max_filesize');
    $max_post = ini_get('post_max_size');

    $max_upload_bytes = convertPHPSizeToBytes($max_upload);
    $max_post_bytes = convertPHPSizeToBytes($max_post);

    $max_file_size = min($max_upload_bytes, $max_post_bytes);
    return formatSizeUnits($max_file_size);
}

function convertPHPSizeToBytes($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    }
    return round($size);
}

function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}



function my_plugin_templates($post_type) {
    $templates = array();

    if ('page' === $post_type) {
        $templates[] = 'hitchstreamcontrols.php';
    }

    return $templates;
}
add_filter('theme_template_compat', 'my_plugin_templates');

function my_plugin_register_template() {
    $filepath = plugin_dir_path(__FILE__) . 'hitchstreamcontrols.php';

    if (is_readable($filepath)) {
        locate_template($filepath, true, false);
    }
}
add_action('init', 'my_plugin_register_template');

// B2.1: Register the live-state REST endpoint.
require_once plugin_dir_path(__FILE__) . 'src/HS/LiveState/Endpoint.php';
add_action('init', function() {
    \HS\LiveState\Endpoint::register();
});

add_action("admin_menu", "HitchStream_CloudFlare_setup_menu");

function HSCF_upload_video() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    if (!empty($_FILES['video_file']) && $_FILES['video_file']['error'] == UPLOAD_ERR_OK) {
        $api_key = get_option("HSCF_cloudflare_api_key");
        $account_id = get_option("HSCF_cloudflare_account_id");
        $email = get_option("HSCF_cloudflare_email");

        // Prepare to get the TUS endpoint from Cloudflare
        try {
            $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
            $result = $client->post('media', [
                'headers' => [
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Length' => (string)$_FILES['video_file']['size'],
                    'Upload-Metadata' => 'filename ' . base64_encode($_FILES['video_file']['name']),
                ],
            ]);

            if ($result['success']) {
                $location = $result['body'] ?? '';
                wp_send_json_success(['location' => $location]);
            } else {
                wp_send_json_error('Error creating TUS session: ' . $result['body']);
            }
        } catch (\HS\ConfigError $e) {
            wp_send_json_error('Credentials not configured: ' . $e->getMessage());
        } catch (\Throwable $e) {
            wp_send_json_error('Error creating TUS session: ' . $e->getMessage());
        }
    }
}
add_action('wp_ajax_hscf_upload_video', 'HSCF_upload_video');



function HitchStream_CloudFlare_setup_menu()
{
    add_menu_page("CloudFlare Setup for HitchStream", "HS CloudFlare", "manage_options", "HitchStream_Cloudflare", "HSCF_Admin");
    add_action("admin_init", "HSCF_register_settings");
    add_action("admin_init", "HSCF_register_webhook_settings");
    add_action("admin_init", "HSCF_register_player_settings");
    add_action("admin_init", "HSCF_register_streamer_settings");
    add_action("admin_init", "HSCF_register_alert_settings");
}

function HSCF_register_settings()
{
    // Register a new setting for Cloudflare API key and Account ID
    register_setting("HSCF_settings", "HSCF_cloudflare_email");
    register_setting("HSCF_settings", "HSCF_cloudflare_api_key");
    register_setting("HSCF_settings", "HSCF_cloudflare_account_id");
    register_setting("HSCF_settings", "HSCF_cloudflare_api_token");

    // Add field for Cloudflare Email
    add_settings_field("HSCF_cloudflare_email_field", "CloudFlare Email", "HSCF_cloudflare_email_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");

    // Add a new section in the admin page
    add_settings_section("HSCF_cloudflare_settings_section", "CloudFlare API Settings", "HSCF_settings_section_callback", "HitchStream_Cloudflare");

    // Add fields for API key and Account ID
    add_settings_field("HSCF_cloudflare_api_key_field", "CloudFlare API Key", "HSCF_cloudflare_api_key_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");

    add_settings_field("HSCF_cloudflare_account_id_field", "CloudFlare Account ID", "HSCF_cloudflare_account_id_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");

    // B3.5: New field for Bearer token (preferred auth method)
    add_settings_field("HSCF_cloudflare_api_token_field", "CloudFlare API Token (Bearer)", "HSCF_cloudflare_api_token_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");

    // B3.5: Test button for Cloudflare credentials
    add_settings_field("hscf_cf_test_field", null, "HSCF_cf_test_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");
}

// --- Webhook Settings ---

function HSCF_register_webhook_settings()
{
    register_setting("HSCF_webhook_settings", "HSCF_webhook_url");
    register_setting("HSCF_webhook_settings", "HSCF_webhook_secret");

    add_settings_section("HSCF_webhook_settings_section", "Webhook Settings", "HSCF_webhook_settings_section_callback", "HitchStream_Cloudflare");
    add_settings_field("HSCF_webhook_url_field", "Webhook Callback URL", "HSCF_webhook_url_field_callback", "HitchStream_Cloudflare", "HSCF_webhook_settings_section");
    add_settings_field("HSCF_webhook_secret_field", "Webhook Secret (for Cloudflare)", "HSCF_webhook_secret_field_callback", "HitchStream_Cloudflare", "HSCF_webhook_settings_section");
}

function HSCF_webhook_settings_section_callback()
{
    echo '<p>Configure webhook notifications from Cloudflare Stream. After registering, the secret returned by Cloudflare will be stored in the Secret field above for signature verification.</p>';
}

function HSCF_webhook_url_field_callback()
{
    $setting = get_option('HSCF_webhook_url', '');
    if (!$setting) {
        $theme_dir = get_stylesheet_directory_uri();
        $setting = rtrim(home_url('/'), '/') . '/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php';
    }
    echo '<input type="url" name="HSCF_webhook_url" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" />';
}

function HSCF_webhook_secret_field_callback()
{
    $setting = get_option('HSCF_webhook_secret', '');
    echo '<input type="text" name="HSCF_webhook_secret" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="Set by Cloudflare on Register" disabled />';
    echo '<p class="description">Cloudflare generates its own secret when you click "Register Webhook". The stored secret is overwritten by Cloudflare\'s value.</p>';
    echo '<p><button type="button" class="button" id="hscf-webhook-rotate-btn">Rotate Secret</button> <span id="hscf-webhook-rotate-result" class="hscf-test-result"></span></p>';
    echo '<script>
    (function($) {
        $(document).on("click", "#hscf-webhook-rotate-btn", function() {
            var $btn = $(this);
            var $result = $("#hscf-webhook-rotate-result");
            $btn.prop("disabled", true).text("Rotating...");
            $result.text("");
            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "hscf_rotate_webhook",
                    nonce: $("#_wpnonce").val() || $("input[name=_wpnonce][value]").val()
                },
                success: function(resp) {
                    $btn.prop("disabled", false).text("Rotate Secret");
                    if (resp.success) {
                        $result.html("<span style=\"color:green\">&#10004; Secret rotated</span>");
                    } else {
                        $result.html("<span style=\"color:red\">&#10008; " + (resp.data || "Rotation failed") + "</span>");
                    }
                },
                error: function() {
                    $btn.prop("disabled", false).text("Rotate Secret");
                    $result.html("<span style=\"color:red\">&#10008; Request failed</span>");
                }
            });
        });
    })(jQuery);
    </script>';
}

// AJAX: Register webhook with Cloudflare
add_action('wp_ajax_hscf_register_webhook', 'hscf_register_webhook_admin');
function hscf_register_webhook_admin() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $callback_url = get_option('HSCF_webhook_url', '');
    $secret = sanitize_text_field(get_option('HSCF_webhook_secret', ''));

    if (empty($secret)) {
        // Auto-generate a secret if none provided
        $secret = bin2hex(random_bytes(32));
    }

    $result = hs_register_cf_webhook($callback_url, $secret);

    if (isset($result['error'])) {
        wp_send_json_error($result['error']);
        return;
    }

    if (isset($result['result']['secret'])) {
        // Store the secret from Cloudflare (overrides any user-provided one)
        update_option('HSCF_webhook_secret', $result['result']['secret']);
        update_option('HSCF_webhook_url', $callback_url);
        wp_send_json_success([
            'message' => 'Webhook registered successfully.',
            'secret' => $result['result']['secret'],
        ]);
    }

    wp_send_json_error('Webhook registration returned no secret. Response: ' . json_encode($result));
}

// AJAX: Delete webhook from Cloudflare
add_action('wp_ajax_hscf_delete_webhook', 'hscf_delete_webhook_admin');
function hscf_delete_webhook_admin() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $result = hs_delete_cf_webhook();
    // Clear local secret so it doesn't persist after the webhook is gone.
    delete_option('HSCF_webhook_secret');
    wp_send_json_success([
        'status' => $result['status'],
        'body' => $result['body'],
    ]);
}

// AJAX: Fetch current webhook status
add_action('wp_ajax_hscf_fetch_webhooks', 'hscf_fetch_webhooks_admin');
function hscf_fetch_webhooks_admin() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $result = hs_list_cf_webhooks();
    if (isset($result['error'])) {
        wp_send_json_error($result['error']);
        return;
    }
    wp_send_json_success($result);
}

// AJAX: Rotate webhook secret
add_action('wp_ajax_hscf_rotate_webhook', 'hscf_rotate_webhook_admin');
function hscf_rotate_webhook_admin() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    // First delete the old webhook, then register a new one with a fresh secret
    $callback_url = get_option('HSCF_webhook_url', '');
    if (!$callback_url) {
        wp_send_json_error('Webhook URL not configured.');
        return;
    }
    $new_secret = bin2hex(random_bytes(32));
    // Delete old webhook first
    hs_delete_cf_webhook();
    // Register new webhook with fresh secret
    $result = hs_register_cf_webhook($callback_url, $new_secret);
    if (isset($result['error'])) {
        wp_send_json_error($result['error']);
        return;
    }
    if (isset($result['result']['secret'])) {
        update_option('HSCF_webhook_secret', $result['result']['secret']);
        update_option('HSCF_webhook_url', $callback_url);
        wp_send_json_success(['message' => 'Secret rotated successfully.']);
    }
    wp_send_json_error('Rotation returned no secret. Response: ' . json_encode($result));
}

// --- Player Settings ---

function HSCF_register_player_settings()
{
    register_setting("HSCF_player_settings", "HSCF_customer_id");
    register_setting("HSCF_player_settings", "HSCF_poster_initial");
    register_setting("HSCF_player_settings", "HSCF_poster_idle");
    register_setting("HSCF_player_settings", "HSCF_poster_fatal");

    add_settings_section("HSCF_player_settings_section", "Player Settings", "HSCF_player_settings_section_callback", "HitchStream_Cloudflare");
    add_settings_field("HSCF_customer_id_field", "Cloudflare Customer ID", "HSCF_customer_id_field_callback", "HitchStream_Cloudflare", "HSCF_player_settings_section");
    add_settings_field("HSCF_poster_initial_field", "Poster Initial URL", "HSCF_poster_initial_field_callback", "HitchStream_Cloudflare", "HSCF_player_settings_section");
    add_settings_field("HSCF_poster_idle_field", "Poster Idle URL", "HSCF_poster_idle_field_callback", "HitchStream_Cloudflare", "HSCF_player_settings_section");
    add_settings_field("HSCF_poster_fatal_field", "Poster Fatal URL", "HSCF_poster_fatal_field_callback", "HitchStream_Cloudflare", "HSCF_player_settings_section");
}

function HSCF_player_settings_section_callback()
{
    echo '<p>Configurable defaults for the HSPlayer video player. URL params on the player page override these values.</p>';
}

function HSCF_customer_id_field_callback()
{
    $setting = get_option('HSCF_customer_id', 'juu1r5es4cbffqjf');
    echo '<input type="text" name="HSCF_customer_id" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" />';
}

function HSCF_poster_initial_field_callback()
{
    $setting = get_option('HSCF_poster_initial', 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Initial_Default.png');
    echo '<input type="url" name="HSCF_poster_initial" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
}

function HSCF_poster_idle_field_callback()
{
    $setting = get_option('HSCF_poster_idle', 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Idle_Default.png');
    echo '<input type="url" name="HSCF_poster_idle" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
}

function HSCF_poster_fatal_field_callback()
{
    $setting = get_option('HSCF_poster_fatal', 'https://hitchstream.com/wp-content/uploads/2025/09/Poster_fatal_2.png');
    echo '<input type="url" name="HSCF_poster_fatal" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
}

// --- Streamer Service Settings ---

function HSCF_register_streamer_settings()
{
    register_setting("HSCF_streamer_settings", "HSCF_streamer_api_url");
    register_setting("HSCF_streamer_settings", "HSCF_streamer_api_key");

    add_settings_section("HSCF_streamer_settings_section", "Streamer Service", "HSCF_streamer_settings_section_callback", "HitchStream_Cloudflare");
    add_settings_field("HSCF_streamer_api_url_field", "Streamer API URL", "HSCF_streamer_api_url_field_callback", "HitchStream_Cloudflare", "HSCF_streamer_settings_section");
    add_settings_field("HSCF_streamer_api_key_field", "Streamer API Key", "HSCF_streamer_api_key_field_callback", "HitchStream_Cloudflare", "HSCF_streamer_settings_section");

    // B3.5: Test button for Streamer
    add_settings_field("hscf_streamer_test_field", null, "HSCF_streamer_test_field_callback", "HitchStream_Cloudflare", "HSCF_streamer_settings_section");
}

function HSCF_streamer_settings_section_callback()
{
    echo '<p>Configuration for the internal placeholder-stream service. The API key is required for all placeholder-stream operations.</p>';
}

function HSCF_streamer_api_url_field_callback()
{
    $setting = get_option('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com');
    echo '<input type="url" name="HSCF_streamer_api_url" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
}

function HSCF_streamer_api_key_field_callback()
{
    $setting = get_option('HSCF_streamer_api_key', '');
    $masked = $setting ? str_repeat('*', 10) . substr($setting, -4) : '';
    echo '<input type="password" name="HSCF_streamer_api_key" value="' . esc_attr($masked) . '" style="width:100%;max-width:600px;" placeholder="Leave blank to keep current key" data-original-value="' . esc_attr($setting) . '" />';
    if ($setting) {
        echo ' <em>(set — leave blank to keep unchanged)</em>';
    }
}

function HSCF_streamer_test_field_callback()
{
    echo '<p><button type="button" class="button" id="hscf-streamer-test-btn">Test Streamer Service</button> <span id="hscf-streamer-test-result" class="hscf-test-result"></span></p>';
    echo '<script>
    (function($) {
        $(document).on("click", "#hscf-streamer-test-btn", function() {
            var $btn = $(this);
            var $result = $("#hscf-streamer-test-result");
            $btn.prop("disabled", true).text("Testing...");
            $result.text("");
            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "hscf_test_streamer",
                    nonce: $("#_wpnonce").val() || $("input[name=_wpnonce][value]").val()
                },
                success: function(resp) {
                    $btn.prop("disabled", false).text("Test Streamer Service");
                    if (resp.success) {
                        $result.html("<span style=\"color:green\">&#10004; " + (resp.data || "Streamer responded OK") + "</span>");
                    } else {
                        $result.html("<span style=\"color:red\">&#10008; " + (resp.data || "Streamer test failed") + "</span>");
                    }
                },
                error: function() {
                    $btn.prop("disabled", false).text("Test Streamer Service");
                    $result.html("<span style=\"color:red\">&#10008; Request failed</span>");
                }
            });
        });
    })(jQuery);
    </script>';
}

add_action('wp_ajax_hscf_test_streamer', function() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $apiUrl = \HS\Config::streamerApiUrl() . '/api/stream-state';
    $apiKey = \HS\Config::streamerApiKey();
    if (!$apiKey) {
        wp_send_json_error('Streamer API key not configured');
        return;
    }
    $response = wp_remote_get($apiUrl, [
        'headers' => ['X-API-KEY' => $apiKey],
        'timeout' => 10,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('Connection failed: ' . $response->get_error_message());
        return;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        wp_send_json_success('Streamer service responded OK');
    } else {
        wp_send_json_error('Streamer returned HTTP ' . $code);
    }
});

// --- Alerts Settings ---

function HSCF_register_alert_settings()
{
    register_setting("HSCF_alert_settings", "HSCF_alert_email");
    register_setting("HSCF_alert_settings", "HSCF_alert_codes");

    add_settings_section("HSCF_alert_settings_section", "Alerts", "HSCF_alert_settings_section_callback", "HitchStream_Cloudflare");
    add_settings_field("HSCF_alert_email_field", "Alert Email", "HSCF_alert_email_field_callback", "HitchStream_Cloudflare", "HSCF_alert_settings_section");
    add_settings_field("HSCF_alert_codes_field", "Alert Codes", "HSCF_alert_codes_field_callback", "HitchStream_Cloudflare", "HSCF_alert_settings_section");
}

function HSCF_alert_settings_section_callback()
{
    echo '<p>Email alerts are sent when critical webhook error_codes are received during a live stream.</p>';
}

function HSCF_alert_email_field_callback()
{
    $setting = get_option('HSCF_alert_email', '');
    echo '<input type="email" name="HSCF_alert_email" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="admin@example.com" />';
    echo '<p class="description">Leave blank to disable critical error email alerts.</p>';
}

function HSCF_alert_codes_field_callback()
{
    $setting = get_option('HSCF_alert_codes', 'ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION');
    echo '<input type="text" name="HSCF_alert_codes" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="Comma-separated error codes" />';
    echo '<p class="description">Default: <code>ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION</code>. These codes are also reflected in the player error messages.</p>';
}

// Admin notice: warn when webhook secret is not configured
add_action('admin_notices', 'HSCF_webhook_secret_notice');
function HSCF_webhook_secret_notice() {
    if (!current_user_can('manage_options')) return;
    $secret = get_option('HSCF_webhook_secret', '');
    if (empty($secret)) {
        echo '<div class="notice notice-error" style="border-left-color:#d63638;"><p><strong style="color:#d63638">&#9888; HitchStream:</strong> <strong style="color:#d63638">Webhook secret is NOT configured!</strong> All incoming webhooks are being rejected. Your weddings are not receiving stream state updates. <a href="' . admin_url('admin.php?page=HitchStream_Cloudflare') . '">Configure it here</a>.</p></div>';
    }
}

function HSCF_settings_section_callback()
{
}

function HSCF_cloudflare_email_field_callback()
{
    $setting = get_option("HSCF_cloudflare_email");
    echo "<input type='email' name='HSCF_cloudflare_email' value='" . esc_attr($setting) . "' />";
}

function HSCF_cloudflare_api_key_field_callback()
{
    // Get the value of the setting we've registered with register_setting()
    $setting = get_option("HSCF_cloudflare_api_key");
    // Output the field
    echo "<input type='text' name='HSCF_cloudflare_api_key' value='" . esc_attr($setting) . "' />";
}

function HSCF_cloudflare_account_id_field_callback()
{
    $setting = get_option("HSCF_cloudflare_account_id");
    echo "<input type='text' name='HSCF_cloudflare_account_id' value='" . esc_attr($setting) . "' />";
}

function HSCF_cloudflare_api_token_field_callback()
{
    $setting = get_option("HSCF_cloudflare_api_token", '');
    echo '<input type="password" name="HSCF_cloudflare_api_token" value="' . esc_attr($setting) . '" style="width:100%;max-width:600px;" placeholder="Optional — Bearer token (preferred over API key)" data-original-value="' . esc_attr($setting) . '" />';
    echo '<p class="description"><strong>Preferred auth method.</strong> When set, CloudflareClient uses Bearer token auth. Falls back to email+API-key with deprecation notice when unset.</p>';
}

function HSCF_cf_test_field_callback()
{
    echo '<p><button type="button" class="button" id="hscf-cf-test-btn">Test Cloudflare Connection</button> <span id="hscf-cf-test-result" class="hscf-test-result"></span></p>';
    echo '<script>
    (function($) {
        $(document).on("click", "#hscf-cf-test-btn", function() {
            var $btn = $(this);
            var $result = $("#hscf-cf-test-result");
            $btn.prop("disabled", true).text("Testing...");
            $result.text("");
            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "hscf_test_connection",
                    nonce: $("#_wpnonce").val() || $("input[name=_wpnonce][value]").val()
                },
                success: function(resp) {
                    $btn.prop("disabled", false).text("Test Cloudflare Connection");
                    if (resp.success) {
                        $result.html("<span style=\"color:green\">&#10004; Connected successfully — " + (resp.data && resp.data.input_id ? "input " + resp.data.input_id : "") + "</span>");
                    } else {
                        $result.html("<span style=\"color:red\">&#10008; " + (resp.data || "Connection failed") + "</span>");
                    }
                },
                error: function() {
                    $btn.prop("disabled", false).text("Test Cloudflare Connection");
                    $result.html("<span style=\"color:red\">&#10008; Request failed</span>");
                }
            });
        });
    })(jQuery);
    </script>';
}

add_action('wp_ajax_hscf_test_connection', function() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->lifecycle('test');
        $data = json_decode($result['body'], true);
        $input_id = $data['inputId'] ?? ($data['result']['inputId'] ?? '');
        wp_send_json_success(['input_id' => $input_id]);
    } catch (\HS\ConfigError $e) {
        wp_send_json_error('Credentials not configured: ' . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error('Connection failed: ' . $e->getMessage());
    }
});

function HSCF_get_live_inputs() {
    try {
        $account_id = \HS\Config::cloudflareAccountId();
        $client = new \HS\CloudflareClient($account_id);
        $result = $client->listVideos();
        $data = json_decode($result['body'], true);

        if (!$result['success'] || !($data['success'] ?? false)) {
            return "Failed to fetch live inputs.";
        }

        foreach ($data['result'] as $input) {
            $detail_result = $client->getLiveInput($input->uid);
            $detail_data = json_decode($detail_result['body'], true);

            if ($detail_result['success'] && $detail_data['success'] ?? false && isset($detail_data['result'])) {
                $input->srt_details = $detail_data['result']['srt'] ?? null;
                $input->rtmp_details = $detail_data['result']['rtmps'] ?? null;
                $input->status_details = $detail_data['result']['status']['current']['state'] ?? 'Status Unavailable';
            }
        }

        return $data['result'];
    } catch (\HS\ConfigError $e) {
        return "Cloudflare credentials not configured: " . $e->getMessage();
    } catch (\Throwable $e) {
        return "Error fetching live inputs: " . $e->getMessage();
    }
}

function HSCF_ajax_get_live_inputs() {
    $live_inputs = HSCF_get_live_inputs();
    
    if (is_array($live_inputs)) {
        $inputs_data = array();
        foreach ($live_inputs as $input) {
            $input_name = isset($input->meta) && isset($input->meta->name) ? $input->meta->name : "Unnamed Input";
            $rtmpKey = isset($input->rtmp_details) ? esc_html($input->rtmp_details->streamKey) : "";
            
            // Combine UID and RTMP key with a delimiter
            $value = $input->uid . "|" . $rtmpKey;

            $inputs_data[] = array(
                'name' => $input_name,
                'value' => $value // UID and RTMP key combined
            );
        }
        wp_send_json_success($inputs_data);
    } else {
        wp_send_json_error('Failed to fetch live inputs.');
    }
}

add_action('wp_ajax_get_live_inputs', 'HSCF_ajax_get_live_inputs');
function HSCF_ajax_get_live_inputs() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $live_inputs = HSCF_get_live_inputs();

function HSCF_delete_live_input($input_id) {
    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->deleteLiveInput($input_id);
        $data = json_decode($result['body'], true);

        if ($result['success'] && ($data['success'] ?? false)) {
            return "Live input deleted successfully.";
        }
        return "Failed to delete live input: " . json_encode($data['errors'] ?? []);
    } catch (\HS\ConfigError $e) {
        return "Cloudflare credentials not configured: " . $e->getMessage();
    } catch (\Throwable $e) {
        return "Error deleting live input: " . $e->getMessage();
    }
}

function HSCF_create_live_input($stream_name) {
    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->createLiveInput([
            'meta' => ['name' => $stream_name],
            'recording' => ['mode' => 'automatic'],
        ]);
        $data = json_decode($result['body'], true);

        if (!$result['success'] || !($data['success'] ?? false)) {
            error_log("Failed to create live input: " . json_encode($data['errors'] ?? []));
            return;
        }

        return $data['result'];
    } catch (\HS\ConfigError $e) {
        error_log("Live input creation failed: " . $e->getMessage());
        return;
    } catch (\Throwable $e) {
        error_log("Error creating live input: " . $e->getMessage());
        return;
    }
}

function HSCF_enqueue_scripts_admin() {
    wp_enqueue_script("hscf-admin-script", plugin_dir_url(__FILE__) . "js/hscf-admin.js", ["jquery"], null, true);
    wp_localize_script("hscf-admin-script", "hscf_ajax", array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('hscf_admin'),
    ));
}

function HSCF_enqueue_scripts_frontend() {
    if (current_user_can('manage_options')) { // Checks if the user is an admin
        wp_enqueue_script("hscf-admin-script-frontend", plugin_dir_url(__FILE__) . "js/hscf-admin.js", ["jquery"], null, true);
        wp_localize_script("hscf-admin-script-frontend", "hscf_ajax", array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('hscf_admin'),
        ));
    }
}

// Enqueue script for the admin dashboard
add_action("admin_enqueue_scripts", "HSCF_enqueue_scripts_admin");

// Enqueue script for the front-end for admin users
add_action("wp_enqueue_scripts", "HSCF_enqueue_scripts_frontend");




function hscf_check_live_input_status()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $live_inputs = HSCF_get_live_inputs();

    if (is_string($live_inputs)) {
        // If the function returned an error string, send an error response
        wp_send_json_error($live_inputs);
        return;
    }

    $statuses = [];
    foreach ($live_inputs as $input) {
        // Assuming $input includes 'uid' and 'status_details'
        $statuses[$input->uid] = isset($input->status_details) ? $input->status_details : "Status Unavailable";
    }

    wp_send_json_success($statuses);
}
add_action("wp_ajax_hscf_check_live_input_status", "hscf_check_live_input_status");

function HSCF_get_live_input_outputs($input_id) {
    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        // Use CloudflareClient's get for the outputs endpoint
        $result = $client->get("stream/live_inputs/{$input_id}/outputs");
        $data = json_decode($result['body'], true);
        if ($result['success'] && ($data['success'] ?? false)) {
            return $data['result'];
        }
        return "Failed to fetch outputs.";
    } catch (\HS\ConfigError $e) {
        return "Cloudflare credentials not configured: " . $e->getMessage();
    } catch (\Throwable $e) {
        return "Error fetching outputs: " . $e->getMessage();
    }
}

add_action("wp_ajax_hscf_delete_output", "hscf_delete_output");

function hscf_delete_output()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $output_id = sanitize_text_field($_POST["output_id"]);
    $input_id = sanitize_text_field($_POST["input_id"]);

    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->delete("stream/live_inputs/{$input_id}/outputs/{$output_id}");
        wp_send_json_success("Output deleted successfully.");
    } catch (\HS\ConfigError $e) {
        wp_send_json_error("Credentials not configured: " . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error("Error deleting output: " . $e->getMessage());
    }
}

add_action("wp_ajax_hscf_create_output", "hscf_create_output");

function hscf_create_output()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $streamKey = sanitize_text_field($_POST["stream_key"]);
    $streamUrl = sanitize_text_field($_POST["stream_url"]);
    $input_id = sanitize_text_field($_POST["input_id"]);

    if (!$streamKey || !$streamUrl || !$input_id) {
        wp_send_json_error("Missing required information");
        return;
    }

    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->post("stream/live_inputs/{$input_id}/outputs", [
            'body' => wp_json_encode(["enabled" => true, "streamKey" => $streamKey, "url" => $streamUrl]),
        ]);
        $response_body = json_decode($result['body'], true);

        if ($result['success'] && ($response_body['success'] ?? false)) {
            wp_send_json_success("Output created successfully.");
        } else {
            wp_send_json_error("Failed to create output");
        }
    } catch (\HS\ConfigError $e) {
        wp_send_json_error("Credentials not configured: " . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error("Error creating output: " . $e->getMessage());
    }
}

add_action("wp_ajax_hscf_toggle_output", "hscf_toggle_output");

function hscf_toggle_output()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $output_id = sanitize_text_field($_POST["output_id"]);
    $input_id = sanitize_text_field($_POST["input_id"]);
    $enabled = filter_var($_POST["enabled"], FILTER_VALIDATE_BOOLEAN);

    if (!$output_id || $input_id === null) {
        wp_send_json_error("Missing required information");
        return;
    }

    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->put("stream/live_inputs/{$input_id}/outputs/{$output_id}", [
            'body' => wp_json_encode(["enabled" => $enabled]),
        ]);
        $decoded_response = json_decode($result['body'], true);

        if ($result['success'] && ($decoded_response['success'] ?? false)) {
            wp_send_json_success("Output updated successfully.");
        } else {
            $error_message = isset($decoded_response['errors']) ? json_encode($decoded_response['errors']) : 'Unknown error';
            wp_send_json_error("Failed to update output: " . $error_message);
        }
    } catch (\HS\ConfigError $e) {
        wp_send_json_error("Credentials not configured: " . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error("Error updating output: " . $e->getMessage());
    }
}

function HSCF_get_videos_by_stream_name($stream_name) {
    try {
        $account_id = \HS\Config::cloudflareAccountId();
        $client = new \HS\CloudflareClient($account_id);

        // Fetch all videos (paginated)
        $videos = [];
        $page = 1;
        do {
            $result = $client->listVideos(null, $page, 100);
            $data = json_decode($result['body'], true);
            if (!$result['success'] || !($data['success'] ?? false) || empty($data['result'])) {
                break;
            }
            $videos = array_merge($videos, $data['result']);
            $page++;
        } while (!empty($data['result']));

        $filtered_videos = [];
        foreach ($videos as $video) {
            if (!isset($video['meta']['name']) || stripos($video['meta']['name'], $stream_name) === false) {
                continue;
            }

            $detail_result = $client->get("stream/{$video['uid']}/downloads");
            if ($detail_result['success']) {
                $detail_data = json_decode($detail_result['body'], true);
                if (($detail_data['success'] ?? false)) {
                    foreach ($detail_data['result'] ?? [] as $download) {
                        if (isset($download['url']) && strpos($download['url'], '.mp4') !== false) {
                            $video['mp4_download_url'] = $download['url'];
                            break;
                        }
                    }
                }
            }

            $filtered_videos[] = $video;
        }

        return $filtered_videos;
    } catch (\HS\ConfigError $e) {
        error_log("Video lookup failed: " . $e->getMessage());
        return;
    } catch (\Throwable $e) {
        error_log("Error fetching videos by stream name: " . $e->getMessage());
        return;
    }
}

add_action("wp_ajax_hscf_create_download", "HSCF_create_download");

function HSCF_create_download()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $video_id = sanitize_text_field($_POST["video_id"]);

    if (!$video_id) {
        wp_send_json_error("Missing required information");
        return;
    }

    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->post("stream/{$video_id}/downloads");
        wp_send_json_success("Download created successfully.");
    } catch (\HS\ConfigError $e) {
        wp_send_json_error("Credentials not configured: " . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error("Error creating download: " . $e->getMessage());
    }
}

add_action("wp_ajax_hscf_check_download_status", "hscf_check_download_status");

function hscf_check_download_status()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $video_id = sanitize_text_field($_POST["video_id"]);

    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->get("stream/{$video_id}/downloads");
        $data = json_decode($result['body'], true);

        if ($result['success'] && !empty($data['result'])) {
            foreach ($data['result'] as $download) {
                if (isset($download['url']) && strpos($download['url'], '.mp4') !== false) {
                    wp_send_json_success(['download_url' => $download['url']]);
                    return;
                }
            }
        }

        wp_send_json_error("MP4 download URL not available yet.");
    } catch (\HS\ConfigError $e) {
        wp_send_json_error("Credentials not configured: " . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error("Error checking download status: " . $e->getMessage());
    }
}

function hscf_delete_recording()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $video_id = sanitize_text_field($_POST["video_id"]);

    if (!$video_id) {
        wp_send_json_error("Missing required information");
        return;
    }

    try {
        $client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
        $result = $client->delete("stream/{$video_id}");
        $response_body = $result['body'];
        wp_send_json_success(['response_code' => $result['status'], 'response_body' => $response_body]);
    } catch (\HS\ConfigError $e) {
        wp_send_json_error("Credentials not configured: " . $e->getMessage());
    } catch (\Throwable $e) {
        wp_send_json_error('Error deleting recording: ' . $e->getMessage());
    }
}

add_action('wp_ajax_hscf_delete_recording', 'hscf_delete_recording');

function get_video_files() {
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $apiUrl = get_option('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com') . '/api/list-videos';
    $apiKey = get_option('HSCF_streamer_api_key', '');
    if (!$apiKey) {
        wp_send_json_error('Streamer API key not configured', 400);
    }

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
        "Content-Type" => "application/json",
    ];

    // Send the GET request
    $response = wp_remote_get($apiUrl, [
        'headers' => $headers,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_get_video_files', 'get_video_files');

function start_placeholderstream()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $videoFile = sanitize_text_field($_POST['videoFile']);
    $rtmpsUrl = sanitize_url($_POST['rtmpsUrl']);
    $rtmpsKey = sanitize_text_field($_POST['rtmpsKey']);
    // API URL of your Node.js app
    $apiUrl = get_option('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com') . '/api/start-streaming';

    // API Key
    $apiKey = get_option('HSCF_streamer_api_key', '');
    if (!$apiKey) {
        wp_send_json_error('Streamer API key not configured', 400);
    }

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
        "Content-Type" => "application/json",
    ];

    // Prepare the payload
    $data = [
        'videoFile' => $videoFile,
        'rtmpsUrl' => $rtmpsUrl,
        'rtmpsKey' => $rtmpsKey,
    ];

    // Use wp_remote_post to send the request
    $response = wp_remote_post($apiUrl, [
        'headers' => $headers,
        'body' => json_encode($data),
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_start_placeholderstream', 'start_placeholderstream');

function stop_placeholderstream()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    // API URL of your Node.js app
    $apiUrl = get_option('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com') . '/api/stop-streaming';

    // API Key
    $apiKey = get_option('HSCF_streamer_api_key', '');
    if (!$apiKey) {
        wp_send_json_error('Streamer API key not configured', 400);
    }

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
        "Content-Type" => "application/json",
    ];

    // Use wp_remote_post to send the request
    $response = wp_remote_post($apiUrl, [
        'headers' => $headers,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_stop_placeholderstream', 'stop_placeholderstream');

function check_stream_state()
{
    if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), 'hscf_admin')) {
        wp_send_json_error('nonce verification failed', 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    // API URL of your Node.js app
    $apiUrl = get_option('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com') . '/api/stream-state';

    // API Key
    $apiKey = get_option('HSCF_streamer_api_key', '');
    if (!$apiKey) {
        wp_send_json_error('Streamer API key not configured', 400);
    }

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
    ];

    // Use wp_remote_get to send the request
    $response = wp_remote_get($apiUrl, [
        'headers' => $headers,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_check_stream_state', 'check_stream_state');

function HSCF_Admin()
{
    // Check for deletion and process it
    if (isset($_GET["delete_input"]) && isset($_GET["confirm_delete"]) && $_GET["confirm_delete"] == "yes") {
        HSCF_delete_live_input(sanitize_text_field($_GET["delete_input"]));
    }

    // Check for stream creation
    if (isset($_POST["create_stream"]) && !empty($_POST["stream_name"])) {
        HSCF_create_live_input(sanitize_text_field($_POST["stream_name"]));
    }
    
    // Check if the form has been submitted and handle the upload
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['video_file'])) {
        HSCF_upload_video();
    }
    ?>
<div class="wrap">
    <div id="cfModal" class="cfmodal">
        <div class="cfmodalbox">
            <span class="close">&times;</span>
            <div class="cfmodal-content">
                <p>...</p>
            </div>
        </div>
    </div>
    <h1>HitchStream CloudFlare Setup</h1>
    <div class="top-container">
        <div class="form-container">
            <h2>Create Stream</h2>
            <form method="post" action="">
                <input type="text" name="stream_name" placeholder="Enter stream name" required>
                <input type="submit" name="create_stream" value="Create" class="copy-btn">
            </form>
            <h2>Placeholder Video</h2>
            <select class="FileSelector"></select>
        </div>

        <div class="middle-img-container">
            <div class="middleitem">
                <a href="https://solo.liveu.tv/dashboard" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/03/livedashboard.png" alt="LiveU Dashboard" />
                </a>
            </div>
            <div class="middleitem">
                <a href="https://streamer1.hitchstream.com" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/04/placeholderstreamer.png" alt="Placeholder Streamer" />
                </a>
            </div>
            <!--
            <div class="speedtest middleitem" style="width:150px;height:150px;">
                <iframe style="border:none;width:100%;height:100%;border:none;border-radius:10px;overflow:hidden !important;" src="//openspeedtest.com/speedtest"></iframe>
            </div>
-->
            <div class="middleitem">
                <a href="https://hitchstream.com/control" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/04/controller.png" alt="HitchStream Controller" />
                </a>
            </div>
            <div class="middleitem">
                <a href="https://dash.cloudflare.com/83584468f508b713b66fd41d85e58280/stream/videos" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/03/cloudflaredashboard.png" alt="CloudFlare Dashboard" />
                </a>
            </div>
        </div>

        <div class="form-container right">
            <form method="post" action="options.php">
                <?php
                settings_fields("HSCF_settings");
                do_settings_sections("HitchStream_Cloudflare");
                submit_button();
                ?>
            </form>
        </div>
    </div>

    <!-- Webhook Settings -->
    <h2>Webhook Settings</h2>
    <div class="webhook-form-container" style="margin-bottom:30px;">
        <form method="post" action="options.php" id="webhook-settings-form">
            <?php
            settings_fields("HSCF_webhook_settings");
            do_settings_sections("HitchStream_Cloudflare");
            ?>
            <div style="margin-top:15px;">
                <input type="button" id="btn-register-webhook" value="Register Webhook with Cloudflare" class="copy-btn" style="background:#06b6d4;border-color:#06b6d4;margin-right:10px;">
                <input type="button" id="btn-delete-webhook" value="Delete Webhook" class="copy-btn" style="background:#ef4444;border-color:#ef4444;">
                <input type="button" id="btn-fetch-webhook-status" value="Fetch Status" class="copy-btn" style="background:#6b7280;border-color:#6b7280;">
            </div>
        </form>
        <div id="webhook-status" style="margin-top:15px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;display:none;"></div>
    </div>

    <!-- Player Settings -->
    <h2>Player Settings</h2>
    <div style="margin-bottom:30px;">
        <form method="post" action="options.php">
            <?php
            settings_fields("HSCF_player_settings");
            do_settings_sections("HitchStream_Cloudflare");
            submit_button();
            ?>
        </form>
    </div>

    <!-- Streamer Service Settings -->
    <h2>Streamer Service Settings</h2>
    <div style="margin-bottom:30px;">
        <form method="post" action="options.php">
            <?php
            settings_fields("HSCF_streamer_settings");
            do_settings_sections("HitchStream_Cloudflare");
            submit_button();
            ?>
        </form>
    </div>

    <!-- Alerts Settings -->
    <h2>Alerts Settings</h2>
    <div style="margin-bottom:30px;">
        <form method="post" action="options.php">
            <?php
            settings_fields("HSCF_alert_settings");
            do_settings_sections("HitchStream_Cloudflare");
            submit_button();
            ?>
        </form>
    </div>

    <h2>Current Streams</h2>
    <style>
        .FileSelector {
            width: 250px;
        }

        .cfmodal {
            display: none;
            /* Hidden by default */
            position: fixed;
            /* Stay in place */
            z-index: 1;
            /* Sit on top */
            left: 0;
            top: 0;
            width: 100%;
            /* Full width */
            height: 100%;
            /* Full height */
            overflow: auto;
            /* Enable scroll if needed */
            background-color: rgb(0, 0, 0);
            /* Fallback color */
            background-color: rgba(0, 0, 0, 0.4);
            /* Black w/ opacity */
        }

        /* Modal Content/Box */
        .cfmodalbox {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 360px;
            border-radius: 10px;
            filter: drop-shadow(0px 5px 10px);
        }

        .cfmodal-content {
            overflow-wrap: break-word;
            padding-top: 10px;
        }

        .modalcontentbox {
            background-color: #e9e9e9;
            border: 1px solid #999999;
            color: black;
            padding: 5px;
            border-radius: 3px;
        }

        #progress-container {
            width: 100%;
            background: #eee;
            display: none;
        }

        #progress-bar {
            height: 20px;
            width: 0%;
            background-color: #2271b1;
        }



        /* The Close Button */
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .top-container {
            display: flex;
            justify-content: space-between;
        }

        .form-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }

        .middle-img-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: flex-end;
        }

        .middleitem {
            padding: 0px 10px 0px 10px;

        }

        .form-container.right {
            align-items: flex-end;
        }

        .form-table th {
            padding: 5px;
            vertical-align: middle;
        }

        .form-table td {
            padding: 5px;
            vertical-align: middle;
        }

        .live-input-container {
            display: flex;
            margin-bottom: 20px;
            background-color: #e5e5e5;
        }

        .live-input-left,
        .live-input-middle-1,
        .live-input-middle-2,
        .live-input-right {
            flex: 1;
        }

        .status-connected {
            color: green;
        }

        .status-disconnected {
            color: red;
        }

        .copy-btn {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
            text-decoration: none;
            text-shadow: none;
            font-size: 13px;
            line-height: 2.15384615;
            min-height: 30px;
            margin: 5px;
            padding: 0 10px;
            cursor: pointer;
            border-width: 1px;
            border-style: solid;
            -webkit-appearance: none;
            border-radius: 3px;
            white-space: nowrap;
            box-sizing: border-box;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 30px;
            height: 17px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 17px;
            width: 16px;
            left: 0px;
            bottom: 0px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
        }

        input:checked+.slider {
            background-color: #2271b1;
        }

        input:focus+.slider {
            box-shadow: 0 0 1px #2271b1;
        }

        input:checked+.slider:before {
            -webkit-transform: translateX(14px);
            -ms-transform: translateX(14px);
            transform: translateX(14px);
        }

        /* Rounded sliders */
        .slider.round {
            border-radius: 34px;
        }

        .slider.round:before {
            border-radius: 50%;
        }

        .video-container {
            width: 200px;
            height: auto;
            /* Adjusted to auto to accommodate variable content heights */
            margin: 5px;
            flex: 0 0 auto;
            /* Prevents shrinking and sets the basis to auto */
            display: flex;
            flex-direction: column;
            /* Organizes the child elements (name, player, buttons) in a column */
            align-items: center;
            /* Centers the content horizontally */
            overflow: hidden;
            /* Keeps all content neatly contained */
        }

        .video-container:nth-child(odd) {
            background-color: #e5e5e5;
            /* Sets a light grey background for odd-numbered video containers */
        }


        .video-name {
            height: auto;
            /* Allows the height to adjust based on content */
            min-height: 36px;
            /* Minimum height to accommodate at least one line of text */
            margin: 5px 0;
            /* Adds vertical spacing */
            text-align: center;
            /* Centers the text horizontally */
            word-wrap: break-word;
            /* Ensures words do not overflow the container */
            line-height: 18px;
            /* Line height to manage the spacing between lines of text */
        }

        #videos-panel {
            display: flex;
            flex-wrap: wrap;
            /* Allows items to wrap to the next line */
            align-items: flex-start;
            /* Aligns items at the start of the line */
            justify-content: center;
            /* Centers containers horizontally */
        }

        .video-buttons {
            display: flex;
            justify-content: center;
            margin-top: 10px;
            /* Space between video and buttons */
        }

        .video-buttons button {
            width: 24px;
            height: 24px;
            background-size: cover;
            border: none;
            cursor: pointer;
            margin: 0 5px;
            /* 10px space between buttons */
        }

    </style>
    <?php
    $live_inputs = HSCF_get_live_inputs();
    if (is_array($live_inputs)) {
        foreach ($live_inputs as $input) {
            $input_name = isset($input->meta) && isset($input->meta->name) ? $input->meta->name : "Unnamed Input";
            $delete_link = admin_url("admin.php?page=HitchStream_Cloudflare&delete_input=" . esc_attr($input->uid) . "&confirm_delete=yes");
            $status_class = isset($input->status_details) ? ($input->status_details === "connected" ? "status-connected" : "status-disconnected") : "";

            echo '<div class="live-input-container">';

            // Left Div
            echo '<div class="live-input-left">';
            echo "<strong>" .
                esc_html($input_name) .
                "</strong> | <span id='status-" .
                esc_attr($input->uid) .
                "' class='" .
                $status_class .
                "'>" .
                esc_html(isset($input->status_details) ? $input->status_details : "Status Unavailable") .
                "</span>";
            echo "<br><br><strong>For Streaming App :</strong><br>";
            if (isset($input->srt_details)) {
                $baseSRTUrl = "srt://live.hitchstream.com:778";
                $simplifiedSRTUrl = "live.hitchstream.com";
                $portSRT = "778";
                $passphrase = esc_html($input->srt_details->passphrase);
                $streamId = esc_html($input->srt_details->streamId);

                // Full SRT URL with all details
                $SRTLatency = 5000000;
                $SRTmaxbw = 3500000;
                $SRTsndbuf = 64777216;
                $SRTrcvbuf = 64777216;
                $fullSRTUrl = $baseSRTUrl . "?passphrase=" . $passphrase . "&streamid=" . $streamId . "&latency=" . $SRTLatency . "&maxbw=" . $SRTmaxbw . "&sndbuf=" . $SRTsndbuf . "&rcvbuf=" . $SRTrcvbuf;

                // Button for SRT URL
//                echo '<br><button class="copy-btn" data-srt-base-url="' . esc_attr($baseSRTUrl) . '">SRT URL</button>';

                // Button for Passphrase
//                echo '<button class="copy-btn" data-passphrase="' . esc_attr($passphrase) . '">SRT Passphrase</button>';

                // Button for StreamId
//                echo '<button class="copy-btn" data-streamid="' . esc_attr($streamId) . '">SRT StreamID</button>';

                // Button for copying the full SRT string
                //    echo '<button class="copy-btn" data-full-srt-url="' . esc_attr($fullSRTUrl) . '">SRT String</button><br>';
                echo '<button class="copy-btn generateSRTvmix" data-simplified-srt-url="' . $simplifiedSRTUrl . '" data-port-srt="' . $portSRT . '" data-passphrase="' . $passphrase . '" data-stream-id="' . $streamId . '">SRT (vMix)</button>';
                echo '<button class="copy-btn generateSRTobs" data-base-srt-url="' . htmlspecialchars($baseSRTUrl . "?passphrase=" . $passphrase . "&streamid=" . $streamId) . '">SRT (OBS)</button><br>';
            }

            if (isset($input->rtmp_details)) {
                $rtmpURL = "rtmps://live.hitchstream.com:443/live/";
                $rtmpKey = esc_html($input->rtmp_details->streamKey);
                echo '<button class="copy-btn copy-rtmp-url-btn" data-rtmp-url="' . esc_attr($rtmpURL) . '">RTMP URL</button>';
                echo '<button class="copy-btn copy-rtmp-key-btn" data-rtmp-key="' . esc_attr($rtmpKey) . '">RTMP Key</button>';
            }
            echo "<br><strong>For Customer Page:</strong>";
            if (isset($input->uid)) {
                echo '<br><button class="copy-btn copy-input-id-btn" data-input-id="' . esc_attr($input->uid) . '">Live Input ID</button>';
                echo '<br><button class="copy-btn copy-embed-code-btn" data-input-id="' . esc_attr($input->uid) . '">Embed Code</button>';
            }

            echo '<div class="stream-toggle-container">';
            echo '<span class="stream-toggle-title"><strong>Placeholder Stream: </strong></span>';
            echo '<label class="switch">';
            echo '<br><input type="checkbox" class="stream-toggle" id="streamToggle-' . esc_attr($input->uid) . '" data-rtmp-key="' . $rtmpKey . '">';
            echo '<span class="slider round"></span></label>';
            echo '</div>';

            echo '<br><a href="' . esc_url($delete_link) . '" onclick="return confirm(\'Are you sure you wish to delete ' . esc_js($input_name) . '?\')">DELETE STREAM</a>';
            echo "</div>"; // Close live-input-left
            // Middle Div 1 for Outputs
            echo '<div class="live-input-middle-1">';
            echo "<strong><br><br>New Social Stream:</strong>";
            echo '<form class="create-output-form" id="create-output-form">';
            echo '<input type="hidden" name="input_id" value="' . esc_attr($input->uid) . '">';
            echo '<input type="text" id="stream-key-input" name="stream_key" placeholder="Stream Key" required>';
            echo '<input type="text" id="stream-url-input" name="url" placeholder="URL" required>';
            echo '<input type="submit" class="copy-btn" value="Create Output">';
            echo "</form>";
            echo "<div><br><strong>Social Streams:</strong></div>";
            $outputs = HSCF_get_live_input_outputs($input->uid);
            if (is_array($outputs)) {
                foreach ($outputs as $output) {
                    echo "<div>";

                    // Check if URL contains "youtube" or "facebook"
                    if (strpos($output->url, "youtube") !== false) {
                        echo "YouTube ";
                    } elseif (strpos($output->url, "facebook") !== false) {
                        echo "Facebook ";
                    } else {
                        echo esc_html($output->url) . " ";
                    }

                    // Toggle switch
                    $checked = $output->enabled ? "checked" : "";
                    echo '<label class="switch"><input type="checkbox" class="output-toggle" data-output-id="' .
                        esc_attr($output->uid) .
                        '" data-input-id="' .
                        esc_attr($input->uid) .
                        '" ' .
                        $checked .
                        '><span class="slider round"></span></label> ';

                    // Delete link
                    echo '<a href="#" class="delete-output-link" data-output-id="' . esc_attr($output->uid) . '" data-input-id="' . esc_attr($input->uid) . '" title="Delete Output" style="position: relative;">';
                    echo '<img src="https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png" width="18px" height="18px" alt="Delete Output" style="vertical-align: middle;">';
                    echo '</a>';

                    echo "</div>";
                }
            } else {
                echo "<p>" . esc_html($outputs) . "</p>";
            }

            echo "</div>"; // Close live-input-middle-1

            // Middle Div 2
            echo '<div class="live-input-middle-2">';

            echo "<br><br><strong>Recordings (" . esc_html($input_name) . "):</strong><br>";
            $filteredVideos = HSCF_get_videos_by_stream_name($input_name);
            if (is_array($filteredVideos) && !empty($filteredVideos)) {
                foreach ($filteredVideos as $video) {
                    $nameParts = explode(" ", $video["meta"]["name"]);
                    $dateString = implode(" ", array_slice($nameParts, -5)); // Extract date part
                    try {
                        $date = new DateTime($dateString, new DateTimeZone("UTC"));
                        $date->setTimezone(new DateTimeZone("MST"));
                        $formattedDate = $date->format("M d Y h:iA T");
                    } catch (Exception $e) {
                        $formattedDate = $dateString; // Fallback to original if parsing fails
                    }

                    $videoUrl = "https://customer-juu1r5es4cbffqjf.cloudflarestream.com/" . esc_attr($video["uid"]) . "/watch";
                    $iconUrl = isset($video["mp4_download_url"]) ? "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png" : "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png";
                    $downloadLink = isset($video["mp4_download_url"]) ? $video["mp4_download_url"] : "#";
                    $deleteIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png";
                    $embedIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/embedcode.png"; // Embed icon URL
                    // Determine the title for the download icon
                    $downloadTitle = isset($video["mp4_download_url"]) ? "Download" : "Create download";

                    echo "<div style='display: flex; align-items: center;'>";
                    echo '<a href="' . $videoUrl . '" target="_blank">' . esc_html($formattedDate) . "</a>&nbsp;";
//                    GPT LOOK HERE DOWNLOAD BUTTON 1
                    echo '<a href="' . $downloadLink . '" class="create-download-link" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $iconUrl . '" title="' . $downloadTitle . '">';
                    echo "</a>";
                    // Embed Icon
                    echo '<a href="#" class="generate-videoid" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $embedIconUrl . '" title="Get Video ID">';
                    echo "</a>";
                    echo '<a href="#" class="delete-video-link" data-video-id="' . esc_attr($video["uid"]) . '">'; // No margin for the last icon
                    echo '<img src="' . $deleteIconUrl . '" title="Delete Video">';
                    echo "</a>";
                    echo "</div>";
                }
            } else {
                echo "<p>No recorded videos for this stream.</p>";
            }
            echo "</div>"; // Close live-input-middle-2

            // Right Div
            echo '<div class="live-input-right">';
            echo '<div style="position: relative;">';
            echo '<iframe src="https://hitchstream.com/player?live=true&inputId=' .
                esc_attr($input->uid) .
                '" style="border: none; width: 100%; aspect-ratio: 16 / 9;" 
                allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" 
                allowfullscreen>
                </iframe>';
            echo "</div>";
            echo "</div>"; // Close live-input-right
            echo "</div>"; // Close live-input-container
        }
    } else {
        echo "<p>" . esc_html($live_inputs) . "</p>";
        echo '<script src="https://unpkg.com/tus-js-client@2"></script>';
    }?>
    <div id="Videos Panel">
        <div class="form-container">
            <h2>Upload a Video (Max file size: <?php echo get_formatted_max_upload_size(); ?>, Max upload time: <?php echo get_max_execution_time(); ?>)</h2>
            <form id="video-upload-form" method="post" enctype="multipart/form-data">
                <input type="file" name="video_file" class="copy-btn" required>
                <input type="submit" name="upload_video" value="Upload Video" class="copy-btn">
                <img src="https://hitchstream.com/wp-content/uploads/2024/01/loading_icon2.gif" style="display: none; width: 10px; height: 10px;" id="loading-icon">
            </form>
        </div>
        <h2>Uploaded Videos</h2>
        <div id="videos-panel">
            <!-- ID for the container to apply flex styles -->
            <?php
$videos = HSCF_list_cloudflare_videos();
if (is_array($videos)) {
    foreach ($videos as $video) {
        
        $iconUrl = isset($video["mp4_download_url"]) ? "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png" : "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png";
        $downloadLink = isset($video["mp4_download_url"]) ? $video["mp4_download_url"] : "#";
        
        $deleteIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png";
        $embedIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/embedcode.png"; // Embed icon URL
        // Determine the title for the download icon
        $downloadTitle = isset($video["mp4_download_url"]) ? "Download" : "Create download";

        echo '<div class="video-container">';
        echo '<p class="video-name">' . esc_html($video['meta']['name']) . '</p>';
        echo '<iframe src="https://hitchstream.com/player?live=false&autoplay=false&inputId=' .
            esc_attr($video['uid']) .
            '" style="border: none; width: 100%; aspect-ratio: 16 / 9;" 
            allow="fullscreen; accelerometer; gyroscope; encrypted-media; picture-in-picture" 
            allowfullscreen>
            </iframe>';

        
        echo '<div class="video-buttons">';
//        GPT LOOK HERE DOWNLOAD BUTTON 2
                    echo '<a href="' . $downloadLink . '" class="create-download-link" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $iconUrl . '" title="' . $downloadTitle . '">';
                    echo "</a>";
                    // Embed Icon
                    echo '<a href="#" class="generate-embed-link" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $embedIconUrl . '" title="Get Embed Code">';
                    echo "</a>";
                    echo '<a href="#" class="delete-video-link" data-video-id="' . esc_attr($video["uid"]) . '">'; // No margin for the last icon
                    echo '<img src="' . $deleteIconUrl . '" title="Delete Video">';
                    echo "</a>";
        echo '</div>';
        echo '</div>';
    }
} else {
    echo '<p>' . esc_html($videos) . '</p>';
}


?>


        </div>
    </div>
    <?php
}
?>
