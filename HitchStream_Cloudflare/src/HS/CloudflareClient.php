<?php
/**
 * Cloudflare API client — single source of truth for all Cloudflare Stream API calls.
 *
 * Auth precedence: Bearer token (preferred) → email + Global API Key (legacy, deprecated).
 * Retry: 3 attempts on 5xx / network error with 250ms / 750ms / 2s backoff.
 * Timeouts: 10s connect, 15s total.
 */

namespace HS;

class CloudflareClient {

    private string $account_id;
    private string $base_url;

    // ── Auth ──────────────────────────────────────────────────────────

    public function __construct(string $account_id) {
        $this->account_id = $account_id;
        $this->base_url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}";
    }

    /** Get auth headers — Bearer token preferred, email/key fallback. */
    private function get_auth_headers(): array {
        $bearer = get_option('HSCF_cloudflare_api_token', '');
        if ($bearer) {
            return [
                'Authorization' => "Bearer {$bearer}",
                'Content-Type'  => 'application/json',
            ];
        }

        $email = get_option('HSCF_cloudflare_email', '');
        $key   = get_option('HSCF_cloudflare_api_key', '');
        if ($email && $key) {
            // B3.3: deprecated fallback — fire notice so migration is visible.
            trigger_error(
                '[HitchStream] Cloudflare auth falling back to email+API-key. Configure HSCF_cloudflare_api_token (Bearer token) to silence this notice.',
                E_USER_DEPRECATED
            );
            return [
                'X-Auth-Email' => $email,
                'X-Auth-Key'   => $key,
                'Content-Type' => 'application/json',
            ];
        }

        // Neither auth method configured.
        trigger_error(
            '[HitchStream] No Cloudflare credentials configured (neither Bearer token nor email+API-key). API calls will fail.',
            E_USER_WARNING
        );
        return ['Content-Type' => 'application/json'];
    }

    // ── Public: send a request ────────────────────────────────────────

    /**
     * Send an HTTP request through the client with retry logic.
     *
     * @param string $method  GET|POST|PUT|DELETE
     * @param string $path    Path relative to base URL (e.g. "stream/live_inputs")
     * @param array  $options Optional: headers, body, query
     * @return array          ['success' => bool, 'body' => string, 'status' => int|null, 'error' => string|null]
     */
    public function request(string $method, string $path, array $options = []): array {
        $url = $this->base_url . '/' . ltrim($path, '/');

        // Build headers: merge custom into auth defaults.
        $headers = $this->get_auth_headers();
        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        $args = [
            'method'      => $method,
            'headers'     => $headers,
            'timeout'     => 15.0,
            'connect_timeout' => 10.0,
        ];
        if (isset($options['body'])) {
            $args['body'] = $options['body'];
        }
        if (isset($options['query']) && is_array($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        // Redact account ID for logging.
        $log_url = preg_replace(
            "|api\.cloudflare\.com/client/v4/accounts/[^/]+|",
            'api.cloudflare.com/client/v4/accounts/REDACTED',
            $url
        );

        $correlation_id = wp_generate_uuid4();
        $start = microtime(true);

        // Retry loop: 3 attempts with backoff on 5xx / network error.
        $attempts = [0, 0.25, 0.75];
        $last_err = null;
        $last_status = null;
        $last_body = '';

        foreach ($attempts as $i => $delay) {
            if ($i > 0) {
                usleep((int)($delay * 1_000_000));
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $last_err = $response->get_error_message();
                $last_status = null;
                $last_body = '';
                // Network error — retry.
                continue;
            }

            $status = wp_remote_retrieve_response_code($response);
            $body   = wp_remote_retrieve_body($response);
            $last_status = $status;
            $last_body = $body;

            // Retry on 5xx.
            if ($status >= 500 && $i < 2) {
                $last_err = null;
                continue;
            }

            // Success or client error — no retry.
            break;
        }

        $duration = round((microtime(true) - $start) * 1000, 1);

        $this->log($correlation_id, $method, $log_url, $last_status, $duration);

        return [
            'success' => $last_status >= 200 && $last_status < 300,
            'body'    => $last_body,
            'status'  => $last_status,
            'error'   => $last_err, // null on last attempt success
            'correlation_id' => $correlation_id,
        ];
    }

    /** Convenience: GET. */
    public function get(string $path, array $options = []): array {
        return $this->request('GET', $path, $options);
    }

    /** Convenience: POST. */
    public function post(string $path, array $options = []): array {
        return $this->request('POST', $path, $options);
    }

    /** Convenience: PUT. */
    public function put(string $path, array $options = []): array {
        return $this->request('PUT', $path, $options);
    }

    /** Convenience: DELETE. */
    public function delete(string $path, array $options = []): array {
        return $this->request('DELETE', $path, $options);
    }

    // ── Public: API methods ───────────────────────────────────────────

    /** GET /accounts/{id}/stream/{input_id}/lifecycle */
    public function lifecycle(string $input_id): array {
        return $this->get("stream/{$input_id}/lifecycle");
    }

    /** GET /accounts/{id}/stream/live_inputs  — list all live inputs (minimal;
     *  enrich per-input with getLiveInput() for srt/rtmps/status). */
    public function listLiveInputs(): array {
        return $this->get('stream/live_inputs');
    }

    /** GET /accounts/{id}/stream  — list uploaded/recorded videos. */
    public function listVideos(?string $input_id = null, int $page = 1, int $per_page = 50): array {
        return $this->get('stream', ['query' => ['page' => $page, 'per_page' => $per_page]]);
    }

    /** GET /accounts/{id}/stream/live_inputs/{id} */
    public function getLiveInput(string $input_id): array {
        return $this->get("stream/live_inputs/{$input_id}");
    }

    /** POST /accounts/{id}/stream/live_inputs with JSON body. */
    public function createLiveInput(array $params): array {
        return $this->post('stream/live_inputs', ['body' => wp_json_encode($params)]);
    }

    /** DELETE /accounts/{id}/stream/live_inputs/{id} */
    public function deleteLiveInput(string $input_id): array {
        return $this->delete("stream/live_inputs/{$input_id}");
    }

    /**
     * PUT /accounts/{id}/stream/webhook — register account-level webhook.
     * Cloudflare's API takes only notification_url; it generates the signing
     * secret itself and returns it in result.secret (do NOT send a secret).
     */
    public function registerWebhook(string $notification_url): array {
        $body = ['notification_url' => $notification_url];
        return $this->put('stream/webhook', ['body' => wp_json_encode($body)]);
    }

    /** DELETE /accounts/{id}/stream/webhook  — remove account-level webhook. */
    public function deleteWebhook(): array {
        return $this->delete('stream/webhook');
    }

    /** GET /accounts/{id}/stream/webhook  — retrieve account-level webhook config. */
    public function getWebhook(): array {
        return $this->get('stream/webhook');
    }

    // ── Notifications / Alerting (LIVE-input webhooks) ─────────────────
    // Live-input events (connected/disconnected/errored) are NOT delivered by
    // the Stream webhook above (that's on-demand video.ready). They come from
    // Cloudflare Notifications: a webhook "destination" + a "policy" of
    // alert_type stream_live_notifications. These wrap that Alerting API.

    /** GET alerting/v3/destinations/webhooks — list notification webhook destinations. */
    public function listWebhookDestinations(): array {
        return $this->get('alerting/v3/destinations/webhooks');
    }

    /** POST alerting/v3/destinations/webhooks — create one. Secret is echoed back to us in the cf-webhook-auth header. */
    public function createWebhookDestination(string $name, string $url, string $secret): array {
        return $this->post('alerting/v3/destinations/webhooks', [
            'body' => wp_json_encode(['name' => $name, 'url' => $url, 'secret' => $secret]),
        ]);
    }

    /** PUT alerting/v3/destinations/webhooks/{id} — update name/url/secret. */
    public function updateWebhookDestination(string $id, string $name, string $url, string $secret): array {
        return $this->put("alerting/v3/destinations/webhooks/{$id}", [
            'body' => wp_json_encode(['name' => $name, 'url' => $url, 'secret' => $secret]),
        ]);
    }

    /** DELETE alerting/v3/destinations/webhooks/{id} */
    public function deleteWebhookDestination(string $id): array {
        return $this->delete("alerting/v3/destinations/webhooks/{$id}");
    }

    /** GET alerting/v3/policies — list notification policies. */
    public function listAlertPolicies(): array {
        return $this->get('alerting/v3/policies');
    }

    /** POST alerting/v3/policies — create a notification policy (pass the full policy body). */
    public function createAlertPolicy(array $policy): array {
        return $this->post('alerting/v3/policies', ['body' => wp_json_encode($policy)]);
    }

    /** DELETE alerting/v3/policies/{id} */
    public function deleteAlertPolicy(string $id): array {
        return $this->delete("alerting/v3/policies/{$id}");
    }

    /**
     * Upload a video to Cloudflare Stream using the TUS resumable protocol.
     *
     * Cloudflare requires TUS (not a plain POST) for video uploads:
     *   1. POST /accounts/{id}/stream  with Tus-Resumable + Upload-Length +
     *      Upload-Metadata  → 201 Created, `Location` header = the upload URL.
     *   2. PATCH that URL in chunks with Upload-Offset → 204, new Upload-Offset.
     *
     * Runs entirely server-side so the API token never reaches the browser.
     * Streams the file from disk in chunks so memory use is bounded regardless
     * of file size. Returns ['success'=>true,'uid'=>...] or ['success'=>false,'error'=>...].
     */
    public function uploadVideoTus(string $tmp_path, string $filename): array {
        if (!is_readable($tmp_path)) {
            return ['success' => false, 'error' => 'Uploaded file is not readable on the server.'];
        }
        $size = filesize($tmp_path);
        if ($size === false || $size < 1) {
            return ['success' => false, 'error' => 'Uploaded file is empty.'];
        }

        // Auth without JSON content-type (TUS sets its own content types).
        $auth = $this->get_auth_headers();
        unset($auth['Content-Type']);

        // 1) Create the upload session.
        $create = wp_remote_post($this->base_url . '/stream', [
            'headers' => array_merge($auth, [
                'Tus-Resumable'   => '1.0.0',
                'Upload-Length'   => (string) $size,
                'Upload-Metadata' => 'name ' . base64_encode($filename),
            ]),
            'body'    => '',
            'timeout' => 30,
        ]);
        if (is_wp_error($create)) {
            return ['success' => false, 'error' => 'Could not start upload: ' . $create->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($create);
        if ($code !== 201) {
            return ['success' => false, 'error' => 'Cloudflare rejected the upload (HTTP ' . $code . '): ' . substr(wp_remote_retrieve_body($create), 0, 300)];
        }
        $location = wp_remote_retrieve_header($create, 'location');
        if (!$location) {
            return ['success' => false, 'error' => 'Cloudflare did not return an upload URL.'];
        }
        $uid = wp_remote_retrieve_header($create, 'stream-media-id');
        if (!$uid) {
            $uid = basename((string) parse_url($location, PHP_URL_PATH));
        }

        // 2) Upload the bytes in chunks (TUS PATCH).
        $chunk_size = 50 * 1024 * 1024; // 50 MB
        $fh = @fopen($tmp_path, 'rb');
        if (!$fh) {
            return ['success' => false, 'error' => 'Could not open the uploaded file.'];
        }
        $offset = 0;
        while ($offset < $size) {
            if (fseek($fh, $offset) !== 0) { fclose($fh); return ['success' => false, 'error' => 'Read error at byte ' . $offset]; }
            $chunk = fread($fh, $chunk_size);
            if ($chunk === false) { fclose($fh); return ['success' => false, 'error' => 'Read error at byte ' . $offset]; }

            $patch = wp_remote_request($location, [
                'method'  => 'PATCH',
                'headers' => array_merge($auth, [
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Offset' => (string) $offset,
                    'Content-Type'  => 'application/offset+octet-stream',
                ]),
                'body'    => $chunk,
                'timeout' => 180,
            ]);
            if (is_wp_error($patch)) { fclose($fh); return ['success' => false, 'error' => 'Upload failed: ' . $patch->get_error_message()]; }
            $pc = wp_remote_retrieve_response_code($patch);
            if ($pc !== 204) { fclose($fh); return ['success' => false, 'error' => 'Upload chunk rejected (HTTP ' . $pc . '): ' . substr(wp_remote_retrieve_body($patch), 0, 200)]; }

            $new = wp_remote_retrieve_header($patch, 'upload-offset');
            $new = is_numeric($new) ? (int) $new : -1;
            if ($new <= $offset) { fclose($fh); return ['success' => false, 'error' => 'Upload stalled at ' . $offset . ' of ' . $size . ' bytes.']; }
            $offset = $new;
        }
        fclose($fh);

        return ['success' => true, 'uid' => $uid];
    }

    // ── Internal ──────────────────────────────────────────────────────

    private function log(string $correlation_id, string $method, string $url, ?int $status, float $duration_ms): void {
        $level = ($status >= 500) ? 'error' : 'info';
        error_log(
            "[HitchStream] CF API [{$correlation_id}] {$method} {$url} status={$status} duration={$duration_ms}ms"
        );
    }
}
