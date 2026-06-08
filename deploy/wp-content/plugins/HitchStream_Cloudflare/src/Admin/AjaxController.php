<?php
/**
 * AjaxController — single dispatcher for all wp_ajax_* handlers.
 *
 * Every action goes through explicit allowlist → nonce → capability → service route.
 */

namespace HS\Admin;

use HS\Services\LiveInputService;
use HS\Services\WebhookService;
use HS\Services\StreamerService;
use HS\Services\RecordingsService;
use HS\Config;
use HS\ConfigError;

class AjaxController {

    /** Explicit allowlist: action name → handler method name. */
    private const ALLOWLIST = [
        'hscf_upload_video'             => 'handleUpload',
        'hscf_register_webhook'         => 'handleRegisterWebhook',
        'hscf_delete_webhook'           => 'handleDeleteWebhook',
        'hscf_fetch_webhooks'           => 'handleFetchWebhooks',
        'hscf_rotate_webhook'           => 'handleRotateWebhook',
        'hscf_test_connection'          => 'handleTestConnection',
        'hscf_test_streamer'            => 'handleTestStreamer',
        'hscf_streamer_list_videos'     => 'handleStreamerListVideos',
        'hscf_streamer_upload_video'    => 'handleStreamerUploadVideo',
        'hscf_streamer_delete_video'    => 'handleStreamerDeleteVideo',
        'get_live_inputs'               => 'handleGetLiveInputs',
        'hscf_check_live_input_status'  => 'handleCheckLiveInputStatus',
        'hscf_delete_output'            => 'handleDeleteOutput',
        'hscf_create_output'            => 'handleCreateOutput',
        'hscf_toggle_output'            => 'handleToggleOutput',
        'hscf_create_download'          => 'handleCreateDownload',
        'hscf_check_download_status'    => 'handleCheckDownloadStatus',
        'hscf_delete_recording'         => 'handleDeleteRecording',
        'get_video_files'               => 'handleGetVideoFiles',
        'start_placeholderstream'       => 'handleStartStream',
        'stop_placeholderstream'        => 'handleStopStream',
        'check_stream_state'            => 'handleCheckStreamState',
    ];

    // All actions require manage_options.
    private const CAPABILITY = 'manage_options';
    private const NONCE_ACTION = 'hscf_admin';

    private LiveInputService $liveInput;
    private WebhookService $webhook;
    private StreamerService $streamer;
    private RecordingsService $recordings;

    public function __construct(
        ?LiveInputService $liveInput = null,
        ?WebhookService $webhook = null,
        ?StreamerService $streamer = null,
        ?RecordingsService $recordings = null
    ) {
        $this->liveInput  = $liveInput  ?? new LiveInputService();
        $this->webhook    = $webhook    ?? new WebhookService();
        $this->streamer   = $streamer   ?? new StreamerService();
        $this->recordings = $recordings ?? new RecordingsService();
    }

    /** Register all wp_ajax_* hooks. */
    public static function register(): void {
        foreach (array_keys(self::ALLOWLIST) as $action) {
            add_action("wp_ajax_{$action}", [self::class, 'dispatchStatic']);
        }
        // Video preview is a GET (an <video> src), so it bypasses the POST-only
        // dispatch and runs its own nonce + capability check.
        add_action('wp_ajax_hscf_streamer_get_video', [self::class, 'streamVideo']);
    }

    /**
     * GET proxy: stream a placeholder video from the streamer to an HTML5
     * <video> element. The API key stays server-side; the browser only ever
     * talks to admin-ajax. Forwards Range so seeking works (206 passthrough).
     */
    public static function streamVideo(): void {
        if (!current_user_can(self::CAPABILITY)) {
            status_header(403);
            exit;
        }
        if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            status_header(403);
            exit;
        }
        $file = self::safeVideoName($_GET['file'] ?? '');
        if (!$file) {
            status_header(400);
            exit;
        }
        $range  = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '';
        $result = (new StreamerService())->fetchVideo($file, $range);
        if (isset($result['error'])) {
            status_header(502);
            echo esc_html($result['error']);
            exit;
        }
        status_header($result['status']);
        header('Content-Type: ' . ($result['headers']['content-type'] ?: 'video/mp4'));
        if (!empty($result['headers']['content-length'])) {
            header('Content-Length: ' . $result['headers']['content-length']);
        }
        if (!empty($result['headers']['content-range'])) {
            header('Content-Range: ' . $result['headers']['content-range']);
        }
        header('Accept-Ranges: ' . ($result['headers']['accept-ranges'] ?: 'bytes'));
        echo $result['body']; // raw video bytes
        exit;
    }

    /**
     * Sanitize a streamer video filename while PRESERVING the real name (spaces
     * and all). sanitize_file_name() rewrites "Wedding Standby.mp4" to
     * "Wedding-Standby.mp4", which then fails to match the file on the streamer.
     * We only strip path components / control chars and require a video ext; the
     * streamer also does basename + containment checks, so traversal is covered.
     */
    private static function safeVideoName($raw): string {
        $name = basename(wp_unslash((string) $raw));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        if ($name === '' || strpos($name, '..') !== false) {
            return '';
        }
        if (!preg_match('/\.(mp4|mov)$/i', $name)) {
            return '';
        }
        return $name;
    }

    /**
     * Lazily construct the controller only when an admin AJAX action actually
     * fires. Constructing at boot (the old `new self()` in register()) eagerly
     * instantiated the service classes, whose constructors call
     * Config::required('HSCF_cloudflare_account_id') and throw ConfigError if
     * it is unset — which white-screened EVERY page (front-end and admin).
     * Deferring construction to dispatch time confines any ConfigError to the
     * AJAX response of an authenticated admin request.
     */
    public static function dispatchStatic(): void {
        try {
            (new self())->dispatch();
        } catch (ConfigError $e) {
            wp_send_json_error('HitchStream is not fully configured: ' . $e->getMessage(), 500);
        }
    }

    /** Dispatch a wp_ajax_* request through the security pipeline. */
    public function dispatch(): void {
        $action = sanitize_text_field($_POST['action'] ?? '');

        // 1. Allowlist check.
        if (!isset(self::ALLOWLIST[$action])) {
            wp_send_json_error('Invalid action', 400);
            return;
        }

        // 2. Nonce check.
        if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            wp_send_json_error('Nonce verification failed', 403);
            return;
        }

        // 3. Capability check.
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error('Forbidden', 403);
            return;
        }

        // 4. Route to handler.
        $handler = self::ALLOWLIST[$action];
        $this->$handler();
    }

    // ── Handlers ───────────────────────────────────────────────────

    private function handleUpload(): void {
        if (empty($_FILES['video_file']) || ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            // Empty $_FILES usually means the file blew past PHP's post_max_size.
            $msg = ($err === UPLOAD_ERR_INI_SIZE || empty($_FILES))
                ? 'File is larger than the server upload limit. Raise upload_max_filesize / post_max_size, or upload via the Cloudflare dashboard.'
                : 'No valid file uploaded.';
            wp_send_json_error($msg);
            return;
        }
        $name = sanitize_file_name($_FILES['video_file']['name']);
        if (!preg_match('/\.(mp4|mov|m4v|mkv|webm|avi)$/i', $name)) {
            wp_send_json_error('Unsupported file type. Upload a video file (mp4, mov, m4v, mkv, webm, avi).');
            return;
        }
        try {
            $client = new \HS\CloudflareClient(Config::cloudflareAccountId());
            $result = $client->uploadVideoTus($_FILES['video_file']['tmp_name'], $name);
            if (!empty($result['success'])) {
                wp_send_json_success([
                    'message' => 'Uploaded "' . $name . '". It will appear in the library once Cloudflare finishes processing (usually under a minute).',
                    'uid'     => $result['uid'] ?? '',
                ]);
            } else {
                wp_send_json_error($result['error'] ?? 'Upload failed.');
            }
        } catch (ConfigError $e) {
            wp_send_json_error('Cloudflare credentials not configured: ' . $e->getMessage());
        } catch (\Throwable $e) {
            wp_send_json_error('Upload error: ' . $e->getMessage());
        }
    }

    private function handleRegisterWebhook(): void {
        // Source the callback URL: what the user typed in the field → the saved
        // option → a sensible default derived from the site URL.
        $callback_url = esc_url_raw(wp_unslash($_POST['webhook_url'] ?? ''));
        if (empty($callback_url)) {
            $callback_url = (string) get_option('HSCF_webhook_url', '');
        }
        if (empty($callback_url)) {
            $callback_url = rtrim(home_url('/'), '/') . '/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php';
        }

        $result = $this->webhook->register($callback_url);
        if (isset($result['error'])) {
            $detail = $result['error'];
            if (!empty($result['response'])) {
                $detail .= ' — ' . wp_strip_all_tags((string) $result['response']);
            }
            wp_send_json_error($detail);
            return;
        }
        // Cloudflare generated and returned the signing secret — store it.
        update_option('HSCF_webhook_secret', $result['secret']);
        update_option('HSCF_webhook_url', $callback_url);
        wp_send_json_success(['message' => 'Webhook registered successfully.', 'secret' => $result['secret'], 'url' => $callback_url]);
    }

    private function handleDeleteWebhook(): void {
        $result = $this->webhook->delete();
        delete_option('HSCF_webhook_secret');
        wp_send_json_success(['status' => $result['status'], 'body' => $result['body']]);
    }

    private function handleFetchWebhooks(): void {
        $result = $this->webhook->get();
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        wp_send_json_success($result);
    }

    private function handleRotateWebhook(): void {
        $callback_url = get_option('HSCF_webhook_url', '');
        if (!$callback_url) {
            wp_send_json_error('Webhook URL not configured.');
            return;
        }
        $result = $this->webhook->rotate($callback_url);
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        wp_send_json_success(['message' => 'Secret rotated successfully.']);
    }

    private function handleTestConnection(): void {
        try {
            $client = new \HS\CloudflareClient(Config::cloudflareAccountId());
            $client->lifecycle('test');
            wp_send_json_success('Connection successful — Cloudflare API is reachable.');
        } catch (ConfigError $e) {
            wp_send_json_error('Credentials not configured: ' . $e->getMessage());
        } catch (\Throwable $e) {
            wp_send_json_error('Connection failed: ' . $e->getMessage());
        }
    }

    private function handleTestStreamer(): void {
        // Validate BEFORE saving so a wrong key is never persisted. Use the
        // freshly-typed value from the form if present; a masked value (contains
        // '*') means "keep stored", so fall back to the saved key.
        $posted_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $typed      = ($posted_key !== '' && strpos($posted_key, '*') === false);
        $apiKey     = $typed ? $posted_key : Config::streamerApiKey();

        $posted_url = !empty($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : '';
        $apiUrl     = $posted_url !== '' ? $posted_url : Config::streamerApiUrl();

        if (!$apiKey) {
            wp_send_json_error('Enter a Streamer API key first.');
            return;
        }

        // /api/auth-check is the ONLY non-destructive endpoint that actually
        // checks the key (stream-state/list-videos are public). 200 = valid,
        // 401/403 = wrong key, 404 = streamer app not yet deployed with it.
        $response = wp_remote_get(rtrim($apiUrl, '/') . '/api/auth-check', [
            'headers' => ['X-API-KEY' => $apiKey],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);

        if ($code === 401 || $code === 403) {
            wp_send_json_error('API key rejected by the streamer service — this key does not match the server. Not saved.');
            return;
        }
        if ($code === 404) {
            wp_send_json_error('Streamer is reachable, but /api/auth-check is missing (404) — the streamer app needs its latest deploy before the key can be validated.');
            return;
        }
        if ($code >= 200 && $code < 300) {
            // Verified — now it is safe to persist the typed key/URL.
            if ($typed) {
                update_option('HSCF_streamer_api_key', $apiKey);
            }
            if ($posted_url !== '') {
                update_option('HSCF_streamer_api_url', $apiUrl);
            }
            wp_send_json_success('API key verified and saved — streamer service is reachable.');
            return;
        }
        wp_send_json_error('Streamer returned HTTP ' . $code);
    }

    /** List placeholder videos stored on the streamer service. */
    private function handleStreamerListVideos(): void {
        $result = $this->streamer->listVideos();
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        $videos = json_decode($result['body'] ?? '[]', true);
        if (!is_array($videos)) {
            $videos = [];
        }
        wp_send_json_success(['videos' => array_values($videos)]);
    }

    /** Proxy a placeholder-video upload (browser → WP → streamer, key stays server-side). */
    private function handleStreamerUploadVideo(): void {
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error('No valid file uploaded.');
            return;
        }
        $name = self::safeVideoName($_FILES['file']['name']);
        if (!$name) {
            wp_send_json_error('Only .mp4 or .mov files are allowed.');
            return;
        }
        $result = $this->streamer->uploadVideo($_FILES['file']['tmp_name'], $name);
        if (isset($result['error'])) {
            wp_send_json_error('Upload failed: ' . $result['error'] . (!empty($result['body']) ? ' — ' . $result['body'] : ''));
            return;
        }
        wp_send_json_success('Uploaded ' . $name);
    }

    /** Delete a placeholder video from the streamer service. */
    private function handleStreamerDeleteVideo(): void {
        $name = self::safeVideoName($_POST['file'] ?? '');
        if (!$name) {
            wp_send_json_error('Invalid filename.');
            return;
        }
        $result = $this->streamer->deleteVideo($name);
        if (isset($result['error'])) {
            wp_send_json_error('Delete failed: ' . $result['error']);
            return;
        }
        wp_send_json_success('Deleted ' . $name);
    }

    private function handleGetLiveInputs(): void {
        $inputs = $this->liveInput->listWithDetails();
        if (is_string($inputs) || isset($inputs['error'])) {
            wp_send_json_error('Failed to fetch live inputs.');
            return;
        }
        $data = [];
        foreach ($inputs as $input) {
            $name = isset($input->meta) && isset($input->meta->name) ? $input->meta->name : 'Unnamed Input';
            $rtmpKey = isset($input->rtmp_details) ? $input->rtmp_details->streamKey ?? '' : '';
            $data[] = ['name' => $name, 'value' => $input->uid . '|' . $rtmpKey];
        }
        wp_send_json_success($data);
    }

    private function handleCheckLiveInputStatus(): void {
        $ids = (isset($_POST['ids']) && is_array($_POST['ids']))
            ? array_values(array_filter(array_map('sanitize_text_field', $_POST['ids'])))
            : [];

        $statuses = [];

        // 1. Webhook-derived state first — but ONLY when the webhook is actually
        //    operational (a secret is configured). With no secret, Cloudflare
        //    can't be delivering events, so any rows in the log are stale and
        //    must be ignored — fall straight through to the API ("polling")
        //    instead. This is the mirror / not-yet-registered case.
        $webhook_configured = (string) get_option('HSCF_webhook_secret', '') !== '';
        if ($ids && $webhook_configured) {
            foreach ($this->webhook->latestStatesByInput($ids) as $uid => $state) {
                $statuses[$uid] = ['status' => self::mapStateToBadge($state), 'source' => 'webhook'];
            }
        }

        // 2. Fall back to the Cloudflare API only for inputs the webhook hasn't
        //    reported (webhook unconfigured/never fired, or unknown id list).
        $missing = $ids ? array_values(array_diff($ids, array_keys($statuses))) : [];
        if (!$ids || $missing) {
            $inputs = $this->liveInput->listWithDetails();
            if (is_array($inputs)) {
                foreach ($inputs as $input) {
                    if (!is_object($input) || !isset($input->uid)) {
                        continue;
                    }
                    if (!$ids || in_array($input->uid, $missing, true)) {
                        $statuses[$input->uid] = ['status' => $input->status_details ?? 'Status Unavailable', 'source' => 'api'];
                    }
                }
            } elseif (!$statuses) {
                // No webhook state and the API failed — surface the error.
                wp_send_json_error($inputs);
                return;
            }
        }

        wp_send_json_success($statuses);
    }

    /** Map a normalized webhook state to the badge vocabulary the JS expects. */
    private static function mapStateToBadge(string $state): string {
        switch ($state) {
            case 'live':         return 'connected';
            case 'idle':         return 'disconnected';
            case 'reconnecting': return 'reconnecting';
            case 'error':        return 'errored';
            default:             return $state !== '' ? $state : 'Status Unavailable';
        }
    }

    private function handleDeleteOutput(): void {
        $input_id   = sanitize_text_field($_POST['input_id'] ?? '');
        $output_id  = sanitize_text_field($_POST['output_id'] ?? '');
        $result     = $this->liveInput->deleteOutput($input_id, $output_id);
        wp_send_json_success($result['success'] ? 'Output deleted successfully.' : 'Failed to delete output.');
    }

    private function handleCreateOutput(): void {
        $input_id  = sanitize_text_field($_POST['input_id'] ?? '');
        $streamKey = sanitize_text_field($_POST['stream_key'] ?? '');
        $url       = sanitize_text_field($_POST['stream_url'] ?? '');
        if (!$input_id || !$streamKey || !$url) {
            wp_send_json_error('Missing required information');
            return;
        }
        $result = $this->liveInput->createOutput($input_id, $streamKey, $url);
        wp_send_json_success($result['success'] ? 'Output created successfully.' : 'Failed to create output.');
    }

    private function handleToggleOutput(): void {
        $input_id  = sanitize_text_field($_POST['input_id'] ?? '');
        $output_id = sanitize_text_field($_POST['output_id'] ?? '');
        $enabled   = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $result    = $this->liveInput->toggleOutput($input_id, $output_id, $enabled);
        if ($result['success']) {
            wp_send_json_success('Output updated successfully.');
        }
        wp_send_json_error('Failed to update output: ' . json_encode($result['error'] ?? ['Unknown error']));
    }

    private function handleCreateDownload(): void {
        $video_id = sanitize_text_field($_POST['video_id'] ?? '');
        if (!$video_id) {
            wp_send_json_error('Missing video_id');
            return;
        }
        $result = $this->recordings->createDownload($video_id);
        wp_send_json_success($result['success'] ? 'Download created successfully.' : 'Failed to create download.');
    }

    private function handleCheckDownloadStatus(): void {
        $video_id = sanitize_text_field($_POST['video_id'] ?? '');
        $result   = $this->recordings->checkDownload($video_id);
        if ($result['success']) {
            wp_send_json_success(['download_url' => $result['download_url'], 'percent' => 100]);
        }
        // Still generating — hand back progress so the UI can show a percentage.
        wp_send_json_error([
            'message' => 'MP4 download not ready yet.',
            'status'  => $result['status'] ?? 'inprogress',
            'percent' => $result['percent'] ?? null,
        ]);
    }

    private function handleDeleteRecording(): void {
        $video_id = sanitize_text_field($_POST['video_id'] ?? '');
        if (!$video_id) {
            wp_send_json_error('Missing video_id');
            return;
        }
        $result = $this->recordings->delete($video_id);
        wp_send_json_success(['response_code' => $result['status'], 'response_body' => $result['body']]);
    }

    private function handleGetVideoFiles(): void {
        $apiKey = Config::streamerApiKey();
        if (!$apiKey) {
            wp_send_json_error('Streamer API key not configured', 400);
            return;
        }
        $response = wp_remote_get(Config::streamerApiUrl() . '/api/list-videos', [
            'headers' => ['X-API-KEY' => $apiKey],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }
        wp_send_json(wp_remote_retrieve_body($response));
    }

    private function handleStartStream(): void {
        $id         = sanitize_text_field($_POST['id'] ?? '');
        $video_file = self::safeVideoName($_POST['videoFile'] ?? '');
        // NB: sanitize_url()/esc_url_raw() strip rtmps:// by default (not an
        // allowed protocol), which silently emptied this field. Allow rtmp(s).
        $rtmps_url  = esc_url_raw(wp_unslash($_POST['rtmpsUrl'] ?? ''), ['rtmps', 'rtmp']);
        $rtmps_key  = sanitize_text_field($_POST['rtmpsKey'] ?? '');
        $missing = [];
        if (!$id)         { $missing[] = 'live input id'; }
        if (!$video_file) { $missing[] = 'video file'; }
        if (!$rtmps_url)  { $missing[] = 'RTMPS URL'; }
        if (!$rtmps_key)  { $missing[] = 'RTMPS key'; }
        if ($missing) {
            wp_send_json_error('Missing: ' . implode(', ', $missing) . '. (If this says "live input id", hard-refresh the page to load the latest script.)');
            return;
        }
        $result = $this->streamer->startStreaming($id, $video_file, $rtmps_url, $rtmps_key);
        if (isset($result['error'])) {
            $msg = $result['error'];
            if (!empty($result['body'])) {
                $msg .= ' — ' . wp_strip_all_tags((string) $result['body']);
            }
            wp_send_json_error($msg);
            return;
        }
        $data = json_decode($result['body'] ?? '{}', true);
        wp_send_json_success(is_array($data) ? $data : ['message' => 'Streaming started']);
    }

    private function handleStopStream(): void {
        $id = sanitize_text_field($_POST['id'] ?? '');
        if (!$id) {
            wp_send_json_error('Missing live input id.');
            return;
        }
        $result = $this->streamer->stopStreaming($id);
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        $data = json_decode($result['body'] ?? '{}', true);
        wp_send_json_success(is_array($data) ? $data : ['message' => 'Streaming stopped']);
    }

    private function handleCheckStreamState(): void {
        $id     = sanitize_text_field($_POST['id'] ?? '');
        $result = $this->streamer->getState($id !== '' ? $id : null);
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        $data = json_decode($result['body'] ?? '{}', true);
        wp_send_json_success(is_array($data) ? $data : []);
    }
}
