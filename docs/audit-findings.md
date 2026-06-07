# Pre-Deploy Audit — Findings & Remediation

**Audit date:** 2026-06-06
**Context:** Full pre-deploy audit of the HitchStream Player v2 rebuild (player JS, plugin PHP, theme PHP, deploy tooling, docs). The v2 code had been merged to `main` and staged in `deploy/` but **was never run end-to-end against a real Cloudflare stream + WordPress** — the Playwright suite fakes Hls.js and forces `playerState='PLAYING'`, so it passed while real wiring was broken. The current live site runs the old code; every issue below is a regression that would have shipped with v2.

**Verification available locally:** Node 22 (`node --check`) for JS; bash `-n` for scripts; PHP lint run on the production server over SSH (no local PHP). All edited files pass.

---

## ⚠️ Status: blockers fixed — NOT yet validated against a real stream

All Critical/High/Medium findings are fixed in the working tree and re-linted. **Do not treat this as "ready to deploy" until the live-stream validation in the last section is done** — the playback fix in particular changes runtime behavior that only a real stream can confirm.

---

## 🔴 Blockers (would break the site or the core feature) — ALL FIXED

- [x] **B1 — Live playback never started (Hls.js path).** `HLS_CONFIG.autoStartLoad=false` but the live path (`loadHls → loadStream → _createEngine → loadSource`) never called `startLoad()`; the probe/gate methods had zero callers. **Fix:** wired `_probeManifestAndStart()` into the `loadHls` effect, added the missing periodic prebuffer-gate tick (`_startPrebufferGate`/`GATE_CHECK_INTERVAL_MS`), made the same fix reach the handover path, and clear the fatal timer once the buffer is confirmed ready so a slow-to-click viewer isn't dropped. `celebration-child/js/HSPlayer/index.js`.
- [x] **B2 — Live-state poll endpoint returned HTTP 500.** `endpoints/live-state.php` called `rest_url()`/`wp_safe_redirect()` without loading WordPress. **Fix:** added the `wp-load.php` bootstrap (matches sibling `cf-live-webhook.php`); the player's poll now follows the 301 to the REST route. `celebration-child/endpoints/live-state.php`.
- [x] **B3 — Site-wide fatal from duplicate function.** `hs_compute_server_live_state()` was defined unguarded in both the theme and the plugin's `BackwardCompat.php` → "Cannot redeclare". **Fix:** removed the plugin's copy (the theme's transient-reading version is what the player page wants and is the sole definition now) and guarded the theme's with `function_exists`. Verified no external callers in repo or on the live server. `functions.php`, `src/BackwardCompat.php`.
- [x] **B4 — Unconfigured-credential white-screen (whole site).** `AjaxController::register()` did `new self()` at boot on every request, eagerly constructing services whose constructors throw `ConfigError` if `HSCF_cloudflare_account_id` is empty. **Fix:** deferred construction to a `dispatchStatic()` that builds the controller only when an admin AJAX action fires, wrapped in a `ConfigError` catch. `src/Admin/AjaxController.php`.
- [x] **B5 — Deploy uploaded files in the wrong order.** The theme's `functions.php` requires a plugin file; `deploy.sh` uploaded the theme first. **Fix:** swapped `deploy.sh` to upload plugin-first, added an `is_readable()` backstop around the require in `functions.php`, and documented the order for manual FTP. `deploy.sh`, `functions.php`, `deployfiles.md`.

- [x] **B6 — Instant FATAL when the manifest isn't ready yet (found during live testing).** The live path called `loadSource()` immediately, handing Cloudflare's empty **HTTP 204** (live input not yet broadcasting) to Hls.js → `manifestParsingError` → instant fatal "please refresh" poster. The pre-probe meant to prevent this was bypassed and also wrongly treated 204 as "ready". **Fix:** the engine is now created only **after** the probe confirms a real `#EXTM3U` playlist (200 + content, not 204); while waiting, the player sits in PREPARING; the stuck-timer now starts only once the manifest is actually loading, and a probe-cap miss enters fatal gracefully. This matters in production for the few-second race at the start of every broadcast. `js/HSPlayer/ManifestProbe.js`, `js/HSPlayer/index.js`.

## 🟠 High (broken features) — ALL FIXED

- [x] **H1 — VOD didn't autoplay on Safari/iOS.** Autoplay was gated to `instanceof HlsEngine`. **Fix:** removed the guard; `manifestParsed` maps to `loadedmetadata` on the native engine too. `index.js`.
- [x] **H2 — Hls.js error handler fired twice** (double-counting recovery → premature give-up). **Fix:** `HlsEngine.on()` no longer also registers `'error'` directly on Hls.js (it's already bridged via `_emit`). `HlsEngine.js`.
- [x] **H3 — Admin Test/Rotate buttons always 403.** They sent the wrong nonce key/value. **Fix:** send `_wpnonce: hscf_ajax.nonce` and use `hscf_ajax.ajax_url`. `src/Admin/SettingsPage.php`.
- [x] **H4 — `?debug=1` panel never appeared.** It was `display:none` with nothing showing it. **Fix:** show it in `connectedCallback` when `debugMode`. `index.js`.

## 🟡 Medium — ALL FIXED

- [x] **M1 — CSV/formula injection** in the activity-log export. **Fix:** added `csvSafe()` that neutralizes leading `= + - @ \t \r` on attacker-influenced columns. `src/Admin/ActivityPage.php`.
- [x] **M2 — `configuration.md` falsely marked `HSCF_customer_id` optional** with a default. It's required; player fatals without it. **Fix:** corrected the table.
- [x] **M3 — Deploy checklist missing required options.** **Fix:** added `HSCF_cloudflare_account_id` and the auth (token OR email+key) rows, plus admin/live-state/config smoke-test steps. `deployfiles.md`.

## ⚪ Operational / cleanup

- [x] **O1 — `rollback.sh` overwrote the server with no current-state snapshot.** **Fix:** it now snapshots the live state to `backups/pre-rollback-<ts>/` before restoring.
- [x] **O2 — Wrong `dbDelta` include path** in a dead helper (`cf-live-webhook.php` `wp-admin/upgrade-functions.php`). **Fix:** corrected to `wp-admin/includes/upgrade.php` (latent trap removed).
- [x] **Note — manifest-probe / prebuffer-gate "dead code"** flagged by the simplification pass is no longer dead: B1 wires it into the live path (its intended use).

## ⏸️ Deferred (optional cleanup — left intentionally, low value / small risk)

- [ ] **Delete the rest of `BackwardCompat.php`** (15 remaining shim functions). No callers found in repo or on the live server's plugins/mu-plugins, but they're a safety net for any DB-stored snippet we can't see. Kept deliberately; remove later if desired.
- [ ] **Remove the redundant transient double-write** in `cf-live-webhook.php` (lines ~413-414 also written by `StateWriter`). Harmless micro-inefficiency and a fallback if the plugin class is absent; left as-is.
- [ ] **Remove remaining dead helpers** in `cf-live-webhook.php` (`hs_install_webhook_log_table` etc.) and a few unused constants (`MIN_PREBUFFER_SECONDS`, …). Cosmetic.
- [ ] **Simplify the flat-file live-state layer** (dual transient+file). Over-built for the scale but working; not worth the churn pre-deploy.

---

## ✅ Cleared during the audit (checked, not problems)
Webhook signature verification (timing-safe, rejects on missing secret); every AJAX action gated by nonce+capability; no hardcoded secrets anywhere (old streamer key confirmed gone); SQL uses `$wpdb->prepare`; admin HTML output escaped; CSP sane; REST `permission_callback` correct for a public poll; `CloudFlareEP.php` is a locked 410 stub; the `wp_hs_webhook_log` table IS created on in-place deploy (idempotent `plugins_loaded` install, not just the activation hook); plugin autoloader resolves all classes; `deploy/` is byte-identical to source.

---

## 🔴 Found by the local WordPress mirror (2026-06-07) — plugin fatals lint couldn't see

A Docker WP mirror (`local-wp/`, gitignored — matches prod: WP 6.1.1 / PHP 8.2) ran the
real theme + plugin for the first time. The v2 plugin had **never** been activated in a real
WordPress (prod still runs the old monolith), so these only surfaced on execution:

- [x] **P1 — Plugin activation fatal: `Class "HS\LiveState\Endpoint" not found`.** The `src/`
  tree is split — `HS\Admin\*` / `HS\Services\*` live under `src/`, but `HS\Config`,
  `HS\LiveState\*`, `HS\Webhook\*` live under `src/HS/`. The autoloader only looked under
  `src/`, so the never-explicitly-required `Endpoint` couldn't load. **Fix:** autoloader now
  tries both `src/` and `src/HS/`. `src/Plugin.php`. (The earlier audit *read* the autoloader
  and cleared it — only running it caught this.)
- [x] **P2 — Plugin load fatal: `Call to undefined function HS\Admin\add_menu_page()`.**
  `boot()` called `SettingsPage::registerMenu()` inline; it calls `add_menu_page()`, which
  only exists in admin context / on the `admin_menu` hook — so it fatals on every front-end
  and wp-cli request. **Fix:** hook it (`add_action('admin_menu', …)`) like the others.
  `src/Plugin.php`.
- [x] **P3 — Player-page CSP blocks Google Fonts → messages fell back to a system font.**
  The page's `Content-Security-Policy` (no `font-src`) blocked fonts, and the real player
  template never loaded Josefin Sans anyway (only the test page did). **Fixed:** self-hosted
  the Josefin Sans variable woff2 in the theme (`fonts/`), `@font-face` in the player page,
  and `font-src 'self'` added to the CSP — no Google dependency. Verified in the mirror under
  the real CSP (font serves `200 font/woff2`). Commit 6b77c3b.

Everything else exercised cleanly: REST `live-state` (contract JSON + cached-state read),
webhook signature accept/reject, test-ping, `disconnected`→state-write→REST chain, and the
real player template render. Drive it with `local-wp/set-live.sh` and `simulate-webhook.sh`.

## 🚦 Still required before this is deploy-ready (NOT code — process)

1. **Validate against a real Cloudflare live stream on a throwaway/staging page.** Confirm: poster + play button pre-click → click → stream reaches PLAYING within ~10s; idle shows the idle poster without crashing; a mid-event `videoUID` change (handover) keeps playing. This is the test that was deferred and never run — the B1 fix specifically needs it.
2. **Cross-device pass** (Android Chrome, desktop Chrome/Firefox/Safari, modern iPhone Hls.js + older iPhone native HLS, VOD on iPhone).
3. **Confirm all required options are set** before/at deploy: `HSCF_cloudflare_account_id`, `HSCF_webhook_secret`, `HSCF_customer_id`, and Cloudflare auth (token or email+key).
4. **Re-run the smoke test** in `deployfiles.md` (now includes the wp-admin-loads and live-state-JSON checks).
5. **Heads-up:** the Playwright/unit tests were not updated to match the new wiring (they fake Hls.js). Treat them as not-yet-trustworthy for the playback path; the real-stream test above is the source of truth.
