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
        $result = $this->client->listVideos();
        $data = json_decode($result['body'], true);

        if (!$result['success'] || !($data['success'] ?? false)) {
            return ['error' => 'Failed to fetch live inputs.'];
        }

        foreach ($data['result'] as $input) {
            $detail_result = $this->client->getLiveInput($input->uid);
            $detail_data = json_decode($detail_result['body'], true);

            if ($detail_result['success'] && ($detail_data['success'] ?? false) && isset($detail_data['result'])) {
                $input->srt_details    = $detail_data['result']['srt'] ?? null;
                $input->rtmp_details   = $detail_data['result']['rtmps'] ?? null;
                $input->status_details = $detail_data['result']['status']['current']['state'] ?? 'Status Unavailable';
            }
        }

        return $data['result'];
    }

    /** Create a live input with the given name. Returns the created input data or null on failure. */
    public function create(string $stream_name): ?array {
        $result = $this->client->createLiveInput([
            'meta'      => ['name' => $stream_name],
            'recording' => ['mode' => 'automatic'],
        ]);
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
            return 'Live input deleted successfully.';
        }
        return 'Failed to delete live input: ' . json_encode($data['errors'] ?? []);
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
