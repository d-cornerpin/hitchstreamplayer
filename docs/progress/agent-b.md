# Agent B — Progress

**Current phase:** B1 (Webhook correctness — code complete, awaiting staging test)
**Actively working on:** B1.7 staging test — blocked until PR #1 is deployed
**Open questions:**
- **B0.4 nopriv rationale (confirmed by user):** `wp_ajax_nopriv_get_status_ajax_` is correct to keep. `status.js` on public wedding pages polls every 60s to update `EventStatus` DOM element.
**Last updated:** 2026-04-25 12:00 UTC

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

## B1 Checklist

- [x] **B1.1** Empirical webhook signature format verification — *validated 2026-04-25 via real Cloudflare Notifications test webhook: header is `cf-webhook-auth` with plain shared-secret token, NO HMAC, NO timestamp. See docs/cloudflare-webhook-format.md.*
- [x] **B1.2** Implement signature verifier class (Verifier.php) — *rewritten to match empirical format: single timing-safe shared-secret comparison via hash_equals(). No HMAC, no replay window.*
- [x] **B1.3** Populate videoUID at webhook-receive time via /lifecycle
- [x] **B1.3a** On /lifecycle failure: do NOT update the transient
- [x] **B1.4** Idempotency + coalesced debounce (3s window, 60s dedup)
- [x] **B1.4a** Original ts carried through coalesced read
- [x] **B1.5** Webhook log table + wp-cron trim job
- [x] **B1.6** Loud red admin notice when secret missing
- [ ] **B1.7** Staging test (blocked until PR #1 deployed)

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
| `NEW`    | B1.1+B1.2 | Align verifier with empirical format: shared-secret, not HMAC |
