<?php
// Cloudflare Stream proxy endpoint (validated and server-side only)

// Bootstrap WordPress APIs (get_option, wp_verify_nonce)
require_once __DIR__ . '/../../../../wp-load.php';

// Respond with JSON
header('Content-Type: application/json; charset=utf-8');

// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read inputs
$nonce = isset($_POST['hs_player_nonce']) ? $_POST['hs_player_nonce'] : '';
$inputId = isset($_POST['inputId']) ? trim($_POST['inputId']) : '';

// Validate nonce
if (!$nonce || !wp_verify_nonce($nonce, 'hs_player_action')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing nonce']);
    exit;
}

// Validate capability
if (!current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['error' => 'insufficient permissions']);
    exit;
}

// Validate input ID
if (!$inputId || !preg_match('/^[A-Za-z0-9_-]+$/', $inputId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid inputId']);
    exit;
}

// Load Cloudflare credentials from WP options
$cloudflareEmail = get_option('HSCF_cloudflare_email', '');
$cloudflareApiKey = get_option('HSCF_cloudflare_api_key', '');
$cloudflareAccountId = get_option('HSCF_cloudflare_account_id', '');

if (!$cloudflareEmail || !$cloudflareApiKey || !$cloudflareAccountId) {
    http_response_code(500);
    echo json_encode(['error' => 'Cloudflare credentials not configured']);
    exit;
}

// Optional action switch (default: pass-through list of videos)
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

// Helper to call CF API
$call_cf = function(string $url) use ($cloudflareEmail, $cloudflareApiKey) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Auth-Email: {$cloudflareEmail}",
        "X-Auth-Key: {$cloudflareApiKey}",
        "Content-Type: application/json"
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$resp, $code, $err];
};

$url = "https://api.cloudflare.com/client/v4/accounts/{$cloudflareAccountId}/stream/live_inputs/{$inputId}/videos";

if ($action === 'live_state') {
    [$response, $httpCode, $curlErr] = $call_cf($url);
    if ($response === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Upstream request failed', 'details' => $curlErr]);
        exit;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code(502);
        $body = json_decode($response, true);
        echo json_encode(['error' => 'Cloudflare API error', 'status' => $httpCode, 'body' => $body]);
        exit;
    }

    $body = json_decode($response, true);
    $ingestLive = false;
    $playbackUrl = '';
    if (is_array($body) && isset($body['result']) && is_array($body['result'])) {
        foreach ($body['result'] as $video) {
            $state = $video['status']['state'] ?? '';
            if ($state === 'live-inprogress') {
                $ingestLive = true;
                $playbackUrl = $video['playback']['hls'] ?? '';
                break;
            }
        }
    }

    // Server-side probe of playlist readiness
    $playlistReady = false;
    $targetDuration = null;
    $mediaSequence = null;
    if ($ingestLive && $playbackUrl) {
        $ch2 = curl_init($playbackUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        $pl = curl_exec($ch2);
        $plCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($pl !== false && $plCode >= 200 && $plCode < 300 && strpos($pl, '#EXTM3U') !== false) {
            $playlistReady = true;
            if (preg_match('/#EXT-X-TARGETDURATION:(\d+)/', $pl, $m)) $targetDuration = intval($m[1]);
            if (preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $pl, $m2)) $mediaSequence = intval($m2[1]);
        }
    }

    echo json_encode([
        'ingest_live'    => $ingestLive,
        'hls_url'        => $playbackUrl,
        'playlist_ready' => $playlistReady,
        'targetduration' => $targetDuration,
        'media_sequence' => $mediaSequence,
        'state'          => $ingestLive ? ($playlistReady ? 'PLAYING' : 'PREPARING') : 'IDLE',
        'source'         => 'cf_api+server_probe'
    ]);
    exit;
}

// Reject unhandled actions — the dangerous default pass-through has been removed.
http_response_code(400);
echo json_encode(['error' => 'Unknown or unsupported action']);
exit;

?>
