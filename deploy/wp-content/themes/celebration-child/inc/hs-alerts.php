<?php
/**
 * HitchStream email alerts — shared logic.
 *
 * Loaded on EVERY request (via functions.php) so the wp-cron recovery handler
 * is always registered, and so the webhook receiver (cf-live-webhook.php, which
 * bootstraps WP via wp-load) and the player's live-state poll can all use it.
 *
 * Behaviour for live-stream errors: DEBOUNCED. A transient Cloudflare `errored`
 * event (common with bonded encoders like LiveU — the stream keeps running and
 * self-recovers) does NOT email immediately. We start a "watch": if the input is
 * still not live after HS_ERROR_DEBOUNCE_SECONDS, send the error email; when it
 * comes back, send a "recovered" email. Brief blips stay silent.
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('HS_ERROR_DEBOUNCE_SECONDS')) {
    // How long an error must persist before we actually email about it.
    define('HS_ERROR_DEBOUNCE_SECONDS', 10);
}

/**
 * Plain-English alert catalog: key => [label, description].
 * The first six keys MUST match SettingsPage::ALERT_EVENTS in the plugin (the
 * admin checkboxes). `live_stream_recovered` is not a checkbox — it's paired
 * with live_stream_error and sent automatically — but lives here for its text.
 */
function hs_alert_events_catalog(): array {
    return [
        'storage_full'            => ['Storage Full', 'Cloudflare Stream storage is full — recordings and live streams may fail until space is freed or the plan is upgraded.'],
        'no_subscription'         => ['No Cloudflare Subscription', 'The Cloudflare Stream subscription is missing or inactive. Streaming will not work until it is restored.'],
        'live_stream_started'     => ['Live Stream Started', 'A live stream went live — a feed connected to the live input.'],
        'live_stream_ended'       => ['Live Stream Ended', 'The live stream feed disconnected and the stream has ended.'],
        'live_stream_reconnected' => ['Live Stream Reconnected', 'A live stream dropped and successfully reconnected.'],
        'live_stream_error'       => ['Live Stream Error', 'A live stream feed reported an error and stayed down (failed to connect or reconnect).'],
        'live_stream_recovered'   => ['Live Stream Recovered', 'A live stream that had errored is back up and live again.'],
    ];
}

/**
 * Which alert events are enabled (array of keys). Reads HSCF_alert_events; falls
 * back to migrating the legacy HSCF_alert_codes CSV, else the two critical defaults.
 */
function hs_alert_enabled_events(): array {
    $val = get_option('HSCF_alert_events', null);
    if (is_array($val)) {
        return $val;
    }
    $legacy = get_option('HSCF_alert_codes', null);
    if (is_string($legacy) && $legacy !== '') {
        $events = [];
        if (strpos($legacy, 'ERR_STORAGE_QUOTA_EXHAUSTED') !== false) $events[] = 'storage_full';
        if (strpos($legacy, 'ERR_MISSING_SUBSCRIPTION') !== false)    $events[] = 'no_subscription';
        return $events ?: ['storage_full', 'no_subscription'];
    }
    return ['storage_full', 'no_subscription'];
}

/**
 * Map a webhook outcome to a single alert event key (or '' for none).
 * $prev_state is the state stored BEFORE this event, so start/end/reconnect only
 * fire on an actual transition rather than on every repeated webhook.
 */
function hs_alert_key_for(?string $normalized, string $error_code, string $prev_state): string {
    if ($error_code === 'ERR_STORAGE_QUOTA_EXHAUSTED') return 'storage_full';
    if ($error_code === 'ERR_MISSING_SUBSCRIPTION')    return 'no_subscription';
    if ($normalized === 'error')                       return 'live_stream_error';
    if ($normalized === 'live') {
        // A return to 'live' from 'reconnecting' is a recovery; from anything
        // else (idle / error / first connect) it's a fresh start.
        if ($prev_state === 'reconnecting') return 'live_stream_reconnected';
        if ($prev_state !== 'live')         return 'live_stream_started';
        return '';
    }
    if ($normalized === 'idle' && in_array($prev_state, ['live', 'reconnecting'], true)) return 'live_stream_ended';
    return '';
}

/** Split a free-form string into a list of valid, unique email addresses. */
function hs_parse_email_list($raw): array {
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && is_email($candidate)) {
            $out[] = $candidate;
        }
    }
    return array_values(array_unique($out));
}

/** Is this input producing playable video right now? (public lifecycle probe) */
function hs_probe_is_live(string $input_id): bool {
    $cust = (string) get_option('HSCF_customer_id', '');
    if ($cust === '' || $input_id === '') {
        return false;
    }
    $resp = wp_remote_get("https://customer-{$cust}.cloudflarestream.com/{$input_id}/lifecycle", ['timeout' => 6]);
    if (is_wp_error($resp)) {
        return false;
    }
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    return is_array($d) && !empty($d['live']);
}

/** Humanize a duration in seconds for the recovery email. */
function hs_human_secs(int $s): string {
    if ($s < 60) return $s . ' second' . ($s === 1 ? '' : 's');
    $m = (int) round($s / 60);
    return $m . ' minute' . ($m === 1 ? '' : 's');
}

/**
 * Send an alert email for a notable event, if it's enabled and not throttled.
 * Uses wp_mail(), so it routes through whatever mailer the site has configured.
 * Throttle: at most one email per event per input per 5 minutes.
 */
function hs_dispatch_alert(string $event_key, string $input_id, string $event_type, string $error_code, string $correlation_id): void {
    if ($event_key === '') {
        return;
    }

    $recipients = hs_parse_email_list(get_option('HSCF_alert_email', ''));
    if (empty($recipients)) {
        return;
    }

    if (!in_array($event_key, hs_alert_enabled_events(), true)) {
        return;
    }

    $catalog = hs_alert_events_catalog();
    if (!isset($catalog[$event_key])) {
        return;
    }
    [$label, $desc] = $catalog[$event_key];

    $throttle_key = "hs_alert_throttle_{$event_key}_{$input_id}";
    if (get_transient($throttle_key)) {
        return;
    }
    set_transient($throttle_key, true, 300);

    // The live-stream lifecycle alerts include a one-click link to the player
    // with the debug panel open, so the recipient can jump straight to checking
    // the stream. Account-level alerts (storage / subscription) don't.
    $with_link = ['live_stream_started', 'live_stream_ended', 'live_stream_reconnected', 'live_stream_error', 'live_stream_recovered'];
    $debug_url = '';
    if (in_array($event_key, $with_link, true) && $input_id) {
        $debug_url = home_url('/player/') . '?live=true&inputId=' . rawurlencode($input_id) . '&debug=1';
    }

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = "[HitchStream] {$label}";
    $lines = [$desc, ''];
    if ($debug_url) {
        $lines[] = 'Check on the stream (opens the player with the debug panel):';
        $lines[] = $debug_url;
        $lines[] = '';
    }
    $lines[] = "Stream input: {$input_id}";
    $lines[] = 'Time: ' . current_time('mysql');
    if ($error_code) {
        $lines[] = "Error code: {$error_code}";
    }
    $lines[] = "Event: {$event_type}";
    $lines[] = "Reference: {$correlation_id}";
    $lines[] = '';
    $lines[] = "Site: {$site}";
    $lines[] = 'See the Activity page in WP Admin for full details.';
    $body = implode("\n", $lines) . "\n";

    $sent = wp_mail($recipients, $subject, $body, ['Content-Type: text/plain; charset=utf-8']);
    error_log(
        '[HitchStream] alert email ' . ($sent ? 'sent' : 'FAILED')
        . ' to ' . implode(', ', $recipients) . " for {$event_key} on {$input_id} (corr: {$correlation_id})"
    );
}

// ── Debounced error + recovery ───────────────────────────────────────────────

/**
 * Begin watching an input that just reported an error. We do NOT email yet —
 * we wait out HS_ERROR_DEBOUNCE_SECONDS (checked by hs_check_error_pending) so a
 * transient blip that self-recovers stays silent.
 */
function hs_begin_error_watch(string $input_id, string $event_type, string $error_code, string $corr): void {
    if (is_array(get_transient("hs_error_pending_{$input_id}"))) {
        return; // already watching this input
    }
    set_transient("hs_error_pending_{$input_id}", [
        'since'      => time(),
        'emailed'    => false,
        'event'      => $event_type,
        'error_code' => $error_code,
        'corr'       => $corr,
    ], HOUR_IN_SECONDS);
    if (!wp_next_scheduled('hs_error_recheck', [$input_id])) {
        wp_schedule_single_event(time() + HS_ERROR_DEBOUNCE_SECONDS + 2, 'hs_error_recheck', [$input_id]);
    }
}

/**
 * Resolve a pending error watch. Called by wp-cron (hs_error_recheck), by the
 * player's live-state poll, and on a 'live' webhook. Sends the (debounced) error
 * email once the error has persisted, and a "recovered" email when it returns.
 */
function hs_check_error_pending($input_id): void {
    $input_id = (string) $input_id;
    $p = get_transient("hs_error_pending_{$input_id}");
    if (!is_array($p)) {
        return; // nothing pending — cheap no-op (the common case on every poll)
    }

    // Single-flight: this runs on EVERY viewer's live-state poll, so during an
    // error with many concurrent viewers we must not stampede the lifecycle probe
    // or duplicate emails. Let one check run per ~8s; the rest bail instantly,
    // keeping player polls fast. Whoever holds the lock reschedules the next
    // check, so the watch chain is preserved. 8s < the 10s debounce / 12s cron.
    $lock = "hs_error_check_lock_{$input_id}";
    if (get_transient($lock)) {
        return;
    }
    set_transient($lock, 1, 8);

    if (hs_probe_is_live($input_id)) {
        if (!empty($p['emailed'])) {
            hs_send_recovery_alert($input_id, $p);
        }
        delete_transient("hs_error_pending_{$input_id}");
        return;
    }

    $down_for = time() - (int) ($p['since'] ?? time());
    if (empty($p['emailed']) && $down_for >= HS_ERROR_DEBOUNCE_SECONDS) {
        hs_dispatch_alert('live_stream_error', $input_id, (string) ($p['event'] ?? 'live_input.errored'), (string) ($p['error_code'] ?? ''), (string) ($p['corr'] ?? ''));
        $p['emailed'] = true;
        set_transient("hs_error_pending_{$input_id}", $p, HOUR_IN_SECONDS);
    }

    // Keep watching — to cross the threshold, or to catch the recovery.
    if (!wp_next_scheduled('hs_error_recheck', [$input_id])) {
        wp_schedule_single_event(time() + 12, 'hs_error_recheck', [$input_id]);
    }
}
add_action('hs_error_recheck', 'hs_check_error_pending');

/**
 * Send the "Live Stream Recovered" email. Paired with the error alert: only
 * sent if live_stream_error is enabled (so recovery follows the same toggle).
 */
function hs_send_recovery_alert(string $input_id, array $pending): void {
    if (!in_array('live_stream_error', hs_alert_enabled_events(), true)) {
        return;
    }
    $recipients = hs_parse_email_list(get_option('HSCF_alert_email', ''));
    if (empty($recipients)) {
        return;
    }
    [$label, $desc] = hs_alert_events_catalog()['live_stream_recovered'];
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $debug_url = home_url('/player/') . '?live=true&inputId=' . rawurlencode($input_id) . '&debug=1';
    $down_secs = isset($pending['since']) ? max(0, time() - (int) $pending['since']) : 0;
    $lines = [
        $desc,
        '',
        'Check on the stream (opens the player with the debug panel):',
        $debug_url,
        '',
        "Stream input: {$input_id}",
        'Time: ' . current_time('mysql'),
        'It was down for about ' . hs_human_secs($down_secs) . '.',
        '',
        "Site: {$site}",
    ];
    $body = implode("\n", $lines) . "\n";
    $sent = wp_mail($recipients, "[HitchStream] {$label}", $body, ['Content-Type: text/plain; charset=utf-8']);
    error_log('[HitchStream] recovery email ' . ($sent ? 'sent' : 'FAILED') . ' to ' . implode(', ', $recipients) . " for {$input_id} (down ~{$down_secs}s)");
}
