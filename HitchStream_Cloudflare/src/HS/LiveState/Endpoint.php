<?php
/**
 * Live-state REST endpoint handler.
 *
 * GET /wp-json/hitchstream/v1/live-state?inputId=<id>
 *
 * Priority: flat-file → transient → /lifecycle probe.
 * Supports ETag/304, X-HS-Correlation-Id, single-flight lock.
 */

namespace HS\LiveState;

require_once __DIR__ . '/StateWriter.php';
use HS\LiveState\StateWriter;

class Endpoint
{
    /**
     * Register the REST route and ensure the flat-state directory exists.
     */
    public static function register()
    {
        add_action('rest_api_init', [__CLASS__, 'register_route']);
        // Lazy-create the flat-state directory once per process. is_dir is
        // cheap and wp_mkdir_p is idempotent; skipping the work on subsequent
        // calls in the same request via a static guard keeps it free.
        add_action('init', [__CLASS__, 'ensureStateDir']);
    }

    /** Ensure the hs-state directory exists. Idempotent. */
    public static function ensureStateDir()
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        $dir = WP_CONTENT_DIR . '/hs-state';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    /**
     * Register the hitchstream/v1/live-state route.
     */
    public static function register_route()
    {
        register_rest_route('hitchstream/v1', '/live-state', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_request'],
            'permission_callback' => '__return_true', // Public endpoint, no auth needed
        ]);
    }

    /**
     * Handle a GET /wp-json/hitchstream/v1/live-state request.
     */
    public static function handle_request(\WP_REST_Request $request)
    {
        $input_id = $request->get_param('inputId');

        // Validate inputId.
        if (!$input_id || !preg_match('/^[A-Za-z0-9_-]+$/', $input_id)) {
            return self::error_response(400, 'Invalid or missing inputId', 'invalid_input_id');
        }

        // Generate per-request correlation ID.
        $correlation_id = wp_generate_uuid4();

        // Check flat file first (fastest path).
        $state_data = self::read_flat_file($input_id);

        if ($state_data === null) {
            // Try transient.
            $state_data = self::read_transient($input_id);
        }

        if ($state_data === null) {
            // Cache miss — acquire single-flight lock (B2.4).
            $lock_key = "hs_probe_lock_{$input_id}";
            $acquired = wp_cache_add('hs_probe_lock_' . md5($input_id), '1', 5);

            if ($acquired) {
                // We got the lock — do the probe.
                $state_data = self::probe_lifecycle($input_id);
                if ($state_data) {
                    self::write_state($input_id, $state_data, 'probe');
                    $state_data['source'] = 'probe';
                } else {
                    // Probe failed — no info available.
                    return self::error_response(502, 'Upstream unavailable', 'upstream_unavailable');
                }
            } else {
                // Lock held — return previous state as coalesced (B2.4).
                $state_data = self::read_transient($input_id);
                if ($state_data) {
                    $state_data['source'] = 'coalesced';
                } else {
                    // No prior state either — serve a probe anyway.
                    $probe_state = self::probe_lifecycle($input_id);
                    if ($probe_state) {
                        self::write_state($input_id, $probe_state, 'probe');
                        $state_data = $probe_state;
                        $state_data['source'] = 'probe';
                    } else {
                        return self::error_response(502, 'Upstream unavailable', 'upstream_unavailable');
                    }
                }
            }
        } else {
            // Cache hit. Keep the source stamped when this state was WRITTEN
            // (a webhook at a transition, or a probe on a refresh) instead of
            // mislabeling every served-from-cache read as 'webhook' — the old
            // behaviour reported "webhook" even when no webhook had fired in
            // minutes, which made the debug panel impossible to trust.
            if (empty($state_data['source']) || !in_array($state_data['source'], ['webhook', 'probe', 'coalesced'], true)) {
                $state_data['source'] = 'probe';
            }
        }

        // Drive the debounced-error / recovery alert check off the player's
        // polling — a reliable ~10s tick during a live stream, more dependable
        // than wp-cron's loopback. Cheap no-op unless a watch is pending for this
        // input (the handler early-returns). Defined in the child theme's
        // inc/hs-alerts.php, loaded on every request via functions.php.
        if (function_exists('hs_check_error_pending')) {
            hs_check_error_pending($input_id);
        }

        // Enforce §4.1 state → field-population contract.
        $state_data = self::enforce_contract($input_id, $state_data);

        // Build response with headers.
        $etag = '"' . md5(json_encode($state_data)) . '"';
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';

        if ($if_none_match === $etag) {
            return self::not_modified_response($etag, $correlation_id);
        }

        $body = json_encode($state_data, JSON_UNESCAPED_SLASHES);

        $response = new \WP_REST_Response(json_decode($body, true), 200);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        $response->header('ETag', $etag);
        $response->header('X-HS-Correlation-Id', $correlation_id);
        return $response;
    }

    /**
     * Is a cached state entry still fresh enough to serve without re-probing?
     *
     * Critical: the flat-file has NO TTL, so without this it served a stale state
     * (e.g. "idle") forever, and the probe never re-ran to notice the input went
     * live. Webhooks were supposed to push updates, but live-input webhooks come
     * from Cloudflare Notifications (cf-webhook-auth) which isn't wired up — so
     * the probe must keep itself current. ~12s refreshes state on the player's
     * normal poll cadence; the single-flight lock keeps /lifecycle calls cheap.
     */
    private static function is_fresh($data)
    {
        return is_array($data) && !empty($data['state'])
            && isset($data['ts']) && (time() - (int) $data['ts']) <= 12;
    }

    /**
     * Read flat-file state. Returns null if missing, invalid, or stale.
     */
    private static function read_flat_file($input_id)
    {
        $file = WP_CONTENT_DIR . "/hs-state/{$input_id}.json";
        if (!is_file($file)) {
            return null;
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        $data = json_decode($contents, true);
        return self::is_fresh($data) ? $data : null;
    }

    /**
     * Read transient state. Returns null if missing or stale.
     */
    private static function read_transient($input_id)
    {
        $data = get_transient("hs_live_state_{$input_id}");
        return self::is_fresh($data) ? $data : null;
    }

    /**
     * Probe /lifecycle (B2.5) — replaces the old /videos probe.
     */
    private static function probe_lifecycle($input_id)
    {
        $customer_code = get_option('HSCF_customer_id', '');
        if (!$customer_code) {
            return null;
        }

        $url = "https://customer-{$customer_code}.cloudflarestream.com/{$input_id}/lifecycle";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("[HitchStream] /lifecycle probe failed: input={$input_id} http={$httpCode} err=" . ($curlErr ?: 'none'));
            return null;
        }

        $lifecycle = json_decode($resp, true);
        // NB: an IDLE input returns {"videoUID":null,...} — the key is present
        // but null. isset() is false for null, so isset() here wrongly rejected
        // every idle input (→ 502 instead of an "idle" state). array_key_exists
        // accepts the null and lets the idle path below handle it.
        if (!is_array($lifecycle) || !array_key_exists('videoUID', $lifecycle)) {
            return null;
        }

        $video_uid = $lifecycle['videoUID'] ?: '';
        $live = !empty($lifecycle['live']) || ($lifecycle['status'] ?? '') === 'live-inprogress';

        if ($live && $video_uid) {
            return [
                'state'      => 'live',
                'videoUID'   => $video_uid,
                'hlsUrl'     => "https://customer-{$customer_code}.cloudflarestream.com/{$video_uid}/manifest/video.m3u8",
                'errorCode'  => null,
                'source'     => 'probe',
                'ts'         => time(),
            ];
        }

        return [
            'state'      => 'idle',
            'videoUID'   => null,
            'hlsUrl'     => null,
            'errorCode'  => null,
            'source'     => 'probe',
            'ts'         => time(),
        ];
    }

    /**
     * Write state to both transient and flat-file (B2.2a).
     */
    private static function write_state($input_id, $data, $source)
    {
        $ttl = 300;
        if ($data['state'] === 'reconnecting') $ttl = 120;
        if ($data['state'] === 'error')         $ttl = 3600;

        StateWriter::write($input_id, $data, $ttl);
    }

    /**
     * Enforce §4.1 state → field-population contract.
     */
    private static function enforce_contract($input_id, $data)
    {
        $state = $data['state'] ?? '';

        switch ($state) {
            case 'live':
            case 'reconnecting':
                // videoUID + hlsUrl must be populated. If missing, set null.
                $data['videoUID']   = $data['videoUID']   ?: null;
                $data['hlsUrl']     = $data['hlsUrl']     ?: null;
                $data['errorCode']  = null;
                break;

            case 'idle':
                // videoUID + hlsUrl MUST be null.
                $data['videoUID']   = null;
                $data['hlsUrl']     = null;
                // errorCode MAY be present (error that caused idle).
                break;

            case 'error':
                // videoUID + hlsUrl MUST be null. errorCode MUST be populated.
                $data['videoUID']   = null;
                $data['hlsUrl']     = null;
                break;

            default:
                // Unknown state — treat as idle.
                $data['state']      = 'idle';
                $data['videoUID']   = null;
                $data['hlsUrl']     = null;
                break;
        }

        // source MUST always be one of {webhook, probe, coalesced}. Default to
        // 'probe' (the safe "we checked Cloudflare" value), never 'webhook' —
        // we must not claim a webhook drove a state we can't attribute to one.
        $valid_sources = ['webhook', 'probe', 'coalesced'];
        if (!in_array($data['source'] ?? '', $valid_sources, true)) {
            $data['source'] = 'probe';
        }

        // ts must be a unix timestamp.
        if (!isset($data['ts']) || !is_numeric($data['ts'])) {
            $data['ts'] = time();
        }

        // videoUID/hlsUrl must be scalar.
        if ($data['videoUID'] !== null && !is_scalar($data['videoUID'])) {
            $data['videoUID'] = null;
        }
        if ($data['hlsUrl'] !== null && !is_scalar($data['hlsUrl'])) {
            $data['hlsUrl'] = null;
        }

        return $data;
    }

    /**
     * Return 304 Not Modified response.
     */
    private static function not_modified_response($etag, $correlation_id)
    {
        $response = new \WP_REST_Response(null, 304);
        $response->header('Cache-Control', 'no-store');
        $response->header('ETag', $etag);
        $response->header('X-HS-Correlation-Id', $correlation_id);
        return $response;
    }

    /**
     * Return error response per §4.1.
     */
    private static function error_response($http_code, $message, $error_code)
    {
        $response = new \WP_REST_Response(null, $http_code);
        $response->set_data([
            'error'  => $message,
            'code'   => $error_code,
        ]);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

}
