# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

HitchStream Player is a WordPress-based live-streaming platform for wedding/events, powered by Cloudflare Stream. It consists of a parent theme (`celebration/`), a child theme (`celebration-child/`) with all HitchStream-specific logic, and a standalone WordPress plugin (`HitchStream_Cloudflare/`). The core product is the `<hs-video>` custom web component (HSPlayer) that plays live HLS streams and VOD videos.

**There is no build system, linter, or test suite.** This is a traditional WordPress theme — edits are direct file modifications deployed via WordPress's theme system.

## Key Directories

- `celebration/` — Parent WordPress theme (Bold Themes product). Handles general theme functionality, WooCommerce support, admin panel, template parts, and bundled plugins.
- `celebration-child/` — HitchStream-specific code. The active theme being developed.
  - `endpoints/` — PHP endpoints: webhook receiver, player polling, Cloudflare API proxy.
  - `js/` — Player JS: `HSPlayerElement.js` (core), `status.js`, `ended.js`, `textscaler.js`.
  - `*.php` templates — Player page, admin controls, various wedding page layouts.
- `HitchStream_Cloudflare/` — Standalone WP plugin for Cloudflare Stream live input management.
- `CloudFlare Docs/` — 24 Cloudflare Stream API reference documents (read-only reference).

## Architecture

```
Streamer ──RTMP/SRT──> Cloudflare Live Input
                     │
            ┌────────┴───────┐
            │               │
         Webhooks        HLS Manifest
            │              Player
     WordPress              │
       Receiver            Polls WP
            │               │
       set_transient()   Gets HLS URL
            │               │
       WP live-state   Load
       Endpoint (GET)   Source
            │
       Player State Machine
       (IDLE → PREPARING → PLAYING)

VOD Mode (separate path):
  videoUID → loadVideoDirectly() → Hls.js → playback
  (No polling, no state machine)
```

## Core Components (in priority order for development)

### 1. HSPlayer (`celebration-child/js/HSPlayerElement.js`) — ~1370 lines
Custom HLS player web component (`<hs-video>`). Two modes determined by `isLive` parameter:
- **Live mode**: Polls WP every 10s for stream status, uses prebuffer gate before playback, status overlay, debug panel.
- **VOD mode**: Loads video directly from Cloudflare Stream video UID, no polling.

Key state machine: IDLE → PREPARING → PLAYING (→ FATAL on errors).

### 2. Player Page (`celebration-child/HitchStream-Player.php`)
Template that renders the `<hs-video>` component, passes URL params (inputId, live, autoplay, poster URLs) to the player, and injects JS globals (endpoint URL, nonce, serverIsLive).

### 3. WordPress Helpers (`celebration-child/functions.php`)
Webhook helpers, shortcode parsing, Cloudflare API integration on the PHP side.

### 4. Endpoints (`celebration-child/endpoints/`)
- `cf-live-webhook.php` — Receives Cloudflare webhook events, validates HMAC, maps states, stores transients.
- `live-state.php` — Player polling endpoint (GET). Returns cached state or does one-time API probe.
- `CloudFlareEP.php` — Admin-facing Cloudflare API proxy for the management panel.

### 5. Cloudflare Plugin (`HitchStream_Cloudflare/`)
Standalone plugin for managing Cloudflare Stream live inputs: create/update/delete inputs, register webhooks, monitor status.

### 6. Wedding Page Templates (`celebration-child/`)
Multiple themed page layouts (Big Modern CF, Chalkboard CF, Flower Table CF, etc.) for wedding/event pages.

## Development Workflow

1. **Edit PHP/JS/CSS files directly** in `celebration-child/` — no build step.
2. **Deploy** by uploading to the WordPress server or using WP's theme upload.
3. **Debug** the player via `?debug=1` URL parameter to see the debug panel overlay.
4. **Test webhooks** locally by running `wp server` or a local WP install, then configure Cloudflare to point to it.

## Configuration

Required WordPress options:
- `HSCF_cloudflare_email` — Cloudflare account email
- `HSCF_cloudflare_api_key` — Cloudflare API key
- `HSCF_cloudflare_account_id` — Cloudflare account ID
- `HSCF_webhook_secret` — HMAC-SHA256 secret for webhook validation

Player URL parameters: `?inputId={id}&live={true|false}&autoplay={true|false}&initialposterURL={url}&idleposterURL={url}`

## File Index

| File | Purpose |
|------|---------|
| `celebration-child/js/HSPlayerElement.js` | Core HLS player web component (~1370 lines) |
| `celebration-child/functions.php` | WP hooks + webhook helpers (~795 lines) |
| `celebration-child/endpoints/cf-live-webhook.php` | Webhook receiver |
| `celebration-child/endpoints/live-state.php` | Player polling endpoint |
| `celebration-child/endpoints/CloudFlareEP.php` | Cloudflare API proxy |
| `celebration-child/HitchStream-Player.php` | Player page template |
| `HitchStream_Cloudflare/HitchStream-Cloudflare.php` | Cloudflare management plugin |
| `PROJECT.md` | Detailed architecture document |
