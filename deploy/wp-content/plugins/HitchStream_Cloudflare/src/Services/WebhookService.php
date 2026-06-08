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
    public function register(string $notification_url): array {
        $result = $this->client->registerWebhook($notification_url);

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
            // 404 = no webhook configured yet. That's a normal state, not an
            // error — return an empty result so the UI shows "not registered".
            if (($result['status'] ?? 0) === 404) {
                return ['result' => null, 'registered' => false];
            }
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

    /**
     * Latest webhook-derived normalized state per input, from the webhook log.
     * Returns [ input_id => 'live'|'idle'|'reconnecting'|'error' ] for inputs
     * that have a valid (signed) event on record. Inputs with no webhook history
     * are simply absent, so callers can fall back to the Cloudflare API for them.
     */
    public function latestStatesByInput(array $input_ids): array {
        global $wpdb;
        $input_ids = array_values(array_filter(array_unique($input_ids)));
        if (empty($input_ids)) {
            return [];
        }
        $table = $wpdb->prefix . 'hs_webhook_log';
        $ph    = implode(',', array_fill(0, count($input_ids), '%s'));
        // Join each input to its newest signed, normalized event (MAX(id)).
        $sql = "SELECT t.input_id, t.normalized_state
                FROM {$table} t
                INNER JOIN (
                    SELECT input_id, MAX(id) AS max_id
                    FROM {$table}
                    WHERE signature_ok = 1 AND normalized_state IS NOT NULL
                      AND input_id IN ($ph)
                    GROUP BY input_id
                ) m ON t.id = m.max_id";
        // @phpcs:ignore — $table is internal, ids are placeholder-bound.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $input_ids), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $r) {
            if (!empty($r['input_id']) && !empty($r['normalized_state'])) {
                $out[$r['input_id']] = $r['normalized_state'];
            }
        }
        return $out;
    }
}
