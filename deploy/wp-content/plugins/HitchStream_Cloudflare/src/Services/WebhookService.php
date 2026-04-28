<?php
/**
 * WebhookService — wraps CloudflareClient for webhook management.
 */

namespace HS\Services;

use HS\CloudflareClient;
use HS\Config;

class WebhookService {

    private CloudflareClient $client;

    public function __construct(?CloudflareClient $client = null) {
        $this->client = $client ?? new CloudflareClient(Config::cloudflareAccountId());
    }

    /** Register account-level webhook. Returns ['secret' => string] on success, ['error' => string] on failure. */
    public function register(string $notification_url, string $secret = ''): array {
        $new_secret = $secret ?: bin2hex(random_bytes(32));
        $result = $this->client->registerWebhook($notification_url, $new_secret);

        if (!$result['success']) {
            return ['error' => 'Cloudflare API error', 'status' => $result['status'], 'response' => $result['body']];
        }

        $data = json_decode($result['body'], true);
        if (($data['result']['secret'] ?? '')) {
            return ['secret' => $data['result']['secret']];
        }

        return ['error' => 'Webhook registration returned no secret.'];
    }

    /** Delete account-level webhook. */
    public function delete(): array {
        $result = $this->client->deleteWebhook();
        return ['status' => $result['status'], 'body' => $result['body']];
    }

    /** List current webhook configuration. */
    public function get(): array {
        $result = $this->client->getWebhook();
        if (!$result['success']) {
            return ['error' => 'Cloudflare API error', 'status' => $result['status'], 'response' => $result['body']];
        }
        return json_decode($result['body'], true);
    }

    /** Rotate webhook secret: delete old, register with fresh secret. Returns ['error' => ...] or ['success' => true]. */
    public function rotate(string $notification_url): array {
        $this->delete();
        $result = $this->register($notification_url);
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }
        update_option('HSCF_webhook_secret', $result['secret']);
        return ['success' => true];
    }
}
