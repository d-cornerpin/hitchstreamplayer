# Agent B — Progress

**Current phase:** B0 (Emergency security triage)
**Actively working on:** Committing B0 code changes, preparing PR
**Open questions:**
- **B0.5 key rotation:** The hardcoded streamer API key (`72c020a8d042a1f549b548311d1e4577`) was present in the initial commit (never in prior history). You must rotate this key on the `streamer1.hitchstream.com` Node.js service in the same change window. The WP option `HSCF_streamer_api_key` starts empty — the plugin will return `400` until it's set.
- **B0.4 nopriv handlers:** Removed `wp_ajax_nopriv_fetch_video_uid` (unauthenticated endpoint that duplicates live-state, per B4.8 cleanup). Kept `wp_ajax_nopriv_get_status_ajax_` — it's used by the public status page. Please confirm.
**Last updated:** 2026-04-24

## B0 Checklist

- [x] **B0.1** Webhook signature bypass removed (cf-live-webhook.php + functions.php). Both `hs_verify_webhook()` and `hs_verify_webhook_signature()` now return `false` + log `CRITICAL` when no secret is configured, instead of accepting everything.
- [x] **B0.2** CloudFlareEP default pass-through removed (lines 127-141). All unhandled actions now return 400.
- [x] **B0.3** `current_user_can('manage_options')` check added to CloudFlareEP after nonce validation.
- [x] **B0.4** Nonce + capability added to all 16 AJAX handlers in HitchStream-Cloudflare.php. `wp_ajax_nopriv_fetch_video_uid` removed (unauthenticated endpoint duplicating live-state). JS updated to use `hscf_ajax` object with nonce on every request.
- [x] **B0.5** Hardcoded streamer API key removed from 4 handlers. WP options `HSCF_streamer_api_url` (default: `https://streamer1.hitchstream.com`) and `HSCF_streamer_api_key` (no default) added with admin settings UI.
- [x] **B0.6** `console.log('RTMP Key being sent:', rtmpsKey)` removed from hscf-admin.js:439.
- [x] **B0.7** All 5 `disable_all_deprecated_warnings` filter hooks removed from functions.php:852-860.
- [ ] **B0.8** Ships as single PR, security-reviewed, deployed
- [ ] **B0.9** CP-0 sign-off
