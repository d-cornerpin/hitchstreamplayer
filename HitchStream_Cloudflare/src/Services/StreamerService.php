<?php
/**
 * StreamerService — wraps Config for placeholder-stream service ops.
 *
 * B4.7: RTMPS URL allowlist — only allow Cloudflare ingest hosts.
 */

namespace HS\Services;

use HS\Config;

class StreamerService {

    private string $api_url;
    private string $api_key;

    public function __construct(?string $api_url = null, ?string $api_key = null) {
        $this->api_url = $api_url ?? Config::streamerApiUrl();
        $this->api_key = $api_key ?? Config::streamerApiKey();
    }

    // ── B4.7: RTMPS allowlist ───────────────────────────────────────

    /**
     * Validate an RTMPS/RTMP ingest URL against Cloudflare's ingest hostnames.
     *
     * Cloudflare Stream ingest hosts:
     *   rtmps://live.hitchstream.com:443/live/...
     *   rtmps://liveu.liveu.tv:443/...   (LiveU aggregator)
     *   wss://live.hitchstream.com/...    (WebRTC/WebSocket)
     */
    public static function validateIngestUrl(string $url): bool {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        $host   = strtolower($parsed['host']);

        // Must be rtmps or wss.
        if (!in_array($scheme, ['rtmps', 'wss'], true)) {
            return false;
        }

        // Allow *.cloudflare.com and known Cloudflare ingest hosts.
        $allowed = [
            'live.hitchstream.com',
            'liveu.liveu.tv',
        ];

        // Also allow any *.cloudflare.com host as Cloudflare adds new ingest points.
        return in_array($host, $allowed, true)
            || preg_match('/^([a-z0-9-]+\.)*cloudflare\.com$/i', $host) === 1;
    }

    // ── Public API methods ──────────────────────────────────────────

    public function listVideos(): array {
        return $this->get('/api/list-videos');
    }

    public function startStreaming(string $video_file, string $rtmps_url, string $rtmps_key): array {
        // B4.7: validate ingest URL
        if (!$rtmps_url || !self::validateIngestUrl($rtmps_url)) {
            error_log("[HitchStream] StreamerService: Rejected invalid RTMPS URL: {$rtmps_url}");
            return ['error' => 'Invalid RTMPS URL. Only Cloudflare ingest hosts are allowed.'];
        }

        return $this->postJson('/api/start-streaming', [
            'videoFile'  => $video_file,
            'rtmpsUrl'   => $rtmps_url,
            'rtmpsKey'   => $rtmps_key,
        ]);
    }

    public function stopStreaming(): array {
        return $this->postJson('/api/stop-streaming', []);
    }

    public function getState(): array {
        return $this->get('/api/stream-state');
    }

    // ── Internal ────────────────────────────────────────────────────

    private function get(string $path): array {
        $url = $this->api_url . $path;
        if (!$this->api_key) {
            return ['error' => 'Streamer API key not configured'];
        }
        $response = wp_remote_get($url, [
            'headers' => ['X-API-KEY' => $this->api_key],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['error' => 'HTTP ' . $code, 'body' => wp_remote_retrieve_body($response)];
        }
        return ['success' => true, 'body' => wp_remote_retrieve_body($response)];
    }

    private function postJson(string $path, array $body): array {
        $url = $this->api_url . $path;
        if (!$this->api_key) {
            return ['error' => 'Streamer API key not configured'];
        }
        $response = wp_remote_post($url, [
            'headers' => ['X-API-KEY' => $this->api_key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['error' => 'HTTP ' . $code, 'body' => wp_remote_retrieve_body($response)];
        }
        return ['success' => true, 'body' => wp_remote_retrieve_body($response)];
    }
}
