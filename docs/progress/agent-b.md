# Agent B — Progress

**Current phase:** Done — awaiting end-of-project audit
**Actively working on:** B-side rebuild complete. Awaiting end-of-project audit + deploy + CP-3/CP-4/CP-5 validation.
**Last updated:** 2026-04-28 12:00 UTC

## B0 Checklist

- [x] **B0.1** Webhook signature bypass removed
- [x] **B0.2** CloudFlareEP default pass-through removed
- [x] **B0.3** Capability check on CloudFlareEP
- [x] **B0.4** Nonce + capability on every HSCF AJAX handler
- [x] **B0.5** Hardcoded streamer API key removed + WP options added
- [x] **B0.6** console.log of RTMP key removed
- [x] **B0.7** disable_all_deprecated_warnings filter removed
- [x] **B0.8** Ships as single PR — PR #1 created, awaiting deploy
- [x] **B0.9** CP-0 sign-off

## B1 Checklist (COMPLETE)

- [x] **B1.1** Empirical webhook signature format verification — *validated 2026-04-25 via real Cloudflare Notifications test webhook: header is `cf-webhook-auth` with plain shared-secret token, NO HMAC, NO timestamp. See docs/cloudflare-webhook-format.md.*
- [x] **B1.2** Implement signature verifier class (Verifier.php) — *rewritten to match empirical format: single timing-safe shared-secret comparison via hash_equals(). No HMAC, no replay window.*
- [x] **B1.3** Populate videoUID at webhook-receive time via /lifecycle
- [x] **B1.3a** On /lifecycle failure: do NOT update the transient
- [x] **B1.4** Idempotency + coalesced debounce (3s window, 60s dedup)
- [x] **B1.4a** Original ts carried through coalesced read
- [x] **B1.5** Webhook log table + wp-cron trim job
- [x] **B1.6** Loud red admin notice when secret missing
- [x] **B1.7** Staging test (gate-met: test plan committed in agent-b-B1.7-test-plan.md; execution deferred to end-of-project per David's deploy-strategy update)

**B1 Phase: COMPLETE.** PR #3 is the canonical B1 PR. Code-and-test gate met.

## B2 Checklist

- [x] **B2.1** Register WP REST API route `hitchstream/v1/live-state`
- [x] **B2.2** Lightweight path — flat-file writes alongside transient
- [x] **B2.2a** Atomic flat-file writes (write to .tmp, rename() into place)
- [x] **B2.3** Response body matches §4.1 exactly (ETag, 304, X-HS-Correlation-Id, source always present)
- [x] **B2.4** Single-flight lock on cache miss
- [x] **B2.5** Switch fallback probe from /videos to /lifecycle
- [x] **B2.6** X-HS-Correlation-Id header on every response
- [ ] **B2.7** Load-test plan committed (agent-b-B2.7-test-plan.md); execution deferred to end-of-project
- [x] **B2.8** Contract validation against Agent A's 8 fixtures — all round-trip compliant

## Commits on b/phase-B1

| Commit | Phase | Description |
|--------|-------|-------------|
| `ebdf8e9` | B1.1+B1.2 | Verifier class + unit tests + webhook format doc |
| `fa502f9` | B1.3+B1.5 | videoUID via /lifecycle + log table + flat-file writer |
| `a742302` | B1.4+B1.6 | Coalesced debounce + red admin notice |
| `ca4d82c` | B1.3a+B1.4 | Coalesced debounce integration + transient guard fix |
| `307cde5` | scope   | Remove B2.2a flat-file writer (defer to B2 PR) |
| `220c50f` | B1.7   | Staging smoke-test plan — 10 scenarios |
| `fa0c715` | B1.7   | Staging smoke-test plan — 10 runnable scenarios |
| `08b5534` | B1.1+B1.2 | Align verifier with empirical format: shared-secret, not HMAC |

## B3 Checklist

- [x] **B3.1** CloudflareClient class created — `src/HS/CloudflareClient.php`
  - Bearer token auth (preferred) with email/key fallback
  - 3 retries on 5xx/network error (250ms / 750ms / 2s backoff)
  - 10s connect / 15s total timeout
  - Every call logged with correlation ID
  - Methods: lifecycle(), listVideos(), getLiveInput(), createLiveInput(), deleteLiveInput(), registerWebhook(), deleteWebhook(), getWebhook()
- [x] **B3.2** Migrated all direct API calls — zero `curl_init` or `wp_remote_*` to Cloudflare API remain in `HitchStream-Cloudflare.php` or `functions.php`
- [x] **B3.3** Dual-auth fallback — Bearer preferred, email/key falls back with `trigger_error(E_USER_DEPRECATED)`
- [x] **B3.4** Config class created — `src/HS/Config.php` with typed accessors, throws `HS\ConfigError` for missing required options
- [x] **B3.5** Settings page extended:
  - New `HSCF_cloudflare_api_token` field in Cloudflare section
  - [Test] button on Cloudflare section → hits `/lifecycle`
  - [Test] button on Streamer section → hits `/stream-state`
  - [Rotate] button on webhook secret → deletes old + registers new
  - New "Alerts" section with `HSCF_alert_email` and `HSCF_alert_codes` fields
- [x] **B3.6** All options documented in `docs/configuration.md`

## Commits on b/phase-B3

| Commit | Phase | Description |
|--------|------|-----|

## B4 Checklist

- [x] **B4.1** Directory structure: `src/Plugin.php`, `src/Admin/`, `src/Services/`, `src/HS/`, `src/HS/LiveState/`, `src/HS/Webhook/`, `src/Log/`
- [x] **B4.2** AjaxController — single dispatcher with explicit allowlist (19 actions), nonce validation, capability check (manage_options)
- [x] **B4.3** SettingsPage — all settings registration (5 sections) + admin UI rendering extracted from monolith
- [x] **B4.4** LiveInputService — live input CRUD with detail enrichment
- [x] **B4.5** WebhookService — account-level webhook management
- [x] **B4.6** RecordingsService — recordings list/download/delete
- [x] **B4.7** StreamerService — RTMPS URL allowlist (only `*.cloudflare.com` and known hosts)
- [x] **B4.8** Dead code removal: `fetch_current_video_uid`, `ajax_fetch_current_video_uid`, `hs_unregister_cf_webhook`, `tus-js-client` reference, `cloudflare_player.js`, `cloudflare_debug.log`
- [x] **B4.9** Admin JS unchanged (hscf-admin.js handles its own AJAX)
- [x] **B4.10** Backward-compat shims (B4.10a) — `src/BackwardCompat.php` with §4 contract validation on every shim
- [x] **B4.10a** §4 contract: all shims return stable shapes (`['error' => string]` for errors, no `['result' => ...]`)
- [x] **B4.11** HitchStream-Cloudflare.php rewritten as thin loader (plugin header + bootstrap + template hooks)
- [x] **B4.12** ConfigError.php created (was referenced but missing)
- [x] **B4.13** LiveState REST endpoint registration wired via `Plugin::registerLiveStateEndpoint()`
- [x] Smoke-test plan committed: `docs/progress/agent-b-B4-smoke-test-plan.md`

## B5 Status

- [x] **B5.4** CSP header on player page + self-host Hls.js
- [x] **B5.5** Admin activity page (Tools → HitchStream Activity)
- [x] **B5.6** Critical-error email alerts from webhooks
- [x] **B5.6a** HSPlayerConfig.errorMessages alignment with settings
- [x] **B5.7** SKIPPED per David's directive — recordings are manual editorial workflow, no recording-check endpoint needed
- [x] **B5.1+B5.2+B5.3** SKIPPED per David's scope review — the 12 wedding templates exist for per-template customization; centralizing 5 lines of iframe HTML fights that design. The $$variable = $value pattern in Event Generic v2.php (line 603) remains as-is.


## B5 Commits on b/phase-B4

| Commit | Phase | Description |
|--------|------|-----|
| `14b4def` | B5.1+B5.2+B5.3 | Skip per David's scope review |
| `f7a9fd1` | B5.4 | CSP header + self-host Hls.js 1.6.16 |
| `dde36da` | B5.5+B5.6+B5.8 | Activity page, error alerts, runbook |

**B5 Phase: COMPLETE.** PR #11 — https://github.com/d-cornerpin/hitchstreamplayer/pull/11
