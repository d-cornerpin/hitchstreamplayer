<?php
/**
 * LiveInputService — wraps CloudflareClient for live input CRUD.
 */

namespace HS\Services;

use HS\CloudflareClient;
use HS\Config;

class LiveInputService {

    private CloudflareClient $client;

    public function __construct(?CloudflareClient $client = null) {
        $this->client = $client ?? new CloudflareClient(Config::cloudflareAccountId());
    }

    /** List all live inputs with detail enrichment (SRT, RTMP, status). */
    public function listWithDetails(): array {
        $result = $this->client->listLiveInputs();
        $data = json_decode($result['body']); // objects — render reads $input->uid etc.

        if (!$result['success'] || !($data->success ?? false)) {
            return ['error' => 'Failed to fetch live inputs.'];
        }

        $inputs = is_array($data->result ?? null) ? $data->result : [];
        foreach ($inputs as $input) {
            if (!is_object($input) || !isset($input->uid)) {
                continue;
            }
            // The list response is minimal; fetch srt/rtmps/status per input.
            $detail_result = $this->client->getLiveInput($input->uid);
            $detail_data   = json_decode($detail_result['body']);

            if ($detail_result['success'] && ($detail_data->success ?? false) && isset($detail_data->result)) {
                $input->srt_details    = $detail_data->result->srt ?? null;
                $input->rtmp_details   = $detail_data->result->rtmps ?? null;
                // Surfaced by the event-day checklist: low-latency mode is a
                // known breaker for LiveU bonded encoders (2026-06-10 incident).
                $input->prefer_low_latency = $detail_data->result->preferLowLatency ?? null;
                // Cloudflare returns status as a nested object {current:{state}};
                // the public docs show a flat string. Handle both so a shape
                // change can't silently blank every status badge.
                $st = $detail_data->result->status ?? null;
                $input->status_details = is_string($st) ? $st : ($st->current->state ?? 'Status Unavailable');
            }
        }

        return $inputs;
    }

    /** Create a live input with the given name. Returns the created input data or null on failure. */
    public function create(string $stream_name, bool $low_latency = false): ?array {
        $params = [
            'meta'      => ['name' => $stream_name],
            'recording' => ['mode' => 'automatic'],
        ];
        if ($low_latency) {
            // Beta LL-HLS pipeline; requires recording mode 'automatic' (set above).
            $params['preferLowLatency'] = true;
        }
        $result = $this->client->createLiveInput($params);
        $data = json_decode($result['body'], true);

        if (!$result['success'] || !($data['success'] ?? false)) {
            error_log("LiveInputService: Failed to create live input: " . json_encode($data['errors'] ?? []));
            return null;
        }

        return $data['result'];
    }

    /** Delete a live input by UID. Returns success message or error. */
    public function delete(string $input_id): string {
        $result = $this->client->deleteLiveInput($input_id);
        $data   = json_decode($result['body'], true);

        if ($result['success'] && ($data['success'] ?? false)) {
            // Retire the input's state artifacts too. The droplet refresher
            // loops over hs-state/*.json forever — a leftover file means it
            // keeps polling a stream that no longer exists (found 2026-07-14:
            // 4 ghost inputs = 57% of the refresher's PHP load was waste).
            self::retireStateFile($input_id);
            return 'Live input deleted successfully.';
        }
        return 'Failed to delete live input: ' . json_encode($data['errors'] ?? []);
    }

    /**
     * Ground-truth "is this input producing playable video right now", via the
     * public lifecycle endpoint. Used to reconcile a sticky webhook 'error'
     * state — Cloudflare sends no recovery event when a transient error clears,
     * so the webhook log stays 'error' while the stream is actually live again.
     *
     * @return string 'live' | 'idle' | '' (empty if the probe failed)
     */
    public function probeLiveStatus(string $input_id): string {
        $cust = (string) get_option('HSCF_customer_id', '');
        if ($cust === '' || $input_id === '') return '';
        $resp = wp_remote_get("https://customer-{$cust}.cloudflarestream.com/{$input_id}/lifecycle", ['timeout' => 6]);
        if (is_wp_error($resp)) return '';
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) return '';
        return !empty($data['live']) ? 'live' : 'idle';
    }

    /**
     * Cloudflare's real-time concurrent live viewer count for an input, or null
     * if it's not currently live / unavailable. Two public lifecycle/views calls
     * (no auth): lifecycle gives the current session's videoUID, then
     * customer-<code>.cloudflarestream.com/<videoUID>/views → { liveViewers }.
     */
    public function liveViewerCount(string $input_id): ?int {
        $cust = (string) get_option('HSCF_customer_id', '');
        if ($cust === '' || $input_id === '') return null;
        $lc = wp_remote_get("https://customer-{$cust}.cloudflarestream.com/{$input_id}/lifecycle", ['timeout' => 4]);
        if (is_wp_error($lc)) return null;
        $ld = json_decode(wp_remote_retrieve_body($lc), true);
        if (!is_array($ld) || empty($ld['live']) || empty($ld['videoUID'])) return null;
        $vr = wp_remote_get("https://customer-{$cust}.cloudflarestream.com/{$ld['videoUID']}/views", ['timeout' => 4]);
        if (is_wp_error($vr)) return null;
        $vd = json_decode(wp_remote_retrieve_body($vr), true);
        return (is_array($vd) && isset($vd['liveViewers'])) ? (int) $vd['liveViewers'] : null;
    }

    /**
     * Remove a deleted input's state artifacts: the hs-state flat file (so the
     * refresher stops tending it) and the live-state transients. Safe to call
     * for ids that have no file.
     */
    public static function retireStateFile(string $input_id): void {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $input_id)) return;
        $file = WP_CONTENT_DIR . '/hs-state/' . $input_id . '.json';
        if (is_file($file)) @unlink($file);
        delete_transient("hs_live_state_{$input_id}");
        delete_transient("hs_webhook_update_ts_{$input_id}");
    }

    /**
     * The RTMPS ingest URL + stream key + name for a live input, resolved
     * server-side. Used by the LiveU panel so the stream key never round-trips
     * through the browser. Uses the vanity ingest host (CNAME → Cloudflare) to
     * match what streamers are handed elsewhere. Null if the key is unavailable.
     */
    public function rtmpIngest(string $input_id): ?array {
        $result = $this->client->getLiveInput($input_id);
        $data   = json_decode($result['body']);
        $key    = $data->result->rtmps->streamKey ?? '';
        if ($key === '') return null;
        return [
            'url'  => 'rtmps://live.hitchstream.com:443/live/',
            'key'  => (string) $key,
            'name' => $data->result->meta->name ?? '',
        ];
    }

    /** Get outputs for a live input. */
    public function getOutputs(string $input_id): array {
        $result = $this->client->get("stream/live_inputs/{$input_id}/outputs");
        $data   = json_decode($result['body'], true);

        if ($result['success'] && ($data['success'] ?? false)) {
            return $data['result'];
        }
        return [];
    }

    /** Create an output on a live input. */
    public function createOutput(string $input_id, string $stream_key, string $url): array {
        $result = $this->client->post("stream/live_inputs/{$input_id}/outputs", [
            'body' => wp_json_encode(['enabled' => true, 'streamKey' => $stream_key, 'url' => $url]),
        ]);
        $data = json_decode($result['body'], true);
        return ['success' => $result['success'], 'result' => $result['success'] ? ($data['result'] ?? []) : ($data['errors'] ?? [])];
    }

    /** Delete an output from a live input. */
    public function deleteOutput(string $input_id, string $output_id): array {
        $result = $this->client->delete("stream/live_inputs/{$input_id}/outputs/{$output_id}");
        return ['success' => $result['success'], 'status' => $result['status']];
    }

    /** Toggle output enabled state. */
    public function toggleOutput(string $input_id, string $output_id, bool $enabled): array {
        $result = $this->client->put("stream/live_inputs/{$input_id}/outputs/{$output_id}", [
            'body' => wp_json_encode(['enabled' => $enabled]),
        ]);
        $data = json_decode($result['body'], true);
        if ($result['success'] && ($data['success'] ?? false)) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => $data['errors'] ?? ['Unknown error']];
    }
}
