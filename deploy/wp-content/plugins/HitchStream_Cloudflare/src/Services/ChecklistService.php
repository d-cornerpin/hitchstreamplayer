<?php
/**
 * ChecklistService — the event-day checklist as one button.
 *
 * Automates RUNBOOK-live-state.md's pre-event checks from the admin UI, plus
 * server-health checks: primes EVERY input Cloudflare knows about (closing the
 * new-input gap — a brand-new Live Input gets its hs-state file created and is
 * thereby enrolled in the droplet refresher), verifies the static files viewers
 * poll, the guest-facing player page, response times, server load, disk, SSL
 * expiry, wp-cron, the placeholder streamer, webhook wiring, and alert config.
 *
 * Each check returns a row: ['label','status' (pass|warn|fail),'detail'].
 * Fail = will break the viewer experience; warn = degraded-but-safe.
 * Every check is defensive — a broken subsystem must produce a row, not a fatal.
 */

namespace HS\Services;

class ChecklistService {

    private LiveInputService $liveInput;
    private WebhookService $webhook;

    /** Response-time samples (ms), piggybacked on requests the checks already make. */
    private array $restMs = [];
    private array $staticMs = [];

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
        [$inputsRow, $inputs, $lowLatencyRow] = $this->checkCloudflareInputs();
        $rows[] = $inputsRow;

        if ($inputs) {
            // 3. Low-latency mode audit (known LiveU breaker).
            $rows[] = $lowLatencyRow;

            // 4. Prime every input via the real REST route (same path the
            //    refresher uses — also creates files for brand-new inputs).
            $rows[] = $this->primeInputs($inputs);

            // 5+6. Verify the static files viewers poll + the cache header.
            [$filesRow, $headerRow] = $this->checkStaticFiles($inputs);
            $rows[] = $filesRow;
            $rows[] = $headerRow;

            // 7. The guest-facing player page itself.
            $rows[] = $this->checkPlayerPage(array_key_first($inputs));
        }

        // 8. Server responsiveness (from the timings gathered above).
        $rows[] = $this->checkResponseTimes();

        // 9-12. Box health: load, disk, SSL, wp-cron.
        $rows[] = $this->checkServerLoad();
        $rows[] = $this->checkApacheHeadroom();
        $rows[] = $this->checkDiskSpace();
        $rows[] = $this->checkSslCertificate();
        $rows[] = $this->checkWpCron();

        // 13. Placeholder streamer service.
        $rows[] = $this->checkStreamerService();

        // 14. Live webhook wiring (instant transitions).
        $rows[] = $this->checkWebhook();

        // 15. Alert emails.
        $rows[] = $this->checkAlerts();

        $rows = array_values(array_filter($rows));
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

    /** @return array{0:array,1:array,2:?array} [row, inputs(uid=>name), lowLatencyRow] */
    private function checkCloudflareInputs(): array {
        try {
            $list = $this->liveInput->listWithDetails();
        } catch (\Throwable $e) {
            return [['label' => 'Cloudflare API', 'status' => 'fail', 'detail' => 'Could not list live inputs: ' . $e->getMessage()], [], null];
        }
        if (!is_array($list) || isset($list['error'])) {
            return [['label' => 'Cloudflare API', 'status' => 'fail', 'detail' => 'Could not list live inputs' . (is_array($list) && isset($list['error']) ? ': ' . $list['error'] : ' (unexpected response).')], [], null];
        }
        $inputs = [];
        $lowLatency = [];
        foreach ($list as $in) {
            if (!is_object($in) || empty($in->uid)) continue;
            $name = $in->meta->name ?? $in->uid;
            $inputs[$in->uid] = $name;
            if (!empty($in->prefer_low_latency)) $lowLatency[] = $name;
        }
        if (!$inputs) {
            return [['label' => 'Cloudflare API', 'status' => 'warn', 'detail' => 'Reachable, but no live inputs exist yet.'], [], null];
        }
        $row = ['label' => 'Cloudflare API', 'status' => 'pass',
            'detail' => count($inputs) . ' live input(s): ' . implode(', ', array_values($inputs)) . '.'];
        $llRow = $lowLatency
            ? ['label' => 'Latency mode', 'status' => 'warn',
               'detail' => 'Low-Latency mode is ON for: ' . implode(', ', $lowLatency) . ' — known to break LiveU bonded encoders (stream connects but never goes playable). Turn it off unless you are sure the encoder supports it.']
            : ['label' => 'Latency mode', 'status' => 'pass',
               'detail' => 'All inputs use standard latency (the reliable choice for LiveU).'];
        return [$row, $inputs, $llRow];
    }

    /** Prime each input through the real REST route (loopback). */
    private function primeInputs(array $inputs): array {
        $failed = [];
        foreach ($inputs as $uid => $name) {
            $url = rest_url('hitchstream/v1/live-state') . '?inputId=' . rawurlencode($uid);
            $t0 = microtime(true);
            $resp = wp_remote_get($url, ['timeout' => 8]);
            $this->restMs[] = (microtime(true) - $t0) * 1000;
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
            $t0 = microtime(true);
            $resp = wp_remote_get($url, ['timeout' => 5]);
            $this->staticMs[] = (microtime(true) - $t0) * 1000;
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

    /** The page guests actually load. If this is broken, nothing else matters. */
    private function checkPlayerPage(string $uid): array {
        $url = home_url('/player/') . '?live=true&inputId=' . rawurlencode($uid);
        $t0 = microtime(true);
        $resp = wp_remote_get($url, ['timeout' => 10]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_wp_error($resp)) {
            return ['label' => 'Player page', 'status' => 'fail', 'detail' => 'The guest-facing player page did not load: ' . $resp->get_error_message() . '.'];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code !== 200 || strpos($body, 'HSPlayerConfig') === false) {
            return ['label' => 'Player page', 'status' => 'fail',
                'detail' => "The guest-facing player page is broken (HTTP {$code}" . (strpos($body, 'HSPlayerConfig') === false ? ', player config missing from page' : '') . '). Guests would not be able to watch — fix before anything else.'];
        }
        return ['label' => 'Player page', 'status' => 'pass',
            'detail' => "Loads with the player config present ({$ms}ms) — this is the page guests hit."];
    }

    /** From the timings piggybacked on the prime + static checks — no extra requests. */
    private function checkResponseTimes(): array {
        if (!$this->restMs && !$this->staticMs) {
            return ['label' => 'Response times', 'status' => 'warn', 'detail' => 'No timing samples (no inputs to check against).'];
        }
        $avg = fn(array $a) => $a ? (int) round(array_sum($a) / count($a)) : 0;
        $restAvg = $avg($this->restMs);
        $restMax = $this->restMs ? (int) round(max($this->restMs)) : 0;
        $statAvg = $avg($this->staticMs);
        $detail = sprintf('WordPress %dms avg / %dms max; static state files %dms avg. (First WordPress hit may include a Cloudflare probe.)', $restAvg, $restMax, $statAvg);
        if ($restAvg >= 4000 || $statAvg >= 1500) {
            return ['label' => 'Response times', 'status' => 'fail', 'detail' => $detail . ' The server is responding very slowly — investigate load before the event.'];
        }
        if ($restAvg >= 1500 || $statAvg >= 400) {
            return ['label' => 'Response times', 'status' => 'warn', 'detail' => $detail . ' Slower than usual — keep an eye on it.'];
        }
        return ['label' => 'Response times', 'status' => 'pass', 'detail' => $detail];
    }

    private function checkServerLoad(): array {
        if (!function_exists('sys_getloadavg')) {
            return ['label' => 'Server load', 'status' => 'warn', 'detail' => 'Load average unavailable on this host.'];
        }
        $load = sys_getloadavg();
        $five = $load[1] ?? $load[0];
        $cores = 1;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && ($n = substr_count($cpuinfo, "\nprocessor")) >= 0) {
            $cores = max(1, $n + (strpos($cpuinfo, 'processor') === 0 ? 1 : 0));
        }
        $ratio = $five / $cores;
        $detail = sprintf('Load %.2f over 5 min on %d core(s).', $five, $cores);
        if ($ratio >= 1.5) return ['label' => 'Server load', 'status' => 'fail', 'detail' => $detail . ' The box is overloaded — find what is eating CPU before the event (top / Virtualmin).'];
        if ($ratio >= 0.8) return ['label' => 'Server load', 'status' => 'warn', 'detail' => $detail . ' Busier than comfortable; keep an eye on it.'];
        return ['label' => 'Server load', 'status' => 'pass', 'detail' => $detail];
    }

    /**
     * Apache worker headroom — can the web server hold a wedding audience?
     *
     * Viewers poll the static hs-state JSON every 10s; each poll is served in
     * milliseconds but the worker stays bound to the idle connection for
     * KeepAliveTimeout afterwards. Under mpm_prefork that's a whole process, so
     * supportable viewers ≈ MaxRequestWorkers × 10s ÷ (KeepAliveTimeout + 0.2)
     * × 0.7 safety margin (leaves room for page/asset traffic). A 20-worker cap
     * chokes a 200-guest audience with the box otherwise idle — this check
     * exists so that can never silently regress (found 2026-07-13).
     */
    private function checkApacheHeadroom(): array {
        $label = 'Web server capacity';

        // Which MPM, and what's its MaxRequestWorkers?
        $mpm = null; $conf = '';
        foreach (['prefork', 'event', 'worker'] as $m) {
            $f = "/etc/apache2/mods-enabled/mpm_{$m}.conf";
            if (is_readable($f)) { $mpm = $m; $conf = (string) @file_get_contents($f); break; }
        }
        if ($mpm === null || $conf === '') {
            return ['label' => $label, 'status' => 'warn', 'detail' => 'Could not read the Apache MPM config on this host (fine after a hosting migration — re-point the check, see ChecklistService).'];
        }
        $workers = preg_match('/^\s*MaxRequestWorkers\s+(\d+)/mi', $conf, $m1) ? (int) $m1[1] : null;
        if (!$workers) {
            return ['label' => $label, 'status' => 'warn', 'detail' => "Read mpm_{$mpm}.conf but found no MaxRequestWorkers value."];
        }

        // KeepAliveTimeout (Apache default 5 if not set).
        $ka = 5;
        $main = @file_get_contents('/etc/apache2/apache2.conf');
        if (is_string($main) && preg_match('/^\s*KeepAliveTimeout\s+(\d+)/mi', $main, $m2)) { $ka = (int) $m2[1]; }

        // Busy workers right now (prefork: one process per worker).
        $running = 0;
        foreach (glob('/proc/[0-9]*/comm') ?: [] as $f) {
            if (trim((string) @file_get_contents($f)) === 'apache2') { $running++; }
        }

        if ($mpm === 'prefork') {
            $capacity = (int) floor($workers * 10 / ($ka + 0.2) * 0.7);
            $detail = sprintf('mpm_prefork: %d max workers, KeepAliveTimeout %ds, %d running now → roughly %d simultaneous viewers.', $workers, $ka, $running, $capacity);
            if ($capacity < 100) {
                return ['label' => $label, 'status' => 'fail', 'detail' => $detail . ' Too low for a wedding audience — raise MaxRequestWorkers/ServerLimit (and consider KeepAliveTimeout 2). See RUNBOOK-live-state.md.'];
            }
            if ($capacity < 200) {
                return ['label' => $label, 'status' => 'warn', 'detail' => $detail . ' OK for smaller events; tight for 200+ guests.'];
            }
            return ['label' => $label, 'status' => 'pass', 'detail' => $detail];
        }

        // event/worker MPMs handle idle keepalive connections asynchronously —
        // MaxRequestWorkers is about in-flight requests, which static polls barely dent.
        $detail = sprintf('mpm_%s: %d max workers (async keepalive), %d apache processes running.', $mpm, $workers, $running);
        return $workers >= 150
            ? ['label' => $label, 'status' => 'pass', 'detail' => $detail]
            : ['label' => $label, 'status' => 'warn', 'detail' => $detail . ' Low-ish; verify sizing for a full audience.'];
    }

    private function checkDiskSpace(): array {
        $free = @disk_free_space(WP_CONTENT_DIR);
        $total = @disk_total_space(WP_CONTENT_DIR);
        if (!$free || !$total) {
            return ['label' => 'Disk space', 'status' => 'warn', 'detail' => 'Could not read disk usage.'];
        }
        $freeGb = $free / (1024 ** 3);
        $pct = (int) round(100 * $free / $total);
        $writable = is_writable(WP_CONTENT_DIR . '/hs-state') || is_writable(WP_CONTENT_DIR);
        $detail = sprintf('%.1f GB free (%d%%).', $freeGb, $pct);
        if (!$writable) {
            return ['label' => 'Disk space', 'status' => 'fail', 'detail' => $detail . ' hs-state is NOT writable — state updates will fail.'];
        }
        if ($freeGb < 0.5) return ['label' => 'Disk space', 'status' => 'fail', 'detail' => $detail . ' Critically low — a full disk breaks state writes, logs, and uploads.'];
        if ($freeGb < 2)   return ['label' => 'Disk space', 'status' => 'warn', 'detail' => $detail . ' Getting low; free some space when convenient.'];
        return ['label' => 'Disk space', 'status' => 'pass', 'detail' => $detail];
    }

    private function checkSslCertificate(): array {
        $host = parse_url(home_url(), PHP_URL_HOST);
        if (!$host || !function_exists('openssl_x509_parse')) {
            return ['label' => 'SSL certificate', 'status' => 'warn', 'detail' => 'Could not check the certificate on this host.'];
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
        $client = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$client) {
            return ['label' => 'SSL certificate', 'status' => 'fail', 'detail' => "Could not make an SSL connection to {$host}: {$errstr}."];
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = isset($params['options']['ssl']['peer_certificate']) ? openssl_x509_parse($params['options']['ssl']['peer_certificate']) : null;
        if (!$cert || empty($cert['validTo_time_t'])) {
            return ['label' => 'SSL certificate', 'status' => 'warn', 'detail' => 'Connected, but could not parse the certificate.'];
        }
        $days = (int) floor(($cert['validTo_time_t'] - time()) / 86400);
        if ($days < 3)  return ['label' => 'SSL certificate', 'status' => 'fail', 'detail' => "Certificate for {$host} expires in {$days} day(s)! An expired cert blocks every guest. Renew NOW (Let's Encrypt auto-renew may have failed)."];
        if ($days < 14) return ['label' => 'SSL certificate', 'status' => 'warn', 'detail' => "Certificate for {$host} expires in {$days} days — check that auto-renew is working."];
        return ['label' => 'SSL certificate', 'status' => 'pass', 'detail' => "Valid for {$days} more days."];
    }

    private function checkWpCron(): array {
        if (!function_exists('_get_cron_array')) {
            return ['label' => 'Scheduled tasks', 'status' => 'warn', 'detail' => 'Could not read the wp-cron queue.'];
        }
        $cron = _get_cron_array();
        if (!is_array($cron) || !$cron) {
            return ['label' => 'Scheduled tasks', 'status' => 'pass', 'detail' => 'wp-cron queue is empty.'];
        }
        $oldest = min(array_keys($cron));
        $overdue = time() - $oldest;
        if ($overdue > 600) {
            return ['label' => 'Scheduled tasks', 'status' => 'warn',
                'detail' => sprintf('wp-cron has tasks %d minutes overdue — background jobs (alert re-checks, cleanup) may not be firing. The refresher normally keeps this moving.', (int) round($overdue / 60))];
        }
        return ['label' => 'Scheduled tasks', 'status' => 'pass', 'detail' => 'wp-cron is keeping up.'];
    }

    private function checkStreamerService(): array {
        try {
            $r = (new StreamerService())->listVideos();
        } catch (\Throwable $e) {
            return ['label' => 'Placeholder streamer', 'status' => 'warn', 'detail' => 'Could not check: ' . $e->getMessage() . '. Only matters if you use placeholder streams today.'];
        }
        if (isset($r['error'])) {
            return ['label' => 'Placeholder streamer', 'status' => 'warn',
                'detail' => 'Streamer service unreachable (' . $r['error'] . '). Only matters if you plan to run a placeholder stream today.'];
        }
        return ['label' => 'Placeholder streamer', 'status' => 'pass', 'detail' => 'streamer1 is reachable and authenticated.'];
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
