# Deploy Files Checklist

Use this document as a checklist when uploading the v2 rebuild to your live site via FTP.

## How to use this

The `deploy/` directory in this repository mirrors the WordPress folder structure on your server. Inside `deploy/` you will find:

```
deploy/
└── wp-content/
    ├── themes/
    │   └── celebration-child/      ← upload contents to /wp-content/themes/celebration-child/
    └── plugins/
        └── HitchStream_Cloudflare/ ← upload contents to /wp-content/plugins/HitchStream_Cloudflare/
```

**The simplest deploy:** upload everything inside `deploy/` to your server, preserving the directory structure. Your FTP client will overwrite existing files. Two minor things — described below — also require **deleting** a couple of stale files from the server.

If you'd rather upload only the files that changed, use the tables below as a checklist. Every file listed needs to land on the server at the path shown.

---

## ⚠️ Before you upload — back up first

You said you would back up your WordPress database and files before this deploy. Do that now if you haven't yet.

The two specific things on the server you cannot easily recover without a backup:

1. **`wp-content/themes/celebration-child/`** — full directory.
2. **`wp-content/plugins/HitchStream_Cloudflare/`** — full directory.
3. **The WordPress database** — for the `wp_options` rows we'll be modifying (`HSCF_*` settings) and the new `wp_hs_webhook_log` table.

---

## 🗑️ Delete these stale files on the server (after your backup)

Two files were removed during the rebuild. If they remain on the server, the new code will still work but the old code stays as dead clutter (and the legacy `HSPlayerElement.js` is still loadable via direct URL, which is undesirable).

Delete on the server (do this AFTER you upload the new files, so there is no window where the page references a missing file):

| Server path | Reason |
|------------|--------|
| `/wp-content/themes/celebration-child/js/HSPlayerElement.js` | Replaced by `/wp-content/themes/celebration-child/js/HSPlayer/index.js` and the supporting modules |
| `/wp-content/themes/celebration-child/js/old/` (whole directory) | Contained `cloudflare_player.js`, the legacy Cloudflare player. Not used. |

If your FTP client supports it, deleting the entire `js/old/` folder is cleaner than just the file inside.

---

## 📁 Theme — `celebration-child/`

Upload destination: **`/wp-content/themes/celebration-child/`**

### Modified files (existing files being replaced)

| Local path (in `deploy/wp-content/themes/celebration-child/`) | What it does | ☐ |
|------|-------------|---|
| `HitchStream-Player.php` | The iframe page that renders the player. Now uses `<script type="module">`, builds `window.HSPlayerConfig`, includes the corrected CSP header. | ☐ |
| `functions.php` | Theme bootstrap and webhook helpers. Dead legacy functions removed. | ☐ |
| `endpoints/cf-live-webhook.php` | Cloudflare webhook receiver. Shared-secret verification, test-ping handling, `/lifecycle` lookup. | ☐ |
| `endpoints/CloudFlareEP.php` | Reduced to a 410 Gone stub. Legacy auth path removed. | ☐ |
| `endpoints/live-state.php` | Now redirects to the new REST route at `/wp-json/hitchstream/v1/live-state`. | ☐ |

### New files (did not exist before)

| Local path (in `deploy/wp-content/themes/celebration-child/`) | What it does | ☐ |
|------|-------------|---|
| `js/HSPlayer/index.js` | Player entry point. Defines `<hs-video>` custom element. | ☐ |
| `js/HSPlayer/constants.js` | Single source of truth for state names, timing constants, Hls.js config. | ☐ |
| `js/HSPlayer/PlayerStateMachine.js` | Pure state-transition module (no DOM, no fetch, no timers). | ☐ |
| `js/HSPlayer/LivePoller.js` | 10s polling loop with timeout, ETag/304, exponential backoff. | ☐ |
| `js/HSPlayer/ManifestProbe.js` | Probes the HLS manifest URL until ready (or cap reached). | ☐ |
| `js/HSPlayer/HlsEngine.js` | Hls.js wrapper for browsers that need MSE-based HLS. | ☐ |
| `js/HSPlayer/NativeHlsEngine.js` | Native HLS wrapper for Safari and older iOS. | ☐ |
| `js/HSPlayer/EngineFactory.js` | Picks the right engine based on browser capability. | ☐ |
| `js/HSPlayer/PosterManager.js` | Owns the initial / idle / fatal poster state. | ☐ |
| `js/HSPlayer/StatusOverlay.js` | Top-left status widget. Pre-gesture suppression preserved. | ☐ |
| `js/HSPlayer/DebugPanel.js` | Top-right debug panel (`?debug=1`). | ☐ |
| `js/HSPlayer/UiController.js` | Owns the shadow DOM HTML and CSS. | ☐ |
| `js/HSPlayer/GestureUnlock.js` | Resolves on first user gesture (play button or document). | ☐ |
| `js/HSPlayer/utils/safe.js` | try/catch wrapper with ring buffer + always-on console.error. | ☐ |
| `js/HSPlayer/utils/timers.js` | TimerRegistry — every timer disposed on disconnect. | ☐ |
| `js/vendor/hls-1.6.16.min.js` | Self-hosted Hls.js (replaces the `cdn.jsdelivr.net` reference). | ☐ |

---

## 🔌 Plugin — `HitchStream_Cloudflare/`

Upload destination: **`/wp-content/plugins/HitchStream_Cloudflare/`**

### Modified files (existing files being replaced)

| Local path (in `deploy/wp-content/plugins/HitchStream_Cloudflare/`) | What it does | ☐ |
|------|-------------|---|
| `HitchStream-Cloudflare.php` | Plugin bootstrap. Now loads `src/Plugin.php`, registers activation hook for the webhook log schema. | ☐ |
| `js/hscf-admin.js` | Admin AJAX glue. Nonce-aware. | ☐ |

### New files (did not exist before)

| Local path (in `deploy/wp-content/plugins/HitchStream_Cloudflare/`) | What it does | ☐ |
|------|-------------|---|
| `src/Plugin.php` | Bootstrap class. Wires AjaxController, SettingsPage, ActivityPage, REST endpoint, schema install. | ☐ |
| `src/BackwardCompat.php` | Procedural function shims so legacy callers keep working. | ☐ |
| `src/Admin/AjaxController.php` | Single allowlisted AJAX dispatcher. Every action: nonce + capability + lookup. | ☐ |
| `src/Admin/SettingsPage.php` | Admin settings UI (Cloudflare creds, webhook secret, posters, alerts). | ☐ |
| `src/Admin/ActivityPage.php` | Tools → HitchStream Activity. Shows webhook log, filters by inputId, CSV export. | ☐ |
| `src/HS/Config.php` | Typed accessor for every plugin option. Throws if a required option is missing. | ☐ |
| `src/HS/ConfigError.php` | Exception thrown by Config when required options are missing. | ☐ |
| `src/HS/CloudflareClient.php` | Single Cloudflare API client. Bearer-preferred auth, deprecated email+key fallback. | ☐ |
| `src/HS/Webhook/Verifier.php` | `cf-webhook-auth` shared-secret verifier (Cloudflare Notifications format). | ☐ |
| `src/HS/LiveState/Endpoint.php` | REST route `/wp-json/hitchstream/v1/live-state`. The polling endpoint. | ☐ |
| `src/HS/LiveState/StateWriter.php` | Writes webhook events to transient + flat file atomically. | ☐ |
| `src/Services/LiveInputService.php` | Create / list / delete Cloudflare live inputs. | ☐ |
| `src/Services/WebhookService.php` | Register / list / delete / rotate webhooks. | ☐ |
| `src/Services/StreamerService.php` | Placeholder-stream service ops with RTMPS allowlist. | ☐ |
| `src/Services/RecordingsService.php` | List / download recordings from Cloudflare. | ☐ |

---

## 🎛️ Post-upload — WordPress admin steps

Once files are uploaded, do these in the WordPress admin (no FTP, no code):

| ☐ | Step | Where |
|---|------|-------|
| ☐ | Generate a long random string (32+ characters) and set it as `HSCF_webhook_secret` | Settings → HS CloudFlare |
| ☐ | Set the same string in the Cloudflare dashboard's webhook destination configuration | Cloudflare → Notifications → Destinations |
| ☐ | Set `HSCF_customer_id` to your Cloudflare customer code (the `customer-XXXXX` value from your Stream URLs) | Settings → HS CloudFlare |
| ☐ | Set `HSCF_cloudflare_api_token` to a Cloudflare API token with Stream read/write scopes | Settings → HS CloudFlare |
| ☐ | Rotate the streamer API key on `streamer1.hitchstream.com` | The streamer service admin |
| ☐ | Set the new streamer API key in `HSCF_streamer_api_key` | Settings → HS CloudFlare |
| ☐ | Verify alert email is set in `HSCF_alert_email` | Settings → HS CloudFlare |

---

## 🧪 Post-deploy smoke test

After all of the above:

| ☐ | Test |
|---|------|
| ☐ | Open one wedding page that's currently configured for live streaming. The poster + play button should render. No browser console errors. |
| ☐ | Click the play button. If a stream is currently live, it should start within ~10 seconds. If idle, the player should show a "waiting" state without crashing. |
| ☐ | Open Tools → HitchStream Activity. The page should load (it'll be empty until the first webhook arrives). |
| ☐ | Trigger a Cloudflare test webhook. Confirm a row appears in the activity page within 5 seconds, with `signature_ok` = ✓. |
| ☐ | Test on an iPhone running iOS 15.x or 16.x (the native-HLS path / B2 fix verification). |
| ☐ | Test on a modern iPhone (Hls.js path). |
| ☐ | Test on Android Chrome. |
| ☐ | Test on desktop Safari. |

---

## 📦 Files NOT to upload (kept local only)

These exist in the repository for development purposes but should never be on the production server. The `deploy/` directory already excludes them, but mentioning here so you don't accidentally drag-and-drop the whole repo:

- `docs/**` — internal documentation
- `HitchStream_Player_v2.md` — rebuild plan
- `PROJECT.md`, `CLAUDE.md`, `README*` — project docs
- `.git/`, `.gitignore`, `.gitattributes` — git metadata
- `.claude/` — Claude Code settings
- `.eslintrc.json` — dev-only lint config
- `celebration-child/js/__tests__/**` — Playwright integration tests, mock server, unit tests
- `HitchStream_Cloudflare/tests/**` — PHP unit tests
- `CloudFlare Docs/**` — Cloudflare reference material (read-only)
- `deploy/` — this is the staging mirror; you upload its **contents**, not the `deploy/` folder itself

---

## 🔄 If `deploy/` ever gets out of sync with the live source

The `deploy/` directory is a one-time snapshot built from `celebration-child/` and `HitchStream_Cloudflare/` at the time of this audit. If you make further code changes locally before deploy, rebuild `deploy/` with this command from the repo root:

```bash
rm -rf deploy
mkdir -p deploy/wp-content/themes deploy/wp-content/plugins
rsync -a --exclude '__tests__' --exclude '*.spec.js' --exclude '.DS_Store' --exclude 'docs' \
  celebration-child/ deploy/wp-content/themes/celebration-child/
rsync -a --exclude '__tests__' --exclude 'tests' --exclude '*.test.php' --exclude '.DS_Store' --exclude 'docs' \
  HitchStream_Cloudflare/ deploy/wp-content/plugins/HitchStream_Cloudflare/
```

That regenerates `deploy/` from whatever's in your working tree.
