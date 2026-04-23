# HitchStream Player — Webhooks + Richer Status Upgrade

## Context

The HSPlayer currently polls Cloudflare's `/lifecycle` endpoint directly via CORS every 10 seconds. This has two fundamental problems:

1. **Discovery delay:** When a stream starts, viewers see "Waiting for stream..." for up to 10 seconds after the stream is actually live. When it ends, the player may keep trying to buffer a dead stream.
2. **Poor status granularity:** The lifecycle endpoint only returns `{live: boolean, videoUID: string}`. The player cannot distinguish between "connected", "reconnecting", "client_disconnect", or "failed_to_connect" — it just sees a boolean.

Cloudflare now supports **Live Webhooks** (connected/disconnected/errored events) and **richer live input status** (8 connection states). This upgrade replaces the polling source from Cloudflare CORS to WordPress, fed by webhooks, while keeping the player's proven state machine, prebuffer gate, and custom poster logic intact.

**Key principle:** Webhooks are the real-time source of truth. The player still polls, but only a WordPress endpoint that serves webhook-updated state. Player code changes are minimal.

---

## Architecture

```
Cloudflare webhook ──POST──> WordPress (cf-live-webhook.php) ──> set_transient()
                                                                        ↓
   Player poll <──fetch── live-state.php <── get_transient() <─── cache layer
         │
         v
   Player state machine (unchanged)
```

### No SSE. No WebSocket. Keep it simple.

WordPress doesn't natively support long-lived connections. The 10s poll interval is acceptable for wedding streaming (low latency is not a priority). Webhooks eliminate the **detection delay** even though polling remains.

---

## Files — 6 total (2 new, 4 modified)

### NEW: `celebration-child/endpoints/cf-live-webhook.php`

Webhook receiver. Cloudflare POSTs to this when stream events occur.

**What it does:**
1. Validates `cf-webhook-signature` header using HMAC-SHA256 (shared secret from WP option `HSCF_webhook_secret`)
2. Extracts `event_type` (`live_input.connected`, `live_input.disconnected`, `live_input.errored`), `input_id`, and optional `error_code`
3. Maps Cloudflare event/state to a normalized state (see mapping table below)
4. Stores in WordPress transient `live_state_{input_id}` with TTL
5. Debounces: if same input receives two webhooks within 3s, only the latest counts
6. Returns `200 OK` to Cloudflare (fire-and-forget)

**State mapping:**

| Cloudflare event/state       | Normalized state | Player display            | TTL    |
|------------------------------|-----------------|---------------|--------|
| `connected`                  | `live`          | "Live"                    | 300s   |
| `reconnected`                | `live`          | "Live" (via "Reconnecting")| 300s  |
| `reconnecting`               | `reconnecting`  | "Reconnecting..."         | 120s   |
| `client_disconnect`          | `idle`          | "Paused/Ended"            | 300s   |
| `ttl_exceeded`               | `idle`          | "Paused/Ended"            | 300s   |
| `failed_to_connect`          | `error`         | "Stream error"            | 3600s  |
| `failed_to_reconnect`        | `error`         | "Stream error"            | 3600s  |
| `new_configuration_accepted`| `live`          | "Live"                    | 300s   |
| `errored` (any)              | `error`         | "Stream error" + code     | 3600s  |

**Webhook payload structure (from Cloudflare docs):**
```json
{
  "name": "Notification name",
  "data": {
    "notification_name": "...",
    "input_id": "abc123...",
    "event_type": "live_input.connected",
    "updated_at": "2025-01-13T..."
  },
  "live_input_errored": {
    "error": { "code": "ERR_GOP_OUT_OF_RANGE", "message": ".." }
  }
}
```

### NEW: `celebration-child/endpoints/live-state.php`

Public GET endpoint. Replaces the Cloudflare `/lifecycle` CORS call. The player fetches this every 10 seconds.

**URL:** `https://hitchstream.com/wp-content/themes/celebration-child/endpoints/live-state.php?inputId={inputId}`

**What it does:**
1. Validates `inputId` (same regex as CloudFlareEP.php: `^[A-Za-z0-9_-]+$`)
2. Reads transient `live_state_{inputId}`
3. If transient exists: returns webhook state + constructs HLS URL from videoUID
4. If transient expired: does a **one-time Cloudflare API probe** to check current state, caches it, returns result
5. Returns JSON with `Cache-Control: no-cache`

**Response format:**
```json
{
  "live": true,
  "state": "live",
  "videoUID": "abc123...",
  "hlsUrl": "https://customer-...cloudflarestream.com/abc123.../manifest/video.m3u8",
  "error_code": null,
  "source": "webhook",
  "ts": 1714000000
}
```

**Phase 1 note:** The one-time Cloudflare probe in step 4 is critical — without it, a viewer who opens the page while a stream is already live would see "Waiting for stream..." until the next webhook. The probe solves this. It's a single server-side API call (authenticated via WP options) — no rate limit concern for one call per page load.

### MODIFY: `celebration-child/HitchStream-Player.php`

**Change 1** (line 5): Replace `cloudflare_endpoint` with `live_state_endpoint`:
```php
$live_state_endpoint = esc_url(get_stylesheet_directory_uri() . '/endpoints/live-state.php');
```

**Change 2** (lines 17-18): Update JS variables:
```javascript
var liveStateEndpoint = "<?php echo $live_state_endpoint; ?>";
```

**Change 3** (server-side `isLive` computation): Before rendering the template, check the transient to determine if the stream is currently live:
```php
$live_state_key = 'live_state_' . esc_attr($inputId);
$state = get_transient($live_state_key);
$is_live = ($state && in_array($state['state'], ['live', 'reconnected', 'new_configuration_accepted']));
```
Pass this to the player via JS: `var serverIsLive = "<?php echo $is_live ? 'true' : 'false'; ?>";`

### MODIFY: `celebration-child/js/HSPlayerElement.js`

**Change 1** (startPolling, line ~234): Replace Cloudflare lifecycle URL:
```javascript
// OLD:
const lifecycleUrl = `https://customer-${CLOUDFLARE_CUSTOMER_ID}.cloudflarestream.com/${this.inputId}/lifecycle`;

// NEW:
const lifecycleUrl = `${window.liveStateEndpoint}?inputId=${encodeURIComponent(this.inputId)}`;
```

**Change 2** (response parsing): Handle new fields from the WP endpoint:
```javascript
const isLive = data && typeof data.live === 'boolean' ? data.live : false;
const vid = data && typeof data.videoUID === 'string' ? data.videoUID : null;
const rawState = data && data.state ? data.state : null;
const error_code = data && data.error_code ? data.error_code : null;
const source = data && data.source ? data.source : null;
```

**Change 3** (live branch, add `reconnecting` handling):
```javascript
if (rawState === 'reconnecting') {
    // Player is still in its current state (PLAYING or PREPARING)
    // Just show the reconnecting status overlay — do NOT stop playback
    this.updateStatus('reconnecting');
    // Do NOT call prepareToPlay or managePlayerState — keep streaming
    if (this.playerState === STATE.PLAYING) {
        // Already playing; just update status
        this.updateStatus('reconnecting');
    } else {
        // Not yet playing — if we have a saved HLS URL, keep preparing
        if (this.latestLiveHlsUrl) {
            this.updateStatus('reconnecting');
        }
    }
} else if (isLive && vid) {
    // ... existing live handling (prepareToPlay, updateStatus('live')) ...
}
```

**Change 4** (idle branch, add error_code handling):
```javascript
if (error_code) {
    // Map error codes to viewer-friendly messages
    const errorMessages = {
        'ERR_GOP_OUT_OF_RANGE': 'Stream issue — restarting...',
        'ERR_UNSUPPORTED_VIDEO_CODEC': 'Stream codec error',
        'ERR_UNSUPPORTED_AUDIO_CODEC': 'Audio stream error',
        'ERR_STORAGE_QUOTA_EXHAUSTED': 'Service temporarily unavailable',
        'ERR_MISSING_SUBSCRIPTION': 'Service unavailable'
    };
    this.updateStatus('error');
    // Log error code for ops (visible in debug panel)
    this._updateDebugPanel({ error_code });
}
```

**Change 5** (`updateStatus`, add `reconnecting` case):
```javascript
case 'reconnecting':
    this.showAnimatedStatus('Reconnecting');
    break;
```

**Change 6** (debug panel): Add `error_code` and `source` to the debug overlay display.

**What does NOT change:**
- Polling interval (10s) — stays the same
- Prebuffer gate logic — unchanged
- Fatal timer logic — unchanged
- HLS loading — unchanged
- State machine transitions (IDLE, PREPARING, PLAYING, FATAL) — `isLive` still drives them
- User gesture handling, audio sync detection, poster handling — all unchanged

### MODIFY: `celebration-child/functions.php`

**Add A:** Webhook verification helper:
```php
function verify_cf_webhook_signature($body, $signature) {
    if (!$signature) return false;
    $secret = get_option('HSCF_webhook_secret', '');
    if (!$secret) return false;
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, $signature);
}
```

**Add B:** Transient store function:
```php
function update_live_state_transient($input_id, $state, $video_uid = '', $error_code = '') {
    // Debounce: check if transient was set within last 3 seconds
    $last_update = get_transient("live_state_update_ts_{$input_id}");
    if ($last_update && (time() - $last_update) < 3) return;

    $ttl = 300;
    if ($state === 'reconnecting') $ttl = 120;
    if ($state === 'error') $ttl = 3600;

    $data = ['state' => $state, 'videoUID' => $video_uid, 'error_code' => $error_code, 'ts' => time()];
    set_transient("live_state_{$input_id}", $data, $ttl);
    set_transient("live_state_update_ts_{$input_id}", time(), 5);
}
```

**Add C:** Server-side live state checker (used by HitchStream-Player.php template):
```php
function compute_server_live_state($input_id) {
    $data = get_transient("live_state_{$input_id}");
    if (!$data) return false;
    return in_array($data['state'], ['live', 'reconnected', 'new_configuration_accepted']);
}
```

**Add D:** Cloudflare webhook registration helper (called on theme activation or via admin UI):
```php
// Registers webhooks with Cloudflare for a given live input
// Called during setup, not per-request
```

### MODIFY: `celebration-child/endpoints/CloudFlareEP.php`

**Minimal change.** Add webhook registration helpers for activation/deactivation. The existing `live_state` action stays for the admin panel (it still provides value — it returns `playlist_ready`, `targetduration`, `media_sequence` from the HLS probe, which webhooks don't carry).

---

## Edge Case Handling

| Scenario | How it's handled |
|------|------|
| Webhook delayed or lost | Transient TTL expires (120s-3600s). The `live-state.php` endpoint does a one-time Cloudflare probe when transient is missing, so the player gets accurate state without waiting. |
| Webhook fails (Cloudflare can't reach WordPress) | Cloudflare retries webhooks on failure. Player's polling is the ultimate fallback. |
| First page load during live stream | `live-state.php` does one-time Cloudflare probe to detect current state immediately. |
| Rapid webhook events (on/off) | Debounce in webhook handler (3s window). |
| Stream ends while viewer is watching | Webhook `disconnected` → transient set to `idle` → next player poll sees `live: false` → existing idle logic handles the rest (destroy HLS, show idle poster). |
| Multiple live inputs on same page | Each input gets its own transient (`live_state_{input_id}`). |
| WordPress transient cleanup | Use `set_transient` (shorter TTLs mitigate this risk). For production, consider object caching (Redis/Memcached). |
| Streamer reconnects mid-broadcast | `reconnecting` webhook → player shows "Reconnecting..." → `reconnected` webhook → player shows "Live". If player is already PLAYING, the HLS buffer carries through the reconnect automatically. |

---

## Migration / Rollout Plan

### Phase 1: Webhook infrastructure (deploy first, no visible player change)
1. Create `cf-live-webhook.php` — webhook receiver
2. Create `live-state.php` — player polling endpoint (with one-time Cloudflare probe fallback)
3. Add helpers to `functions.php` (verify, store, compute)
4. Register webhooks with Cloudflare via API (one-time setup per live input)
5. Test: manually trigger webhooks, verify transient updates

### Phase 2: Switch player to new endpoint
1. Update `HitchStream-Player.php` template
2. Update `HSPlayerElement.js` — change poll URL, handle `reconnecting` state and `error_code`
3. Deploy to **one staging live input** — verify all state transitions
4. Monitor for 1-2 wedding events
5. Roll out to all live inputs

### Phase 3: Error handling and admin alerts
1. Add error code mapping in player (viewer-facing messages)
2. Add server-side logging to WP debug log
3. Add admin notification for critical errors (`ERR_STORAGE_QUOTA_EXHAUSTED`, `ERR_MISSING_SUBSCRIPTION`)

### Incremental safety
Both the old Cloudflare CORS endpoint and new WP endpoint can coexist. Use a WP option (`hs_use_webhook_player`) as a feature flag. If something goes wrong, flip it back — zero downtime.

---

## Summary of Changes

| File | Type | Delta |
|------|------|-----|
| `endpoints/cf-live-webhook.php` | NEW | ~100 lines — webhook receiver |
| `endpoints/live-state.php` | NEW | ~80 lines — player polling endpoint with Cloudflare probe fallback |
| `endpoints/CloudFlareEP.php` | MODIFY | ~20 lines — webhook registration helpers |
| `HitchStream-Player.php` | MODIFY | ~10 lines — new endpoint URL, server-side isLive |
| `js/HSPlayerElement.js` | MODIFY | ~50 lines — poll URL, reconnect/error handling, debug panel |
| `functions.php` | MODIFY | ~80 lines — verify, store, compute helpers |

**Total: ~340 lines of new code, ~160 lines changed. No breaking changes to the state machine.**
