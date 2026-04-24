<?php
/**
 * Live-state polling endpoint for HitchStream Player.
 *
 * Replaces the Cloudflare /lifecycle CORS endpoint. The player polls this
 * every 10 seconds to discover live stream status. Data comes from webhook-
 * updated WordPress transients, with a one-time Cloudflare API fallback when
 * the transient is expired.
 *
 * URL: GET ?inputId={live_input_id}
 */

// Load WordPress.
require_once __DIR__ . '/../../../../wp-load.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// --- Input validation ---

$input_id = isset($_GET['inputId']) ? trim($_GET['inputId']) : '';

if (!$input_id || !preg_match('/^[A-Za-z0-9_-]+$/', $input_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing inputId']);
    exit;
}

// --- Get webhook-cached state ---

$transient_key = "hs_live_state_{$input_id}";
$cached = get_transient($transient_key);

// --- Helper: one-time Cloudflare API probe ---
// Used when transient is expired to avoid showing "Waiting" for a live stream.

$probe_cloudflare = function($input_id) {
    $email    = get_option('HSCF_cloudflare_email', '');
    $api_key  = get_option('HSCF_cloudflare_api_key', '');
    $account  = get_option('HSCF_cloudflare_account_id', '');

    if (!$email || !$api_key || !$account) {
        return null;
    }

    $url = "https://api.cloudflare.com/client/v4/accounts/{$account}/stream/live_inputs/{$input_id}/videos";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Auth-Email: {$email}",
        "X-Auth-Key: {$api_key}",
        "Content-Type: application/json",
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$resp) {
        return null;
    }

    $data = json_decode($resp, true);
    if (!isset($data['result']) || !is_array($data['result'])) {
        return null;
    }

    // First live-inprogress video.
    foreach ($data['result'] as $video) {
        $state = $video['status']['state'] ?? '';
        if ($state === 'live-inprogress') {
            return [
                'state'   => 'live',
                'videoUID' => $video['uid'],
                'hls'     => $video['playback']['hls'] ?? '',
            ];
        }
    }

    // No live video found.
    return [
        'state'   => 'idle',
        'videoUID' => null,
        'hls'     => null,
    ];
};

// --- Build response ---

if ($cached && is_array($cached)) {
    // Transient is fresh — serve from cache.
    $state       = $cached['state'];
    $video_uid   = $cached['videoUID'] ?? '';
    $error_code  = $cached['error_code'] ?? null;

    $live = in_array($state, ['live', 'reconnected', 'new_configuration_accepted']);
    $hls_url = '';
    if ($video_uid) {
        $customer_id = get_option('HSCF_customer_id', 'juu1r5es4cbffqjf');
        $hls_url = "https://customer-{$customer_id}.cloudflarestream.com/{$video_uid}/manifest/video.m3u8";
    }

    echo json_encode([
        'live'      => $live,
        'state'     => $state,
        'videoUID'  => $video_uid ?: null,
        'hlsUrl'    => $hls_url ?: null,
        'error_code' => $error_code ?: null,
        'source'    => 'webhook',
        'ts'        => $cached['ts'],
    ]);
    exit;
}

// Transient expired or missing — do one-time Cloudflare probe.
$probe = $probe_cloudflare($input_id);

if ($probe) {
    // Cache the result for future polls (prevents repeated API calls).
    $ttl = ($probe['state'] === 'live') ? 300 : 60;
    set_transient($transient_key, [
        'state'    => $probe['state'],
        'videoUID' => $probe['videoUID'] ?? '',
        'error_code' => null,
        'ts'       => time(),
    ], $ttl);

    $hls_url = $probe['hls'] ?? '';
    if ($probe['videoUID'] && !$hls_url) {
        $customer_id = get_option('HSCF_customer_id', 'juu1r5es4cbffqjf');
        $hls_url = "https://customer-{$customer_id}.cloudflarestream.com/{$probe['videoUID']}/manifest/video.m3u8";
    }

    echo json_encode([
        'live'      => $probe['state'] === 'live',
        'state'     => $probe['state'],
        'videoUID'  => $probe['videoUID'] ?: null,
        'hlsUrl'    => $hls_url ?: null,
        'error_code' => null,
        'source'    => 'cf_probe',
        'ts'        => time(),
    ]);
} else {
    // Probe failed — nothing is live (or we can't determine it).
    echo json_encode([
        'live'      => false,
        'state'     => 'idle',
        'videoUID'  => null,
        'hlsUrl'    => null,
        'error_code' => null,
        'source'    => 'no_data',
        'ts'        => time(),
    ]);
}
exit;
