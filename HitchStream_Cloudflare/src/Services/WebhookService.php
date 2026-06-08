<?php
/**
 * WebhookService — wraps CloudflareClient for webhook management.
 */

namespace HS\Services;

use HS\CloudflareClient;
use HS\Config;

class WebhookService {

    /** Names used to find-or-create our Cloudflare objects (idempotency keys). */
    const DEST_NAME   = 'HitchStream Live Player';
    const POLICY_NAME = 'HitchStream Live Input Webhook';
    const ALERT_TYPE  = 'stream_live_notifications';

    private CloudflareClient $client;

    public function __construct(?CloudflareClient $client = null) {
        $this->client = $client ?? new CloudflareClient(Config::cloudflareAccountId());
    }

    /**
     * Set up the LIVE webhook in Cloudflare Notifications, idempotently.
     *
     * Find-or-create a webhook destination (matched by URL) pointing at our
     * receiver, set/sync its cf-webhook-auth secret, then find-or-create a
     * stream_live_notifications policy routing to it. Safe to run repeatedly —
     * re-running just re-syncs the secret and verifies the wiring.
     */
    public function setup(): array {
        $url    = $this->receiverUrl();
        $secret = $this->ensureSecret();

        // 1. Destination — match by URL so a renamed dest is still reused.
        $dest = $this->findDestinationByUrl($url);
        if ($dest && !empty($dest['id'])) {
            $res = $this->client->updateWebhookDestination($dest['id'], self::DEST_NAME, $url, $secret);
            if (!$res['success']) {
                return ['error' => 'Could not update webhook destination', 'detail' => $this->errDetail($res)];
            }
            $dest_id = $dest['id'];
            $dest_created = false;
        } else {
            $res = $this->client->createWebhookDestination(self::DEST_NAME, $url, $secret);
            if (!$res['success']) {
                return ['error' => 'Could not create webhook destination', 'detail' => $this->errDetail($res)];
            }
            // The 201 body doesn't reliably carry the id — re-list to fetch it.
            $created = $this->findDestinationByUrl($url);
            $dest_id = $created['id'] ?? ($this->decode($res)['result']['id'] ?? '');
            if (!$dest_id) {
                return ['error' => 'Destination created but its ID could not be determined.'];
            }
            $dest_created = true;
        }

        // 2. Policy — reuse any stream_live policy already routing to this dest.
        $policy = $this->findLivePolicyForDest($dest_id);
        if ($policy && !empty($policy['id'])) {
            $policy_id = $policy['id'];
            $policy_created = false;
        } else {
            $res = $this->client->createAlertPolicy([
                'name'       => self::POLICY_NAME,
                'alert_type' => self::ALERT_TYPE,
                'enabled'    => true,
                'mechanisms' => ['webhooks' => [['id' => $dest_id]]],
            ]);
            if (!$res['success']) {
                return ['error' => 'Could not create notification policy', 'detail' => $this->errDetail($res)];
            }
            $policy_id = $this->decode($res)['result']['id'] ?? '';
            $policy_created = true;
        }

        update_option('HSCF_webhook_url', $url);
        update_option('HSCF_webhook_dest_id', $dest_id);
        update_option('HSCF_webhook_policy_id', $policy_id);

        return [
            'success'        => true,
            'url'            => $url,
            'dest_id'        => $dest_id,
            'policy_id'      => $policy_id,
            'dest_created'   => $dest_created,
            'policy_created' => $policy_created,
        ];
    }

    /** Report whether the live webhook is fully wired (destination + routing policy). */
    public function status(): array {
        $url  = $this->receiverUrl();
        $dest = $this->findDestinationByUrl($url);
        if (!$dest || empty($dest['id'])) {
            return ['configured' => false, 'url' => $url];
        }
        $policy = $this->findLivePolicyForDest($dest['id']);
        return [
            'configured'     => (bool) $policy,
            'url'            => $url,
            'dest_id'        => $dest['id'],
            'dest_name'      => $dest['name'] ?? '',
            'policy_id'      => $policy['id'] ?? '',
            'policy_enabled' => (bool) ($policy['enabled'] ?? false),
        ];
    }

    /** Remove the live webhook: delete the routing policy, then the destination. */
    public function remove(): array {
        $url     = $this->receiverUrl();
        $removed = [];
        $dest    = $this->findDestinationByUrl($url);
        if ($dest && !empty($dest['id'])) {
            $policy = $this->findLivePolicyForDest($dest['id']);
            if ($policy && !empty($policy['id'])) {
                $this->client->deleteAlertPolicy($policy['id']);
                $removed[] = 'policy';
            }
            $this->client->deleteWebhookDestination($dest['id']);
            $removed[] = 'destination';
        }
        delete_option('HSCF_webhook_dest_id');
        delete_option('HSCF_webhook_policy_id');
        // Secret is kept (harmless) so a later re-setup reuses the same value.
        return ['success' => true, 'removed' => $removed];
    }

    /** Rotate the cf-webhook-auth secret: new value on both the CF destination and WP, atomically enough. */
    public function rotate(): array {
        $url  = $this->receiverUrl();
        $dest = $this->findDestinationByUrl($url);
        if (!$dest || empty($dest['id'])) {
            return ['error' => 'No live webhook is set up yet. Click "Set Up Live Webhook" first.'];
        }
        $secret = bin2hex(random_bytes(24)); // 48 hex chars, alphanumeric
        $res = $this->client->updateWebhookDestination($dest['id'], self::DEST_NAME, $url, $secret);
        if (!$res['success']) {
            return ['error' => 'Could not update the destination secret', 'detail' => $this->errDetail($res)];
        }
        update_option('HSCF_webhook_secret', $secret);
        return ['success' => true];
    }

    // ── Internal helpers ──────────────────────────────────────────────

    /** Receiver URL: saved option → default theme endpoint. */
    private function receiverUrl(): string {
        $url = (string) get_option('HSCF_webhook_url', '');
        if (!$url) {
            $url = rtrim(home_url('/'), '/') . '/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php';
        }
        return $url;
    }

    /** Current secret, or a freshly generated one (persisted) if missing/invalid. */
    private function ensureSecret(): string {
        $secret = (string) get_option('HSCF_webhook_secret', '');
        // CF requires the secret to be alphanumeric, <=100 chars.
        if (!preg_match('/^[A-Za-z0-9]{16,100}$/', $secret)) {
            $secret = bin2hex(random_bytes(24));
            update_option('HSCF_webhook_secret', $secret);
        }
        return $secret;
    }

    private function decode(array $result): array {
        $data = json_decode($result['body'] ?? '', true);
        return is_array($data) ? $data : [];
    }

    private function errDetail(array $res): string {
        $data = $this->decode($res);
        if (!empty($data['errors'])) {
            return wp_json_encode($data['errors']);
        }
        return 'HTTP ' . ($res['status'] ?? '?');
    }

    private function findDestinationByUrl(string $url): ?array {
        $res = $this->client->listWebhookDestinations();
        if (!$res['success']) {
            return null;
        }
        foreach (($this->decode($res)['result'] ?? []) as $d) {
            if (($d['url'] ?? '') === $url) {
                return $d;
            }
        }
        return null;
    }

    private function findLivePolicyForDest(string $dest_id): ?array {
        $res = $this->client->listAlertPolicies();
        if (!$res['success']) {
            return null;
        }
        foreach (($this->decode($res)['result'] ?? []) as $p) {
            if (($p['alert_type'] ?? '') !== self::ALERT_TYPE) {
                continue;
            }
            foreach (($p['mechanisms']['webhooks'] ?? []) as $w) {
                if (($w['id'] ?? '') === $dest_id) {
                    return $p;
                }
            }
        }
        return null;
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
