<?php
/**
 * SettingsPage — admin settings registration and page rendering.
 *
 * Extracted from HitchStream-Cloudflare.php in B4. WordPress function-style
 * add_action hooks are registered via closures in Plugin.php.
 */

namespace HS\Admin;

use HS\Config;
use HS\ConfigError;

class SettingsPage {

    /** Register all settings sections and fields. */
    public static function register(): void {
        add_action('admin_init', [__CLASS__, 'registerCloudflareSettings']);
        add_action('admin_init', [__CLASS__, 'registerWebhookSettings']);
        add_action('admin_init', [__CLASS__, 'registerPlayerSettings']);
        add_action('admin_init', [__CLASS__, 'registerStreamerSettings']);
        add_action('admin_init', [__CLASS__, 'registerAlertSettings']);
    }

    /** Register admin menu. */
    public static function registerMenu(): void {
        add_menu_page('CloudFlare Setup for HitchStream', 'HS CloudFlare', 'manage_options', 'HitchStream_Cloudflare', [__CLASS__, 'renderAdminUI']);
    }

    /** Show admin notice when webhook secret is not configured. */
    public static function showWebhookSecretNotice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $secret = get_option('HSCF_webhook_secret', '');
        if (empty($secret)) {
            echo '<div class="notice notice-error" style="border-left-color:#d63638;"><p><strong style="color:#d63638">&#9888; HitchStream:</strong> <strong style="color:#d63638">Webhook secret is NOT configured!</strong> All incoming webhooks are being rejected. Your weddings are not receiving stream state updates. <a href="' . admin_url('admin.php?page=HitchStream_Cloudflare') . '">Configure it here</a>.</p></div>';
        }
    }

    // ── Settings registration ──────────────────────────────────────

    public static function registerCloudflareSettings(): void {
        register_setting('HSCF_settings', 'HSCF_cloudflare_email');
        register_setting('HSCF_settings', 'HSCF_cloudflare_api_key');
        register_setting('HSCF_settings', 'HSCF_cloudflare_account_id');
        register_setting('HSCF_settings', 'HSCF_cloudflare_api_token');

        add_settings_section('HSCF_cloudflare_settings_section', 'CloudFlare API Settings', '__return_empty_string', 'HitchStream_Cloudflare');

        add_settings_field('HSCF_cloudflare_email_field', 'CloudFlare Email', [__CLASS__, 'cf_email_field'], 'HitchStream_Cloudflare', 'HSCF_cloudflare_settings_section');
        add_settings_field('HSCF_cloudflare_api_key_field', 'CloudFlare API Key', [__CLASS__, 'cf_api_key_field'], 'HitchStream_Cloudflare', 'HSCF_cloudflare_settings_section');
        add_settings_field('HSCF_cloudflare_account_id_field', 'CloudFlare Account ID', [__CLASS__, 'cf_account_id_field'], 'HitchStream_Cloudflare', 'HSCF_cloudflare_settings_section');
        add_settings_field('HSCF_cloudflare_api_token_field', 'CloudFlare API Token (Bearer)', [__CLASS__, 'cf_api_token_field'], 'HitchStream_Cloudflare', 'HSCF_cloudflare_settings_section');
        add_settings_field('hscf_cf_test_field', null, [__CLASS__, 'cf_test_field'], 'HitchStream_Cloudflare', 'HSCF_cloudflare_settings_section');
    }

    public static function registerWebhookSettings(): void {
        register_setting('HSCF_webhook_settings', 'HSCF_webhook_url');
        register_setting('HSCF_webhook_settings', 'HSCF_webhook_secret');

        add_settings_section('HSCF_webhook_settings_section', 'Webhook Settings', [__CLASS__, 'webhook_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_webhook_url_field', 'Webhook Callback URL', [__CLASS__, 'webhook_url_field'], 'HitchStream_Cloudflare', 'HSCF_webhook_settings_section');
        add_settings_field('HSCF_webhook_secret_field', 'Webhook Secret (for Cloudflare)', [__CLASS__, 'webhook_secret_field'], 'HitchStream_Cloudflare', 'HSCF_webhook_settings_section');
    }

    public static function registerPlayerSettings(): void {
        register_setting('HSCF_player_settings', 'HSCF_customer_id');
        register_setting('HSCF_player_settings', 'HSCF_poster_initial');
        register_setting('HSCF_player_settings', 'HSCF_poster_idle');
        register_setting('HSCF_player_settings', 'HSCF_poster_fatal');

        add_settings_section('HSCF_player_settings_section', 'Player Settings', [__CLASS__, 'player_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_customer_id_field', 'Cloudflare Customer ID', [__CLASS__, 'customer_id_field'], 'HitchStream_Cloudflare', 'HSCF_player_settings_section');
        add_settings_field('HSCF_poster_initial_field', 'Poster Initial URL', [__CLASS__, 'poster_initial_field'], 'HitchStream_Cloudflare', 'HSCF_player_settings_section');
        add_settings_field('HSCF_poster_idle_field', 'Poster Idle URL', [__CLASS__, 'poster_idle_field'], 'HitchStream_Cloudflare', 'HSCF_player_settings_section');
        add_settings_field('HSCF_poster_fatal_field', 'Poster Fatal URL', [__CLASS__, 'poster_fatal_field'], 'HitchStream_Cloudflare', 'HSCF_player_settings_section');
    }

    public static function registerStreamerSettings(): void {
        register_setting('HSCF_streamer_settings', 'HSCF_streamer_api_url');
        register_setting('HSCF_streamer_settings', 'HSCF_streamer_api_key');

        add_settings_section('HSCF_streamer_settings_section', 'Streamer Service', [__CLASS__, 'streamer_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_streamer_api_url_field', 'Streamer API URL', [__CLASS__, 'streamer_api_url_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
        add_settings_field('HSCF_streamer_api_key_field', 'Streamer API Key', [__CLASS__, 'streamer_api_key_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
        add_settings_field('hscf_streamer_test_field', null, [__CLASS__, 'streamer_test_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
    }

    public static function registerAlertSettings(): void {
        register_setting('HSCF_alert_settings', 'HSCF_alert_email');
        register_setting('HSCF_alert_settings', 'HSCF_alert_codes');

        add_settings_section('HSCF_alert_settings_section', 'Alerts', [__CLASS__, 'alert_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_alert_email_field', 'Alert Email', [__CLASS__, 'alert_email_field'], 'HitchStream_Cloudflare', 'HSCF_alert_settings_section');
        add_settings_field('HSCF_alert_codes_field', 'Alert Codes', [__CLASS__, 'alert_codes_field'], 'HitchStream_Cloudflare', 'HSCF_alert_settings_section');
    }

    // ── Field callbacks ────────────────────────────────────────────

    public static function cf_email_field(): void {
        $val = get_option('HSCF_cloudflare_email', '');
        echo "<input type='email' name='HSCF_cloudflare_email' value='" . esc_attr($val) . "' />";
    }

    public static function cf_api_key_field(): void {
        $val = get_option('HSCF_cloudflare_api_key', '');
        echo "<input type='password' name='HSCF_cloudflare_api_key' value='" . esc_attr($val) . "' />";
    }

    public static function cf_account_id_field(): void {
        $val = get_option('HSCF_cloudflare_account_id', '');
        echo "<input type='text' name='HSCF_cloudflare_account_id' value='" . esc_attr($val) . "' />";
    }

    public static function cf_api_token_field(): void {
        $val = get_option('HSCF_cloudflare_api_token', '');
        echo '<input type="password" name="HSCF_cloudflare_api_token" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="Optional — Bearer token (preferred over API key)" />';
        echo '<p class="description"><strong>Preferred auth method.</strong> When set, CloudflareClient uses Bearer token auth. Falls back to email+API-key with deprecation notice when unset.</p>';
    }

    public static function cf_test_field(): void {
        echo '<p><button type="button" class="button" id="hscf-cf-test-btn">Test Cloudflare Connection</button> <span id="hscf-cf-test-result" class="hscf-test-result"></span></p>';
        echo self::testButtonScript('hscf-cf-test-btn', 'hscf-cf-test-result', 'hscf_test_connection', 'Test Cloudflare Connection');
    }

    public static function webhook_section_desc(): void {
        echo '<p>Configure webhook notifications from Cloudflare Stream. After registering, the secret returned by Cloudflare will be stored in the Secret field above for signature verification.</p>';
    }

    public static function webhook_url_field(): void {
        $val = get_option('HSCF_webhook_url', '');
        if (!$val) {
            $val = rtrim(home_url('/'), '/') . '/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php';
        }
        echo '<input type="url" name="HSCF_webhook_url" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" />';
    }

    public static function webhook_secret_field(): void {
        $val = get_option('HSCF_webhook_secret', '');
        echo '<input type="text" name="HSCF_webhook_secret" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="Set by Cloudflare on Register" disabled />';
        echo '<p class="description">Cloudflare generates its own secret when you click "Register Webhook". The stored secret is overwritten by Cloudflare\'s value.</p>';
        echo '<p><button type="button" class="button" id="hscf-webhook-rotate-btn">Rotate Secret</button> <span id="hscf-webhook-rotate-result" class="hscf-test-result"></span></p>';
        echo self::testButtonScript('hscf-webhook-rotate-btn', 'hscf-webhook-rotate-result', 'hscf_rotate_webhook', 'Rotate Secret');
    }

    public static function player_section_desc(): void {
        echo '<p>Configurable defaults for the HSPlayer video player. URL params on the player page override these values.</p>';
    }

    public static function customer_id_field(): void {
        $val = get_option('HSCF_customer_id', '');
        echo '<input type="text" name="HSCF_customer_id" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" />';
    }

    public static function poster_initial_field(): void {
        $val = get_option('HSCF_poster_initial', '');
        echo '<input type="url" name="HSCF_poster_initial" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
    }

    public static function poster_idle_field(): void {
        $val = get_option('HSCF_poster_idle', '');
        echo '<input type="url" name="HSCF_poster_idle" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
    }

    public static function poster_fatal_field(): void {
        $val = get_option('HSCF_poster_fatal', '');
        echo '<input type="url" name="HSCF_poster_fatal" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
    }

    public static function streamer_section_desc(): void {
        echo '<p>Configuration for the internal placeholder-stream service. The API key is required for all placeholder-stream operations.</p>';
    }

    public static function streamer_api_url_field(): void {
        $val = get_option('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com');
        echo '<input type="url" name="HSCF_streamer_api_url" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="https://..." />';
    }

    public static function streamer_api_key_field(): void {
        $val = get_option('HSCF_streamer_api_key', '');
        $masked = $val ? str_repeat('*', 10) . substr($val, -4) : '';
        echo '<input type="password" name="HSCF_streamer_api_key" value="' . esc_attr($masked) . '" style="width:100%;max-width:600px;" placeholder="Leave blank to keep current key" data-original-value="' . esc_attr($val) . '" />';
        if ($val) {
            echo ' <em>(set — leave blank to keep unchanged)</em>';
        }
    }

    public static function streamer_test_field(): void {
        echo '<p><button type="button" class="button" id="hscf-streamer-test-btn">Test Streamer Service</button> <span id="hscf-streamer-test-result" class="hscf-test-result"></span></p>';
        echo self::testButtonScript('hscf-streamer-test-btn', 'hscf-streamer-test-result', 'hscf_test_streamer', 'Test Streamer Service');
    }

    public static function alert_section_desc(): void {
        echo '<p>Email alerts are sent when critical webhook error_codes are received during a live stream.</p>';
    }

    public static function alert_email_field(): void {
        $val = get_option('HSCF_alert_email', '');
        echo '<input type="email" name="HSCF_alert_email" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="admin@example.com" />';
        echo '<p class="description">Leave blank to disable critical error email alerts.</p>';
    }

    public static function alert_codes_field(): void {
        $val = get_option('HSCF_alert_codes', 'ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION');
        echo '<input type="text" name="HSCF_alert_codes" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="Comma-separated error codes" />';
        echo '<p class="description">Default: <code>ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION</code>. These codes are also reflected in the player error messages.</p>';
    }

    // ── Admin notice ───────────────────────────────────────────────

    public static function adminNotices(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $secret = get_option('HSCF_webhook_secret', '');
        if (empty($secret)) {
            echo '<div class="notice notice-error" style="border-left-color:#d63638;"><p><strong style="color:#d63638">&#9888; HitchStream:</strong> <strong style="color:#d63638">Webhook secret is NOT configured!</strong> All incoming webhooks are being rejected. Your weddings are not receiving stream state updates. <a href="' . admin_url('admin.php?page=HitchStream_Cloudflare') . '">Configure it here</a>.</p></div>';
        }
    }

    // ── Admin page rendering ───────────────────────────────────────
    // Dead-code removed (post-audit cleanup): renderAdmin() and
    // renderStreams() were a 280-line never-called duplicate of
    // renderAdminUI() / its inline streams view. Only renderAdminUI
    // is wired through HSCF_Admin() in the plugin bootstrap.


    // ── Internal helper ────────────────────────────────────────────

    private static function testButtonScript(string $btnId, string $resultId, string $ajaxAction, string $btnText): string {
        $scriptId = sanitize_key(str_replace('-', '_', $ajaxAction));
        return '<script>
    (function($) {
        $(document).on("click", "#' . $btnId . '", function() {
            var $btn = $(this);
            var $result = $("#' . $resultId . '");
            $btn.prop("disabled", true).text("Testing...");
            $result.text("");
            $.ajax({
                url: hscf_ajax.ajax_url,
                type: "POST",
                data: {
                    action: "' . $ajaxAction . '",
                    _wpnonce: hscf_ajax.nonce
                },
                success: function(resp) {
                    $btn.prop("disabled", false).text("' . esc_js($btnText) . '");
                    if (resp.success) {
                        $result.html("<span style=\\"color:green\\">&#10004; " + (resp.data || "' . esc_js($btnText . ' OK') . '") + "</span>");
                    } else {
                        $result.html("<span style=\\"color:red\\">&#10008; " + (resp.data || "' . esc_js($btnText . ' failed') . '") + "</span>");
                    }
                },
                error: function() {
                    $btn.prop("disabled", false).text("' . esc_js($btnText) . '");
                    $result.html("<span style=\\"color:red\\">&#10008; Request failed</span>");
                }
            });
        });
    })(jQuery);
    </script>';
    }

    // ── Admin page HTML ──────────────────────────────────────────

    /** Render the full admin page. Extracted from old HSCF_Admin(). */
    public static function renderAdminUI(): void {
        $live_inputs = self::getLiveInputs();
        $videos = self::listCloudflareVideos();

        // Process form submissions.
        if (isset($_GET['delete_input']) && isset($_GET['confirm_delete']) && $_GET['confirm_delete'] === 'yes') {
            $input_id = sanitize_text_field($_GET['delete_input']);
            $svc = new \HS\Services\LiveInputService();
            $svc->delete($input_id);
        }
        if (isset($_POST['create_stream']) && !empty($_POST['stream_name'])) {
            $svc = new \HS\Services\LiveInputService();
            $svc->create(sanitize_text_field($_POST['stream_name']));
        }

        $max_upload = self::getFormattedMaxUploadSize();
        $max_exec = self::getMaxExecutionTime();
        ?>
<div class="wrap">
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
                settings_fields('HSCF_settings');
                do_settings_sections('HitchStream_Cloudflare');
                submit_button();
                ?>
            </form>
        </div>
    </div>

    <h2>Webhook Settings</h2>
    <div class="webhook-form-container" style="margin-bottom:30px;">
        <form method="post" action="options.php" id="webhook-settings-form">
            <?php settings_fields('HSCF_webhook_settings'); do_settings_sections('HitchStream_Cloudflare'); ?>
            <div style="margin-top:15px;">
                <input type="button" id="btn-register-webhook" value="Register Webhook with Cloudflare" class="copy-btn" style="background:#06b6d4;border-color:#06b6d4;margin-right:10px;">
                <input type="button" id="btn-delete-webhook" value="Delete Webhook" class="copy-btn" style="background:#ef4444;border-color:#ef4444;">
                <input type="button" id="btn-fetch-webhook-status" value="Fetch Status" class="copy-btn" style="background:#6b7280;border-color:#6b7280;">
            </div>
        </form>
        <div id="webhook-status" style="margin-top:15px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;display:none;"></div>
    </div>

    <h2>Player Settings</h2>
    <div style="margin-bottom:30px;">
        <form method="post" action="options.php">
            <?php settings_fields('HSCF_player_settings'); do_settings_sections('HitchStream_Cloudflare'); submit_button(); ?>
        </form>
    </div>

    <h2>Streamer Service Settings</h2>
    <div style="margin-bottom:30px;">
        <form method="post" action="options.php">
            <?php settings_fields('HSCF_streamer_settings'); do_settings_sections('HitchStream_Cloudflare'); submit_button(); ?>
        </form>
    </div>

    <h2>Alerts Settings</h2>
    <div style="margin-bottom:30px;">
        <form method="post" action="options.php">
            <?php settings_fields('HSCF_alert_settings'); do_settings_sections('HitchStream_Cloudflare'); submit_button(); ?>
        </form>
    </div>

    <h2>Current Streams</h2>
    <style>
        .FileSelector { width: 250px; }
        .cfmodal { display: none; position: fixed; z-index: 1; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgb(0,0,0); background-color: rgba(0,0,0,0.4); }
        .cfmodalbox { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 360px; border-radius: 10px; }
        .cfmodal-content { overflow-wrap: break-word; padding-top: 10px; }
        .modalcontentbox { background-color: #e9e9e9; border: 1px solid #999999; color: black; padding: 5px; border-radius: 3px; }
        #progress-container { width: 100%; background: #eee; display: none; }
        #progress-bar { height: 20px; width: 0%; background-color: #2271b1; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close:hover, .close:focus { color: black; text-decoration: none; cursor: pointer; }
        .top-container { display: flex; justify-content: space-between; }
        .form-container { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; }
        .middle-img-container { flex: 1; display: flex; justify-content: center; align-items: flex-end; }
        .middleitem { padding: 0px 10px 0px 10px; }
        .form-container.right { align-items: flex-end; }
        .form-table th { padding: 5px; vertical-align: middle; }
        .form-table td { padding: 5px; vertical-align: middle; }
        .live-input-container { display: flex; margin-bottom: 20px; background-color: #e5e5e5; }
        .live-input-left, .live-input-middle-1, .live-input-middle-2, .live-input-right { flex: 1; }
        .status-connected { color: green; }
        .status-disconnected { color: red; }
        .copy-btn { background: #2271b1; border-color: #2271b1; color: #fff; text-decoration: none; text-shadow: none; font-size: 13px; line-height: 2.15384615; min-height: 30px; margin: 5px; padding: 0 10px; cursor: pointer; border-width: 1px; border-style: solid; -webkit-appearance: none; border-radius: 3px; white-space: nowrap; box-sizing: border-box; }
        .switch { position: relative; display: inline-block; width: 30px; height: 17px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; -webkit-transition: .4s; transition: .4s; }
        .slider:before { position: absolute; content: ""; height: 17px; width: 16px; left: 0px; bottom: 0px; background-color: white; -webkit-transition: .4s; transition: .4s; }
        input:checked+.slider { background-color: #2271b1; }
        input:focus+.slider { box-shadow: 0 0 1px #2271b1; }
        input:checked+.slider:before { -webkit-transform: translateX(14px); -ms-transform: translateX(14px); transform: translateX(14px); }
        .slider.round { border-radius: 34px; }
        .slider.round:before { border-radius: 50%; }
        .video-container { width: 200px; height: auto; margin: 5px; flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; overflow: hidden; }
        .video-container:nth-child(odd) { background-color: #e5e5e5; }
        .video-name { height: auto; min-height: 36px; margin: 5px 0; text-align: center; word-wrap: break-word; line-height: 18px; }
        #videos-panel { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: center; }
        .video-buttons { display: flex; justify-content: center; margin-top: 10px; }
        .video-buttons button { width: 24px; height: 24px; background-size: cover; border: none; cursor: pointer; margin: 0 5px; }
    </style>
    <?php if (is_array($live_inputs)): foreach ($live_inputs as $input):
        $input_name = isset($input->meta) && isset($input->meta->name) ? $input->meta->name : 'Unnamed Input';
        $delete_link = admin_url('admin.php?page=HitchStream_Cloudflare&delete_input=' . esc_attr($input->uid) . '&confirm_delete=yes');
        $status_class = isset($input->status_details) ? ($input->status_details === 'connected' ? 'status-connected' : 'status-disconnected') : '';
        $rtmpKey = isset($input->rtmp_details) ? ($input->rtmp_details->streamKey ?? '') : '';
        ?>
    <div class="live-input-container">
        <div class="live-input-left">
            <strong><?= esc_html($input_name) ?></strong> | <span class="<?= esc_attr($status_class) ?>"><?= esc_html($input->status_details ?? 'Status Unavailable') ?></span>
            <br><br><strong>For Streaming App :</strong><br>
            <?php if (isset($input->srt_details)):
                $baseSRTUrl = 'srt://live.hitchstream.com:778';
                $passphrase = esc_html($input->srt_details->passphrase);
                $streamId = esc_html($input->srt_details->streamId);
                ?>
                <button class="copy-btn generateSRTvmix" data-simplified-srt-url="live.hitchstream.com" data-port-srt="778" data-passphrase="<?= $passphrase ?>" data-stream-id="<?= $streamId ?>">SRT (vMix)</button>
                <button class="copy-btn generateSRTobs" data-base-srt-url="<?= htmlspecialchars($baseSRTUrl . '?passphrase=' . $passphrase . '&streamid=' . $streamId) ?>">SRT (OBS)</button><br>
            <?php endif; ?>
            <?php if (isset($input->rtmp_details)):
                $rtmpURL = 'rtmps://live.hitchstream.com:443/live/';
                $rtmpKey = esc_html($input->rtmp_details->streamKey);
                ?>
                <button class="copy-btn copy-rtmp-url-btn" data-rtmp-url="<?= esc_attr($rtmpURL) ?>">RTMP URL</button>
                <button class="copy-btn copy-rtmp-key-btn" data-rtmp-key="<?= esc_attr($rtmpKey) ?>">RTMP Key</button>
            <?php endif; ?>
            <br><strong>For Customer Page:</strong>
            <?php if (isset($input->uid)): ?>
                <br><button class="copy-btn copy-input-id-btn" data-input-id="<?= esc_attr($input->uid) ?>">Live Input ID</button>
                <br><button class="copy-btn copy-embed-code-btn" data-input-id="<?= esc_attr($input->uid) ?>">Embed Code</button>
            <?php endif; ?>
            <div class="stream-toggle-container">
                <span class="stream-toggle-title"><strong>Placeholder Stream: </strong></span>
                <label class="switch">
                    <br><input type="checkbox" class="stream-toggle" id="streamToggle-<?= esc_attr($input->uid) ?>" data-rtmp-key="<?= esc_attr($rtmpKey) ?>">
                    <span class="slider round"></span></label>
                </div>
            <br><a href="<?= esc_url($delete_link) ?>" onclick="return confirm('Are you sure you wish to delete <?= esc_js($input_name) ?>?')">DELETE STREAM</a>
        </div>
        <div class="live-input-middle-1">
            <strong><br><br>New Social Stream:</strong>
            <form class="create-output-form" id="create-output-form">
                <input type="hidden" name="input_id" value="<?= esc_attr($input->uid) ?>">
                <input type="text" id="stream-key-input" name="stream_key" placeholder="Stream Key" required>
                <input type="text" id="stream-url-input" name="url" placeholder="URL" required>
                <input type="submit" class="copy-btn" value="Create Output">
            </form>
            <div><br><strong>Social Streams:</strong></div>
            <?php $outputs = self::getLiveInputOutputs($input->uid);
            if (is_array($outputs)): foreach ($outputs as $output): ?>
                <div>
                    <?php if (strpos($output->url, 'youtube') !== false) echo 'YouTube ';
                    elseif (strpos($output->url, 'facebook') !== false) echo 'Facebook ';
                    else echo esc_html($output->url); ?>
                    <label class="switch"><input type="checkbox" class="output-toggle" data-output-id="<?= esc_attr($output->uid) ?>" data-input-id="<?= esc_attr($input->uid) ?>" <?= $output->enabled ? 'checked' : '' ?>><span class="slider round"></span></label>
                    <a href="#" class="delete-output-link" data-output-id="<?= esc_attr($output->uid) ?>" data-input-id="<?= esc_attr($input->uid) ?>" title="Delete Output" style="position: relative;"><img src="https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png" width="18px" height="18px" alt="Delete Output" style="vertical-align: middle;"></a>
                </div>
            <?php endforeach; else: echo '<p>' . esc_html($outputs ?? 'No outputs') . '</p>'; endif; ?>
        </div>
        <div class="live-input-middle-2">
            <br><br><strong>Recordings (<?= esc_html($input_name) ?>):</strong><br>
            <?php $filteredVideos = self::getVideosByStreamName($input_name);
            if (is_array($filteredVideos) && !empty($filteredVideos)): foreach ($filteredVideos as $video):
                $nameParts = explode(' ', $video['meta']['name']);
                $dateString = implode(' ', array_slice($nameParts, -5));
                try {
                    $date = new DateTime($dateString, new DateTimeZone('UTC'));
                    $date->setTimezone(new DateTimeZone('MST'));
                    $formattedDate = $date->format('M d Y h:iA T');
                } catch (Exception $e) { $formattedDate = $dateString; }
                $cf_customer = get_option('HSCF_customer_id', '');
                $videoUrl = $cf_customer
                    ? 'https://customer-' . $cf_customer . '.cloudflarestream.com/' . esc_attr($video['uid']) . '/watch'
                    : '';
                $iconUrl = isset($video['mp4_download_url']) ? 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png' : 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png';
                $downloadLink = isset($video['mp4_download_url']) ? $video['mp4_download_url'] : '#';
                $deleteIconUrl = 'https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png';
                $embedIconUrl = 'https://hitchstream.com/wp-content/uploads/2024/01/embedcode.png';
                $downloadTitle = isset($video['mp4_download_url']) ? 'Download' : 'Create download';
                ?>
                <div style="display: flex; align-items: center;">
                    <a href="<?= $videoUrl ?>" target="_blank"><?= esc_html($formattedDate) ?></a>&nbsp;
                    <a href="<?= $downloadLink ?>" class="create-download-link" data-video-id="<?= esc_attr($video['uid']) ?>" style="margin-right: 10px;"><img src="<?= $iconUrl ?>" title="<?= $downloadTitle ?>"></a>
                    <a href="#" class="generate-videoid" data-video-id="<?= esc_attr($video['uid']) ?>" style="margin-right: 10px;"><img src="<?= $embedIconUrl ?>" title="Get Video ID"></a>
                    <a href="#" class="delete-video-link" data-video-id="<?= esc_attr($video['uid']) ?>"><img src="<?= $deleteIconUrl ?>" title="Delete Video"></a>
                </div>
            <?php endforeach; else: echo "<p>No recorded videos for this stream.</p>"; endif; ?>
        </div>
        <div class="live-input-right">
            <div style="position: relative;">
                <iframe src="https://hitchstream.com/player?live=true&inputId=<?= esc_attr($input->uid) ?>" style="border: none; width: 100%; aspect-ratio: 16 / 9;" allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
            </div>
        </div>
    </div>
    <?php endforeach; else: echo '<p>' . esc_html($live_inputs ?? 'No inputs available') . '</p>'; endif; ?>

    <div id="Videos Panel">
        <div class="form-container">
            <h2>Upload a Video (Max file size: <?= $max_upload ?>, Max upload time: <?= $max_exec ?>)</h2>
            <form id="video-upload-form" method="post" enctype="multipart/form-data">
                <input type="file" name="video_file" class="copy-btn" required>
                <input type="submit" name="upload_video" value="Upload Video" class="copy-btn">
                <img src="https://hitchstream.com/wp-content/uploads/2024/01/loading_icon2.gif" style="display: none; width: 10px; height: 10px;" id="loading-icon">
            </form>
        </div>
        <h2>Uploaded Videos</h2>
        <div id="videos-panel">
        <?php if (is_array($videos)): foreach ($videos as $video):
            $iconUrl = isset($video['mp4_download_url']) ? 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png' : 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png';
            $downloadLink = isset($video['mp4_download_url']) ? $video['mp4_download_url'] : '#';
            $deleteIconUrl = 'https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png';
            $embedIconUrl = 'https://hitchstream.com/wp-content/uploads/2024/01/embedcode.png';
            $downloadTitle = isset($video['mp4_download_url']) ? 'Download' : 'Create download';
            ?>
            <div class="video-container">
                <p class="video-name"><?= esc_html($video['meta']['name'] ?? 'Untitled') ?></p>
                <iframe src="https://hitchstream.com/player?live=false&autoplay=false&inputId=<?= esc_attr($video['uid']) ?>" style="border: none; width: 100%; aspect-ratio: 16 / 9;" allow="fullscreen; accelerometer; gyroscope; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                <div class="video-buttons">
                    <a href="<?= $downloadLink ?>" class="create-download-link" data-video-id="<?= esc_attr($video['uid']) ?>" style="margin-right: 10px;"><img src="<?= $iconUrl ?>" title="<?= $downloadTitle ?>"></a>
                    <a href="#" class="generate-embed-link" data-video-id="<?= esc_attr($video['uid']) ?>" style="margin-right: 10px;"><img src="<?= $embedIconUrl ?>" title="Get Embed Code"></a>
                    <a href="#" class="delete-video-link" data-video-id="<?= esc_attr($video['uid']) ?>"><img src="<?= $deleteIconUrl ?>" title="Delete Video"></a>
                </div>
            </div>
        <?php endforeach; else: echo '<p>' . esc_html($videos ?? 'No videos') . '</p>'; endif; ?>
        </div>
    </div>
    <?php
    }

    // ── Helper methods ─────────────────────────────────────────

    private static function getLiveInputs(): array|string {
        $svc = new \HS\Services\LiveInputService();
        $inputs = $svc->listWithDetails();
        return is_array($inputs) ? $inputs : (string) $inputs;
    }

    private static function listCloudflareVideos(): array {
        $svc = new \HS\Services\LiveInputService();
        $inputs = $svc->listWithDetails();
        return is_array($inputs) ? $inputs : [];
    }

    private static function getLiveInputOutputs(string $input_id): array|string {
        $svc = new \HS\Services\LiveInputService();
        return $svc->getOutputs($input_id);
    }

    private static function getVideosByStreamName(string $stream_name): array {
        $svc = new \HS\Services\RecordingsService();
        return $svc->findByStreamName($stream_name);
    }

    private static function getFormattedMaxUploadSize(): string {
        $max_upload = ini_get('upload_max_filesize');
        $max_post = ini_get('post_max_size');
        $bytes = min(self::convertPHPSizeToBytes($max_upload), self::convertPHPSizeToBytes($max_post));
        return self::formatSizeUnits($bytes);
    }

    private static function getMaxExecutionTime(): string {
        $max_execution_time = ini_get('max_execution_time');
        if ($max_execution_time > 60) {
            $minutes = floor($max_execution_time / 60);
            $seconds = $max_execution_time % 60;
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ($seconds > 0 ? ' and ' . $seconds . ' seconds' : '');
        }
        return $max_execution_time . ' seconds';
    }

    private static function convertPHPSizeToBytes($size): int {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $size = preg_replace('/[^0-9\.]/', '', $size);
        if ($unit) {
            return (int) round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }
        return (int) round($size);
    }

    private static function formatSizeUnits($bytes): string {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        if ($bytes > 1) return $bytes . ' bytes';
        if ($bytes == 1) return $bytes . ' byte';
        return '0 bytes';
    }
}
