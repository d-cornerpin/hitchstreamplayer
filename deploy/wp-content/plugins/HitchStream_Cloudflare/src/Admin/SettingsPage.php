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

    /**
     * Catalog of alertable events: key => [label, description].
     * The keys are the contract shared with the webhook receiver
     * (celebration-child/endpoints/cf-live-webhook.php) — keep them in sync.
     * 'default' marks the two critical events that are pre-checked.
     */
    public const ALERT_EVENTS = [
        'storage_full'            => ['Storage Full',            'Cloudflare Stream storage is full — recordings and streams may fail.', true],
        'no_subscription'         => ['No Cloudflare Subscription', 'The Cloudflare Stream subscription is missing or inactive.', true],
        'live_stream_started'     => ['Live Stream Started',     'A live stream went live (a feed connected to the live input).', false],
        'live_stream_ended'       => ['Live Stream Ended',       'The live stream feed disconnected and the stream ended.', false],
        'live_stream_reconnected' => ['Live Stream Reconnected', 'A live stream dropped and then successfully reconnected.', false],
        'live_stream_error'       => ['Live Stream Error',       'A live stream feed failed to connect or reconnect.', false],
    ];

    /** Register all settings sections and fields. */
    public static function register(): void {
        add_action('admin_init', [__CLASS__, 'registerCloudflareSettings']);
        add_action('admin_init', [__CLASS__, 'registerWebhookSettings']);
        add_action('admin_init', [__CLASS__, 'registerPlayerSettings']);
        add_action('admin_init', [__CLASS__, 'registerStreamerSettings']);
        add_action('admin_init', [__CLASS__, 'registerAlertSettings']);
        add_action('admin_init', [__CLASS__, 'registerLiveUSettings']);
    }

    /** Register admin menu. */
    public static function registerMenu(): void {
        add_menu_page('CloudFlare Setup for HitchStream', 'HS CloudFlare', 'manage_options', 'HitchStream_Cloudflare', [__CLASS__, 'renderAdminUI']);
    }

    /** Show admin notice when webhook secret is not configured. */
    /**
     * Surface missing/incomplete configuration as admin notices on every admin
     * page. ERRORS (red) = something is broken; WARNINGS (yellow) = an optional
     * feature is off. All checks are cheap get_option() reads — no HTTP — so
     * this is safe to run on every page load.
     */
    public static function showConfigNotices(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings_url = admin_url('admin.php?page=HitchStream_Cloudflare&tab=settings');
        $errors   = [];
        $warnings = [];

        // ── Cloudflare API credentials: account ID + an auth method ──
        $account_id = (string) get_option('HSCF_cloudflare_account_id', '');
        $has_token  = (string) get_option('HSCF_cloudflare_api_token', '') !== '';
        $has_legacy = (string) get_option('HSCF_cloudflare_api_key', '') !== ''
                   && (string) get_option('HSCF_cloudflare_email', '') !== '';
        if ($account_id === '' || (!$has_token && !$has_legacy)) {
            $errors[] = '<strong>Cloudflare API credentials are incomplete.</strong> Stream and video management can\'t reach Cloudflare. Add your Account ID and an API token (or email + API key) in Settings.';
        }

        // ── Customer ID — the player builds every stream URL from this ──
        if ((string) get_option('HSCF_customer_id', '') === '') {
            $errors[] = '<strong>Cloudflare Customer ID is not set.</strong> The player cannot build stream URLs — live and VOD playback will not work.';
        }

        // ── Live webhook wiring (Cloudflare Notifications) ──
        if ((string) get_option('HSCF_webhook_policy_id', '') === '') {
            $errors[] = '<strong>Live webhook is not set up.</strong> Instant connect/disconnect updates depend on it; without it the player falls back to ~10–20s polling. Open the Webhook panel and click “Set Up Live Webhook”.';
        }

        // ── Streamer service key (optional — degraded, not broken) ──
        if ((string) get_option('HSCF_streamer_api_key', '') === '') {
            $warnings[] = '<strong>Streamer service key is not set.</strong> Placeholder / holding streams and video management won\'t work until you add it.';
        }

        if ($errors) {
            echo '<div class="notice notice-error" style="border-left-color:#d63638;"><p><strong style="color:#d63638">&#9888; HitchStream — needs attention:</strong></p><ul style="list-style:disc;margin:6px 0 10px 22px;">';
            foreach ($errors as $e) {
                echo '<li style="margin-bottom:4px;">' . $e . '</li>';
            }
            echo '</ul><p><a href="' . esc_url($settings_url) . '" class="button button-primary button-small">Open HitchStream Settings</a></p></div>';
        }

        if ($warnings) {
            echo '<div class="notice notice-warning"><p><strong>&#9888; HitchStream — optional features not configured:</strong></p><ul style="list-style:disc;margin:6px 0 10px 22px;">';
            foreach ($warnings as $w) {
                echo '<li style="margin-bottom:4px;">' . $w . '</li>';
            }
            echo '</ul><p><a href="' . esc_url($settings_url) . '" class="button button-small">Open HitchStream Settings</a></p></div>';
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
        register_setting('HSCF_player_settings', 'HSCF_customer_id', [
            'sanitize_callback' => [__CLASS__, 'sanitize_customer_id'],
        ]);

        add_settings_section('HSCF_player_settings_section', 'Player Settings', [__CLASS__, 'player_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_customer_id_field', 'Cloudflare Customer ID', [__CLASS__, 'customer_id_field'], 'HitchStream_Cloudflare', 'HSCF_player_settings_section');
    }

    /**
     * Store the BARE customer code. The player builds URLs as
     * customer-{code}.cloudflarestream.com, so a pasted "customer-XXXX" would
     * double-prefix and break every stream URL site-wide. Strip it defensively.
     */
    public static function sanitize_customer_id($value): string {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return preg_replace('/^customer-/', '', $value);
    }

    public static function registerStreamerSettings(): void {
        register_setting('HSCF_streamer_settings', 'HSCF_streamer_api_url');
        register_setting('HSCF_streamer_settings', 'HSCF_streamer_api_key', [
            'sanitize_callback' => [__CLASS__, 'sanitize_streamer_api_key'],
        ]);

        add_settings_section('HSCF_streamer_settings_section', 'Streamer Service', [__CLASS__, 'streamer_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_streamer_api_url_field', 'Streamer API URL', [__CLASS__, 'streamer_api_url_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
        add_settings_field('HSCF_streamer_api_key_field', 'Streamer API Key', [__CLASS__, 'streamer_api_key_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
        add_settings_field('hscf_streamer_test_field', null, [__CLASS__, 'streamer_test_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
        add_settings_field('hscf_streamer_videos_field', 'Placeholder Videos', [__CLASS__, 'streamer_videos_field'], 'HitchStream_Cloudflare', 'HSCF_streamer_settings_section');
    }

    public static function registerAlertSettings(): void {
        register_setting('HSCF_alert_settings', 'HSCF_alert_email', [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitizeAlertEmail'],
            'default'           => '',
        ]);
        register_setting('HSCF_alert_settings', 'HSCF_alert_events', [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitizeAlertEvents'],
            'default'           => ['storage_full', 'no_subscription'],
        ]);

        add_settings_section('HSCF_alert_settings_section', 'Alerts', [__CLASS__, 'alert_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_alert_email_field', 'Alert Email(s)', [__CLASS__, 'alert_email_field'], 'HitchStream_Cloudflare', 'HSCF_alert_settings_section');
        add_settings_field('HSCF_alert_events_field', 'Send an email when…', [__CLASS__, 'alert_events_field'], 'HitchStream_Cloudflare', 'HSCF_alert_settings_section');
        add_settings_field('HSCF_alert_test_field', 'Test delivery', [__CLASS__, 'alert_test_field'], 'HitchStream_Cloudflare', 'HSCF_alert_settings_section');
    }

    /** LiveU Solo login — unlocks the per-input bonded-encoder control panel. */
    public static function registerLiveUSettings(): void {
        register_setting('HSCF_liveu_settings', 'HSCF_liveu_email');
        register_setting('HSCF_liveu_settings', 'HSCF_liveu_password');
        add_settings_section('HSCF_liveu_settings_section', 'LiveU Solo', [__CLASS__, 'liveu_section_desc'], 'HitchStream_Cloudflare');
        add_settings_field('HSCF_liveu_email_field', 'Solo Portal Email', [__CLASS__, 'liveu_email_field'], 'HitchStream_Cloudflare', 'HSCF_liveu_settings_section');
        add_settings_field('HSCF_liveu_password_field', 'Solo Portal Password', [__CLASS__, 'liveu_password_field'], 'HitchStream_Cloudflare', 'HSCF_liveu_settings_section');
        add_settings_field('hscf_liveu_test_field', 'Verify login', [__CLASS__, 'liveu_test_field'], 'HitchStream_Cloudflare', 'HSCF_liveu_settings_section');
        // Changing the login invalidates the cached bearer token.
        add_action('update_option_HSCF_liveu_email', [\HS\LiveU\Client::class, 'forgetToken']);
        add_action('update_option_HSCF_liveu_password', [\HS\LiveU\Client::class, 'forgetToken']);
    }

    /** Tidy the alert-email list on save (trim + normalize separators to ", ")
     *  without dropping entries, so a typo is visible rather than silently lost. */
    public static function sanitizeAlertEmail($value): string {
        if (!is_string($value)) return '';
        $parts = array_filter(array_map('trim', preg_split('/[,;\s]+/', $value) ?: []), 'strlen');
        return implode(', ', array_map('sanitize_text_field', $parts));
    }

    /** Sanitize the alert-events checkbox array against the known catalog. */
    public static function sanitizeAlertEvents($value): array {
        if (!is_array($value)) return [];
        $allowed = array_keys(self::ALERT_EVENTS);
        return array_values(array_intersect($allowed, array_map('sanitize_key', $value)));
    }

    // ── Field callbacks ────────────────────────────────────────────

    public static function liveu_section_desc(): void {
        echo '<p>Your <a href="https://solo.liveu.tv" target="_blank" rel="noopener">solo.liveu.tv</a> portal login. Enter it to unlock the <strong>LiveU Solo</strong> control panel on each live input — arm a bonded Solo encoder at your Cloudflare input, toggle LRT, and start/stop the stream from here. Leave blank to hide the panel entirely.</p>';
    }

    public static function liveu_email_field(): void {
        $val = get_option('HSCF_liveu_email', '');
        echo "<input type='email' name='HSCF_liveu_email' value='" . esc_attr($val) . "' class='regular-text' autocomplete='off' placeholder='you@example.com' />";
    }

    public static function liveu_password_field(): void {
        $val = get_option('HSCF_liveu_password', '');
        echo "<input type='password' name='HSCF_liveu_password' value='" . esc_attr($val) . "' class='regular-text' autocomplete='new-password' />";
        echo '<p class="description">Stored as a WordPress option (same as the Cloudflare key) and only ever used server-side — the password never reaches the browser.</p>';
    }

    public static function liveu_test_field(): void {
        echo '<p><button type="button" class="button" id="hscf-liveu-test-btn">Test LiveU Login</button> <span id="hscf-liveu-test-result" class="hscf-test-result"></span></p>';
        echo '<p class="description">Checks the email &amp; password typed above against the Solo portal — <strong>before</strong> you save.</p>';
        echo self::testButtonScript(
            'hscf-liveu-test-btn', 'hscf-liveu-test-result', 'hscf_test_liveu', 'Test LiveU Login',
            '_data.email = $("input[name=HSCF_liveu_email]").val(); _data.password = $("input[name=HSCF_liveu_password]").val();'
        );
    }

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
        echo '<p>Live-input events (connected / disconnected / errored) are delivered through <strong>Cloudflare Notifications</strong>. Click <em>Set Up Live Webhook</em> and the plugin creates the webhook destination and notification policy in your Cloudflare account automatically and keeps the shared secret in sync — no dashboard steps needed. <em>(This is separate from Stream\'s on-demand <code>video.ready</code> webhook, which this player does not use.)</em></p>';
    }

    public static function webhook_url_field(): void {
        $val = get_option('HSCF_webhook_url', '');
        if (!$val) {
            $val = rtrim(home_url('/'), '/') . '/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php';
        }
        echo '<input type="url" name="HSCF_webhook_url" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" />';
    }

    public static function webhook_secret_field(): void {
        $val = (string) get_option('HSCF_webhook_secret', '');
        $masked = strlen($val) > 8
            ? substr($val, 0, 4) . str_repeat('•', strlen($val) - 8) . substr($val, -4)
            : ($val ? str_repeat('•', strlen($val)) : '');
        echo '<input type="text" value="' . esc_attr($masked) . '" style="width:100%;max-width:600px;" placeholder="Generated automatically on Set Up" disabled />';
        echo '<p class="description">The <code>cf-webhook-auth</code> shared secret. Managed automatically — generated on setup, rotated with the button below — and kept identical on the Cloudflare destination and here so incoming webhooks authenticate.</p>';
        echo '<p><button type="button" class="button" id="hscf-webhook-rotate-btn">Rotate Secret</button> <span id="hscf-webhook-rotate-result" class="hscf-test-result"></span></p>';
        echo self::testButtonScript('hscf-webhook-rotate-btn', 'hscf-webhook-rotate-result', 'hscf_rotate_webhook', 'Rotate Secret');
    }

    public static function player_section_desc(): void {
        echo '<p>Configurable defaults for the HSPlayer video player. URL params on the player page override these values.</p>';
    }

    public static function customer_id_field(): void {
        $val = get_option('HSCF_customer_id', '');
        echo '<input type="text" name="HSCF_customer_id" value="' . esc_attr($val) . '" class="regular-text" placeholder="e.g. f33zs165nr7gyfy4 — bare code, no customer- prefix" />';
        echo '<p class="description">The customer code from your Cloudflare Stream URLs — the part <strong>after</strong> <code>customer-</code> in <code>customer-XXXX.cloudflarestream.com</code>. Enter just the <code>XXXX</code> (a leading <code>customer-</code> is stripped automatically). Required — the player builds every stream URL from this.</p>';
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

    /**
     * Sanitize the streamer API key on save. The field renders a MASKED value
     * and promises "leave blank to keep current key" — so a blank submission or
     * a re-submitted mask must NOT overwrite the stored key (that bug erased /
     * corrupted the key). Only a genuinely new value is written.
     */
    public static function sanitize_streamer_api_key($value): string {
        $value    = is_string($value) ? trim($value) : '';
        $existing = (string) get_option('HSCF_streamer_api_key', '');

        // Blank = keep current key.
        if ($value === '') {
            return $existing;
        }
        // The masked placeholder we render (e.g. "**********6577") came back
        // unchanged — keep the current key rather than saving asterisks.
        if ($existing !== '' && strpos($value, '*') !== false) {
            return $existing;
        }
        return sanitize_text_field($value);
    }

    public static function streamer_test_field(): void {
        echo '<p><button type="button" class="button button-primary" id="hscf-streamer-test-btn">Save and Test Streamer Service</button> <span id="hscf-streamer-test-result" class="hscf-test-result"></span></p>';
        echo '<p class="description">Saves the key/URL above, then tests the connection — no separate Save needed.</p>';
        // Send whatever is typed in the key/URL boxes so the handler can persist
        // it before testing. A masked value (contains "*") is sent blank so the
        // already-stored key is kept.
        $extra = '_data.api_url = ($("input[name=HSCF_streamer_api_url]").val() || "");'
               . 'var _k = ($("input[name=HSCF_streamer_api_key]").val() || "");'
               . '_data.api_key = (_k.indexOf("*") > -1 ? "" : _k);';
        echo self::testButtonScript('hscf-streamer-test-btn', 'hscf-streamer-test-result', 'hscf_test_streamer', 'Save and Test Streamer Service', $extra);
    }

    public static function streamer_videos_field(): void {
        ?>
        <div class="hscf-sv">
            <div id="hscf-sv-modal" class="hscf-sv-modal" style="display:none;">
                <div class="hscf-sv-modal-box">
                    <span class="hscf-sv-modal-close" title="Close">&times;</span>
                    <h3 class="hscf-sv-modal-title"></h3>
                    <video class="hscf-sv-video" controls preload="metadata" playsinline></video>
                </div>
            </div>
            <div id="hscf-sv-list" class="hscf-sv-list">Loading…</div>
            <div class="hscf-sv-upload">
                <input type="file" id="hscf-sv-file" accept=".mp4,.mov" />
                <button type="button" class="button button-secondary" id="hscf-sv-upload-btn"><span class="dashicons dashicons-upload"></span> Upload Video</button>
                <span id="hscf-sv-msg" class="hscf-sv-msg"></span>
            </div>
            <p class="description">Holding-loop videos the streamer can broadcast (.mp4 or .mov). Max file size: <strong><?= esc_html(self::getFormattedMaxUploadSize()) ?></strong></p>
        </div>
        <script>
        jQuery(function($){
            var ajax = hscf_ajax.ajax_url, nonce = hscf_ajax.nonce;
            function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
            function msg(t, ok){ $('#hscf-sv-msg').html('<span style="color:' + (ok ? 'green' : '#b32d2e') + '">' + esc(t) + '</span>'); }
            function load(){
                var $l = $('#hscf-sv-list').html('Loading…');
                $.post(ajax, { action: 'hscf_streamer_list_videos', _wpnonce: nonce }, function(resp){
                    if (!resp || !resp.success) { $l.html('<span style="color:#b32d2e">' + esc((resp && resp.data) || 'Could not load videos') + '</span>'); return; }
                    var vids = (resp.data && resp.data.videos) || [];
                    if (!vids.length) { $l.html('<em>No videos on the streamer yet.</em>'); return; }
                    var html = '<ul class="hscf-sv-ul">';
                    vids.forEach(function(v){
                        html += '<li><span class="dashicons dashicons-format-video"></span> <a href="#" class="hscf-sv-play" data-file="' + esc(v) + '" title="Play preview">' + esc(v) + '</a> <a href="#" class="hscf-sv-del" data-file="' + esc(v) + '">Delete</a></li>';
                    });
                    $l.html(html + '</ul>');
                }).fail(function(){ $l.html('<span style="color:#b32d2e">Request failed</span>'); });
            }
            $(document).on('click', '#hscf-sv-upload-btn', function(){
                var input = $('#hscf-sv-file')[0];
                var f = input && input.files[0];
                if (!f) { msg('Choose a file first.', false); return; }
                if (!/\.(mp4|mov)$/i.test(f.name)) { msg('Only .mp4 or .mov allowed.', false); return; }
                var fd = new FormData();
                fd.append('action', 'hscf_streamer_upload_video');
                fd.append('_wpnonce', nonce);
                fd.append('file', f);
                var $b = $(this).prop('disabled', true).html('Uploading…');
                msg('Uploading ' + f.name + '…', true);
                $.ajax({ url: ajax, type: 'POST', data: fd, processData: false, contentType: false })
                  .done(function(resp){
                      if (resp && resp.success) { msg(resp.data || 'Uploaded', true); $('#hscf-sv-file').val(''); load(); }
                      else { msg((resp && resp.data) || 'Upload failed', false); }
                  })
                  .fail(function(){ msg('Upload request failed (file may exceed the server upload limit).', false); })
                  .always(function(){ $b.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Upload Video'); });
            });
            $(document).on('click', '.hscf-sv-del', function(e){
                e.preventDefault();
                var file = $(this).data('file');
                if (!window.confirm('Delete "' + file + '" from the streamer?')) return;
                $.post(ajax, { action: 'hscf_streamer_delete_video', _wpnonce: nonce, file: file }, function(resp){
                    if (resp && resp.success) { msg(resp.data || 'Deleted', true); load(); }
                    else { msg((resp && resp.data) || 'Delete failed', false); }
                }).fail(function(){ msg('Delete request failed', false); });
            });

            // ── Preview modal ──
            function videoUrl(name){
                return ajax + '?action=hscf_streamer_get_video&_wpnonce=' + encodeURIComponent(nonce) + '&file=' + encodeURIComponent(name);
            }
            function openPlayer(name){
                var $m = $('#hscf-sv-modal');
                $m.find('.hscf-sv-modal-title').text(name);
                var v = $m.find('.hscf-sv-video')[0];
                v.src = videoUrl(name);
                $m.css('display', 'flex');
                v.play().catch(function(){});
            }
            function closePlayer(){
                var v = $('#hscf-sv-modal').find('.hscf-sv-video')[0];
                try { v.pause(); v.removeAttribute('src'); v.load(); } catch(e){}
                $('#hscf-sv-modal').hide();
            }
            $(document).on('click', '.hscf-sv-play', function(e){ e.preventDefault(); openPlayer($(this).data('file')); });
            $(document).on('click', '#hscf-sv-modal, .hscf-sv-modal-close', function(e){ if (e.target === this) closePlayer(); });
            $(document).on('keydown', function(e){ if (e.key === 'Escape' && $('#hscf-sv-modal').is(':visible')) closePlayer(); });

            load();
        });
        </script>
        <?php
    }

    public static function alert_section_desc(): void {
        echo '<p>Get an email when something notable happens to a live stream. Pick which events below, set the address(es), then send a test to confirm delivery.</p>';
    }

    public static function alert_email_field(): void {
        $val = get_option('HSCF_alert_email', '');
        echo '<input type="text" id="hscf-alert-email" name="HSCF_alert_email" value="' . esc_attr($val) . '" style="width:100%;max-width:600px;" placeholder="you@example.com, partner@example.com" />';
        echo '<p class="description">One or more addresses, separated by commas — every alert goes to all of them. Leave blank to turn off all email alerts.</p>';
    }

    public static function alert_events_field(): void {
        $enabled = Config::alertEvents();
        echo '<fieldset>';
        foreach (self::ALERT_EVENTS as $key => [$label, $desc, $default]) {
            $checked = in_array($key, $enabled, true) ? ' checked' : '';
            echo '<label style="display:block;margin:0 0 .5em;">'
                . '<input type="checkbox" name="HSCF_alert_events[]" value="' . esc_attr($key) . '"' . $checked . ' /> '
                . '<strong>' . esc_html($label) . '</strong>'
                . '<span class="description" style="display:block;margin:.1em 0 0 1.8em;">' . esc_html($desc) . '</span>'
                . '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">“Stream Started/Ended” fire once per actual change of state, and every alert is throttled to at most one email per event every 5 minutes.</p>';
    }

    public static function alert_test_field(): void {
        echo '<p><button type="button" class="button" id="hscf-alert-test-btn">Send Test Email</button> <span id="hscf-alert-test-result" class="hscf-test-result"></span></p>';
        echo '<p class="description">Sends a sample alert to the address above (uses the value currently in the box — no need to save first).</p>';
        // Pass the live value of the email field so it can be tested before saving.
        echo self::testButtonScript(
            'hscf-alert-test-btn', 'hscf-alert-test-result', 'hscf_test_alert_email', 'Send Test Email',
            '_data.email = $("#hscf-alert-email").val();'
        );
    }

    // ── Admin page rendering ───────────────────────────────────────
    // Dead-code removed (post-audit cleanup): renderAdmin() and
    // renderStreams() were a 280-line never-called duplicate of
    // renderAdminUI() / its inline streams view. Only renderAdminUI
    // is wired through HSCF_Admin() in the plugin bootstrap.


    // ── Internal helper ────────────────────────────────────────────

    private static function testButtonScript(string $btnId, string $resultId, string $ajaxAction, string $btnText, string $extraDataJs = ''): string {
        $scriptId = sanitize_key(str_replace('-', '_', $ajaxAction));
        return '<script>
    (function($) {
        $(document).on("click", "#' . $btnId . '", function() {
            var $btn = $(this);
            var $result = $("#' . $resultId . '");
            $btn.prop("disabled", true).text("Testing...");
            $result.text("");
            var _data = {
                action: "' . $ajaxAction . '",
                _wpnonce: hscf_ajax.nonce
            };
            ' . $extraDataJs . '
            $.ajax({
                url: hscf_ajax.ajax_url,
                type: "POST",
                data: _data,
                success: function(resp) {
                    $btn.prop("disabled", false).text("' . esc_js($btnText) . '");
                    var d = resp && resp.data;
                    if (d && typeof d === "object") { d = d.message || JSON.stringify(d); }
                    if (resp.success) {
                        $result.html("<span style=\\"color:green\\">&#10004; " + (d || "' . esc_js($btnText . ' OK') . '") + "</span>");
                    } else {
                        $result.html("<span style=\\"color:red\\">&#10008; " + (d || "' . esc_js($btnText . ' failed') . '") + "</span>");
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
        // ── Process operations (hardened — a Cloudflare/config failure must
        //    never fatal the admin page) ──
        if (isset($_GET['delete_input'], $_GET['confirm_delete']) && $_GET['confirm_delete'] === 'yes') {
            try { (new \HS\Services\LiveInputService())->delete(sanitize_text_field($_GET['delete_input'])); } catch (\Throwable $e) {}
        }
        if (isset($_POST['create_stream']) && !empty($_POST['stream_name'])) {
            try { (new \HS\Services\LiveInputService())->create(sanitize_text_field($_POST['stream_name']), !empty($_POST['low_latency'])); } catch (\Throwable $e) {}
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'streams';
        if (!in_array($tab, ['streams', 'videos', 'checklist', 'settings'], true)) { $tab = 'streams'; }
        $base       = admin_url('admin.php?page=HitchStream_Cloudflare');
        $account_id = get_option('HSCF_cloudflare_account_id', '');
        $cf_dash    = $account_id
            ? 'https://dash.cloudflare.com/' . rawurlencode($account_id) . '/stream/videos'
            : 'https://dash.cloudflare.com/';
        // Preview player on THIS site (so the mirror previews the local player and
        // production previews the production player) — page lives at /player/.
        $player_base = home_url('/player/');
        // Streaming ingest host for the streamer-facing RTMP/SRT URLs. This is a
        // fixed Cloudflare custom-ingest domain (live.hitchstream.com), NOT derived
        // from the WP site URL — it doesn't change when the site domain does.
        // (The placeholder-stream push uses Cloudflare's own rtmp_details->url.)
        $ingest_host = 'live.hitchstream.com';
        ?>
<div class="wrap hscf-admin">
    <h1 class="wp-heading-inline"><span class="dashicons dashicons-video-alt2 hscf-title-icon"></span> HitchStream CloudFlare</h1>
    <hr class="wp-header-end">

    <nav class="nav-tab-wrapper hscf-tabs">
        <a href="<?= esc_url($base . '&tab=streams') ?>" class="nav-tab <?= $tab === 'streams' ? 'nav-tab-active' : '' ?>"><span class="dashicons dashicons-video-alt2"></span> Live Streams</a>
        <a href="<?= esc_url($base . '&tab=videos') ?>" class="nav-tab <?= $tab === 'videos' ? 'nav-tab-active' : '' ?>"><span class="dashicons dashicons-format-video"></span> Video Library</a>
        <a href="<?= esc_url($base . '&tab=checklist') ?>" class="nav-tab <?= $tab === 'checklist' ? 'nav-tab-active' : '' ?>"><span class="dashicons dashicons-yes-alt"></span> Checklist</a>
        <a href="<?= esc_url($base . '&tab=settings') ?>" class="nav-tab <?= $tab === 'settings' ? 'nav-tab-active' : '' ?>"><span class="dashicons dashicons-admin-generic"></span> Settings</a>
    </nav>

    <?php if ($tab === 'settings'): ?>
    <?php // ── SETTINGS TAB ── collapsible cards, each its own form. Sections are
          //    rendered with do_settings_fields() (one section) instead of
          //    do_settings_sections() (all sections) — which is what caused the
          //    5x duplication. ?>
    <div class="hscf-settings">
        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><span class="dashicons dashicons-yes-alt" style="color:#008a20;vertical-align:middle;"></span> <strong>Saved.</strong> Your settings have been updated.</p></div>
        <?php endif; ?>
        <p class="hscf-lead">Set-and-forget configuration. Once these are correct you rarely need to touch them.</p>

        <details class="hscf-card" open>
            <summary class="hscf-card__head"><span class="dashicons dashicons-cloud"></span> Cloudflare API <span class="hscf-card__hint"><?= $account_id ? '<span class="hscf-ok">Account ID set</span>' : '<span class="hscf-warn">Account ID missing</span>' ?></span></summary>
            <div class="hscf-card__body">
                <form method="post" action="options.php">
                    <?php settings_fields('HSCF_settings'); ?>
                    <table class="form-table" role="presentation"><?php do_settings_fields('HitchStream_Cloudflare', 'HSCF_cloudflare_settings_section'); ?></table>
                    <?php submit_button('Save Cloudflare API'); ?>
                </form>
            </div>
        </details>

        <details class="hscf-card">
            <summary class="hscf-card__head"><span class="dashicons dashicons-rss"></span> Live Webhook <span class="hscf-card__hint"><?= get_option('HSCF_webhook_policy_id', '') ? '<span class="hscf-ok">Configured</span>' : '<span class="hscf-warn">Not set up</span>' ?></span></summary>
            <div class="hscf-card__body">
                <form method="post" action="options.php" id="webhook-settings-form">
                    <?php settings_fields('HSCF_webhook_settings'); ?>
                    <?php self::webhook_section_desc(); ?>
                    <table class="form-table" role="presentation"><?php do_settings_fields('HitchStream_Cloudflare', 'HSCF_webhook_settings_section'); ?></table>
                    <p class="hscf-btn-row">
                        <button type="button" id="btn-register-webhook" class="button button-primary"><span class="dashicons dashicons-cloud"></span> Set Up Live Webhook</button>
                        <button type="button" id="btn-fetch-webhook-status" class="button"><span class="dashicons dashicons-visibility"></span> Check Status</button>
                        <button type="button" id="btn-delete-webhook" class="button button-link-delete"><span class="dashicons dashicons-trash"></span> Remove Live Webhook</button>
                    </p>
                </form>
                <div id="webhook-status" class="hscf-status-box" style="display:none;"></div>
            </div>
        </details>

        <details class="hscf-card">
            <summary class="hscf-card__head"><span class="dashicons dashicons-format-video"></span> Player <span class="hscf-card__hint"><?= get_option('HSCF_customer_id', '') ? '<span class="hscf-ok">Customer ID set</span>' : '<span class="hscf-warn">Customer ID missing</span>' ?></span></summary>
            <div class="hscf-card__body">
                <form method="post" action="options.php">
                    <?php settings_fields('HSCF_player_settings'); ?>
                    <?php self::player_section_desc(); ?>
                    <table class="form-table" role="presentation"><?php do_settings_fields('HitchStream_Cloudflare', 'HSCF_player_settings_section'); ?></table>
                    <?php submit_button('Save Player'); ?>
                </form>
            </div>
        </details>

        <details class="hscf-card">
            <summary class="hscf-card__head"><span class="dashicons dashicons-controls-play"></span> Streamer Service</summary>
            <div class="hscf-card__body">
                <form method="post" action="options.php">
                    <?php settings_fields('HSCF_streamer_settings'); ?>
                    <?php self::streamer_section_desc(); ?>
                    <table class="form-table" role="presentation"><?php do_settings_fields('HitchStream_Cloudflare', 'HSCF_streamer_settings_section'); ?></table>
                    <?php submit_button('Save Streamer'); ?>
                </form>
            </div>
        </details>

        <details class="hscf-card">
            <summary class="hscf-card__head"><span class="dashicons dashicons-email-alt"></span> Alerts</summary>
            <div class="hscf-card__body">
                <form method="post" action="options.php">
                    <?php settings_fields('HSCF_alert_settings'); ?>
                    <?php self::alert_section_desc(); ?>
                    <table class="form-table" role="presentation"><?php do_settings_fields('HitchStream_Cloudflare', 'HSCF_alert_settings_section'); ?></table>
                    <?php submit_button('Save Alerts'); ?>
                </form>
            </div>
        </details>

        <details class="hscf-card">
            <summary class="hscf-card__head"><span class="dashicons dashicons-video-alt3"></span> LiveU Solo <span class="hscf-card__hint"><?= \HS\Config::liveuConfigured() ? '<span class="hscf-ok">Login set</span>' : '<span class="hscf-warn">Not configured</span>' ?></span></summary>
            <div class="hscf-card__body">
                <form method="post" action="options.php">
                    <?php settings_fields('HSCF_liveu_settings'); ?>
                    <?php self::liveu_section_desc(); ?>
                    <table class="form-table" role="presentation"><?php do_settings_fields('HitchStream_Cloudflare', 'HSCF_liveu_settings_section'); ?></table>
                    <?php submit_button('Save LiveU Login'); ?>
                </form>
            </div>
        </details>
    </div>
    <script>
    jQuery(function($){
        // Remember which settings group was just submitted so that after the
        // save redirect we can flash that exact card's button green.
        $('.hscf-card form').on('submit', function(){
            try { sessionStorage.setItem('hscfSavedGroup', $(this).find('input[name=option_page]').val() || ''); } catch(e){}
        });
        if (/[?&]settings-updated=true/.test(location.search)) {
            var grp = ''; try { grp = sessionStorage.getItem('hscfSavedGroup') || ''; sessionStorage.removeItem('hscfSavedGroup'); } catch(e){}
            var $form = grp ? $('input[name=option_page][value="' + grp + '"]').closest('form') : $();
            if (!$form.length) { $form = $('.hscf-card form').first(); }
            $form.closest('details').attr('open', 'open');
            var $btn = $form.find('.button-primary').first();
            if ($btn.length) {
                var setLabel = function(t){ $btn.is('input') ? $btn.val(t) : $btn.text(t); };
                var orig = $btn.is('input') ? $btn.val() : $btn.text();
                $btn.addClass('hscf-saved-flash'); setLabel('✓ Saved!');
                $btn.get(0).scrollIntoView({block:'center', behavior:'smooth'});
                setTimeout(function(){ $btn.removeClass('hscf-saved-flash'); setLabel(orig); }, 2500);
            }
        }
    });
    </script>

    <?php elseif ($tab === 'videos'): ?>
    <?php // ── VIDEO LIBRARY TAB ── ?>
    <?php $videos = self::listCloudflareVideos();
          $max_upload = self::getFormattedMaxUploadSize();
          $max_exec   = self::getMaxExecutionTime(); ?>
    <div class="hscf-panel">
        <h2 class="hscf-panel__title"><span class="dashicons dashicons-upload"></span> Upload a Video</h2>
        <p class="description">Max file size: <strong><?= esc_html($max_upload) ?></strong> &middot; Max upload time: <strong><?= esc_html($max_exec) ?></strong></p>
        <form id="video-upload-form" method="post" enctype="multipart/form-data" class="hscf-upload">
            <input type="file" name="video_file" accept=".mp4,.mov,.m4v,.mkv,.webm,.avi,video/*" required>
            <button type="submit" name="upload_video" class="button button-primary"><span class="dashicons dashicons-upload"></span> Upload Video</button>
            <span id="upload-percentage" class="hscf-upload__pct"></span>
        </form>
        <div id="progress-container" class="hscf-progress" style="display:none;"><div id="progress-bar"></div></div>
    </div>

    <h2 class="hscf-section-title"><span class="dashicons dashicons-format-video"></span> Uploaded Videos</h2>
    <div id="videos-panel" class="hscf-video-grid">
    <?php if (is_array($videos) && !empty($videos)): foreach ($videos as $video):
        if (!is_array($video) || !isset($video['uid'])) { continue; }
        $dlName        = self::cfDownloadFilename($video['meta']['name'] ?? 'video');
        $downloadLink  = isset($video['mp4_download_url']) ? self::withDownloadFilename($video['mp4_download_url'], $dlName) : '#';
        $downloadTitle = isset($video['mp4_download_url']) ? 'Download' : 'Create download';
        ?>
        <div class="video-container hscf-video-card">
            <p class="video-name"><?= esc_html($video['meta']['name'] ?? 'Untitled') ?></p>
            <iframe src="<?= esc_url($player_base . '?live=false&autoplay=false&inputId=' . $video['uid']) ?>" loading="lazy" style="border:none;width:100%;aspect-ratio:16/9;" allow="fullscreen; accelerometer; gyroscope; encrypted-media; picture-in-picture" allowfullscreen></iframe>
            <div class="video-buttons">
                <a href="<?= esc_url($downloadLink) ?>" class="create-download-link hscf-icon-btn hscf-icon-download" data-video-id="<?= esc_attr($video['uid']) ?>" data-filename="<?= esc_attr($dlName) ?>" title="<?= esc_attr($downloadTitle) ?>"><span class="dashicons <?= isset($video['mp4_download_url']) ? 'dashicons-download' : 'dashicons-cloud' ?>"></span></a>
                <a href="#" class="generate-embed-link hscf-icon-btn hscf-icon-embed" data-video-id="<?= esc_attr($video['uid']) ?>" title="Get Embed Code"><span class="dashicons dashicons-editor-code"></span></a>
                <a href="#" class="delete-video-link hscf-icon-btn hscf-icon-delete" data-video-id="<?= esc_attr($video['uid']) ?>" title="Delete Video"><span class="dashicons dashicons-trash"></span></a>
            </div>
        </div>
    <?php endforeach; else: ?>
        <div class="hscf-empty"><span class="dashicons dashicons-format-video"></span><p><?= is_string($videos) && $videos ? esc_html($videos) : 'No uploaded videos yet.' ?></p></div>
    <?php endif; ?>
    </div>

    <?php elseif ($tab === 'checklist'): ?>
    <?php // ── EVENT-DAY CHECKLIST TAB ── one button that runs the pre-event
          //    checks from RUNBOOK-live-state.md (prime all inputs, verify the
          //    static files viewers poll, refresher heartbeat, webhook, alerts). ?>
    <div class="hscf-checklist">
        <p>Run this <strong>the morning of every event</strong> — and always after creating a <strong>new</strong> Live Input
           (priming is what enrolls a new input in the state refresher). It checks everything guests depend on:
           state files, freshness, the refresher, webhooks, and alerts. Takes a few seconds.</p>
        <p>
            <button type="button" class="button button-primary button-hero" id="hscf-checklist-run">
                <span class="dashicons dashicons-yes-alt"></span> Run Event-Day Checklist
            </button>
        </p>
        <div id="hscf-checklist-results" style="display:none;">
            <div class="hscf-checklist__summary" id="hscf-checklist-summary"></div>
            <div class="hscf-checklist__rows" id="hscf-checklist-rows"></div>
            <p class="description">Fail = will affect viewers, fix before the event. Warn = degraded but safe — the stream will still work.
               Manual fallback commands live in <code>RUNBOOK-live-state.md</code>.</p>
        </div>
    </div>

    <?php else: ?>
    <?php // ── LIVE STREAMS TAB ── ?>
    <?php $live_inputs = self::getLiveInputs(); ?>
    <div class="hscf-toolbar">
        <div class="hscf-toolbar__group">
            <form method="post" action="" class="hscf-create">
                <input type="text" name="stream_name" placeholder="New stream name" required>
                <label class="hscf-ll" title="Create with Low-Latency HLS"><input type="checkbox" name="low_latency" value="1"> Low-Latency</label>
                <button type="submit" name="create_stream" class="button button-primary"><span class="dashicons dashicons-plus-alt2"></span> Create Stream</button>
            </form>
        </div>
        <div class="hscf-toolbar__links">
            <a href="https://solo.liveu.tv/dashboard" target="_blank" rel="noopener" class="button"><span class="dashicons dashicons-rss"></span> LiveU</a>
            <a href="<?= esc_url($cf_dash) ?>" target="_blank" rel="noopener" class="button"><span class="dashicons dashicons-cloud"></span> Cloudflare</a>
        </div>
    </div>

    <?php if ($live_inputs === 'not_configured'): ?>
        <div class="hscf-empty hscf-empty--warn">
            <span class="dashicons dashicons-warning"></span>
            <p><strong>Cloudflare isn't configured yet.</strong> Add your Account ID and authentication under the <a href="<?= esc_url($base . '&tab=settings') ?>">Settings</a> tab, then your live streams will appear here.</p>
        </div>
    <?php elseif (is_string($live_inputs)): ?>
        <div class="hscf-empty hscf-empty--warn">
            <span class="dashicons dashicons-cloud"></span>
            <p><strong>Couldn't reach Cloudflare.</strong> <?= esc_html($live_inputs) ?></p>
        </div>
    <?php elseif (empty($live_inputs)): ?>
        <div class="hscf-empty">
            <span class="dashicons dashicons-video-alt2"></span>
            <p>No live streams yet. Create one above to get started.</p>
        </div>
    <?php else: foreach ($live_inputs as $input):
        if (!is_object($input) || !isset($input->uid)) { continue; }
        $input_name   = $input->meta->name ?? 'Unnamed Input';
        $delete_link  = admin_url('admin.php?page=HitchStream_Cloudflare&delete_input=' . esc_attr($input->uid) . '&confirm_delete=yes');
        $is_connected = isset($input->status_details) && $input->status_details === 'connected';
        $status_label = $input->status_details ?? 'Status unavailable';
        $rtmpKey      = $input->rtmp_details->streamKey ?? '';
        ?>
    <div class="hscf-stream is-collapsed">
        <div class="hscf-stream__head">
            <button type="button" class="hscf-stream__chevron" title="Collapse / expand" aria-label="Collapse / expand this stream"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
            <strong class="hscf-stream__name"><?= esc_html($input_name) ?></strong>
            <span class="hscf-badge <?= $is_connected ? 'hscf-badge--live' : 'hscf-badge--off' ?>" id="badge-<?= esc_attr($input->uid) ?>">
                <span class="dashicons <?= $is_connected ? 'dashicons-controls-play' : 'dashicons-controls-pause' ?>"></span><span class="hscf-badge__text"><?= esc_html($status_label) ?></span>
            </span>
            <span class="hscf-viewers" id="viewers-<?= esc_attr($input->uid) ?>" style="display:none;" title="Connections watching right now — includes any open previews/tabs that are buffering the stream; Cloudflare updates this on a ~1 minute rolling window"><span class="dashicons dashicons-visibility"></span><span class="hscf-viewers__n"></span></span>
            <a href="<?= esc_url($delete_link) ?>" class="hscf-stream__delete" title="Delete stream" onclick="return confirm('Delete <?= esc_js($input_name) ?>? This cannot be undone.')"><span class="dashicons dashicons-trash"></span></a>
        </div>
        <div class="hscf-stream__grid">
            <div class="hscf-stream__col">
                <h4>For the streaming app</h4>
                <?php if (isset($input->srt_details)):
                    $passphrase = esc_attr($input->srt_details->passphrase);
                    $streamId   = esc_attr($input->srt_details->streamId);
                    $baseSRTUrl = 'srt://' . $ingest_host . ':778'; ?>
                    <button class="button copy-btn generateSRTvmix" data-host="<?= esc_attr($ingest_host) ?>" data-simplified-srt-url="<?= esc_attr($ingest_host) ?>" data-port-srt="778" data-passphrase="<?= $passphrase ?>" data-stream-id="<?= $streamId ?>">SRT (vMix)</button>
                    <button class="button copy-btn generateSRTobs" data-host="<?= esc_attr($ingest_host) ?>" data-base-srt-url="<?= esc_attr($baseSRTUrl . '?passphrase=' . $passphrase . '&streamid=' . $streamId) ?>">SRT (OBS)</button>
                <?php endif; ?>
                <?php if (isset($input->rtmp_details)):
                    $rtmpURL = 'rtmps://' . $ingest_host . ':443/live/';
                    $rtmpKey = esc_attr($input->rtmp_details->streamKey); ?>
                    <button class="button copy-btn copy-rtmp-url-btn" data-rtmp-url="<?= esc_attr($rtmpURL) ?>">RTMP URL</button>
                    <button class="button copy-btn copy-rtmp-key-btn" data-rtmp-key="<?= $rtmpKey ?>">RTMP Key</button>
                <?php endif; ?>
                <h4>For the customer page</h4>
                <button class="button copy-btn copy-input-id-btn" data-input-id="<?= esc_attr($input->uid) ?>">Live Input ID</button>
                <button class="button copy-btn copy-embed-code-btn" data-input-id="<?= esc_attr($input->uid) ?>">Embed Code</button>
                <?php $phRtmpUrl = $input->rtmp_details->url ?? 'rtmps://live.cloudflare.com:443/live/'; ?>
                <div class="hscf-ph-row">
                    <select class="FileSelector hscf-ph-video" data-input-id="<?= esc_attr($input->uid) ?>">
                        <option value="">Placeholder Stream</option>
                    </select>
                    <button type="button" class="button hscf-ph-btn is-idle" data-input-id="<?= esc_attr($input->uid) ?>" data-rtmp-url="<?= esc_attr($phRtmpUrl) ?>" data-rtmp-key="<?= esc_attr($rtmpKey) ?>" title="Start placeholder stream"><span class="dashicons dashicons-controls-play"></span></button>
                    <span class="hscf-ph-status" id="phstatus-<?= esc_attr($input->uid) ?>" data-input-id="<?= esc_attr($input->uid) ?>"></span>
                </div>
            </div>
            <div class="hscf-stream__col">
                <h4>Restream</h4>
                <div class="hscf-output-providers">
                    <?php foreach (self::OUTPUT_PROVIDERS as $pkey => [$plabel, $purl, $pcolor]): ?>
                        <button type="button" class="hscf-add-output hscf-provider-<?= esc_attr($pkey) ?>"
                            data-input-id="<?= esc_attr($input->uid) ?>" data-provider="<?= esc_attr($pkey) ?>"
                            data-provider-label="<?= esc_attr($plabel) ?>" data-ingest-url="<?= esc_attr($purl) ?>"
                            title="Add <?= esc_attr($plabel) ?> output"><?= self::providerIcon($pkey) ?><span><?= esc_html($plabel) ?></span></button>
                    <?php endforeach; ?>
                </div>
                <div class="hscf-output-list" data-input-id="<?= esc_attr($input->uid) ?>">
                <?php $outputs = self::getLiveInputOutputs($input->uid);
                if (is_array($outputs) && !empty($outputs)): foreach ($outputs as $output):
                    if (!is_array($output) || empty($output['uid'])) { continue; }
                    echo self::renderOutputRow($output, $input->uid);
                endforeach; else: ?><p class="description hscf-no-outputs">No restreams yet.</p><?php endif; ?>
                </div>
            </div>
            <div class="hscf-stream__col">
                <h4>Recordings</h4>
                <div class="hscf-recordings-list">
                <?php $filteredVideos = self::getVideosByStreamName($input_name);
                if (is_array($filteredVideos) && !empty($filteredVideos)): foreach ($filteredVideos as $video):
                    if (!is_array($video) || !isset($video['uid'])) { continue; }
                    $nameParts  = explode(' ', $video['meta']['name'] ?? '');
                    $dateString = implode(' ', array_slice($nameParts, -5));
                    try {
                        $date = new \DateTime($dateString, new \DateTimeZone('UTC'));
                        $date->setTimezone(new \DateTimeZone('MST'));
                        $formattedDate = $date->format('M d Y h:iA T');
                    } catch (\Exception $e) { $formattedDate = $dateString; }
                    $cf_customer  = get_option('HSCF_customer_id', '');
                    $videoUrl     = $cf_customer ? 'https://customer-' . $cf_customer . '.cloudflarestream.com/' . esc_attr($video['uid']) . '/watch' : '';
                    $dlName       = self::cfDownloadFilename($video['meta']['name'] ?? 'recording');
                    $downloadLink = isset($video['mp4_download_url']) ? self::withDownloadFilename($video['mp4_download_url'], $dlName) : '#';
                    $downloadTitle= isset($video['mp4_download_url']) ? 'Download' : 'Create download'; ?>
                    <div class="hscf-recording">
                        <a href="<?= esc_url($videoUrl) ?>" target="_blank" rel="noopener"><?= esc_html($formattedDate) ?></a>
                        <span class="hscf-recording__actions">
                            <a href="<?= esc_url($downloadLink) ?>" class="create-download-link hscf-icon-btn hscf-icon-download" data-video-id="<?= esc_attr($video['uid']) ?>" data-filename="<?= esc_attr($dlName) ?>" title="<?= esc_attr($downloadTitle) ?>"><span class="dashicons <?= isset($video['mp4_download_url']) ? 'dashicons-download' : 'dashicons-cloud' ?>"></span></a>
                            <a href="#" class="generate-videoid hscf-icon-btn hscf-icon-embed" data-video-id="<?= esc_attr($video['uid']) ?>" title="Copy Video ID (embed)"><span class="dashicons dashicons-editor-code"></span></a>
                            <a href="#" class="delete-video-link hscf-icon-btn hscf-icon-delete" data-video-id="<?= esc_attr($video['uid']) ?>" title="Delete recording"><span class="dashicons dashicons-trash"></span></a>
                        </span>
                    </div>
                <?php endforeach; else: ?><p class="description">No recordings for this stream.</p><?php endif; ?>
                </div>
            </div>
            <div class="hscf-stream__col hscf-stream__preview">
                <h4>Preview</h4>
                <?php
                $playerUrl = $player_base . '?live=true&inputId=' . $input->uid;
                // The pop-out opens with the debug panel on by default; the
                // embedded iframe above stays clean (no overlay in the small embed).
                $playerDebugUrl = $playerUrl . '&debug=1';
                ?>
                <?php // Click-to-load: the embedded player PRE-BUFFERS the stream the
                      // moment it loads (instant-start behavior), which Cloudflare
                      // counts as a live viewer — so an auto-loading preview inflated
                      // the viewer chip by +1 whenever this card was open. ?>
                <div class="hscf-preview-slot" data-src="<?= esc_url($playerUrl) ?>">
                    <button type="button" class="button hscf-preview-load"><span class="dashicons dashicons-visibility"></span> Load preview</button>
                    <span class="hscf-preview-note">Loading the preview connects to the stream (counts as a viewer)</span>
                </div>
                <a href="<?= esc_url($playerDebugUrl) ?>" target="_blank" rel="noopener" class="hscf-preview-pop" title="Open player in a new window (with debug panel)"><span class="dashicons dashicons-external"></span></a>
            </div>
        </div>
        <?php if (\HS\Config::liveuConfigured()): ?>
        <div class="hscf-liveu" data-input-id="<?= esc_attr($input->uid) ?>" data-input-name="<?= esc_attr($input_name) ?>">
            <?php // 3-column grid: identity + arm on the left, stats centred up
                  // top, LRT/refresh + the single Start-or-Stop control on the
                  // right. Kept deliberately structured (grid areas) so items
                  // sit in fixed slots rather than floating. ?>
            <div class="hscf-liveu__id">
                <span class="dashicons dashicons-video-alt3"></span>
                <strong>LiveU&nbsp;Solo</strong>
                <select class="hscf-liveu-unit" aria-label="Solo unit"><option value="">Loading units…</option></select>
                <span class="hscf-liveu-offline" title="This Solo unit is powered off or disconnected — turn it on to use the controls."><span class="dashicons dashicons-warning"></span> Unit offline</span>
            </div>
            <div class="hscf-liveu__stats">
                <span class="hscf-liveu-video hscf-liveu-video--off" title="Camera / video source feeding the encoder">—</span>
                <span class="hscf-liveu-battery" title="Battery">—</span>
                <div class="hscf-liveu-networks"></div>
            </div>
            <div class="hscf-liveu__ctl">
                <span class="hscf-liveu-lrtgroup">
                    <label class="hscf-toggle" title="LiveU Reliable Transport — bonded via <?= esc_attr(\HS\Config::liveuZone()) ?> when on, direct RTMP (no bonding) when off. Independent of arming a stream.">
                        <input type="checkbox" class="hscf-liveu-lrt" aria-label="LRT on/off">
                        <span class="hscf-toggle__track"><span class="hscf-toggle__thumb"></span></span>
                    </label>
                    <span class="hscf-liveu__zone" title="Current LRT state reported by the unit">LRT: <span class="hscf-liveu-zone-val">…</span></span>
                </span>
                <button type="button" class="button hscf-liveu-refresh" title="Refresh unit status"><span class="dashicons dashicons-update"></span></button>
            </div>
            <div class="hscf-liveu__arm">
                <button type="button" class="button button-primary hscf-liveu-set"><span class="dashicons dashicons-migrate"></span> Set as destination</button>
                <span class="hscf-liveu-verify hscf-liveu-verify--unknown">
                    <span class="dashicons dashicons-marker"></span><span class="hscf-liveu-verify__text">Not checked</span>
                </span>
            </div>
            <div class="hscf-liveu__go">
                <span class="hscf-liveu-streamstate hscf-liveu-streamstate--unknown">—</span>
                <?php // One state-aware control: "Start" when idle (gated on a
                      // passing verification), flips to "Stop" while streaming. ?>
                <button type="button" class="button hscf-liveu-toggle-stream hscf-liveu-toggle--start" data-mode="start" disabled title="Arm &amp; verify this stream first">
                    <span class="dashicons dashicons-controls-play"></span> Start
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
    <?php endif; ?>

    <div id="cfModal" class="cfmodal"><div class="cfmodalbox"><span class="close">&times;</span><div class="cfmodal-content"></div></div></div>
</div>
        <?php
    }

    // ── Helper methods ─────────────────────────────────────────

    private static function getLiveInputs(): array|string {
        try {
            $svc = new \HS\Services\LiveInputService();
            $inputs = $svc->listWithDetails();
        } catch (ConfigError $e) {
            return 'not_configured';
        } catch (\Throwable $e) {
            return 'Cloudflare request failed: ' . $e->getMessage();
        }
        return is_array($inputs) ? $inputs : (string) $inputs;
    }

    private static function listCloudflareVideos(): array {
        try {
            return (new \HS\Services\RecordingsService())->listAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function getLiveInputOutputs(string $input_id): array|string {
        try {
            $svc = new \HS\Services\LiveInputService();
            return $svc->getOutputs($input_id);
        } catch (\Throwable $e) {
            return 'Unable to load outputs.';
        }
    }

    /**
     * Social-stream providers: key => [label, ingest URL, accent color].
     * The keys are the contract with OutputMeta + the JS. RTMP is the custom
     * catch-all (no preset URL).
     */
    public const OUTPUT_PROVIDERS = [
        'youtube'  => ['YouTube',  'rtmp://a.rtmp.youtube.com/live2',          '#ff0000'],
        'facebook' => ['Facebook', 'rtmps://live-api-s.facebook.com:443/rtmp/', '#1877f2'],
        'rtmp'     => ['RTMP',     '',                                          '#2271b1'],
    ];

    /** Inline brand/RTMP icon (currentColor) for a provider key. */
    public static function providerIcon(string $provider): string {
        $icons = [
            'youtube'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M23 7.5a3 3 0 0 0-2.1-2.1C19 5 12 5 12 5s-7 0-8.9.4A3 3 0 0 0 1 7.5 31 31 0 0 0 .6 12 31 31 0 0 0 1 16.5a3 3 0 0 0 2.1 2.1C5 19 12 19 12 19s7 0 8.9-.4a3 3 0 0 0 2.1-2.1A31 31 0 0 0 23.4 12 31 31 0 0 0 23 7.5zM9.8 15.3V8.7l5.7 3.3z"/></svg>',
            'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.8 3.8-3.8 1.1 0 2.2.2 2.2.2v2.4h-1.2c-1.2 0-1.6.8-1.6 1.6V12h2.7l-.4 2.9h-2.3v7A10 10 0 0 0 22 12z"/></svg>',
            'rtmp'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM8.5 8.5 7.1 7.1a7 7 0 0 0 0 9.8l1.4-1.4a5 5 0 0 1 0-7zm7 0a5 5 0 0 1 0 7l1.4 1.4a7 7 0 0 0 0-9.8zM5.6 5.6 4.2 4.2a11 11 0 0 0 0 15.6l1.4-1.4a9 9 0 0 1 0-12.8zm12.8 0a9 9 0 0 1 0 12.8l1.4 1.4a11 11 0 0 0 0-15.6z"/></svg>',
        ];
        return $icons[$provider] ?? $icons['rtmp'];
    }

    /**
     * Render one social-stream output row (icon + name + toggle + edit + delete).
     * Single source of truth — used by the initial page render AND the AJAX
     * create/update responses. $output is the Cloudflare output array.
     */
    public static function renderOutputRow(array $output, string $input_id): string {
        $uid     = (string) ($output['uid'] ?? '');
        $url     = (string) ($output['url'] ?? '');
        $key     = (string) ($output['streamKey'] ?? '');
        $enabled = !empty($output['enabled']);

        $meta     = \HS\OutputMeta::get($uid);
        $provider = $meta['provider'] ?? \HS\OutputMeta::providerFromUrl($url);
        $label    = self::OUTPUT_PROVIDERS[$provider][0] ?? 'RTMP';
        $name     = $meta['name'] ?? '';
        if ($name === '') { $name = $label !== '' ? $label : ($url ?: 'Output'); }

        ob_start(); ?>
        <div class="hscf-output" data-output-id="<?= esc_attr($uid) ?>" data-input-id="<?= esc_attr($input_id) ?>"
             data-provider="<?= esc_attr($provider) ?>" data-name="<?= esc_attr($name) ?>"
             data-url="<?= esc_attr($url) ?>" data-stream-key="<?= esc_attr($key) ?>"
             data-enabled="<?= $enabled ? '1' : '0' ?>">
            <span class="hscf-output__icon hscf-provider-<?= esc_attr($provider) ?>"><?= self::providerIcon($provider) ?></span>
            <span class="hscf-output__name"><?= esc_html($name) ?></span>
            <span class="hscf-output__actions">
                <label class="switch" title="Enable / disable"><input type="checkbox" class="output-toggle" data-output-id="<?= esc_attr($uid) ?>" data-input-id="<?= esc_attr($input_id) ?>" <?= $enabled ? 'checked' : '' ?>><span class="slider round"></span></label>
                <a href="#" class="edit-output-link hscf-icon-btn" data-output-id="<?= esc_attr($uid) ?>" data-input-id="<?= esc_attr($input_id) ?>" title="Edit output"><span class="dashicons dashicons-edit"></span></a>
                <a href="#" class="delete-output-link hscf-icon-btn" data-output-id="<?= esc_attr($uid) ?>" data-input-id="<?= esc_attr($input_id) ?>" title="Delete output"><span class="dashicons dashicons-trash"></span></a>
            </span>
        </div>
        <?php
        return trim(ob_get_clean());
    }

    private static function getVideosByStreamName(string $stream_name): array {
        try {
            $svc = new \HS\Services\RecordingsService();
            return $svc->findByStreamName($stream_name);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Sanitize a video name into a Cloudflare ?filename= value. Cloudflare only
     * allows [A-Za-z0-9-_], max 120 chars, and appends .mp4 itself — so without
     * this, downloads save as the generic "default.mp4".
     */
    private static function cfDownloadFilename(string $name): string {
        $name = preg_replace('/\.(mp4|mov)$/i', '', trim($name)); // drop any extension
        $name = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $name);   // collapse disallowed runs to "-"
        $name = trim($name, '-_');
        $name = substr($name, 0, 120);
        return $name !== '' ? $name : 'video';
    }

    /** Append ?filename= to a Cloudflare download URL so the file saves with a real name. */
    private static function withDownloadFilename(string $url, string $filename): string {
        if ($url === '' || $url === '#') {
            return $url;
        }
        $sep = strpos($url, '?') !== false ? '&' : '?';
        return $url . $sep . 'filename=' . rawurlencode($filename);
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
