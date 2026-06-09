<?php
/**
 * Cloudflare Live Webhook receiver for HitchStream Player.
 *
 * Receives live_input.connected, live_input.disconnected, and live_input.errored
 * events from Cloudflare, validates the signature, maps to normalized states,
 * and stores them in WordPress transients for the player to poll.
 *
 * Install: Register this URL as a webhook callback in Cloudflare Dash or via API.
 * Config: Set HSCF_webhook_secret in WordPress options for signature validation.
 */

// Load WordPress so we can use get_option/set_transient.
require_once __DIR__ . '/../../../../wp-load.php';

header('Content-Type: application/json; charset=utf-8');

// --- Helpers ---

/**
 * Install the webhook log table if it doesn't exist.
 * Call from plugin activation / settings page.
 */
function hs_install_webhook_log_table() {
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
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Insert a row into the webhook log table.
 */
function hs_webhook_log_insert($row) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'hs_webhook_log', $row, [
        '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s',
    ]);
}

/**
 * Trim webhook log rows older than 30 days.
 * Called via wp-cron weekly.
 */
function hs_trim_webhook_log() {
    global $wpdb;
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $wpdb->delete($wpdb->prefix . 'hs_webhook_log', [
        'received_at <=' => $cutoff,
    ], ['%s']);
}

/**
 * Schedule/unschedule the weekly trim job.
 */
function hs_schedule_webhook_log_trim() {
    if (!wp_next_scheduled('hs_webhook_log_trim')) {
        wp_schedule_event(time(), 'weekly', 'hs_webhook_log_trim');
    }
}

function hs_unschedule_webhook_log_trim() {
    $timestamp = wp_next_scheduled('hs_webhook_log_trim');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'hs_webhook_log_trim');
    }
}

/**
 * Get the webhook shared secret from WP options.
 * Returns empty string if not configured.
 */
function hs_webhook_secret() {
    return get_option('HSCF_webhook_secret', '');
}

/**
 * Verify Cloudflare Notifications shared-secret token.
 * Returns true if valid; rejects (returns false) if the secret is not configured
 * or the token does not match.
 */
function hs_verify_webhook($auth) {
    $secret = hs_webhook_secret();

    // No secret configured — reject and alert loudly.
    if (!$secret) {
        error_log('[HitchStream] CRITICAL: Webhook secret not configured. Rejecting webhook to prevent unauthorized state manipulation.');
        return false;
    }

    if (!$auth) {
        error_log('[HitchStream] Webhook received without cf-webhook-auth header.');
        return false;
    }

    return hash_equals($secret, $auth);
}

/**
 * Map Cloudflare event_type + state to a normalized state string.
 */
function hs_normalize_state($event_type, $state) {
    if ($event_type === 'live_input.connected') {
        return 'live';
    }

    if ($event_type === 'live_input.disconnected') {
        // client_disconnect and ttl_exceeded are both graceful disconnects.
        return 'idle';
    }

    if ($event_type === 'live_input.errored') {
        return 'error';
    }

    // For direct state events from the live input status field.
    switch ($state) {
        case 'connected':
        case 'reconnected':
        case 'new_configuration_accepted':
            return 'live';
        case 'reconnecting':
            return 'reconnecting';
        case 'client_disconnect':
        case 'ttl_exceeded':
            return 'idle';
        case 'failed_to_connect':
        case 'failed_to_reconnect':
            return 'error';
        default:
            return null;
    }
}

/**
 * Get the TTL for a given normalized state.
 */
function hs_state_ttl($state) {
    switch ($state) {
        case 'reconnecting':
            return 120;
        case 'error':
            return 3600;
        default:
            return 300;
    }
}

// --- B5.6: Email alerts for notable live-stream events ---

/**
 * Plain-English alert catalog: key => [label, description].
 * Keys MUST match SettingsPage::ALERT_EVENTS in the HitchStream_Cloudflare
 * plugin (that's where the admin checkboxes are defined). Keep them in sync.
 */
function hs_alert_events_catalog(): array {
    return [
        'storage_full'        => ['Storage Full', 'Cloudflare Stream storage is full — recordings and live streams may fail until space is freed or the plan is upgraded.'],
        'no_subscription'     => ['No Cloudflare Subscription', 'The Cloudflare Stream subscription is missing or inactive. Streaming will not work until it is restored.'],
        'stream_error'        => ['Stream Error', 'A live input reported an error (failed to connect or reconnect).'],
        'stream_started'      => ['Stream Started', 'A streamer connected and the live stream is now active.'],
        'stream_ended'        => ['Stream Ended', 'The streamer disconnected and the live stream has ended.'],
        'stream_reconnecting' => ['Streamer Reconnecting', 'The streamer connection dropped and is attempting to reconnect.'],
    ];
}

/**
 * Which alert events are enabled (array of keys). Reads HSCF_alert_events; falls
 * back to migrating the legacy HSCF_alert_codes CSV, else the two critical defaults.
 */
function hs_alert_enabled_events(): array {
    $val = get_option('HSCF_alert_events', null);
    if (is_array($val)) {
        return $val;
    }
    $legacy = get_option('HSCF_alert_codes', null);
    if (is_string($legacy) && $legacy !== '') {
        $events = [];
        if (strpos($legacy, 'ERR_STORAGE_QUOTA_EXHAUSTED') !== false) $events[] = 'storage_full';
        if (strpos($legacy, 'ERR_MISSING_SUBSCRIPTION') !== false)    $events[] = 'no_subscription';
        return $events ?: ['storage_full', 'no_subscription'];
    }
    return ['storage_full', 'no_subscription'];
}

/**
 * Map a webhook outcome to a single alert event key (or '' for none).
 * $prev_state is the state stored BEFORE this event, so start/end/reconnect only
 * fire on an actual transition rather than on every repeated webhook.
 */
function hs_alert_key_for(?string $normalized, string $error_code, string $prev_state): string {
    if ($error_code === 'ERR_STORAGE_QUOTA_EXHAUSTED') return 'storage_full';
    if ($error_code === 'ERR_MISSING_SUBSCRIPTION')    return 'no_subscription';
    if ($normalized === 'error')                       return 'stream_error';
    if ($normalized === 'live' && $prev_state !== 'live')                          return 'stream_started';
    if ($normalized === 'idle' && in_array($prev_state, ['live', 'reconnecting'], true)) return 'stream_ended';
    if ($normalized === 'reconnecting' && $prev_state !== 'reconnecting')          return 'stream_reconnecting';
    return '';
}

/**
 * Send an alert email for a notable event, if it's enabled and not throttled.
 * Uses wp_mail(), so it routes through whatever mailer is active (WPO365 / M365).
 * Throttle: at most one email per event per input per 5 minutes.
 */
function hs_dispatch_alert(string $event_key, string $input_id, string $event_type, string $error_code, string $correlation_id): void {
    if ($event_key === '') {
        return;
    }

    $alert_email = get_option('HSCF_alert_email', '');
    if (!$alert_email || !is_email($alert_email)) {
        return;
    }

    if (!in_array($event_key, hs_alert_enabled_events(), true)) {
        return;
    }

    $catalog = hs_alert_events_catalog();
    if (!isset($catalog[$event_key])) {
        return;
    }
    [$label, $desc] = $catalog[$event_key];

    $throttle_key = "hs_alert_throttle_{$event_key}_{$input_id}";
    if (get_transient($throttle_key)) {
        return;
    }
    set_transient($throttle_key, true, 300);

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = "[HitchStream] {$label}";
    $lines = [
        $desc,
        '',
        "Stream input: {$input_id}",
        'Time: ' . current_time('mysql'),
    ];
    if ($error_code) {
        $lines[] = "Error code: {$error_code}";
    }
    $lines[] = "Event: {$event_type}";
    $lines[] = "Reference: {$correlation_id}";
    $lines[] = '';
    $lines[] = "Site: {$site}";
    $lines[] = 'See the Activity page in WP Admin for full details.';
    $body = implode("\n", $lines) . "\n";

    $sent = wp_mail($alert_email, $subject, $body, ['Content-Type: text/plain; charset=utf-8']);
    error_log(
        '[HitchStream] alert email ' . ($sent ? 'sent' : 'FAILED')
        . " to {$alert_email} for {$event_key} on {$input_id} (corr: {$correlation_id})"
    );
}
// --- Main ---

// Read the raw POST body for signature verification.
$body = file_get_contents('php://input');
$parsed = json_decode($body, true);

if (!$parsed) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Extract fields from the webhook payload.
$event_type = $parsed['data']['event_type'] ?? '';
$input_id   = $parsed['data']['input_id'] ?? '';
$error_code = '';

if (isset($parsed['live_input_errored']['error']['code'])) {
    $error_code = $parsed['live_input_errored']['error']['code'];
}

// Extract state from nested data (only present in live_input_errored events).
$state = $parsed['live_input_errored']['state'] ?? null;

// Validate signature. Cloudflare Notifications sends the shared secret
// verbatim in the cf-webhook-auth header.
$auth = $_SERVER['HTTP_CF_WEBHOOK_AUTH'] ?? '';
if (!hs_verify_webhook($auth)) {
    // Log failed signature to webhook log table
    $log_data = [
        'received_at'   => current_time('mysql'),
        'input_id'      => $input_id,
        'event_type'    => $event_type,
        'raw_body_hash' => hash('sha256', $body),
        'normalized_state' => null,
        'error_code'    => $error_code,
        'signature_ok'  => 0,
        'processed'     => 0,
        'correlation_id'=> '',
    ];
    hs_webhook_log_insert($log_data);
    http_response_code(403);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

// Detect test pings (no data.event_type — sent by Cloudflare Notifications "Save and Test")
$is_test_ping = (empty($event_type) && empty($input_id));
if ($is_test_ping) {
    hs_webhook_log_insert([
        'received_at'   => current_time('mysql'),
        'input_id'      => '',
        'event_type'    => 'notifications.test',
        'raw_body_hash' => hash('sha256', $body),
        'normalized_state' => null,
        'error_code'    => null,
        'signature_ok'  => 1,
        'processed'     => 0,
        'correlation_id'=> '',
    ]);
    http_response_code(200);
    echo json_encode(['received' => true, 'test' => true]);
    exit;
}

// Validate we have required fields.
if (!$event_type || !$input_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_type or input_id']);
    exit;
}

// Coalesced debounce (B1.4): drop duplicates within 60s, coalesce within 3s window.
function hs_should_process_coalesced($input_id, $body) {
    global $parsed;
    $idempotency_key = "hs_webhook_idem_{$input_id}";
    $last = get_transient($idempotency_key);
    if ($last) {
        $last_ts = $last['ts'] ?? 0;
        $last_event = $last['event'] ?? '';
        $last_body = $last['body'] ?? '';
        // Drop exact duplicates within 60s
        if ((time() - $last_ts) < 60 && $last_event === ($parsed['data']['event_type'] ?? '') && $last_body === $body) {
            return ['allow' => false];
        }
    }
    // Coalesce: accept if >3s since last event of any type
    $coalesce_key = "hs_webhook_coalesce_{$input_id}";
    $last_coalesce = get_transient($coalesce_key);
    if ($last_coalesce && (time() - ($last_coalesce['ts'] ?? 0)) < 3) {
        // Return coalesced; read path should use the existing state
        return ['allow' => false, 'coalesced' => true, 'ts' => $last_coalesce['ts']];
    }
    set_transient($idempotency_key, ['ts' => time(), 'event' => ($parsed['data']['event_type'] ?? ''), 'body' => $body], 60);
    set_transient($coalesce_key, ['ts' => time()], 5);
    return ['allow' => true];
}

// Normalize.
$normalized = hs_normalize_state($event_type, $state);
if (!$normalized) {
    error_log("[HitchStream] Unrecognized webhook event: {$event_type} state: " . json_encode($state));
    // Log unknown event but don't fail the webhook
    echo json_encode(['status' => 'unknown_event']);
    exit;
}

// B1.4: Coalesced debounce check
$coalesce_result = hs_should_process_coalesced($input_id, $body);
if (!$coalesce_result['allow']) {
    if (isset($coalesce_result['coalesced']) && $coalesce_result['coalesced']) {
        // Coalesced: return existing state with carried ts
        $existing = get_transient("hs_live_state_{$input_id}");
        if ($existing) {
            $existing['ts'] = $coalesce_result['ts'];
            echo json_encode(['status' => 'coalesced', 'data' => $existing]);
            exit;
        }
    }
    echo json_encode(['status' => 'debounced']);
    exit;
}

// Cloudflare webhook payload does not include videoUID. Fetch it from /lifecycle.
$video_uid = '';
$hls_url = null;
$lifecycle_failed = false;

if ($normalized === 'live') {
    // B1.3: fetch videoUID from lifecycle endpoint
    $customer_code = get_option('HSCF_customer_id', '');
    if ($customer_code) {
        $lifecycle_url = "https://customer-{$customer_code}.cloudflarestream.com/{$input_id}/lifecycle";
        $ch = curl_init($lifecycle_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
            $lifecycle_data = json_decode($resp, true);
            if (is_array($lifecycle_data) && isset($lifecycle_data['videoUID'])) {
                $video_uid = $lifecycle_data['videoUID'] ?: '';
                $hls_url = "https://customer-{$customer_code}.cloudflarestream.com/{$lifecycle_data['videoUID']}/manifest/video.m3u8";
            }
            // B1.3a: on /lifecycle failure, do NOT update the transient.
            // Prior value is preserved (stale but valid). The next webhook or
            // next viewer cache miss on the read path will re-attempt.
        } else {
            $lifecycle_failed = true;
            error_log("[HitchStream] /lifecycle failed: input={$input_id} http={$httpCode} err=" . ($curlErr ?: 'none') . " — NOT updating transient per B1.3a");
        }
    }
}

// On disconnect: explicitly null videoUID so stale "connected" state doesn't leak.
if ($normalized === 'idle' && ($event_type === 'live_input.disconnected' || in_array($state, ['client_disconnect', 'ttl_exceeded'], true))) {
    $video_uid = null;
    $hls_url = null;
}

// B1.3a: If /lifecycle failed for a live event, do NOT update the transient.
// Preserve stale-but-valid prior state. Log the failure; the next webhook or
// next viewer cache miss will re-attempt.
if ($normalized === 'live' && $lifecycle_failed) {
    // Write log row even though we don't update state
    $log_data = [
        'received_at'   => current_time('mysql'),
        'input_id'      => $input_id,
        'event_type'    => $event_type,
        'raw_body_hash' => hash('sha256', $body),
        'normalized_state' => $normalized,
        'error_code'    => $error_code,
        'signature_ok'  => 1,
        'processed'     => 0,
        'correlation_id'=> '',
    ];
    hs_webhook_log_insert($log_data);
    error_log("[HitchStream] B1.3a: /lifecycle failed for live input={$input_id} — NOT updating transient. Prior state preserved.");
    echo json_encode(['status' => 'ok', 'normalized_state' => $normalized, 'lifecycle_failed' => true]);
    exit;
}

// Capture the prior state (before we overwrite it) so Stream Started/Ended
// alerts only fire on an actual transition, not on every repeated webhook.
$prev = get_transient("hs_live_state_{$input_id}");
$prev_state = is_array($prev) ? (string) ($prev['state'] ?? '') : '';

// Normal path: update transient.
$ttl = hs_state_ttl($normalized);
$correlation_id = wp_generate_uuid4();
$now_ts = time();
$data = [
    'state'      => $normalized,
    'videoUID'   => $video_uid,
    'hlsUrl'     => $hls_url,
    'errorCode'  => $error_code ?: null,
    'source'     => 'webhook',
    'ts'         => $now_ts,
];

set_transient("hs_live_state_{$input_id}", $data, $ttl);
set_transient("hs_webhook_update_ts_{$input_id}", $now_ts, 5);

// B2.2a: Also write flat-file for the lightweight read path.
if (class_exists('HS\LiveState\StateWriter')) {
    \HS\LiveState\StateWriter::write($input_id, $data, $ttl);
}

// B1.5: Write log row
$log_data = [
    'received_at'   => current_time('mysql'),
    'input_id'      => $input_id,
    'event_type'    => $event_type,
    'raw_body_hash' => hash('sha256', $body),
    'normalized_state' => $normalized,
    'error_code'    => $error_code,
    'signature_ok'  => 1,
    'processed'     => 1,
    'correlation_id'=> $correlation_id,
];
hs_webhook_log_insert($log_data);

// B5.6: Email alerts for notable events (errors + state transitions).
hs_dispatch_alert(
    hs_alert_key_for($normalized, (string) $error_code, $prev_state),
    $input_id, $event_type, (string) $error_code, $correlation_id
);
// Log for debugging.
error_log("[HitchStream] Webhook: input={$input_id} event={$event_type} state={$normalized} videoUID={$video_uid} corr={$correlation_id}");

echo json_encode(['status' => 'ok', 'normalized_state' => $normalized]);
exit;
