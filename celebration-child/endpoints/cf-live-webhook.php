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
 * Get the webhook shared secret from WP options.
 * Returns empty string if not configured.
 */
function hs_webhook_secret() {
    return get_option('HSCF_webhook_secret', '');
}

/**
 * Verify HMAC-SHA256 signature from Cloudflare.
 * Returns true if valid or if secret is not yet configured (with a warning).
 */
function hs_verify_webhook($body, $signature) {
    $secret = hs_webhook_secret();

    // Allow a grace period: if no secret is configured, accept but log a warning.
    if (!$secret) {
        error_log('[HitchStream] Webhook signature validation skipped — HSCF_webhook_secret not configured.');
        return true;
    }

    if (!$signature) {
        error_log('[HitchStream] Webhook received without signature header.');
        return false;
    }

    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, $signature);
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

/**
 * Debounce: only process if last update for this input was > 3 seconds ago.
 */
function hs_should_process($input_id) {
    $last_update = get_transient("hs_webhook_update_ts_{$input_id}");
    if ($last_update && (time() - $last_update) < 3) {
        return false;
    }
    return true;
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

// Extract state from nested data if present.
$state = $parsed['data']['state'] ?? null;
if (isset($parsed['live_input_errored']['state'])) {
    $state = $parsed['live_input_errored']['state'];
}

// Validate signature.
$signature = $_SERVER['HTTP_CF_WEBHOOK_SIGNATURE'] ?? '';
if (!hs_verify_webhook($body, $signature)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

// Validate we have required fields.
if (!$event_type || !$input_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_type or input_id']);
    exit;
}

// Debounce.
if (!hs_should_process($input_id)) {
    echo json_encode(['status' => 'debounced']);
    exit;
}

// Normalize.
$normalized = hs_normalize_state($event_type, $state);
if (!$normalized) {
    error_log("[HitchStream] Unrecognized webhook event: {$event_type} state: " . json_encode($state));
    echo json_encode(['status' => 'unknown_event']);
    exit;
}

// Get videoUID if present.
$video_uid = $parsed['data']['video_uid'] ?? $parsed['data']['videoUID'] ?? '';

// Store in transient.
$ttl = hs_state_ttl($normalized);
$data = [
    'state'      => $normalized,
    'videoUID'   => $video_uid,
    'error_code' => $error_code,
    'event_type' => $event_type,
    'ts'         => time(),
];

set_transient("hs_live_state_{$input_id}", $data, $ttl);
set_transient("hs_webhook_update_ts_{$input_id}", time(), 5);

// Log for debugging.
error_log("[HitchStream] Webhook: input={$input_id} event={$event_type} state={$normalized} videoUID={$video_uid}");

echo json_encode(['status' => 'ok', 'normalized_state' => $normalized]);
exit;
