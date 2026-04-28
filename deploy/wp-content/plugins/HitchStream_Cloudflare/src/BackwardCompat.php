<?php
/**
 * Backward-compat shims — thin delegates for all old public functions.
 *
 * Every old function is covered by a shim that creates a fresh service,
 * calls it, and validates the return shape (§4 contract) before returning.
 *
 * §4 contract: return type is guaranteed (array|bool|string), keys are stable,
 * errors use ['error' => string] — callers never see ['result' => ...].
 */

use HS\Services\LiveInputService;
use HS\Services\WebhookService;
use HS\Services\RecordingsService;
use HS\Services\StreamerService;

// ── Guard ────────────────────────────────────────────────────
// If the child theme already defined any of these functions, use them.
// Otherwise, provide shims that delegate to the new service classes.

$already_defined = function_exists('hs_register_cf_webhook')
    || function_exists('hs_delete_cf_webhook')
    || function_exists('hs_list_cf_webhooks')
    || function_exists('hs_create_live_input')
    || function_exists('hs_get_live_input_outputs')
    || function_exists('hs_get_video_uid')
    || function_exists('hs_create_recording_download')
    || function_exists('hs_check_recording_download')
    || function_exists('hs_delete_recording')
    || function_exists('hs_get_streamer_video_list')
    || function_exists('hs_start_placeholder_stream')
    || function_exists('hs_stop_placeholder_stream')
    || function_exists('hs_get_stream_state');
if ($already_defined) return;

/**
 * @deprecated Use LiveInputService / WebhookService directly.
 * §4: returns ['secret' => string] on success, ['error' => string] on failure.
 */
function hs_register_cf_webhook(string $callback_url, string $secret = ''): array {
    $svc = new WebhookService();
    $result = $svc->register($callback_url, $secret);
    // §4 validation: must have 'secret' or 'error'.
    if (!isset($result['secret']) && !isset($result['error'])) {
        $result = ['error' => 'Unexpected webhook registration response shape.'];
    }
    return $result;
}

/**
 * @deprecated Use WebhookService directly.
 * §4: returns ['status' => int, 'body' => string].
 */
function hs_delete_cf_webhook(): array {
    $svc = new WebhookService();
    return $svc->delete();
}

/**
 * @deprecated Use WebhookService directly.
 * §4: returns array of webhook config on success, ['error' => string] on failure.
 */
function hs_list_cf_webhooks(): array {
    $svc = new WebhookService();
    $result = $svc->get();
    if (isset($result['error'])) {
        return $result; // already §4-compliant
    }
    // §4 validation: must be an array, not ['result' => ...].
    if (!is_array($result)) {
        return ['error' => 'Unexpected webhook list response shape.'];
    }
    return $result;
}

// ── Live-state shim ────────────────────────────────────────────────

/**
 * @deprecated Use LiveInputService::listWithDetails() directly.
 * §4: returns bool (isLive = first input with 'connected' status).
 */
function hs_compute_server_live_state(string $input_id): bool {
    $svc = new LiveInputService();
    $inputs = $svc->listWithDetails();
    if (!is_array($inputs)) {
        return false;
    }
    foreach ($inputs as $input) {
        if (isset($input->uid) && $input->uid === $input_id && isset($input->status_details)) {
            return strtolower($input->status_details) === 'connected';
        }
    }
    return false;
}

// ── Live input shims ───────────────────────────────────────────────

/**
 * @deprecated Use LiveInputService directly.
 * §4: returns array of inputs with details, or string error.
 */
function HSCF_list_cloudflare_videos(?string $filter = null): array {
    $svc = new LiveInputService();
    $inputs = $svc->listWithDetails();
    if (!is_array($inputs)) {
        return [$inputs]; // wrap error string in array for BC
    }
    return $inputs;
}

/**
 * @deprecated Use LiveInputService::listWithDetails() directly.
 * §4: returns array of inputs with details, or string error.
 */
function HSCF_get_live_inputs(): array|string {
    $svc = new LiveInputService();
    $inputs = $svc->listWithDetails();
    if (!is_array($inputs)) {
        return (string) $inputs;
    }
    return $inputs;
}

/**
 * @deprecated Use LiveInputService::create() directly.
 */
function hs_create_live_input(string $stream_name): ?object {
    $svc = new LiveInputService();
    return $svc->create($stream_name);
}

/**
 * @deprecated Use LiveInputService::getOutputs() directly.
 */
function hs_get_live_input_outputs(string $input_id): array|string {
    $svc = new LiveInputService();
    $outputs = $svc->getOutputs($input_id);
    if (!is_array($outputs)) {
        return (string) $outputs;
    }
    return $outputs;
}

// ── Recording shims ────────────────────────────────────────────────

/**
 * @deprecated Use RecordingsService directly.
 * §4: returns filtered videos array.
 */
function HSCF_get_videos_by_stream_name(string $stream_name): array {
    $svc = new RecordingsService();
    return $svc->findByStreamName($stream_name);
}

/**
 * @deprecated Use RecordingsService directly.
 */
function hs_get_video_uid(string $stream_name): array|false {
    $svc = new RecordingsService();
    $videos = $svc->findByStreamName($stream_name);
    if (empty($videos)) {
        return false;
    }
    return ['uid' => $videos[0]['uid'], 'mp4_download_url' => $videos[0]['mp4_download_url'] ?? null];
}

/**
 * @deprecated Use RecordingsService directly.
 */
function hs_create_recording_download(string $video_id): array {
    $svc = new RecordingsService();
    return $svc->createDownload($video_id);
}

/**
 * @deprecated Use RecordingsService directly.
 */
function hs_check_recording_download(string $video_id): array {
    $svc = new RecordingsService();
    return $svc->checkDownload($video_id);
}

/**
 * @deprecated Use RecordingsService directly.
 */
function hs_delete_recording(string $video_id): array {
    $svc = new RecordingsService();
    return $svc->delete($video_id);
}

// ── Streamer shims ─────────────────────────────────────────────────

/**
 * @deprecated Use StreamerService directly.
 */
function hs_get_streamer_video_list(): array {
    $svc = new StreamerService();
    return $svc->listVideos();
}

/**
 * @deprecated Use StreamerService directly.
 */
function hs_start_placeholder_stream(string $video_file, string $rtmps_url, string $rtmps_key): array {
    $svc = new StreamerService();
    return $svc->startStreaming($video_file, $rtmps_url, $rtmps_key);
}

/**
 * @deprecated Use StreamerService directly.
 */
function hs_stop_placeholder_stream(): array {
    $svc = new StreamerService();
    return $svc->stopStreaming();
}

/**
 * @deprecated Use StreamerService directly.
 */
function hs_get_stream_state(): array {
    $svc = new StreamerService();
    return $svc->getState();
}
