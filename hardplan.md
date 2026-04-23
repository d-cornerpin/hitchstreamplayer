# HitchStream Player — Hardening & Refactoring Plan

> **Assumptions preserved:** Player URL parameters (`inputId`, `live`, `autoplay`, `initialposterURL`, `idleposterURL`) stay the same. All 12 wedding page templates continue to embed the player via `<iframe>`. No functional behavior changes — only cleanup, simplification, and hardening.

---

## Audit Findings

### Critical Issues

1. **`$PlayerURL` is undefined in all 12 wedding templates.** Every template (Big Modern CF, Chalkboard CF, etc.) uses `$PlayerURL` in the iframe src but it is never defined anywhere in the codebase. Either the Celebration parent theme sets it via content processing (shortcode/rapid_composer) or this is a latent bug. **Action:** Trace the variable origin; if it comes from page content shortcodes, consolidate into explicit `get_post_meta()` calls.

2. **Cloudflare credentials in HLS URL are hardcoded.** `CLOUDFLARE_CUSTOMER_ID = 'juu1r5es4cbffqjf'` is embedded in both `HSPlayerElement.js` and `live-state.php`. **Action:** Move to a configurable constant or WP option.

3. **Poster URLs are hardcoded.** `POSTER_INITIAL_URL`, `POSTER_IDLE_URL`, `POSTER_FATAL_URL` are hard-coded HTTPS URLs in the player JS. **Action:** Accept via URL params or data attributes; fall back to configurable defaults.

### Security Issues

4. **Webhook grace period allows unsigned requests.** `hs_verify_webhook()` returns `true` when `HSCF_webhook_secret` is not set, with only a log warning. In production, every request from Cloudflare could be spoofed. **Action:** Log a critical alert; add an admin notice when secret is missing.

5. **`live-state.php` has no rate limiting.** Any visitor can poll the endpoint repeatedly, triggering Cloudflare API probes. **Action:** Add IP-based rate limiting or a short TTL cache.

6. **`CloudFlareEP.php` proxy allows default passthrough of `/videos` list endpoint.** An attacker could enumerate all Cloudflare stream videos. **Action:** Remove default passthrough or restrict to admin-only.

7. **Nonce is passed as a URL-injected JS variable** in `HitchStream-Player.php` (`var hsPlayerNonce`). It is visible in the page source. **Action:** This is acceptable for a player nonce (it's short-lived), but verify it's only used for POST to `CloudFlareEP.php`.

### Code Quality Issues

8. **HSPlayerElement.js is 1376 lines with no modularization.** The class mixes HLS logic, polling, UI, debug panel, status messages, prebuffer gating, audio drift detection, and manifest probing all in one file. **Action:** Extract into internal classes/modules.

9. **Excessive try/catch silence.** The codebase has ~100 `try { ... } catch (_) {}` blocks that silently swallow errors. Many hide real failures (e.g., DOM queries that return null). **Action:** Replace with explicit null checks and targeted error logging.

10. **Duplicate property declarations.** `HSPlayerElement.js` declares `currentStreamUrl` and `ingestFalseCount` twice (lines 85-88). **Action:** Deduplicate.

11. **Hls.js config has duplicate `manifestLoadingMaxRetry`** (lines 485 and 497) with different retry counts (5 vs 10). **Action:** Keep one value.

12. **Player polls WordPress but some code paths still reference Cloudflare CORS polling** in comments ("Poll Cloudflare's lifecycle endpoint directly" at line 226). **Action:** Update stale comments.

13. **`ended.js` uses `endedMessage` from global scope** without defensive checks. If `textScalingConfig` is missing, `textScale()` will fail. **Action:** Add null guards or load-order guarantees.

14. **Status messages only show when `userGestureUnlocked` is true.** This means no status is visible until the user clicks — but `updateStatus('waiting')` is called before any user interaction in several places. **Action:** Decide if pre-gesture status is desired; if so, remove the guard.

---

## Refactoring Plan

### Phase 1: Security Hardening (no behavioral changes)

| # | File | Change | Priority |
|---|------|--------|----------|
| 1.1 | `cf-live-webhook.php` | Add admin notice when `HSCF_webhook_secret` is missing. Require secret in production (config flag). | High |
| 1.2 | `live-state.php` | Add IP-based rate limiting (e.g., max 10 polls/min per IP). Return 429 if exceeded. | High |
| 1.3 | `CloudFlareEP.php` | Remove default passthrough of `/videos` list. Only allow `live_state` action. | High |
| 1.4 | `HitchStream-Player.php` | Add `x-content-type-options: nosniff` and CSP headers to prevent MIME sniffing. | Medium |
| 1.5 | `HSPlayerElement.js` | Make `CLOUDFLARE_CUSTOMER_ID` configurable via URL param (`customerID`). | Medium |
| 1.6 | Poster URLs | Accept `posterFatalURL` as a URL parameter (in addition to initial/idle). Remove hard-coded fallbacks. | Medium |

### Phase 2: Bug Fixes

| # | File | Change | Priority |
|---|------|--------|----------|
| 2.1 | All templates | Fix undefined `$PlayerURL` — trace its origin and make explicit. | Critical |
| 2.2 | `HSPlayerElement.js` | Remove duplicate `currentStreamUrl` / `ingestFalseCount` declarations. | High |
| 2.3 | `HSPlayerElement.js` | Remove duplicate `manifestLoadingMaxRetry` Hls.js config (lines 485, 497). | High |
| 2.4 | `HSPlayerElement.js` | Update stale comments referencing "Cloudflare CORS polling" to say "WordPress polling". | Low |
| 2.5 | `ended.js` | Add null guard for `textScalingConfig` and `endedMessage`. | Medium |

### Phase 3: Code Simplification

| # | File | Change | Effort |
|---|------|--------|--------|
| 3.1 | `HSPlayerElement.js` | Extract status message system (`showAnimatedStatus`, `showStatusMessage`, `hideStatusMessage`, `updateStatus`) into a private `StatusOverlay` class. | Medium |
| 3.2 | `HSPlayerElement.js` | Extract prebuffer gate logic (`tryStartPlayback`, `getBufferAhead`, `getSegmentDuration`, `getConservativeThroughput`, `mapHeadroomToThreshold`) into a private `PrebufferGate` class. | Medium |
| 3.3 | `HSPlayerElement.js` | Extract audio drift detection into a private `AudioDriftMonitor` class (already partially done via FRAG_PARSING_DATA handler). | Low |
| 3.4 | `HSPlayerElement.js` | Extract manifest probing into a private `ManifestProbe` class. | Low |
| 3.5 | `HSPlayerElement.js` | Extract debug panel into a private `DebugPanel` class. | Low |
| 3.6 | `HSPlayerElement.js` | Replace `try/catch` silence with explicit null checks (~100 instances). Replace with a single `_safe(fn)` helper where appropriate. | High |
| 3.7 | `HitchStream-Player.php` | Simplify the inline JS: it only needs to read URL params and call `setApiInfo`. This is already clean. | — |
| 3.8 | `functions.php` | Consolidate webhook helper functions (`hs_verify_webhook_signature`, `hs_update_live_state_transient`, `hs_compute_server_live_state`, `hs_register_cf_webhook`, `hs_unregister_cf_webhook`) into a single `class HS_Webhook` to prevent namespace pollution. | Medium |
| 3.9 | `HitchStream-Cloudflare.php` | Split into separate classes for LiveInput CRUD, Webhook management, and Video upload (currently ~1369 lines of mixed concerns). | Large |

### Phase 4: Architecture Improvements

| # | File | Change | Effort |
|---|------|--------|--------|
| 4.1 | All PHP | Add `declare(strict_types=1)` to all PHP files. Add PHPDoc blocks for all public functions. | Medium |
| 4.2 | `live-state.php` | Add a local in-memory cache (APCu or WP object cache with short TTL) to prevent repeated Cloudflare API probes when transient expires. | Medium |
| 4.3 | `HSPlayerElement.js` | Add a `destroy()` lifecycle method that cleanly tears down all subscriptions, timers, and event listeners — called from `disconnectedCallback` and after fatal state. | Low |
| 4.4 | All templates | Create a shared PHP include or helper function for `$PlayerURL` construction so all 12 templates use the same logic. | Medium |
| 4.5 | `style.css` | Extract player-specific CSS into a separate file to reduce stylesheet size. | Low |

---

## Recommended Implementation Order

```
Phase 1 (Security)  →  Phase 2 (Bugs)  →  Phase 3 (Simplify)  →  Phase 4 (Architecture)
      High risk             Hidden bugs          Developer DX          Maintainability
```

### Sprint breakdown recommendation

**Sprint 1 — Security + Critical bugs:** Items 1.1, 1.3, 2.1, 2.2, 2.3
**Sprint 2 — Security hardening + code cleanup:** Items 1.2, 1.4, 1.5, 3.6, 3.8
**Sprint 3 — Refactoring:** Items 3.1–3.5, 3.9, 4.2, 4.4
**Sprint 4 — Polish:** Items 1.6, 2.4, 2.5, 3.7, 4.1, 4.3, 4.5

### Admin Config Items (coordinate with other dev)

| Item | Details |
|---|---|
| `CLOUDFLARE_CUSTOMER_ID` | Move from `HSPlayerElement.js` constant to a WP option in `HitchStream_Cloudflare`. Player reads it from a global or URL param. |
| Poster fallback URLs | `POSTER_INITIAL_URL`, `POSTER_IDLE_URL`, `POSTER_FATAL_URL` — currently hard-coded as HTTPS fallbacks. Add as admin settings in `HitchStream_Cloudflare` so they're configurable without code deploys. These are only used when the rapid composer HS_Player component doesn't supply poster params. |
| Prebuffer/gate parameters | `MIN_PREBUFFER_SECONDS`, `PREBUFFER_TIMEOUT_MS`, `FATAL_TIMEOUT_MS`, etc. — all hard-coded in `HSPlayerElement.js`. Could be admin-configurable for tuning per-event. |

### Risk assessment

- **Highest risk:** Changing how `$PlayerURL` is constructed (2.1) could break all 12 templates simultaneously. **Mitigation:** Test each template individually.
- **Medium risk:** Extracting classes from `HSPlayerElement.js` (Phase 3) could break the single-entry-point contract (shadow DOM, custom element lifecycle). **Mitigation:** Keep all extracted classes as private methods on `HSVideoElement`; no public API changes.
- **Low risk:** Security hardening (Phase 1) has minimal behavioral impact.
