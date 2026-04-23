# HitchStream Player

A custom HLS live-streaming video player integrated into a WordPress wedding/event page theme, powered by Cloudflare Stream. This document explains the project architecture, key components, and how everything fits together.

---

## Overview

HitchStream is a WordPress-based platform for live-streaming wedding events (and other celebrations) to guests. The system consists of:

- **Parent theme** (`celebration/`) — A full WordPress theme with custom post types, template pages, shortcodes, and WooCommerce support.
- **Child theme** (`celebration-child/`) — Contains all HitchStream-specific logic: the custom video player, page templates, cloudflare integration, and webhook infrastructure.
- **Cloudflare Stream** — Used for live ingest, transcoding, and HLS delivery. All API calls go through WordPress (server-side proxy) or webhook events — never directly from the browser.
- **HSPlayer** (`<hs-video>`) — A custom web component (HTML5 Shadow DOM) that plays live HLS streams with a prebuffer gate, status overlay, and debug panel.

The core workflow: a streamer pushes video to a Cloudflare live input → Cloudflare sends webhooks to WordPress → WordPress caches state in transients → the player polls a WordPress endpoint every 10 seconds → the player loads the HLS manifest once the stream is confirmed live.

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

A custom HTML web component (`<hs-video>`) that wraps Hls.js with wedding-specific UX:

**State Machine:**
- `IDLE` — No stream active, shows poster image
- `PREPARING` — HLS manifest loaded, prebuffer gate counting up
- `PLAYING` — Video is playing
- `FATAL` — Unrecoverable error (viewer must refresh)

**Key Features:**
- **Dynamic prebuffer gate** — Measures network throughput and buffer headroom before starting playback. Conservative networks (headroom < 1.0x) wait up to 28s; healthy networks (headroom >= 2.0x) start after 10s. This prevents playback from starting on slow connections before enough buffer is ready, then gives up after 60s.
- **Prebuffer gate timeout** — Even on slow networks, playback starts after 60s regardless of buffer amount.
- **Audio sync monitoring** — Tracks audio/video PTS from HLS fragment parsing. Shows an "Audio sync issue" message when drift exceeds 4 frames.
- **Status overlay** — Top-left animated messages: "Waiting for stream...", "Preparing to stream...", "Reconnecting", "Live", "Paused/Ended", "Error".
- **Debug panel** — Top-right overlay showing state, buffer level, latency, videoUID, poll count, error_code, and source.
- **User gesture unlock** — Player waits for the first click anywhere on the page before loading the HLS stream (browser autoplay policy). After the first unlock, clicking the play button begins loading the stream.
- **Fatal recovery** — Media errors get 3 automatic recovery attempts. Network errors go straight to fatal state.
- **HLS config** — `lowLatencyMode: false`, `maxBufferLength: 90s`, `backBufferLength: 120s`, `maxAudioFramesDrift: 30`, `startLevel: 0` (lowest quality first).
- **Poster images** — Three poster images configurable via attributes (`poster-initial`, `poster-idle`, `poster-fatal`).

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
| `inputId` | Cloudflare live input ID (required) |
| `live` | Whether to start polling (true/false) |
| `autoplay` | Whether to auto-load after user gesture (true/false, default true) |
| `initialposterURL` | URL for initial poster image |
| `idleposterURL` | URL for idle/ended poster image |

### Debug Mode

Add `?debug=1` to the player URL to enable the debug panel and verbose console logging.

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
