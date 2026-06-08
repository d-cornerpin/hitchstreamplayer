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

    public function startStreaming(string $id, string $video_file, string $rtmps_url, string $rtmps_key): array {
        // B4.7: validate ingest URL
        if (!$rtmps_url || !self::validateIngestUrl($rtmps_url)) {
            error_log("[HitchStream] StreamerService: Rejected invalid RTMPS URL: {$rtmps_url}");
            return ['error' => 'Invalid RTMPS URL. Only Cloudflare ingest hosts are allowed.'];
        }

        return $this->postJson('/api/start-streaming', [
            'id'         => $id,
            'videoFile'  => $video_file,
            'rtmpsUrl'   => $rtmps_url,
            'rtmpsKey'   => $rtmps_key,
        ]);
    }

    public function stopStreaming(string $id): array {
        return $this->postJson('/api/stop-streaming', ['id' => $id]);
    }

    /** Get placeholder-stream state. With $id → that input only; without → all. */
    public function getState(?string $id = null): array {
        $path = '/api/stream-state' . ($id ? '?id=' . rawurlencode($id) : '');
        return $this->get($path);
    }

    /**
     * Upload a placeholder video. Sends the raw file bytes with the name in an
     * X-Filename header (the streamer writes them straight to disk) — no
     * multipart encoding needed, and the API key never leaves the server.
     */
    public function uploadVideo(string $tmp_path, string $filename): array {
        if (!$this->api_key) {
            return ['error' => 'Streamer API key not configured'];
        }
        if (!is_readable($tmp_path)) {
            return ['error' => 'Uploaded file is not readable on the server'];
        }
        $body = file_get_contents($tmp_path);
        if ($body === false) {
            return ['error' => 'Could not read the uploaded file'];
        }
        $response = wp_remote_post($this->api_url . '/api/upload', [
            'headers' => [
                'X-API-KEY'    => $this->api_key,
                'X-Filename'   => $filename,
                'Content-Type' => 'application/octet-stream',
            ],
            'body'    => $body,
            'timeout' => 120,
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

    public function deleteVideo(string $filename): array {
        return $this->postJson('/api/delete-video', ['file' => $filename]);
    }

    /**
     * Fetch a placeholder video for in-browser preview. Forwards the browser's
     * Range header so the streamer can answer with 206 partial content (needed
     * for <video> playback/seeking, esp. Safari). Returns the streamer's status,
     * the headers we need to pass through, and the raw body — or ['error'=>...].
     */
    public function fetchVideo(string $filename, string $range = ''): array {
        if (!$this->api_key) {
            return ['error' => 'Streamer API key not configured'];
        }
        $headers = ['X-API-KEY' => $this->api_key];
        if ($range !== '') {
            $headers['Range'] = $range;
        }
        $response = wp_remote_get($this->api_url . '/api/video?file=' . rawurlencode($filename), [
            'headers' => $headers,
            'timeout' => 60,
        ]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return ['error' => 'Streamer HTTP ' . $code];
        }
        return [
            'status'  => $code,
            'headers' => [
                'content-type'   => wp_remote_retrieve_header($response, 'content-type') ?: 'video/mp4',
                'content-length' => wp_remote_retrieve_header($response, 'content-length'),
                'content-range'  => wp_remote_retrieve_header($response, 'content-range'),
                'accept-ranges'  => wp_remote_retrieve_header($response, 'accept-ranges'),
            ],
            'body'    => wp_remote_retrieve_body($response),
        ];
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
