# HitchStream Player

A custom HLS live-streaming video player integrated into a WordPress wedding/event page theme, powered by Cloudflare Stream. This document explains the project architecture, key components, and how everything fits together.

---

## Overview

HitchStream is a WordPress-based platform for live-streaming wedding events (and other celebrations) to guests. The system consists of:

- **Parent theme** (`celebration/`) — A full WordPress theme with custom post types, template pages, shortcodes, and WooCommerce support.
- **Child theme** (`celebration-child/`) — Contains all HitchStream-specific logic: the custom video player, page templates, cloudflare integration, and webhook infrastructure.
- **Cloudflare Stream** — Used for live ingest, transcoding, and HLS delivery. All API calls go through WordPress (server-side proxy) or webhook events — never directly from the browser.
- **HSPlayer** (`<hs-video>`) — A custom web component (HTML5 Shadow DOM) that plays **live HLS streams** (with prebuffer gate, status overlay, debug panel) and **pre-recorded VOD videos** (loaded directly without polling).

Two player modes:
- **Live mode** — Polls WordPress every 10s for stream status, loads HLS manifest only after user gesture and live detection.
- **VOD mode** — Loads a pre-recorded Cloudflare Stream video directly via its video UID, no polling or state machine.

The live stream workflow: a streamer pushes video to a Cloudflare live input → Cloudflare sends webhooks to WordPress → WordPress caches state in transients → the player polls a WordPress endpoint every 10 seconds → the player loads the HLS manifest once the stream is confirmed live.

---

## Architecture

```
Streamer ──RTMP/SRT──> Cloudflare Live Input
                       │
            ┌──────────┴──────────┐
            │                     │
         Webhooks (POST)        HLS Manifest
            │                     │
  WordPress Webhook            Player
    Receiver                  Polls WP
            │                     │
       set_transient()      Gets HLS URL
            │                     │
            └─────────┐            │
                      │            │
           WP live-state     Load
           Endpoint (GET)    Source
                      │
           Player State Machine
           (IDLE → PREPARING → PLAYING)


VOD Mode (separate path):
  inputId (video UID) -> loadVideoDirectly() -> Hls.js loadSource() -> playback
  (No polling, no state machine, no prebuffer gate)
```

---

## Directory Structure

```
celebration/                    # Parent WordPress theme
  admin-style.php
  class-tgm-plugin-activation.php
  config-meta-boxes.php         # Custom meta boxes for event pages
  functions.php                 # Theme functions, shortcode registration
  header.php, footer.php        # Theme templates
  hitchstreamcontrols.php       # Admin controls page
  icons.php                     # Icon helper
  php/                          # Theme helper classes
  plugins/                      # Bundled plugins (.zip)
  views/                        # Template overrides
  woocommerce/                  # WooCommerce template overrides

celebration-child/              # Child theme (HitchStream-specific)
  borders/                      # SVG border decorations
  endpoints/                    # PHP endpoints
    CloudFlareEP.php            # Cloudflare API proxy (admin panel)
    cf-live-webhook.php         # Webhook receiver (Cloudflare POST)
    live-state.php              # Player polling endpoint (player GET)
  fonts/                        # Web fonts (FontAwesome, Pe-icon, wedding)
  img/                          # Static images (posters, flourishes, backgrounds)
  js/
    HSPlayerElement.js          # Custom HLS player web component
    ended.js                    # Ended stream state handling
    status.js                   # AJAX status updater
    textscaler.js               # Text scaling utility
  style.css                     # Child theme stylesheet
  HitchStream-Player.php        # Player page template
  hitchstreamcontrols.php       # Admin controls page
  NoHeader.php                  # Headerless page template
  VenueShow.php                 # Venue showcase template
  functions.php                 # WordPress functions + webhook helpers
  [multiple other .php templates for different page designs]

HitchStream_Cloudflare/         # Standalone Cloudflare management plugin
  HitchStream-Cloudflare.php
  hscf-admin.js
  icon.svg

CloudFlare Docs/                # Cloudflare Stream API reference docs
  *.md                          # 24 documents covering all API endpoints
```

---

## Key Components

### 1. HSPlayer (`celebration-child/js/HSPlayerElement.js`)

A custom HTML web component (`<hs-video>`) that wraps Hls.js to play **both live HLS streams and pre-recorded (VOD) Cloudflare Stream videos** with wedding-specific UX. The player has two completely separate modes determined by the `live` parameter passed via `setApiInfo()`:

#### Live Stream Mode (`live=true`)

The player polls WordPress every 10 seconds for live stream status. When a stream is confirmed live, it defers loading the HLS manifest until the viewer clicks (user gesture unlock).

#### VOD / Pre-recorded Video Mode (`live=false`)

When `live=false`, the player skips all polling and state machine logic. The `inputId` parameter is interpreted as a **Cloudflare Stream video UID** (not a live input ID). The player constructs the HLS URL `https://customer-{CF_CUSTOMER_ID}/{inputId}/manifest/video.m3u8` and loads it directly via `loadVideoDirectly()`. VOD playback has no prebuffer gate, no fatal timer, and no polling — just straight Hls.js loading with autoplay support.

#### State Machine

- `IDLE` — No stream active, shows poster image. For live: polling continues. For VOD: video is paused, controls hidden.
- `PREPARING` — HLS manifest loaded via `_probeManifestAndStart()`, prebuffer gate counting up. Only used in live mode.
- `PLAYING` — Video is playing.
- `FATAL` — Unrecoverable error (viewer must refresh).

#### Player Lifecycle

1. **Constructor** — Initializes all internal state (hls instance, timers, counters, PTS trackers, status overlay refs).
2. **`setApiInfo({inputId, isLive, autoplay})`** — Called by the page template. Sets `this.inputId`, `this.isLive`, `this.autoplay`. If the component is already connected, dispatches to either `startPolling()` (live) or `loadVideoDirectly()` (VOD).
3. **`connectedCallback()`** — Builds the Shadow DOM UI (video element, overlay with poster img, play button, debug panel, status message overlay). Wires up `playing` and `click` event listeners. Dispatches to live or VOD path.
4. **`disconnectedCallback()`** — Cleans up all timers, removes event listeners, destroys Hls.js instance.

#### Key Methods

- **`loadVideoDirectly()` (VOD path)** — Constructs HLS URL from `inputId` as a video UID. Loads via Hls.js or native HLS. On manifest parse, attempts autoplay (or muted retry). No prebuffer gate, no polling, no state machine transitions.
- **`startPolling()` (live path)** — Polls `live-state.php` every 10s. On live detection: saves HLS URL, starts status countdown. On idle: destroys HLS, pauses video, shows idle poster. On error: maps Cloudflare error codes to viewer messages. On reconnecting: shows status overlay without stopping playback.
- **`_probeManifestAndStart()`** — Probes the HLS manifest URL via CORS fetch before loading with Hls.js. Probes every 1500ms with a 5s initial delay. On success, gives a 2s grace period then calls `hls.loadSource()` and `hls.startLoad()`.
- **`loadStream(streamUrl)`** — Creates a new Hls.js instance with wedding-optimized config. Wires up `FRAG_PARSING_DATA` (audio/video PTS tracking for sync monitoring), `FRAG_LOADED` (throughput sampling for prebuffer gate), `MANIFEST_PARSED` (starts prebuffer gate + fatal timer), and `ERROR` (media error recovery).
- **`managePlayerState(newState, streamUrl)`** — Bridges between state changes. For PLAYING: loads stream. For IDLE: destroys HLS, pauses video, shows poster, restarts polling.
- **`prepareToPlay(streamUrl)`** — Loads stream without transitioning to PLAYING state. Used after user gesture but before playback actually starts.
- **`tryStartPlayback()`** — Runs the prebuffer gate. Measures buffer ahead, segment duration, throughput (20th percentile), and current bitrate. Maps headroom to threshold: headroom >= 2.0x → 10s, >= 1.5x → 12s, >= 1.2x → 15s, >= 1.0x → 20s, < 1.0x → 28s. Also enforces minimum 3 segments. Gives up after 60s. Also caps quality to `currentLevel - 1` when headroom < 1.2x.
- **`startFatalTimer(initialBufferAhead)`** — Starts a 45s countdown. Resets if buffer grows by 2s+ during countdown. Enters FATAL if playback hasn't started.
- **`enterFatalState()`** — Cancels timers, stops polling, destroys HLS, pauses video, shows fatal poster, hides UI.
- **`_attemptAutoplay(video)`** — Attempts `video.play()`. On failure, retries with `video.muted = true`.
- **`setPoster(url, type)`** — Sets poster on both the video element and the overlay img. Types: `initial`, `idle`, `fatal` (defaults: `POSTER_INITIAL_URL`, `POSTER_IDLE_URL`, `POSTER_FATAL_URL`).

#### Status Overlay

Top-left animated messages (shown only after user gesture):
- `"Waiting for stream..."` (animated ellipsis) — No stream detected yet
- `"Preparing to stream..."` (animated ellipsis) — Manifest loaded, prebuffering
- `"Reconnecting"` (animated ellipsis) — Streamer reconnecting mid-broadcast
- `"Live"` (static, fades after 3s) — Stream confirmed live
- `"Paused/Ended"` (static, fades after 3s) — Stream ended after playing
- `"Error"` (static, fades after 3s) — Error or fatal state
- `"Audio sync issue"` (animated ellipsis) — Audio/video drift detected (debug only)

#### Debug Panel

Top-right overlay (enabled via `?debug=1` or `debug` attribute):
- `state` — Current player state
- `prebuffer` — Buffer ahead in seconds
- `In Progress` — Whether video has rendered a frame
- `clicked` — Whether user has unlocked gesture
- `latency` — HLS latency (debug only)
- `live` — Whether stream is live
- `videoUID` — Current video UID
- `polls` — Number of polls performed
- `error_code` — Cloudflare error code (if any)
- `source` — Data source: `webhook`, `cf_probe`, or `no_data`

#### Key Constants

| Constant | Value | Purpose |
|----------|-------|--------|
| `MIN_PREBUFFER_SECONDS` | 10 | Minimum buffer before start |
| `MIN_PREBUFFER_SEGMENTS` | 3 | Minimum segments before start |
| `MIN_THROUGHPUT_SAMPLES` | 3 | Samples needed to trust throughput |
| `PREBUFFER_TIMEOUT_MS` | 60000 | Start anyway after this wait |
| `MAX_THROUGHPUT_SAMPLES` | 10 | Cap for stored throughput |
| `POLL_INITIAL_DELAY_MS` | 3000 | Delay before first poll |
| `POLL_INTERVAL_MS` | 10000 | Polling interval |
| `FATAL_TIMEOUT_MS` | 45000 | Fatal countdown duration |
| `MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS` | 3 | Media error recovery retries |
| `AUDIO_DRIFT_FRAMES_THRESHOLD` | 4 | Audio/video sync threshold |
| `CLOUDFLARE_CUSTOMER_ID` | `juu1r5es4cbffqjf` | Cloudflare customer ID |

**Hls.js Configuration:**
- `lowLatencyMode: false` — Prefer smoothness over low-latency
- `liveSyncMode: 'buffered'` — Buffer-based sync
- `liveSyncDuration: 20` — Target live sync duration
- `liveMaxLatencyDuration: 60` — Maximum allowed latency
- `maxBufferLength: 90` / `maxMaxBufferLength: 300` — Forward buffer limits
- `maxBufferSize: 100MB` / `maxBufferHole: 1` — Buffer size/hole tolerance
- `backBufferLength: 120` — Back buffer
- `maxAudioFramesDrift: 30` — Audio sync drift tolerance
- `startLevel: 0` — Start at lowest quality
- `capLevelToPlayerSize: true` — Auto-adjust resolution
- `startFragPrefetch: true` — Pre-fetch first fragment

### 2. Cloudflare Live Webhooks (`celebration-child/endpoints/cf-live-webhook.php`)

Receives `live_input.connected`, `live_input.disconnected`, and `live_input.errored` events from Cloudflare.

**What it does:**
1. Validates HMAC-SHA256 signature using `HSCF_webhook_secret` WP option
2. Extracts event type, input ID, error code, and state
3. Maps Cloudflare's 8 states to 4 normalized states:
   - `connected` / `reconnected` / `new_configuration_accepted` → `live` (TTL: 300s)
   - `reconnecting` → `reconnecting` (TTL: 120s)
   - `client_disconnect` / `ttl_exceeded` → `idle` (TTL: 300s)
   - `failed_to_connect` / `failed_to_reconnect` → `error` (TTL: 3600s)
4. Debounces within a 3-second window per input ID
5. Stores result in `hs_live_state_{input_id}` transient

### 3. Live-State Endpoint (`celebration-child/endpoints/live-state.php`)

Public GET endpoint the player polls every 10 seconds.

**What it does:**
1. Validates `inputId` parameter (`^[A-Za-z0-9_-]+$`)
2. Returns `hs_live_state_{inputId}` transient if fresh (source: `webhook`)
3. If transient expired, does a one-time Cloudflare API probe (source: `cf_probe`)
4. If probe fails, returns idle (source: `no_data`)
5. Constructs HLS URL from videoUID: `https://customer-{CF_CUSTOMER_ID}/{videoUID}/manifest/video.m3u8`

**Response:**
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

### 4. WordPress Helpers (`celebration-child/functions.php`)

**Webhook helpers (lines 664-779):**
- `hs_verify_webhook_signature()` — HMAC validation
- `hs_update_live_state_transient()` — Debounced transient store
- `hs_compute_server_live_state()` — Server-side isLive check
- `hs_register_cf_webhook()` — Cloudflare API webhook registration
- `hs_unregister_cf_webhook()` — Cloudflare API webhook removal

### 5. Cloudflare API Proxy (`celebration-child/endpoints/CloudFlareEP.php`)

Server-side proxy for Cloudflare Stream API calls. Used by the admin panel.

**Endpoint:** POST `?inputId={id}&action=live_state`

Returns:
- `ingest_live` — Whether a live stream is active
- `hls_url` — HLS playback URL
- `playlist_ready` — Whether the HLS playlist is readable
- `targetduration` — Segment duration from manifest
- `media_sequence` — Media sequence from manifest
- `state` — PLAYING / PREPARING / IDLE

Also supports default passthrough of the `/videos` list endpoint.

### 6. Page Templates

- **HitchStream-Player.php** — The player page. Reads `inputId`, `live`, `autoplay`, `initialposterURL`, `idleposterURL` from URL parameters. Sets `serverIsLive` from the transient. Injects `liveStateEndpoint` as a JS global.
- **hitchstreamcontrols.php** — Admin controls page for managing live inputs.
- **NoHeader.php** — Headerless template used by the player page.
- Multiple theme templates (Big Modern CF, Chalkboard CF, Flower Table CF, etc.) — Pre-designed page layouts for wedding/event pages.

### 7. Shortcode System (`celebration-child/functions.php`)

Custom shortcodes parse content blocks from post content using regex:
- `[current_date]` — Current date
- `[cf7_get_text key="OR"]` — CF7 form text value
- `[cf7_get_hidden key="..."]` — CF7 form hidden value
- `<cont_couplename>`, `<cont_eventtitle>`, `<cont_venue>`, `<cont_colors>`, etc. — Content blocks for the wedding page

### 8. Cloudflare Management (`HitchStream_Cloudflare/`)

A standalone WordPress plugin for managing Cloudflare Stream live inputs:
- Create, update, retrieve, delete live inputs
- Register/remove webhooks
- Monitor live stream status
- All API calls go through the plugin's server-side proxy (no client-side API exposure)

---

## Configuration

### WordPress Options Required

| Option | Purpose |
|--------|---------|
| `HSCF_cloudflare_email` | Cloudflare account email |
| `HSCF_cloudflare_api_key` | Cloudflare API key |
| `HSCF_cloudflare_account_id` | Cloudflare account ID |
| `HSCF_webhook_secret` | HMAC-SHA256 secret for webhook validation |

### Webhook Installation

Register webhooks with Cloudflare via `hs_register_cf_webhook(input_id, callback_url, secret)` or the admin panel. The callback URL should be:
```
https://hitchstream.com/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php
```

### Player URL Parameters

```
/HitchStream-Player/?inputId={live_input_id}&live=true&autoplay=true&initialposterURL={url}&idleposterURL={url}
```

| Parameter | Description |
|-----------|-------------|
| `inputId` | **Dual semantics:** When `live=true`, this is a **Cloudflare live input ID** (for polling stream status). When `live=false`, it's a **Cloudflare Stream video UID** for a pre-recorded VOD (loaded directly via `loadVideoDirectly()`, no polling). (required) |
| `live` | Whether to start polling (true/false) |
| `autoplay` | Whether to auto-load after user gesture (true/false, default true) |
| `initialposterURL` | URL for initial poster image |
| `idleposterURL` | URL for idle/ended poster image |
| `poster-fatal` | URL for fatal state poster image (via attribute, not URL param) |

### Debug Mode

Add `?debug=1` or the `debug` HTML attribute to enable the debug panel and verbose console logging.

---

## Deployment Notes

- **Feature flag:** Use WP option `hs_use_webhook_player` to toggle between the old Cloudflare CORS endpoint and the new webhook-powered endpoint.
- **Transient cleanup:** WordPress transients are subject to cron-based cleanup. For production, consider object caching (Redis/Memcached) to prevent stale state.
- **Polling interval:** 10 seconds — appropriate for wedding streaming where low latency is not a priority.
- **Error handling:** Cloudflare error codes are mapped to viewer-friendly messages and logged in the debug panel. Critical errors (`ERR_STORAGE_QUOTA_EXHAUSTED`, `ERR_MISSING_SUBSCRIPTION`) indicate service-level issues.

---

## Files Summary

| File | Lines | Purpose |
|------|-------|---------|
| `celebration-child/js/HSPlayerElement.js` | ~1370 | Custom HLS player web component |
| `celebration-child/functions.php` | ~795 | WordPress hooks + webhook helpers |
| `celebration-child/endpoints/cf-live-webhook.php` | ~188 | Webhook receiver |
| `celebration-child/endpoints/live-state.php` | ~153 | Player polling endpoint |
| `celebration-child/endpoints/CloudFlareEP.php` | ~143 | Cloudflare API proxy |
| `celebration-child/HitchStream-Player.php` | ~82 | Player page template |
| `HitchStream_Cloudflare/HitchStream-Cloudflare.php` | ~ | Cloudflare management plugin |
| `webhooks-upgrade-plan.md` | ~ | Implementation plan |
| `CloudFlare Docs/*.md` | ~12892 | Cloudflare Stream API reference |
