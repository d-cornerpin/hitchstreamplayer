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
        $ctrl = new self();
        foreach (array_keys(self::ALLOWLIST) as $action) {
            add_action("wp_ajax_{$action}", [$ctrl, 'dispatch']);
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
        if (empty($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('No valid file uploaded.');
            return;
        }
        try {
            $client = new \HS\CloudflareClient(Config::cloudflareAccountId());
            $result = $client->post('media', [
                'headers' => [
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Length' => (string) $_FILES['video_file']['size'],
                    'Upload-Metadata' => 'filename ' . base64_encode($_FILES['video_file']['name']),
                ],
            ]);
            if ($result['success']) {
                wp_send_json_success(['location' => $result['body']]);
            }
            wp_send_json_error('Error creating TUS session: ' . $result['body']);
        } catch (ConfigError $e) {
            wp_send_json_error('Credentials not configured: ' . $e->getMessage());
        } catch (\Throwable $e) {
            wp_send_json_error('Error creating TUS session: ' . $e->getMessage());
        }
    }

    private function handleRegisterWebhook(): void {
        $callback_url = get_option('HSCF_webhook_url', '');
        $secret       = sanitize_text_field(get_option('HSCF_webhook_secret', ''));
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
        }
        $result = $this->webhook->register($callback_url, $secret);
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        update_option('HSCF_webhook_secret', $result['secret']);
        update_option('HSCF_webhook_url', $callback_url);
        wp_send_json_success(['message' => 'Webhook registered successfully.', 'secret' => $result['secret']]);
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
            $result = $client->lifecycle('test');
            $data   = json_decode($result['body'], true);
            $input_id = $data['inputId'] ?? ($data['result']['inputId'] ?? '');
            wp_send_json_success(['input_id' => $input_id]);
        } catch (ConfigError $e) {
            wp_send_json_error('Credentials not configured: ' . $e->getMessage());
        } catch (\Throwable $e) {
            wp_send_json_error('Connection failed: ' . $e->getMessage());
        }
    }

    private function handleTestStreamer(): void {
        $apiKey = Config::streamerApiKey();
        if (!$apiKey) {
            wp_send_json_error('Streamer API key not configured');
            return;
        }
        $apiUrl = Config::streamerApiUrl() . '/api/stream-state';
        $response = wp_remote_get($apiUrl, ['headers' => ['X-API-KEY' => $apiKey], 'timeout' => 10]);
        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            wp_send_json_success('Streamer service responded OK');
        }
        wp_send_json_error('Streamer returned HTTP ' . $code);
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
        $inputs = $this->liveInput->listWithDetails();
        if (is_string($inputs) || isset($inputs['error'])) {
            wp_send_json_error($inputs);
            return;
        }
        $statuses = [];
        foreach ($inputs as $input) {
            $statuses[$input->uid] = $input->status_details ?? 'Status Unavailable';
        }
        wp_send_json_success($statuses);
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
            wp_send_json_success(['download_url' => $result['download_url']]);
        }
        wp_send_json_error('MP4 download URL not available yet.');
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
        $video_file = sanitize_text_field($_POST['videoFile'] ?? '');
        $rtmps_url  = sanitize_url($_POST['rtmpsUrl'] ?? '');
        $rtmps_key  = sanitize_text_field($_POST['rtmpsKey'] ?? '');
        $result     = $this->streamer->startStreaming($video_file, $rtmps_url, $rtmps_key);
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        wp_send_json($result['body']);
    }

    private function handleStopStream(): void {
        $result = $this->streamer->stopStreaming();
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        wp_send_json($result['body']);
    }

    private function handleCheckStreamState(): void {
        $result = $this->streamer->getState();
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
            return;
        }
        wp_send_json($result['body']);
    }
}
