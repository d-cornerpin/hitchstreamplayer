<?php
/**
 * ChecklistService — the event-day checklist as one button.
 *
 * Automates RUNBOOK-live-state.md's pre-event checks from the admin UI:
 * primes EVERY input Cloudflare knows about (closing the new-input gap — a
 * brand-new Live Input gets its hs-state file created and is thereby enrolled
 * in the droplet refresher), verifies the static files viewers poll, the
 * cache header, refresher liveness, webhook wiring, and alert config.
 *
 * Each check returns a row: ['label','status' (pass|warn|fail),'detail'].
 * Fail = will break the viewer experience; warn = degraded-but-safe.
 */

namespace HS\Services;

class ChecklistService {

    private LiveInputService $liveInput;
    private WebhookService $webhook;

    public function __construct(?LiveInputService $liveInput = null, ?WebhookService $webhook = null) {
        $this->liveInput = $liveInput ?? new LiveInputService();
        $this->webhook   = $webhook   ?? new WebhookService();
    }

    /** Run every check, in dependency order. @return array{rows:array,ok:bool} */
    public function run(): array {
        $rows = [];

        // 1. Refresher heartbeat FIRST — priming (below) writes the files, which
        //    would mask a dead refresher. Only the refresher rewrites state files
        //    steadily (viewers poll the static file, webhooks only on transitions),
        //    so "newest write age" is a reliable liveness signal.
        $rows[] = $this->checkRefresherHeartbeat();

        // 2. Cloudflare API + input list (everything else needs it).
        [$inputsRow, $inputs] = $this->checkCloudflareInputs();
        $rows[] = $inputsRow;

        if ($inputs) {
            // 3. Prime every input via the real REST route (same path the
            //    refresher uses — also creates files for brand-new inputs).
            $rows[] = $this->primeInputs($inputs);

            // 4+5. Verify the static files viewers poll + the cache header.
            [$filesRow, $headerRow] = $this->checkStaticFiles($inputs);
            $rows[] = $filesRow;
            $rows[] = $headerRow;
        }

        // 6. Live webhook wiring (instant transitions).
        $rows[] = $this->checkWebhook();

        // 7. Alert emails.
        $rows[] = $this->checkAlerts();

        $ok = !array_filter($rows, fn($r) => $r['status'] === 'fail');
        return ['rows' => $rows, 'ok' => $ok];
    }

    // ── Individual checks ──────────────────────────────────────────

    private function checkRefresherHeartbeat(): array {
        $dir = WP_CONTENT_DIR . '/hs-state';
        $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];
        if (!$files) {
            return ['label' => 'Refresher heartbeat', 'status' => 'warn',
                'detail' => 'No state files yet (they will be created by priming below). Re-run the checklist to confirm the refresher picks them up.'];
        }
        $newest = max(array_map('filemtime', $files));
        $age = time() - $newest;
        if ($age <= 25) {
            return ['label' => 'Refresher heartbeat', 'status' => 'pass',
                'detail' => sprintf('State files are being refreshed (newest write %ds ago).', $age)];
        }
        if ($age <= 90) {
            return ['label' => 'Refresher heartbeat', 'status' => 'warn',
                'detail' => sprintf('Newest state write is %ds old — expected under ~25s. The refresher may be slow or recently restarted; re-run in a minute.', $age)];
        }
        return ['label' => 'Refresher heartbeat', 'status' => 'fail',
            'detail' => sprintf('Newest state write is %ds old. The droplet refresher looks DOWN — on the server run: systemctl status hs-live-state-refresher (webhooks still push transitions, but the probe backstop is gone).', $age)];
    }

    /** @return array{0:array,1:array} [row, inputs(uid=>name)] */
    private function checkCloudflareInputs(): array {
        try {
            $list = $this->liveInput->listWithDetails();
        } catch (\Throwable $e) {
            return [['label' => 'Cloudflare API', 'status' => 'fail', 'detail' => 'Could not list live inputs: ' . $e->getMessage()], []];
        }
        if (!is_array($list)) {
            return [['label' => 'Cloudflare API', 'status' => 'fail', 'detail' => 'Could not list live inputs (unexpected response).'], []];
        }
        $inputs = [];
        foreach ($list as $in) {
            $uid = is_object($in) ? ($in->uid ?? '') : ($in['uid'] ?? '');
            if ($uid === '') continue;
            $name = is_object($in) ? ($in->meta->name ?? $uid) : ($in['meta']['name'] ?? $uid);
            $inputs[$uid] = $name;
        }
        if (!$inputs) {
            return [['label' => 'Cloudflare API', 'status' => 'warn', 'detail' => 'Reachable, but no live inputs exist yet.'], []];
        }
        return [['label' => 'Cloudflare API', 'status' => 'pass',
            'detail' => count($inputs) . ' live input(s): ' . implode(', ', array_values($inputs)) . '.'], $inputs];
    }

    /** Prime each input through the real REST route (loopback). */
    private function primeInputs(array $inputs): array {
        $failed = [];
        foreach ($inputs as $uid => $name) {
            $url = rest_url('hitchstream/v1/live-state') . '?inputId=' . rawurlencode($uid);
            $resp = wp_remote_get($url, ['timeout' => 8]);
            $code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
            $body = is_wp_error($resp) ? [] : json_decode(wp_remote_retrieve_body($resp), true);
            if ($code !== 200 || empty($body['state'])) {
                $failed[] = $name . (is_wp_error($resp) ? ' (' . $resp->get_error_message() . ')' : " (HTTP {$code})");
            }
        }
        if ($failed) {
            return ['label' => 'Prime state files', 'status' => 'fail',
                'detail' => 'Priming failed for: ' . implode('; ', $failed) . '. Viewers of these inputs may see stale state.'];
        }
        return ['label' => 'Prime state files', 'status' => 'pass',
            'detail' => sprintf('All %d input(s) primed via the live-state endpoint (new inputs are now enrolled in the refresher).', count($inputs))];
    }

    /** Verify each static file over HTTP (what viewers actually poll) + the cache header. */
    private function checkStaticFiles(array $inputs): array {
        $failed = [];
        $cacheHeader = null;
        foreach ($inputs as $uid => $name) {
            $url = content_url('hs-state/' . rawurlencode($uid) . '.json');
            $resp = wp_remote_get($url, ['timeout' => 5]);
            if (is_wp_error($resp)) { $failed[] = "{$name} (" . $resp->get_error_message() . ')'; continue; }
            $code = wp_remote_retrieve_response_code($resp);
            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if ($cacheHeader === null) {
                $cc = wp_remote_retrieve_header($resp, 'cache-control');
                $cacheHeader = is_array($cc) ? implode(', ', $cc) : (string) $cc;
            }
            if ($code !== 200)                       { $failed[] = "{$name} (HTTP {$code})"; continue; }
            if (!is_array($data) || empty($data['state'])) { $failed[] = "{$name} (invalid JSON)"; continue; }
            $age = isset($data['ts']) ? time() - (int) $data['ts'] : PHP_INT_MAX;
            if ($age > 60)                           { $failed[] = "{$name} (stale — {$age}s old)"; }
        }

        $filesRow = $failed
            ? ['label' => 'Static state files', 'status' => 'fail',
               'detail' => 'Problems: ' . implode('; ', $failed) . '.']
            : ['label' => 'Static state files', 'status' => 'pass',
               'detail' => sprintf('All %d file(s) are web-accessible, valid JSON, and fresh — this is exactly what viewers poll.', count($inputs))];

        if ($cacheHeader !== null && stripos($cacheHeader, 'no-cache') !== false) {
            $headerRow = ['label' => 'Cache header', 'status' => 'pass', 'detail' => "Cache-Control: {$cacheHeader}."];
        } else {
            $headerRow = ['label' => 'Cache header', 'status' => 'warn',
                'detail' => 'Cache-Control: no-cache is missing (got: ' . ($cacheHeader !== '' && $cacheHeader !== null ? $cacheHeader : 'nothing') . '). Degraded-but-safe — the player forces no-store client-side — but fix per the runbook STOP gate (mod_headers / vhost) when convenient.'];
        }
        return [$filesRow, $headerRow];
    }

    private function checkWebhook(): array {
        try {
            $s = $this->webhook->status();
        } catch (\Throwable $e) {
            return ['label' => 'Live webhook', 'status' => 'warn', 'detail' => 'Could not check: ' . $e->getMessage() . '. (The probe backstop still covers state changes, ~10-20s slower.)'];
        }
        if (!empty($s['configured']) && !empty($s['policy_enabled'])) {
            return ['label' => 'Live webhook', 'status' => 'pass', 'detail' => 'Cloudflare notification webhook is configured and enabled — state changes reach viewers instantly.'];
        }
        return ['label' => 'Live webhook', 'status' => 'warn',
            'detail' => 'Webhook is not fully configured (Settings → Webhook → Set Up Live Webhook). The probe backstop still works, but updates are ~10-20s slower.'];
    }

    private function checkAlerts(): array {
        $recipients = function_exists('hs_parse_email_list')
            ? hs_parse_email_list(get_option('HSCF_alert_email', ''))
            : array_filter([get_option('HSCF_alert_email', '')]);
        $events = \HS\Config::alertEvents();
        if ($recipients && $events) {
            return ['label' => 'Email alerts', 'status' => 'pass',
                'detail' => count($events) . ' event(s) → ' . implode(', ', $recipients) . '.'];
        }
        return ['label' => 'Email alerts', 'status' => 'warn',
            'detail' => $recipients ? 'No alert events are ticked.' : 'No alert email configured — you will not be notified of stream problems.'];
    }
}
