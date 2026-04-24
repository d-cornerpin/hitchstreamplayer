# HitchStream Player v2 — Bulletproof Rebuild Plan

> **Who this is for:** two developer agents working in parallel — **Agent A** (Player) and **Agent B** (Server + Plugin). Each agent owns one workstream end-to-end. The two meet at a frozen contract (§4) and at scheduled integration checkpoints (§7).
> **What the player is:** a custom `<hs-video>` web component, built in-house, that plays Cloudflare Stream live HLS and VODs inside an iframe embedded on a wedding page. It is **not** the Cloudflare-provided player. Everything in this plan exists to serve it.
> **The bar:** a viewer at a wedding sees the stream begin within seconds of clicking play, plays smoothly for the whole event, survives the streamer's internet blips and deliberate cuts between ceremony and reception, and never sees text on screen that claims a cause the player cannot actually know.

---

## How to read this doc

1. **Everyone** reads §1 (product model), §2 (critical bugs), §3 (architecture), §4 (contract), §7 (checkpoints), §10 (preserved behaviors).
2. **Agent A** owns §5 (Player workstream) end-to-end.
3. **Agent B** owns §6 (Server + Plugin workstream) end-to-end.
4. The checklists in §5 and §6 are hard gates. **Do not advance to the next phase until every box in the current phase is checked.** No exceptions — staging surprises are what this structure is designed to prevent.

---

## 1. The product (correct understanding — read this first)

### What the viewer actually sees

The player is loaded inside an iframe at 16:9 on a wedding page. From the moment the page loads until the moment the viewer clicks, **all the viewer sees is the poster image and a play button centered on it.** No spinner, no status text, no loader, no "waiting" message. Deliberately silent. The poster is either the couple's custom initial poster (before any playback) or the vague logo idle poster (after playback has happened at least once in this session).

When the viewer clicks play, the in-player status overlay (top-left, small) becomes permitted to show. From that point the status overlay is the only textual signal the viewer gets about what the player is doing.

### What "live" actually looks like in practice

A wedding is not a single monotonic broadcast. The stream goes up and comes down repeatedly during a single event:

- Rig connects 20 minutes before ceremony, broadcasts for 30 minutes.
- Streamer **kills the stream** to move cameras/rig from ceremony to reception.
- Stream is idle for 20–60 minutes.
- Rig reconnects at reception. This is usually a **new `videoUID`** because it's a new broadcast session in Cloudflare.
- Streams for 3 hours with occasional **cellular/Starlink blips** (`reconnecting` → back on).
- Ends.

The player cannot tell why the stream is down at any given moment. Could be a deliberate cut, could be a dead modem, could be "they went to dinner." Therefore the player never displays text claiming a cause. The **vague idle poster is the universal tell**: something is happening, or not, and we're not pretending to know which. If the stream comes back, the player resumes. If it doesn't, the viewer sees the logo and makes their own call.

### Pre-playback vs post-playback idle

The player distinguishes two kinds of idle using `hasPlayedOnce`:

- **Idle, never played** → initial poster (custom per wedding).
- **Idle, played at least once** → idle poster (vague logo) + the transient "Paused/Ended" status message that fades after 3 seconds. This is the existing behavior; it is correct; do not change it.

### Debug mode

The player has a debug panel (top-right) that shows state, prebuffer depth, buffered-ness, click-gesture status, HLS latency, live flag, current videoUID, poll count, error code, data source. Enabled via `?debug=1` in the iframe URL. **The debug panel is a preserved feature.** Keep it, improve its content, never remove it.

### Two modes

- **Live mode** (`live=true&inputId=<live_input_id>`): polls WP for state, uses prebuffer gate, state machine IDLE → PREPARING → PLAYING → FATAL.
- **VOD mode** (`live=false&inputId=<video_uid>`): loads a specific Cloudflare video UID directly, no polling, no state machine, no prebuffer gate. Used for playback of recordings.

### Why we still poll

The primary source of truth is webhooks. Cloudflare fires a webhook to WordPress the instant anything changes; WordPress writes the normalized state to a store. But the browser can't receive webhooks. The browser has to ask. Polling is the *transport* that carries webhook-truth to the iframe.

At 50 concurrent viewers × 10-second polling × 6-hour wedding on a correctly-built lightweight endpoint, this costs single-digit percent of one core on a 4GB droplet. Trivially safe at your scale.

Polling runs at ~10s throughout the entire event — before playback, during playback, and after the stream ends — because the player genuinely has no way to know when the next state transition will happen and must keep asking.

---

## 2. Critical bugs (confirmed by code review; file:line references)

All of these are real. File paths are relative to the repo root.

### Tier 1 — affects live weddings today

| ID | Where | What happens |
|----|-------|--------------|
| **B1** | `celebration-child/endpoints/cf-live-webhook.php:170` | Webhook handler writes `videoUID=''` into the transient because Cloudflare's webhook payload does not carry the UID. The player's live branch (`HSPlayerElement.js:276`) requires `isLive && videoUID` to load HLS. Result: for up to the transient TTL (5 min), viewers see `{live:true, videoUID:null}` and the player falls through to idle. **The webhook path cannot currently start playback in production.** |
| **B2** | `celebration-child/js/HSPlayerElement.js:460–577` (`loadStream`) | Live path unconditionally does `new Hls()` + `attachMedia(video)` with no `Hls.isSupported()` check and no native-HLS fallback. On iPhones running iOS < 17.1 (no MSE), nothing plays; the player sits in PREPARING until the 45-second fatal timer fires. The VOD path at line 215 has the correct fallback; the live path does not. |
| **B3** | `HSPlayerElement.js:192 vs 264 vs 806` | `this.isLive` is set by `setApiInfo` to mean "this is a live-mode player (not VOD)". The poll callback then overwrites it every 10s with "the stream is currently live". `onClickPlayButton` (line 806) dispatches live-vs-VOD on the current value. If a viewer clicks play while the stream is idle, the code goes into the VOD branch and calls `_attemptAutoplay(video)` on an empty `<video>`. |
| **B4** | `HSPlayerElement.js:798–839` | `onClickPlayButton` is registered on the play button (persistent) and separately on `document` click/touchstart/keydown (`{once: true}`). A click anywhere in the iframe before the play button fires the document handler, then clicking the button fires the button handler. The handler runs twice; the second call reaches `prepareToPlay(hlsUrl)` and destroys/rebuilds the HLS pipeline. Viewer sees a second loading cycle. |
| **B5** | `HSPlayerElement.js:1020–1045` (`_probeManifestAndStart`) | Probes the manifest URL every 1.5 s with no maximum attempts. A permanently-404ing manifest (wrong UID, deleted video) loops forever. Because MANIFEST_PARSED never fires, the fatal timer never starts either — the player never transitions to FATAL. |
| **B6** | `HSPlayerElement.js:248–370` (`startPolling`) | `fetch()` has no AbortController, no timeout, no concurrency guard. If WordPress hangs, fetches stack at every 10 s interval tick. No exponential backoff on errors. |
| **B7** | `HSPlayerElement.js:835–839` + `863–888` | The document-level gesture listeners (click/touchstart/keydown) are registered with `{once:true}` but not explicitly removed on `disconnectedCallback`. If the element unmounts before any of them fire, the closure lingers on `document` over the old element. |
| **B8** | `celebration-child/endpoints/cf-live-webhook.php:36–39` + `celebration-child/functions.php:664–674` | When the webhook secret option is blank, the signature check returns `true` and accepts every request. An attacker who discovers the webhook URL can set any input's state to anything. Bypass exists in two places (both inline and as a shared helper). |
| **B9** | `HitchStream_Cloudflare/HitchStream-Cloudflare.php` (AJAX handlers) | Every AJAX handler registered via `add_action('wp_ajax_*')` lacks both `check_ajax_referer` and `current_user_can`. Any authenticated user can delete live inputs, rotate the webhook, start/stop the placeholder stream, etc. |
| **B10** | `HitchStream_Cloudflare/HitchStream-Cloudflare.php:786, 819, 857, 887` | Hardcoded API key `72c020a8d042a1f549b548311d1e4577` in source, duplicated four times. This is the `X-API-KEY` for `streamer1.hitchstream.com` (your internal placeholder-stream service), not a Cloudflare credential. Still must be removed and moved to a configurable option. |
| **B11** | `celebration-child/endpoints/CloudFlareEP.php:127–141` | Default pass-through proxies any authenticated POST to Cloudflare's `/live_inputs/{id}/videos` endpoint, returning the raw list. Should be removed. |

### Tier 2 — affects edge cases or code health

| ID | Where | What happens |
|----|-------|--------------|
| B12 | `HSPlayerElement.js:267–275` | `reconnecting` handler only updates the status text; it does not restart the fatal timer. If reconnecting persists long enough for the buffer to drain, the player freezes at end-of-buffer with no fatal transition. |
| B13 | `HSPlayerElement.js:276–302` | On `videoUID` change (e.g., ceremony → reception), the player destroys the existing HLS and builds a new one from scratch. Viewer sees 2–5 seconds of black frame. No handover logic. |
| B14 | `HSPlayerElement.js:276–302` | Player reconstructs `hlsUrl` client-side from `videoUID` + the hardcoded customer code, instead of trusting the `hlsUrl` field the server already returns. |
| B15 | `HSPlayerElement.js:502–516` (Hls.js config) | Duplicate keys: `manifestLoadingRetryDelay` set to 1500 and 2000; `manifestLoadingMaxRetry` set to 5 and 10. |
| B16 | `HSPlayerElement.js:174–178` | `observedAttributes` returns only `['poster-initial','poster-idle']`. `poster-fatal` changes are silently ignored. `attributeChangedCallback` only acts on `poster-initial` and only when state is IDLE; `poster-idle` changes are silently dropped. |
| B17 | `HSPlayerElement.js:12–17` | `POSTER_INITIAL_URL`, `POSTER_IDLE_URL`, `POSTER_FATAL_URL`, `CLOUDFLARE_CUSTOMER_ID` are mutable module-level `let`s, overwritten by every `setApiInfo`. Single-instance assumption. |
| B18 | `HSPlayerElement.js` (class-wide) | ~100 `try { ... } catch (_) {}` blocks silently swallow errors, including around DOM reads and plain assignments that cannot throw. |
| B19 | `HSPlayerElement.js:630–654` | Hls.js ERROR handler only retries `MEDIA_ERROR` (3 times). `NETWORK_ERROR` (e.g., a brief segment 404) escalates directly to fatal, even though many network errors are recoverable via `hls.startLoad(-1)`. |
| B20 | `HSPlayerElement.js:303–365` | Idle-transition teardown is duplicated between the poll callback (inline) and `managePlayerState(STATE.IDLE)`. Two places to update; drift possible. |
| B21 | `HSPlayerElement.js:252` | `window.liveStateEndpoint` is a raw global read. If `HitchStream-Player.php` fails to emit the `<script>`, the fetch URL becomes `undefined?inputId=…` and silently 404s forever. |
| B22 | `HSPlayerElement.js:766–796` | `onPlaying` waits for a subsequent `timeupdate` before hiding the overlay. No fallback — if `timeupdate` doesn't fire (possible on some Android devices with thin buffer), overlay stays up over a playing video. |
| B23 | `celebration-child/endpoints/cf-live-webhook.php:137–140` | Webhook signature header name is guessed with three fallbacks (`cf-webhook-signature`, `cf-webhook-sig`, `x-cf-webhook-signature`). The Cloudflare Notifications system's actual header format (including whether it uses a timestamped `t=…,v1=…` format) is undocumented in the repo and has never been empirically verified against a real webhook delivery. The signature check may be silently failing even with a secret configured. |
| B24 | `celebration-child/endpoints/live-state.php:1` | Every poll runs `require_once wp-load.php`, loading the full WordPress bootstrap. At 50 viewers × 10 s polling, this burns 15–40 ms of CPU per request on nothing useful. |
| B25 | `celebration-child/endpoints/live-state.php:37–86` | Fallback probe uses the expensive `/accounts/{account}/stream/live_inputs/{input_id}/videos` endpoint. The cheaper `https://customer-<CODE>.cloudflarestream.com/<INPUT_ID>/lifecycle` endpoint (documented in `CloudFlare Docs/Watch a Live Stream.md`) returns `{isInput, videoUID, live}` directly and needs no account auth. |
| B26 | `celebration-child/functions.php:852–860` | `disable_all_deprecated_warnings` is set for every deprecated-* filter hook. Hides legitimate WordPress upgrade warnings. |

### Not bugs — confirmed intended behavior

During review I initially flagged the following as bugs. They are not.

- Status overlay suppressed before `userGestureUnlocked` (`updateStatus()`, line 1350) — **intentional**. Pre-click, the player shows only the poster and play button. No status text.
- Idle poster being deliberately vague with no explanatory text — **intentional**. The player has no way to know whether a cut was deliberate, accidental, or terminal, and must not pretend to.

Any earlier language in this document about "Waiting for stream..." being visible pre-click is wrong and has been removed.

---

## 3. Architecture of the work

### 3.1 Two workstreams in parallel

| Workstream | Owner | Scope | File ownership |
|---|---|---|---|
| **A — Player** | Agent A | The `<hs-video>` web component, the iframe page template, browser-side integration tests | `celebration-child/js/HSPlayer/**`, `celebration-child/js/HSPlayerElement.js` (removed at end), `celebration-child/HitchStream-Player.php`, `celebration-child/js/__tests__/**` |
| **B — Server + Plugin** | Agent B | Webhook receiver, live-state endpoint, WP helpers, admin plugin, wedding page templates, admin activity panel | `celebration-child/endpoints/**`, `celebration-child/functions.php`, `celebration-child/*.php` (templates), `HitchStream_Cloudflare/**`, `docs/**` |

**Shared files** (rare; coordinate on Slack before touching):
- `docs/contract.md` — the JSON contract between A and B. Authored jointly in CP-0. Any change requires agreement from both agents.
- `docs/cloudflare-webhook-format.md` — B authors this after empirical verification. A reads it.

### 3.2 Contract-first development

Before either agent writes code (after Phase 0), both agents must agree on and freeze the contract in §4. Both then develop against that contract independently:

- **Agent A** builds the player with a mocked endpoint that matches the contract.
- **Agent B** builds the endpoint to match the contract.
- They meet at CP-1 and validate that A's player works against B's real endpoint.

This is the single most important structural decision in this plan. Skipping the contract step and trying to "figure it out as we go" is how parallel workstreams break weddings.

### 3.3 Integration checkpoints

Five scheduled points at which both agents stop feature work and validate end-to-end against each other. Detailed in §7.

- **CP-0** Contract freeze (before Phase 1 of either workstream)
- **CP-1** Webhook → transient → player roundtrip works for a single live input
- **CP-2** Module-split player plays the full state machine happy path against the real lightweight endpoint
- **CP-3** All edge cases (reconnecting, videoUID change mid-event, streamer idle + resume, iPhone native HLS) pass on staging with real Cloudflare
- **CP-4** Shadow-mode production run on one real low-stakes wedding
- **CP-5** Staged rollout to 10% of weddings for 2 weeks

### 3.4 Daily sync

Each agent posts a short status at end of day:

- Which phase they're in
- Which checkboxes they completed today
- What's blocking them (if anything blocks on the other workstream, name it)

Blockers on the other workstream are rare if the contract is respected. When they happen, escalate to CP-level integration immediately.

---

## 4. The frozen contract

**Both agents must read and agree on this section before starting Phase 1 of their workstream.** Any change requires re-agreement in a CP-0 re-convene.

### 4.1 `GET /wp-json/hitchstream/v1/live-state?inputId=<id>`

Request: simple GET, no body, no auth. `inputId` matches `^[A-Za-z0-9_-]+$`.

Successful response body (HTTP 200):

```json
{
  "state": "live" | "reconnecting" | "idle" | "error",
  "videoUID": "string" | null,
  "hlsUrl": "https://customer-<code>.cloudflarestream.com/<uid>/manifest/video.m3u8" | null,
  "errorCode": "string" | null,
  "source": "webhook" | "probe" | "coalesced",
  "ts": 1714000000
}
```

`errorCode` is a **server pass-through**: the server stores and returns whatever Cloudflare sent, without filtering. New/unknown codes remain visible in the activity log for forensics. The player displays viewer-facing text ONLY for codes present in `window.HSPlayerConfig.errorMessages`; unknown codes are treated as equivalent to `idle` for UI purposes (no alarming message, no fatal) and logged via the `error_code` debug panel field.

**State → field-population table:**

| state | videoUID | hlsUrl | errorCode |
|---|---|---|---|
| `live` | populated (string) | populated (string) | null |
| `reconnecting` | populated (string) — same as preceding `live` | populated (string) — same as preceding `live` | null |
| `idle` | null | null | null or a Cloudflare error code (if the stream went idle due to an error) |
| `error` | null | null | populated (string) — Cloudflare error code |

Error response body (HTTP 4xx/5xx):

```json
{ "error": "string description", "code": "invalid_input_id" | "upstream_unavailable" | "internal" }
```

Response headers:

- `Content-Type: application/json; charset=utf-8`
- `Cache-Control: no-store`
- `ETag: "<opaque>"` — changes on any change to state/videoUID/hlsUrl/errorCode. Stable across polls that return identical data.
- `X-HS-Correlation-Id: <uuid>` — per-request value set server-side. The player reads it and logs it in the debug panel; it is **not** echoed back on subsequent polls. Each poll is an independent request. The value is forensic: when a viewer reports a problem and you see "last correlation ID was abc123" in their debug panel, you can grep server logs for that ID.

**304 handling:** On receiving `304 Not Modified` (when the client sends `If-None-Match` matching the current ETag), the player:
- Does NOT advance the state machine (no state-change event)
- Does NOT parse a body (there is none)
- Increments the poll counter and updates "last poll timestamp" in the debug panel
- Stores the ETag it sent in `If-None-Match` as "last confirmed" in the debug panel
- Otherwise behaves as if nothing happened

In other words: 304 = "state is unchanged from my last good response." The player already holds the right state; it just confirms freshness and moves on.

`source` describes **HOW** the server determined the current state, not **WHAT** the state is:
- `webhook` — the current state was last written by a webhook event (most common during a healthy event)
- `probe` — the transient/cache was empty or expired, and the server called `/lifecycle` to determine state
- `coalesced` — this request was served the result of another in-flight probe via the single-flight lock

There is no fourth value for "no information." If the server genuinely cannot determine state (credentials missing, Cloudflare unreachable and no cache), that is an error response per §4.1, not a success with a missing source.

### 4.2 State semantics

| State | Player-side meaning | Expected client behavior |
|---|---|---|
| `live` | Cloudflare reports the streamer is actively broadcasting and an HLS manifest is available for `videoUID`. | If not already PLAYING, prepare (load HLS, run prebuffer gate) and play. If PLAYING and `videoUID` is unchanged, do nothing. If PLAYING and `videoUID` changed, handover to the new HLS (§5.5). |
| `reconnecting` | Streamer disconnected briefly and Cloudflare is still holding the session open waiting for them. | Do not tear anything down. Keep playing buffered video. Do not show new visible messaging (vague idle poster logic only triggers when we actually transition to idle). Restart the fatal timer if buffer depth drops below 2 s (§5.6). |
| `idle` | No active broadcast. Either hasn't started yet, or streamer has cut (for any reason). | If playing and buffer has content, keep playing until buffer exhausts, then transition to IDLE. If already idle, stay. Do not make claims about cause. **`videoUID` and `hlsUrl` are always `null` when state is `idle`.** On transition into idle, clear `latestLiveHlsUrl` and any cached `videoUID`. On the next `live` poll, read fresh values from the response. Never carry a URL across an idle transition. |
| `error` | Cloudflare reported a hard error. `errorCode` indicates type. | Log to debug panel. Viewer-facing: only error codes present in `window.HSPlayerConfig.errorMessages` surface visible text. Unknown codes fall through to idle UX. |

### 4.3 `hlsUrl` origin allowlist

The player validates `hlsUrl` against this regex before passing to Hls.js or native HLS:

```
^https://customer-[a-z0-9]{12,20}\.cloudflarestream\.com/[A-Za-z0-9]+/manifest/video\.m3u8(\?.*)?$
```

Non-matching URLs are rejected (logged to debug panel, ignored for that poll cycle). This prevents a compromised server from pointing the player at arbitrary content.

### 4.4 `window.HSPlayerConfig` (PHP → JS bootstrap)

Emitted by `HitchStream-Player.php` as a single `<script>` tag before the player JS loads. One global read, never again.

```js
window.HSPlayerConfig = {
  debug: false,                           // from ?debug=1 query
  mode: 'live' | 'vod',                   // from ?live=…
  inputId: 'string',                      // from ?inputId=…
  autoplay: true,                         // from ?autoplay=… (default true)
  endpoints: {
    liveState: 'https://hitchstream.com/wp-json/hitchstream/v1/live-state'
  },
  cloudflare: {
    customerCode: 'juu1r5es4cbffqjf',     // from WP option
    hlsOriginAllowlistRegex: '^https://customer-[a-z0-9]{12,20}\\.cloudflarestream\\.com/[A-Za-z0-9]+/manifest/video\\.m3u8(\\?.*)?$'
  },
  posters: {
    initial: 'https://…',                 // URL param override, else WP option
    idle:    'https://…',
    fatal:   'https://…'
  },
  server: {
    isLive: false                         // from webhook state at render time — lets the player avoid the first-poll delay if already live
  },
  errorMessages: {
    ERR_STORAGE_QUOTA_EXHAUSTED: 'Service unavailable',
    ERR_MISSING_SUBSCRIPTION:    'Service unavailable'
  }
};
```

`errorMessages` is the **only** set of error codes that produces viewer-facing text. Any `errorCode` from the server that is NOT a key in `errorMessages` falls through to idle UX (no alarming message, no fatal). This means new Cloudflare error codes are safe to receive without a code change — they log to the debug panel and the player behaves as idle.

Missing or invalid `window.HSPlayerConfig` → player shows fatal poster and logs a distinctive error. No silent 404s.

### 4.5 `POST /wp-json/hitchstream/v1/webhook` (Agent B server-side; player has no knowledge of this)

Receives Cloudflare Notifications. Response does not matter to the player. Noted here only so Agent A knows why state changes sometimes appear within one poll cycle.

### 4.6 Configuration options (WP options the plan introduces)

| Option key | Purpose | Used by |
|---|---|---|
| `HSCF_cloudflare_api_token` | Scoped Bearer token (replaces `HSCF_cloudflare_email` + `HSCF_cloudflare_api_key`) | B |
| `HSCF_cloudflare_account_id` | (Existing) | B |
| `HSCF_cloudflare_customer_code` | Cloudflare customer code for HLS origin (currently hardcoded fallback `juu1r5es4cbffqjf`) | Both (A reads from `HSPlayerConfig`) |
| `HSCF_webhook_secret` | Cloudflare Notifications HMAC secret | B |
| `HSCF_webhook_max_age_seconds` | Replay window for webhook timestamp validation, default 300 | B |
| `HSCF_streamer_api_url` | Placeholder-stream service base URL, default `https://streamer1.hitchstream.com` | B |
| `HSCF_streamer_api_key` | Placeholder-stream service `X-API-KEY` | B |
| `HSCF_poster_initial`, `HSCF_poster_idle`, `HSCF_poster_fatal` | Default poster URLs (overridden by URL params per player) | Both (A reads from `HSPlayerConfig`) |
| `HSCF_alert_email` | Where critical error alerts go | B |
| `HSCF_alert_codes` | Comma-separated Cloudflare error codes that trigger an email alert. Default `"ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION"`. | B |

---

## 5. Workstream A — Player (Agent A)

**You are Agent A.** Your job is the `<hs-video>` web component and the iframe page that hosts it. You own the browser side of the player end-to-end. Read §1, §2, §4, §10. Then follow the checklist below in order.

Rules:

- **Do not skip checkboxes.** If you don't know whether to check a box, the box isn't checked.
- **Do not advance to the next phase until every box in the current phase is checked.**
- Any assumption you make about server behavior that isn't in §4 is a bug. If you need something from Agent B that isn't in the contract, request a CP-0 amendment before coding around it.
- Branch per phase: `a/phase-A1`, `a/phase-A2`, etc. Each phase ships as its own PR.
- Every phase ends with a manual smoke test on staging against real Cloudflare.

### Phase A0 — Preparation

- [ ] **A0.1** Read §1, §2, §4, §10 of this doc. Read `CloudFlare Docs/Watch a Live Stream.md` and `CloudFlare Docs/Receive Live Webhooks.md`.
- [ ] **A0.2** Read the current `celebration-child/js/HSPlayerElement.js` front to back. Map every function to one of the future modules in §5.3. You should be able to answer: which new module will each of the ~40 methods/handlers end up in?
- [ ] **A0.3** Read the current `celebration-child/HitchStream-Player.php` front to back. Note every way config flows into the player today (URL params, `<script>` globals, HTML attributes).
- [ ] **A0.4** Stand up a mock contract server. Node + Express is fine. It serves `GET /live-state?inputId=…` per §4.1 with programmable responses (read from a JSON file you can edit per-test), and serves a static HLS manifest from a sample clip for the "live" case.
- [ ] **A0.5** Run the existing player locally against the mock. Confirm you can reproduce **B2** (load a page with the mock returning `live` + a real videoUID, on a WebKit browser with MSE disabled via Playwright flag — player sits in PREPARING).
- [ ] **A0.6** Sign off on §4 at CP-0 joint session with Agent B.

**GATE:** Do not proceed until all of A0.1–A0.6 are done.

### Phase A1 — In-place critical fixes (no refactor yet)

Goal: fix every Tier-1 player bug that can be fixed without splitting the file, shipping each as an independently revertible change. The player continues to be one file after this phase. Refactor comes in A2.

- [ ] **A1.1** Disambiguate `this.isLive` (fixes **B3**)
  - Add `this.playerMode` (read-only after `setApiInfo`), values `'live'` or `'vod'`.
  - Replace every usage that dispatches live-vs-VOD (currently `HSPlayerElement.js:201`, `:806`, `:855–859`) with `this.playerMode`.
  - Add `this.streamCurrentlyLive` (default `false`), written only by the poll callback.
  - Do not leave `this.isLive` in the code. Delete it entirely. Both new fields replace it.
  - Test: with mock returning idle, click play. `_attemptAutoplay` is **not** called. Player stays on poster.
- [ ] **A1.2** Add native HLS fallback to the live path (fixes **B2**)
  - In `loadStream()`, wrap the Hls.js setup in `if (Hls.isSupported()) { … } else if (video.canPlayType('application/vnd.apple.mpegurl')) { video.src = streamUrl; }`.
  - For the native path: no prebuffer gate (browser handles it), no FRAG_PARSING_DATA sampling, no Hls.js ERROR handler. Do attach a `loadedmetadata` listener that attempts autoplay. Do still run the fatal timer on the time-to-first-frame.
  - Test: load the page in a WebKit browser with `Hls.isSupported()` stubbed to false. Live playback starts and plays.
- [ ] **A1.3** Single-fire guard on `onClickPlayButton` (fixes **B4**)
  - First line of the handler: `if (this.userGestureUnlocked) return;`.
  - Replace the three `document.addEventListener(…, { once: true })` calls with a single `AbortController`; pass `{ signal: this.gestureController.signal }` to each; on first fire, `this.gestureController.abort()` cancels the rest.
  - Test: with Playwright, click an empty area of the iframe, then the play button. `_updateDebugPanel` shows `clicked: yes` exactly once. HLS pipeline builds once.
- [ ] **A1.4** Cap manifest probe attempts (fixes **B5**)
  - Add `#probeAttempts` counter in `_probeManifestAndStart`. After 40 attempts (~60 s), call `enterFatalState()` and clear the interval.
  - Every attempt must early-return if `this.hls === null` or `this.playerState === STATE.FATAL`.
  - Test: mock returns `live` + a videoUID that 404s. Player enters FATAL within 65 seconds.
- [ ] **A1.5** AbortController + timeout + concurrency guard on polling (fixes **B6**)
  - Each poll creates a new `AbortController`. Timeout via `setTimeout(() => controller.abort(), 8000)`.
  - `#inFlight` boolean. If the interval ticks while `#inFlight` is true, skip this tick.
  - After 3 consecutive errors, double the poll interval (cap 60 s). Reset to 10 s after one success.
  - Add ±1500 ms jitter to the initial poll delay.
  - Test: point the mock at a URL that hangs for 20 s. Watch Network tab. There is never more than one in-flight request.
- [ ] **A1.6** Clean up all listeners on disconnect (fixes **B7**)
  - Store an `AbortController` at `connectedCallback` start. Pass its signal to every `addEventListener` call, including document-level ones.
  - `disconnectedCallback` calls `controller.abort()` as the first step.
  - Null out `this.videoEl`, `this.playButtonEl`, `this.overlayEl`, `this.statusMessageEl`, `this.debugPanelEl`, `this.overlayPosterEl` after clearing listeners.
  - Set `this.#destroyed = true`. Every public method starts with `if (this.#destroyed) return;`.
  - Test: Playwright test that creates the element, removes it, then dispatches `document.click`. No handler from the old instance fires. No error in console.
- [ ] **A1.7** Fix duplicate Hls.js config keys (**B15**)
  - `manifestLoadingRetryDelay` and `manifestLoadingMaxRetry` declared once.
  - Add eslint config with `no-dupe-keys` enabled to the repo.
- [ ] **A1.8** Complete `observedAttributes` (**B16**)
  - Return `['poster-initial','poster-idle','poster-fatal']`.
  - `attributeChangedCallback` handles all three, each routed to `setPoster` with the right type regardless of state.
- [ ] **A1.9** Trust server `hlsUrl` + origin allowlist (**B14**, part of contract §4.3)
  - In the poll callback, use `data.hlsUrl` directly. Only fall back to client-side construction if `hlsUrl` is missing.
  - Validate every `hlsUrl` (whether from server or constructed) against the regex in §4.3. Reject and log on mismatch.
  - Test: mock returns an `hlsUrl` pointing at `evil.example.com`. Player does not call `hls.loadSource`. Debug panel shows the rejection.
- [ ] **A1.10** Fail loud on missing config (**B21**)
  - At `setApiInfo` (or earlier, at element construction), assert `window.HSPlayerConfig` exists and has all required fields (§4.4). If missing, set poster to fatal, render a distinctive error in debug panel, do not start polling.
  - Test: remove the `<script>` tag from the page template. Player shows fatal poster with explanatory debug-panel text.
- [ ] **A1.11** Deduplicate idle teardown (**B20**)
  - The poll callback's idle branch calls `managePlayerState(STATE.IDLE)` only. All teardown code lives in one place.
  - Test: existing staging smoke test still passes (poll returns idle → player transitions to idle poster).
- [ ] **A1.12** Idle-while-playing drains buffer (§4.2 correctness)
  - Current behavior (line 326–364): on the second consecutive idle poll, the code destroys HLS and pauses the video immediately, discarding any buffered future content.
  - Change: when poll returns idle and the player is currently PLAYING with `video.buffered` containing content beyond `video.currentTime`, **do not tear down**. Set `this.#drainingToIdle = true`. Let playback continue until `video.ended` or buffer is exhausted, then execute the existing idle teardown.
  - If a live poll arrives during drain (new `videoUID` → stream came back), cancel the drain, detect UID change, hand over (deferred to A5; for now in A1, rebuild cleanly as today but retain gesture state).
  - Test: mock goes live → player plays for 20 s → mock goes idle → player continues playing for the remaining buffer, then transitions to idle poster. No abrupt cut.
- [ ] **A1.13** Page visibility handling
  - On `document.visibilitychange` to hidden: stop the poll interval; if playing, do not pause (let the buffer continue; browser suspends decoding anyway).
  - On return to visible: poll immediately; if we were playing and have been hidden > 30 s, call `hls.startLoad(-1)` to re-sync to the live edge.
  - Test: Playwright simulates tab hidden for 40 s, then visible. Poll fires within 500 ms of visible. No stacked polls.
- [ ] **A1.14** Fallback timer for the overlay-hide race (**B22**)
  - `onPlaying` adds both the `timeupdate` once-listener and a 2-second `setTimeout`. Whichever fires first clears the other and hides the overlay.
- [ ] **A1.15** Post-network-error recovery (**B19**)
  - In the Hls.js ERROR handler, when `data.type === Hls.ErrorTypes.NETWORK_ERROR` and `data.details` is in `{manifestLoadError, manifestLoadTimeOut, levelLoadError, levelLoadTimeOut, fragLoadError, fragLoadTimeOut}`, attempt `hls.startLoad(-1)`. Budget of 2 retries with 2 s / 5 s backoff. Only escalate to fatal after budget exhausted.
- [ ] **A1.16** Staging smoke test — one real wedding-shaped flow:
  - Page loads while stream is idle. Viewer sees initial poster + play button. No status text.
  - Viewer clicks play. Status overlay appears (top-left). Stream goes live. Prebuffer gate runs. PLAYING.
  - Streamer cuts. Player drains buffer, transitions to idle poster, status overlay shows "Paused/Ended" then fades.
  - Streamer restarts with a new `videoUID`. Player re-enters PREPARING and plays the new stream.
  - Streamer ends for good. Player transitions to idle poster, stays.
  - Refresh mid-playback. Player picks up cleanly.
  - iPhone iOS 15 (or WebKit with MSE disabled) plays live successfully.
  - Debug panel (`?debug=1`) shows every field populated and updating.

**GATE:** All 16 boxes above checked. Staging smoke test signed off by a second human reviewer. Only then: begin A2.

### Phase A2 — State machine extraction (pure module)

Goal: extract the state-transition logic as a dependency-free pure module with full unit-test coverage. The player still ships as a single file after this phase; it just calls into the extracted module internally.

- [ ] **A2.1** Create `celebration-child/js/HSPlayer/constants.js`. Exports `STATE`, `STATUS` enums, all timing constants (`POLL_INTERVAL_MS`, `FATAL_TIMEOUT_MS`, `MIN_PREBUFFER_SECONDS`, etc.), `HLS_CONFIG` (the single frozen Hls.js config object), `CF_ERROR_MESSAGES`, `HLS_ORIGIN_ALLOWLIST_REGEX`. **This module has no imports.**
- [ ] **A2.2** Create `celebration-child/js/HSPlayer/PlayerStateMachine.js`. Pure. No DOM. No fetch. No timers. No class — a set of functions. Signature:
  ```js
  // input: { currentState, event: {type, payload}, context: { hasPlayedOnce, userGestureUnlocked, bufferAhead, ... } }
  // output: { nextState, sideEffects: [ {type: 'loadHls', url} | {type: 'destroyHls'} | {type: 'setPoster', which: 'initial'|'idle'|'fatal'} | ... ] }
  function transition(input) { … }
  ```
- [ ] **A2.3** Unit tests in `celebration-child/js/__tests__/PlayerStateMachine.test.js`. Use Jest or Vitest. Cover:
  - Every `(currentState × event)` combination that exists in today's flow. Minimum 30 cases.
  - The mid-event state flapping: PLAYING + poll=idle → PLAYING with `#drainingToIdle` side effect; PLAYING with depleted buffer + poll=idle → IDLE; IDLE + poll=live + new videoUID → PREPARING with `handover` side effect.
  - FATAL is a terminal state. No events transition out of it.
- [ ] **A2.4** All unit tests pass.
- [ ] **A2.5** Refactor `HSPlayerElement.js` to call `PlayerStateMachine.transition` for every state change. The shell dispatches the side effects it receives.
- [ ] **A2.6** Staging smoke test — every scenario from A1.16 still passes. No behavior change.

**GATE:** Unit tests green. Staging smoke test green. Only then: A3.

### Phase A3 — Extract polling, prebuffer, manifest probe, HLS engine

- [ ] **A3.1** Create `celebration-child/js/HSPlayer/LivePoller.js`. Owns the poll loop. Accepts `(endpointUrl, inputId, onState, onError)` at construction. Implements A1.5 (AbortController/timeout/jitter/backoff) and A1.13 (visibility handling) natively. Exposes `start()`, `stop()`, `forcePollNow()`.
- [ ] **A3.2** Create `celebration-child/js/HSPlayer/PrebufferGate.js`. Pure-function given `(video, hlsInstance, throughputSamples)`. The existing headroom math from lines 891–1018. Exposes `shouldStartPlayback({bufferAhead, bufferedSegments, segmentDuration, throughputSamples, bitrate, userGesture, ready})` → `{start: boolean, thresholdUsed, reasonIfNot}`. Unit-tested.
- [ ] **A3.3** Create `celebration-child/js/HSPlayer/ManifestProbe.js`. Owns the CORS-readiness probe. Accepts `(url, onReady, onFatal)`. Implements A1.4 (max attempts, early-return on destroyed). Exposes `start()`, `stop()`.
- [ ] **A3.4** Create `celebration-child/js/HSPlayer/HlsEngine.js` and `NativeHlsEngine.js` + `EngineFactory.js` (fixes **B2** properly).
  - Both engines expose: `loadSource(url)`, `destroy()`, `getThroughputSamples()`, `on(event, handler)` where events are `'manifestParsed'`, `'fragLoaded'`, `'fragParsingData'`, `'error'`.
  - `HlsEngine` wraps Hls.js, applies `HLS_CONFIG`, centralizes error handling (media error recovery, network error recovery per A1.15).
  - `NativeHlsEngine` wraps `video.src = url` + `loadedmetadata` listener. Does not expose fragment-level events (returns empty from `getThroughputSamples`).
  - `EngineFactory.create(video)` returns one or the other based on `Hls.isSupported() ? HlsEngine : NativeHlsEngine`.
- [ ] **A3.5** Unit tests for `PrebufferGate` (pure math is easy to test), `LivePoller` (mock fetch), `ManifestProbe` (mock fetch). `EngineFactory` gets a minimal smoke test; the engines themselves are tested by integration tests in A6.
- [ ] **A3.6** Refactor `HSPlayerElement.js` to delegate to these modules. The shell is now < 500 lines.
- [ ] **A3.7** Staging smoke test — every A1.16 scenario still passes. Plus: confirm iPhone/WebKit native-HLS path still works (NativeHlsEngine is now the iPhone path).

**GATE:** All unit tests green. Staging smoke test green + iPhone-class device verified. Only then: A4.

### Phase A4 — Extract UI modules

- [ ] **A4.1** Create `celebration-child/js/HSPlayer/StatusOverlay.js`. Owns the top-left status widget and its animations, fades, hide timers. The pre-gesture suppression (only show status after `userGestureUnlocked === true`) stays — confirmed intended behavior.
- [ ] **A4.2** Create `celebration-child/js/HSPlayer/DebugPanel.js`. Owns the top-right overlay. Enabled by `window.HSPlayerConfig.debug`. Renders a single snapshot object; merges partial updates. Fields as today: state, prebuffer, in-progress, clicked, latency, live flag, videoUID, pollCount, errorCode, source, plus three new: `correlationId` (from `X-HS-Correlation-Id`), `engineKind` (`'hls.js'` or `'native-hls'`), `ringBufferTail` (last 5 logged errors from `safe()`).
- [ ] **A4.3** Create `celebration-child/js/HSPlayer/PosterManager.js`. Single owner of poster state. Knows the initial/idle/fatal types, reads from `HSPlayerConfig.posters` and from `poster-*` attributes with attribute taking priority. Caches URLs at `setApiInfo`.
- [ ] **A4.4** Create `celebration-child/js/HSPlayer/GestureUnlock.js`. Returns a Promise that resolves on first play-button click OR document click/touchstart/keydown. Implements A1.3 + A1.6 AbortController pattern. After resolve, all listeners auto-removed.
- [ ] **A4.5** Create `celebration-child/js/HSPlayer/UiController.js`. Owns the shadow DOM HTML, the CSS, the overlay show/hide. No state of its own. Takes refs at construction.
- [ ] **A4.6** Create `celebration-child/js/HSPlayer/utils/safe.js`. `safe(label, fn, onErrorReturn?)`. Always logs to a ring buffer (last 20 errors); logs to console only when `HSPlayerConfig.debug`. Replace every bare `catch (_) {}` in the codebase with either (a) explicit null-check before the try, (b) `safe('op-name', () => {...})`, or (c) typed exception handling. **Grep check: zero `catch\s*\(\s*_\s*\)` after this step.**
- [ ] **A4.7** Create `celebration-child/js/HSPlayer/utils/timers.js`. `TimerRegistry` with `setInterval`, `setTimeout`, `dispose()`. Every timer in the player registers here; `disconnectedCallback` calls `registry.dispose()` once.
- [ ] **A4.8** Refactor `HSPlayerElement.js` into `HSPlayer/HSVideoElement.js` (< 300 lines now) and `HSPlayer/index.js` (just does `customElements.define`). Delete old `HSPlayerElement.js`.
- [ ] **A4.9** Update `HitchStream-Player.php` (coordinate with Agent B on the one-line script tag change — this is a contract-neutral HTML change, but flag it in daily sync). Script tag becomes `<script type="module" src="/wp-content/themes/celebration-child/js/HSPlayer/index.js"></script>`.
- [ ] **A4.10** Staging smoke test — every A1.16 scenario still passes. Plus: debug panel shows new fields (correlationId, engineKind, ringBufferTail). Pre-click UX unchanged (poster + play button only; no status text).

**GATE:** Zero bare catches in the file tree (CI grep check passes). Every A1.16 scenario green. Only then: A5.

### Phase A5 — Seamless mid-event handover and resume

This phase implements the technical smoothness the plan exists to deliver. No new on-screen messaging. Purely buttery transitions.

- [ ] **A5.1** Gesture persistence across idle/live transitions (audit)
  - Confirm: after a full `live → idle (drain) → idle poster` cycle, `this.userGestureUnlocked` remains `true`.
  - Confirm: when the next `live` arrives, the player proceeds to PREPARING without requiring another click.
  - Test: Playwright flow — click play, stream runs, streamer cuts, 30 s idle, streamer restarts with new `videoUID`. Player plays the new stream with no additional click.
- [ ] **A5.2** Handover between `videoUID`s (fixes **B13**)
  - When poll returns `live` with `videoUID_B` while the player is currently PLAYING or draining on `videoUID_A`:
    - Create a second engine instance (`engineB = EngineFactory.create(<offscreen-video-or-same>)`). On `HlsEngine` this means a fresh Hls.js pre-loading the new manifest into its own MediaSource. On `NativeHlsEngine` this means preparing a second `<video>` element (or just reassigning `src` on the existing one, since native HLS handles the handoff acceptably).
    - When `engineB` fires `manifestParsed` and the prebuffer gate reports ready, swap: detach `engineA` from the visible video, attach `engineB`, destroy `engineA` 2 s later.
    - If `engineA` is still draining its buffer when the swap happens, cross-fade audio briefly (CSS opacity on the video element is enough — the viewer doesn't hear decode-level details).
  - Skip the handover if the current engine is `NativeHlsEngine` — the native path doesn't support this pattern cleanly; just reassign `video.src = newUrl`.
  - Test: mock goes live → plays for 10 s → mock goes idle → 3 s later mock goes live with a different videoUID. Viewer sees at most 500 ms of black between streams. With a warm buffer on `videoUID_A`, sees zero black.
- [ ] **A5.3** Reconnecting watchdog (fixes **B12**)
  - When `LivePoller` reports `state: 'reconnecting'` and player is PLAYING, start a watchdog interval. Every second, check `getBufferAhead()`. If it drops below 2.0 s, restart the fatal timer with a 90 s TTL.
  - When next poll reports `state: 'live'` or `state: 'idle'`, clear the watchdog.
  - Test: mock returns `reconnecting` for 2 minutes while playback runs. Once buffer is exhausted, player fatals (rather than sitting frozen forever).
- [ ] **A5.4** (Optional, only if time) Recording auto-swap
  - When `LivePoller` reports `state: 'idle'` after `hasPlayedOnce`, schedule a one-time probe in 60 s.
  - The probe queries `POST /wp-json/hitchstream/v1/admin/recording-check?inputId=…` (Agent B builds this in B5.x) which returns `{ready: boolean, videoUID?: string, hlsUrl?: string}`.
  - If `ready: true` and no new `live` event has arrived, switch the player into VOD mode on that URL. No banner, no text — just a smooth mode change. The viewer sees playback resume; they will infer from context that it's the recording.
  - If the business doesn't want this behavior, skip. Confirm with Agent B in daily sync before building the endpoint.
- [ ] **A5.5** Staging smoke test — full wedding shape:
  - Play ceremony → streamer cuts → player drains to idle poster → wait 10 minutes → streamer starts reception on new videoUID → player handovers cleanly (verify black-frame duration is < 500 ms with the Playwright video capture).
  - `reconnecting` during playback with thin buffer → fatal after 90 s.
  - iPhone/WebKit: entire flow works on native HLS.

**GATE:** All five boxes green. Only then: A6.

### Phase A6 — Full integration test suite

- [ ] **A6.1** Create `celebration-child/js/__tests__/integration/` directory. Use Playwright.
- [ ] **A6.2** Implement 20 integration tests (run on Chromium + WebKit + Firefox):

| # | Scenario | Assertion |
|---|---|---|
| IT-1 | Cold page load, stream idle | Only poster + play button visible. No status text. No console errors. |
| IT-2 | Stream goes live while page is open, viewer has not clicked | Still only poster + play button. No status text. |
| IT-3 | Viewer clicks play while stream is live | Status overlay appears, prebuffer gate runs, PLAYING within `FATAL_TIMEOUT_MS`. |
| IT-4 | Viewer clicks play while stream is idle | Status overlay shows animated waiting state. No `_attemptAutoplay` call. |
| IT-5 | Autoplay blocked by browser policy | Muted-retry succeeds; video plays muted. |
| IT-6 | Streamer enters `reconnecting` mid-playback with deep buffer | No visible cut. Playback continues from buffer. Debug panel shows source + state. |
| IT-7 | `reconnecting` persists, buffer depletes | Fatal state within 90 s of buffer depletion. |
| IT-8 | Streamer cuts cleanly | Buffer drains to end, then idle poster appears. No abrupt cut. |
| IT-9 | Streamer restarts with new `videoUID` after cut | Handover completes within 10 s. < 500 ms black frame. No additional click required. |
| IT-10 | Cloudflare returns 500 on poll | Poll interval doubles. Next success resets to 10 s. No fatal. |
| IT-11 | WP endpoint hangs (20 s no response) | AbortController fires at 8 s. Never more than one in-flight fetch. |
| IT-12 | Page visibility hidden for 60 s then visible | Poll fires immediately on visible. HLS re-syncs to live edge. |
| IT-13 | `hlsUrl` from server points at disallowed origin | Engine.loadSource never called. Debug panel logs the rejection. |
| IT-14 | `window.HSPlayerConfig` absent | Fatal poster visible. Debug panel shows config-missing error. |
| IT-15 | Element disconnected, then `document.dispatchEvent('click')` | No handler from old instance runs. |
| IT-16 | Element mounted, disconnected, remounted (SPA-style) | New instance works from scratch. No cross-talk. |
| IT-17 | VOD mode (`live=false&inputId=<uid>`) | Direct load, muted-autoplay, no polling. |
| IT-18 | VOD with 404 videoUID | Fatal within 60 s (native HLS: uses fatal timer; Hls.js: uses manifest-probe cap). |
| IT-19 | `?debug=1` | Debug panel visible with all contracted fields populated. |
| IT-20 | Manifest probe hits a permanently-404 URL | Fatal state within 65 s (cap 40 attempts × 1.5 s + grace). |

- [ ] **A6.3** All 20 tests pass on all three browsers in CI.
- [ ] **A6.4** Real-device pass checklist (20 minutes, done manually once per release):
  - iPhone iOS 15.x (native HLS path)
  - iPhone iOS 17+ (Hls.js path)
  - Android Chrome (current)
  - Desktop Safari
  - Smart TV browser (Samsung Tizen or LG webOS) — if available
- [ ] **A6.5** CI gate: the `grep` checks from §9 (no bare catches, no `'IDLE'` magic strings, no duplicate Hls.js config keys) all return empty.

**GATE:** All 20 integration tests passing. Real-device pass signed off. Only then: CP-4.

---

## 6. Workstream B — Server + Plugin (Agent B)

**You are Agent B.** Your job is the webhook receiver, the live-state endpoint, the admin plugin, wedding page templates, and the observability layer. You own the server side end-to-end. Read §1, §2, §4, §10. Then follow the checklist below in order.

Rules:

- **Do not skip checkboxes.**
- **Do not advance to the next phase until every box in the current phase is checked.**
- Any change to the contract in §4 must go through a CP-0 re-convene with Agent A.
- Branch per phase: `b/phase-B0`, `b/phase-B1`, etc.

### Phase B0 — Emergency security triage (must ship within 48 hours)

This entire phase is a single hotfix PR. Ship it before the next weekend's weddings. None of it touches player code — it's all server-side.

- [ ] **B0.1** Remove webhook signature bypass (fixes **B8**)
  - In `celebration-child/endpoints/cf-live-webhook.php:36–39`, replace the `if (!$secret) { return true; }` branch with a 403 + critical log.
  - In `celebration-child/functions.php:664–674`, same fix. Delete the shared helper entirely if nothing calls it (grep before deleting).
- [ ] **B0.2** Remove CloudFlareEP default pass-through (fixes **B11**)
  - Delete lines 127–141 of `celebration-child/endpoints/CloudFlareEP.php`. After the `live_state` branch returns, the file 400s on any other action.
- [ ] **B0.3** Add capability check to CloudFlareEP
  - After the nonce validation (line 22), add `if (!current_user_can('manage_options')) { http_response_code(403); echo json_encode(['error' => 'insufficient permissions']); exit; }`.
- [ ] **B0.4** Nonce + capability on every HSCF AJAX handler (fixes **B9**)
  - Grep for `add_action\('wp_ajax_` in `HitchStream_Cloudflare/HitchStream-Cloudflare.php`. For each handler:
    - First three lines: `check_ajax_referer('hscf_admin', '_hscf_nonce');` then `if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }`.
  - Remove `wp_ajax_nopriv_*` variants for anything that should be admin-only (most should).
  - Update `hscf-admin.js` to include the nonce on every AJAX body. Emit the nonce via `wp_localize_script`.
- [ ] **B0.5** Remove hardcoded streamer API key (fixes **B10**)
  - Add WP options `HSCF_streamer_api_url` (default `https://streamer1.hitchstream.com`) and `HSCF_streamer_api_key` (no default; starts empty).
  - Add a helper `HSCF_streamer_credentials()` in the plugin that reads both options and returns them. Use it in all four affected handlers (`HSCF-Cloudflare.php:786, 819, 857, 887`). Do not reference the hardcoded string anywhere.
  - Extend the plugin's admin settings page with a "Streamer Service" section: URL field (text) and API Key field (password, masked when set).
  - **Coordinate with the business:** rotate the key on the Node.js side in the same change window. Update the WP option to the new value.
  - If this repo has a remote (GitHub, etc.), also purge the old key from git history with `git filter-repo --replace-text` and force-push.
- [ ] **B0.6** Remove `console.log` of RTMP key in `hscf-admin.js`.
- [ ] **B0.7** Delete `disable_all_deprecated_warnings` filter bundle in `functions.php:852–860` (**B26**).
- [ ] **B0.8** Ship as a single PR. Have a human security-review it. Merge. Deploy to production that day.
- [ ] **B0.9** Sign off on §4 at CP-0 joint session with Agent A.

**GATE:** All nine boxes checked. Only then: begin B1.

### Phase B1 — Webhook correctness

Goal: make the webhook path actually deliver playable state. Fixes **B1** and the unknown in **B23**.

- [ ] **B1.1** Empirical verification of webhook signature format (**B23**)
  - Create a test webhook in the Cloudflare dashboard pointing at an ngrok URL that runs `printenv && cat > /tmp/hs-webhook-body`.
  - Trigger "Send test notification" from the dashboard.
  - Record exactly: the header name Cloudflare sends, the signature format (plain HMAC over body, vs. `t=<ts>,v1=<hex>` over `<ts>.<body>`), whether there's a replay-protection timestamp.
  - Write findings into `docs/cloudflare-webhook-format.md` with the raw captured header and body as evidence.
- [ ] **B1.2** Implement signature verification to match the empirical format (**B8** complete fix)
  - Create `src/HS/Webhook/Verifier.php` class. Method `verify(string $header, string $body, string $secret, int $maxAgeSeconds): bool`.
  - If the real format is timestamped: parse `t=…,v1=…`, reject if `abs(time() - t) > maxAgeSeconds`, HMAC over `"{$t}.{$body}"`, `hash_equals`.
  - If the real format is plain: HMAC over `$body` directly, still `hash_equals`.
  - Unit tests covering valid / invalid / expired / malformed cases.
- [ ] **B1.3** Populate `videoUID` at webhook-receive time (fixes **B1** — the single biggest server-side bug)
  - In the webhook handler, on `live_input.connected` or `live_input.reconnected` events, immediately call `GET https://customer-<customerCode>.cloudflarestream.com/<input_id>/lifecycle`. This endpoint is unauth'd (documented in `CloudFlare Docs/Watch a Live Stream.md`). Response: `{isInput, videoUID, live}`.
  - Store both `state` and `videoUID` in the transient atomically.
  - **B1.3a — On `/lifecycle` failure (network error, 5xx, malformed response): do NOT update the transient.** Log the failure with the input ID and event type, return 200 to Cloudflare so it doesn't retry pointlessly. Writing `state="live"` with `videoUID=""` violates §4.1 (live state requires populated videoUID) and is worse than serving stale-but-valid prior state. The next webhook will repair, or the next viewer cache miss on the read path will re-attempt `/lifecycle`.
  - On `live_input.disconnected`: explicitly write the transient with `state="idle"`, `videoUID=null`, `hlsUrl=null`. The previous "connected" transient still holds the old UID — if you don't null it explicitly, the read path serves stale data.
  - Unit tests: (a) given a mocked `/lifecycle` 200 response, handler writes a contract-valid transient; (b) given a mocked `/lifecycle` 500, handler does NOT write the transient and prior value is preserved; (c) given a `disconnected` event, handler writes `videoUID=null`.
- [ ] **B1.4** Idempotency and coalesced debounce
  - Record the last-seen `data.updated_at + event_type` per input in a 60 s transient. Drop duplicates.
  - Replace the current drop-on-second-event debounce with coalesce: the newest event within a 3 s window wins (not the first).
  - **B1.4a — `ts` field on coalesced responses must reflect the original probe write time, not `time()` at the moment of the coalesced read.** Carry the original `ts` through the single-flight lock value. Otherwise the player cannot detect stale data through the freshness window the contract implies.
- [ ] **B1.5** Custom webhook log table
  - Create `{$wpdb->prefix}hs_webhook_log` with columns: `id`, `received_at` (UTC), `input_id`, `event_type`, `raw_body_hash`, `normalized_state`, `error_code`, `signature_ok`, `processed`, `correlation_id`.
  - Every webhook receipt writes one row, signature-failed or not.
  - Weekly wp-cron trims rows older than 30 days.
- [ ] **B1.6** Admin notice when `HSCF_webhook_secret` is empty. Loud. Red. Links to the settings page.
- [ ] **B1.7** Staging test: trigger a webhook manually via curl with a known-good signature. Confirm: transient has state=live AND videoUID populated; log row written; signature_ok=true. Repeat with a tampered signature: 403, log row with signature_ok=false.

**GATE:** All seven boxes. Signed `docs/cloudflare-webhook-format.md` committed. Only then: B2.

### Phase B2 — Live-state endpoint rebuild

Goal: make polling cheap. Fixes **B6** server-side and **B24**, **B25**.

- [ ] **B2.1** Register a WP REST API route `hitchstream/v1/live-state` (replaces `endpoints/live-state.php`)
  - Use `register_rest_route()` with `methods: GET`, `permission_callback: __return_true`.
  - Keep `endpoints/live-state.php` in place as a thin `wp_safe_redirect(rest_url('hitchstream/v1/live-state') . '?inputId=…')` so any existing bookmarks / monitors don't 404.
- [ ] **B2.2** Lightweight path (fixes **B24**)
  - The REST route handler must NOT do a full WP bootstrap per request — WP REST handlers already run after bootstrap, which is fine, but the handler itself must be a pure transient read with no plugin hooks firing.
  - If latency profile shows p50 > 5 ms, fall back to a dedicated non-WP PHP file that reads the transient value directly from `wp_options` with a single prepared query, bypassing WordPress entirely. Only do this if the REST route's measured p50 is too high.
  - Have the webhook handler (B1.3) write a flat JSON file `{wp-content}/hs-state/{inputId}.json` in addition to the transient. The lightweight endpoint reads the flat file with `readfile()` — zero DB hits. This is the fastest, cleanest path; use it unless something prevents it.
  - **B2.2a — Flat-file writes MUST be atomic.** Write to `<wp-content>/hs-state/<inputId>.json.tmp` then `rename()` to the final path. POSIX `rename()` is atomic on the same filesystem. Without this, a concurrent reader can hit a partially-written file mid-write and parse-fail. Same applies to any other flat-file the webhook handler maintains.
- [ ] **B2.3** Response shape matches §4.1 exactly. Includes `ETag` header and respects `If-None-Match` → 304.
- [ ] **B2.4** Single-flight lock on cache miss
  - Use a short `hs_probe_lock_{inputId}` transient (TTL 5 s) with `add_option` or `wp_cache_add` semantics. Before calling `/lifecycle` on a miss, acquire the lock. If the lock is held, return the previous state with `source: 'coalesced'` and no API call.
- [ ] **B2.5** Switch fallback probe from `/videos` to `/lifecycle` (fixes **B25**)
  - The fallback (when no transient and no flat file) calls `/lifecycle`. No account auth needed.
  - Keep the `/videos` call only for the recording-check endpoint (B5.2).
- [ ] **B2.6** Response carries `X-HS-Correlation-Id` header.
- [ ] **B2.7** Load test: 50 concurrent viewers polling every 10 s for 1 hour against staging. Measure p50, p95, p99 response time; CPU usage on the droplet. p95 must be < 50 ms; CPU impact must be < 5% of one core.
- [ ] **B2.8** Against the Agent A mock, plus direct curl, confirm contract §4.1 compliance: all four `state` values possible; `videoUID` and `hlsUrl` both populated when `state=live`; `errorCode` populated when `state=error`; `source` field always present.

**GATE:** All eight boxes. Load test numbers documented in `docs/load-test-results.md`. Only then: B3.

### Phase B3 — Cloudflare API client + option plumbing

Goal: replace legacy Global API Key auth with scoped Bearer tokens; consolidate API calls.

- [ ] **B3.1** Create `src/HS/CloudflareClient.php` class
  - Single source of truth for Cloudflare API calls.
  - Uses `Authorization: Bearer <token>` from `HSCF_cloudflare_api_token` option.
  - Methods: `lifecycle($inputId)`, `listVideos($inputId)`, `getLiveInput($inputId)`, `createLiveInput($params)`, `deleteLiveInput($inputId)`, `registerWebhook($url)`, `deleteWebhook()`, `getWebhook()`.
  - 10 s connect, 15 s total timeout. 3 retries on 5xx / network error with 250 ms / 750 ms / 2 s backoff.
  - Every call logs via `HS\Log`: method, URL (account ID redacted), duration, HTTP status, correlation ID.
- [ ] **B3.2** Migrate all existing `curl_init` / `wp_remote_*` calls in `functions.php` and `HitchStream-Cloudflare.php` to go through `CloudflareClient`.
- [ ] **B3.3** Dual-auth fallback
  - If `HSCF_cloudflare_api_token` is set, use Bearer.
  - Else fall back to `HSCF_cloudflare_email` + `HSCF_cloudflare_api_key`, emit a `_deprecated_argument` notice (outside the filter bundle you removed in B0.7 — use `trigger_error` directly).
  - Remove the email/key fallback path in a future release after migration is complete.
- [ ] **B3.4** Create `src/HS/Config.php` — typed accessor class for every WP option this plugin uses. Listed in §4.6.
  - Throws `HS\ConfigError` if a required option is missing. No silent fallback to `juu1r5es4cbffqjf`.
  - Admin settings page (updated in B4) shows red for unset required values.
- [ ] **B3.5** Settings page extension
  - Existing Cloudflare credentials section gets a new field for `HSCF_cloudflare_api_token`.
  - Add "Streamer Service" section (URL + API key, from B0.5).
  - Add "Player Defaults" section (initial, idle, fatal poster URLs).
  - Add "Alerts" section (alert email).
  - `[Test]` button on Cloudflare section: hits `/lifecycle` for a sentinel input ID and shows green/red inline.
  - `[Test]` button on Streamer section: hits `/stream-state` and shows green/red inline.
  - `[Rotate]` button on webhook secret: calls `CloudflareClient::registerWebhook()` with a freshly-generated secret.
- [ ] **B3.6** Document every option in `docs/configuration.md`: purpose, default, whether required.

**GATE:** All six boxes. Admin settings page screenshots in `docs/configuration.md`. Only then: B4.

### Phase B4 — Plugin refactor

Goal: split the 1369-line monolith into classes. Remove dead code.

- [ ] **B4.1** Create `HitchStream_Cloudflare/src/**` directory structure per §4.1 of the earlier plan draft (Plugin, Admin/*, Services/*, Log/*).
- [ ] **B4.2** Migrate AJAX handlers into `Admin/AjaxController.php` — single dispatcher. Every action is validated against an allowlist. Each action wrapped with nonce + capability check (kept from B0.4).
- [ ] **B4.3** Extract live-input CRUD into `Services/LiveInputService.php` wrapping `CloudflareClient`.
- [ ] **B4.4** Extract webhook management into `Services/WebhookService.php` wrapping `CloudflareClient`.
- [ ] **B4.5** Extract placeholder-stream ops into `Services/StreamerService.php` wrapping `Config::streamerApiUrl()` + `Config::streamerApiKey()`. The four hardcoded-key handlers from B0.5 now all route through this.
- [ ] **B4.6** Extract recordings list/download into `Services/RecordingsService.php`.
- [ ] **B4.7** Add RTMPS URL allowlist in `StreamerService::startStream()` — only allow `*.cloudflare.com` RTMPS destinations. Prevents authenticated attackers from redirecting the rig.
- [ ] **B4.8** Delete dead code:
  - `hs_unregister_cf_webhook` (deprecated in `functions.php`)
  - `fetch_current_video_uid` + `ajax_fetch_current_video_uid` (unauthenticated AJAX endpoint that duplicates live-state)
  - Any tus-js-client references that aren't actually used
  - `celebration-child/js/old/cloudflare_player.js`
  - `celebration-child/endpoints/cloudflare_debug.log` (and add to `.gitignore`)
- [ ] **B4.9** `admin.js` cleanup: AJAX responses re-render affected sections without full page reload; nonce always included; no `console.log` of sensitive values.
- [ ] **B4.10** Backward-compat shims: keep old procedural function names (`hs_register_cf_webhook`, `hs_compute_server_live_state`, etc.) as thin delegates for one release.
  - **B4.10a — Shims MUST validate their return value against the §4 contract shape before returning.** If a legacy code path produces contract-violating data (e.g., `state="live"` with `videoUID=null`, or a `source` value not in `{webhook, probe, coalesced}`), the shim logs a `_doing_it_wrong` notice and returns either a sanitized response or null. Do not silently propagate malformed data — that defeats the purpose of having a contract.

**GATE:** All 10 boxes. Plugin smoke test: create a live input, register webhook, verify state, delete input — all via the admin UI. No console errors. Only then: B5.

### Phase B5 — Wedding templates + observability

- [ ] **B5.1** Create `celebration-child/inc/render-player-embed.php` shared partial (per §5.1 of earlier plan draft). Single source of truth for the player iframe HTML.
- [ ] **B5.2** Migrate all 12 wedding templates to call the shared partial.
- [ ] **B5.3** Replace `$$variable = $value` content-parsing pattern (`Event Generic v2.php:602–603` and any siblings) with explicit `get_post_meta()` reads. Keep a fallback path that reads the old format and logs a deprecation warning when used, so legacy events still work while they're migrated.
- [ ] **B5.4** Add `Content-Security-Policy` header to the player page (`HitchStream-Player.php`). Allow Cloudflare Stream origins only. Self-host Hls.js under `celebration-child/js/vendor/hls-<version>.min.js` rather than CDN.
- [ ] **B5.5** Create admin activity page (`Tools → HitchStream Activity`). Renders last 200 rows of the webhook log table with filter-by-inputId. Two-click answer to "what happened at Wedding X at 3:42pm?"
- [ ] **B5.6** Critical error email alerts: when a webhook arrives with an `error_code` in the configured alert set, send an email to `HSCF_alert_email`.
  - **B5.6a — The alert-code set is read from a new WP option `HSCF_alert_codes`** (comma-separated string, default `"ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION"`). Same default codes as today, but configurable so future Cloudflare error codes can be added or removed without a code deploy. Document in the settings page UI which codes are currently configured. The two default codes are also the two codes carried in `HSPlayerConfig.errorMessages` — keep these aligned by reading both lists from the same option in B3.5's settings page render.
- [ ] **B5.7** (Optional, if A5.4 goes ahead) Build `POST /wp-json/hitchstream/v1/admin/recording-check?inputId=…` endpoint. Returns `{ready, videoUID, hlsUrl}`. Coordinate with Agent A on whether this is needed.
- [ ] **B5.8** Runbook: `docs/runbook.md` with:
  - How to trigger a test webhook from Cloudflare dashboard
  - How to rotate the webhook secret
  - How to rotate the Cloudflare API token
  - How to read the activity log
  - Every `error_code` → action mapping
  - Day-of-wedding 5-bullet pre-flight checklist

**GATE:** All eight boxes. Admin activity page shows real webhook events for a test input ID. Only then: CP-3.

---

## 7. Integration checkpoints

Both agents stop feature work at each CP and validate together.

### CP-0 — Contract freeze ✅ SIGNED 2026-04-24
Both agents confirmed §4 in their progress files. Five amendments folded into §6 (B1.3a, B1.4a, B2.2a, B4.10a, B5.6a) per Agent A's review of B's workstream. Contract is frozen. A1 and B1 may proceed in parallel.

Before A1 / B1 start. One joint session. Both agents:
- Have read and understood §1, §2, §4, §10.
- Agree on every field name, type, and semantic in §4.
- Sign off by committing `docs/contract.md` with both agents listed as reviewers.

### CP-1 — First end-to-end roundtrip
After B1 + A3. Both agents:
- Deploy B's webhook + live-state endpoint to staging.
- Run A's player (module-split, with real engines) against B's real endpoint.
- Trigger a live stream from a real Cloudflare test input.
- Verify: webhook fires → transient populated with videoUID → player polls → enters PREPARING → plays.
- **If any player-side behavior diverges from the contract, fix the contract, not the code.**

### CP-2 — Full state machine happy path
After A4 + B2. Staging. Run every scenario in A1.16 against real Cloudflare. Both agents watch the activity log and debug panel simultaneously.

### CP-3 — All edge cases green
After A6 + B5. The 20 integration tests in A6.2 run against staging (not just mocks). iPhone/native-HLS verified on a real device. Reconnecting, videoUID handover, streamer idle + resume all work.

### CP-4 — Shadow-mode production run
One real low-stakes wedding (elopement, small ceremony, internal test stream). Agents A and B both on-call. Staff member watches the debug panel. Activity log reviewed post-event. Success = zero manual intervention, zero viewer complaints.

### CP-5 — Staged rollout
Flip 10% of weddings to v2 via a per-event post-meta flag. Run for 2 weeks. Review activity log for every stream. If clean: flip default to v2. v1 code stays for one more release as fallback.

---

## 8. Rollout plan

### Feature flag
WP option `hs_use_player_v2` (boolean, default false during staged rollout, flipped to true at the end of CP-5). The flag lives in the post-meta of each wedding page, not globally, so a single-option rollback is possible.

### Rollback
`wp option update hs_use_player_v2 0` (or equivalent for post-meta). Documented in the runbook (B5.8).

### Day-of-wedding pre-flight (5 minutes)
From the runbook:
1. Open Tools → HitchStream Activity. Filter by this event's input ID. Confirm a recent webhook or no webhooks yet.
2. Have the streamer push a 30-second test. Confirm a `connected` webhook arrives within 60 seconds.
3. Open the wedding page in a private browser. Confirm the player loads, the iframe shows the initial poster + play button, no console errors.
4. Click play. Confirm prebuffer → PLAYING within 15 seconds of the test stream being live.
5. Have streamer stop the test. Confirm the buffer drains and the idle poster appears.

If any of these fail, flip the feature flag off for this event and use v1.

---

## 9. CI / lint gates

All gates must pass in CI before any merge to main. Enforced as GitHub Actions (or equivalent).

- **No bare catches:** `grep -rn 'catch\s*(\s*_\s*)' celebration-child/ HitchStream_Cloudflare/` returns empty.
- **No magic state strings:** `grep -rnE "'(IDLE|PREPARING|PLAYING|FATAL)'" celebration-child/js/HSPlayer/` returns empty outside `constants.js`.
- **No hardcoded secrets:** `grep -rnE "(api[_-]?key|secret|token)\s*=\s*['\"][a-z0-9]{16,}" celebration-child/ HitchStream_Cloudflare/` returns empty outside `docs/` and `*.test.*`.
- **No hardcoded customer code:** `grep -rn "juu1r5es4cbffqjf" celebration-child/ HitchStream_Cloudflare/` returns empty outside `docs/` and `*.test.*`.
- **No legacy Cloudflare auth:** `grep -rn "X-Auth-Key\|X-Auth-Email" celebration-child/ HitchStream_Cloudflare/` returns empty after B3 is complete.
- **ESLint:** `no-dupe-keys`, `no-empty`, `no-unused-vars` all error-level in player JS.
- **Unit tests green.**
- **Integration tests green** (Chromium + WebKit + Firefox).

---

## 10. Preserved behaviors (DO NOT change these)

Listed explicitly so no refactor accidentally deletes a hard-earned wedding-specific decision.

1. **Pre-click UX is silent.** Only the poster and play button. No status text, no spinner, no animation. Until the viewer clicks, the player is indistinguishable from a static image.
2. **Idle poster is deliberately vague.** The logo-only idle poster works for every cause of a stream cut — deliberate, accidental, terminal. The player must never display text claiming to explain a cut.
3. **Two-poster system keyed on `hasPlayedOnce`.** Initial poster (custom per wedding) before first play; idle poster (vague logo) after. The existing transient "Paused/Ended" status message that fades after 3 seconds on post-playback idle transition is kept.
4. **Debug panel.** Top-right, `?debug=1`. Preserved and extended, never removed.
5. **Conservative Hls.js tuning.** `lowLatencyMode: false`, `liveSyncDuration: 20`, `maxBufferLength: 90`, `liveMaxLatencyDuration: 60`, etc. These were tuned for wedding streaming (smoothness > low-latency) and are correct for the product. Do not change without a measured reason.
6. **Prebuffer gate with 20th-percentile throughput estimation.** The exact right instinct for weak cellular at outdoor venues. Keep the math; refactor the layout.
7. **Fatal-timer reset on buffer-progress.** Prevents slow-but-progressing networks from false-fataling.
8. **CORS pre-probe before `hls.loadSource`.** Avoids Hls.js's retry-storm when the HLS edge is briefly behind ingest at stream start.
9. **Muted-retry autoplay fallback.** Correct for Chrome/iOS policies.
10. **VOD mode is a separate, simpler code path.** Don't merge it with live. `live=false&inputId=<video_uid>` loads the specific video directly with no polling, no prebuffer gate, no state machine.
11. **Iframe embed model.** The player is always loaded as an iframe at 16:9. Never embedded inline. The 12 wedding templates pass params via the iframe URL.
12. **Polling interval ~10s throughout the event.** Including during PLAYING. The state can flap at any time (streamer moves, internet drops, deliberate cut between ceremony and reception). The player must keep asking. Load is trivial at your scale.
13. **Webhooks are the source of truth.** Polling is the transport that carries that truth to the browser. Do not replace polling with SSE/WebSocket/Workers unless scale dictates it (not at 20–50 concurrent viewers).

---

## Appendix A — Known-good preserved files

These don't need changes and should not be touched in v2:

- `CloudFlare Docs/**` — reference material
- `celebration/**` — parent theme, out of scope
- `celebration-child/borders/**`, `celebration-child/fonts/**`, `celebration-child/img/**` — assets
- `celebration-child/js/textscaler.js`, `jquery.quickfit.js` — supporting utilities, no known issues

## Appendix B — File-level change map

| File | Owner | Action |
|---|---|---|
| `celebration-child/js/HSPlayerElement.js` | A | Iteratively refactored in A1 then replaced by `HSPlayer/**` in A4. Delete at end of A4. |
| `celebration-child/js/HSPlayer/**` | A | New. ~18 modules. See §5.3. |
| `celebration-child/js/__tests__/**` | A | New. Unit and integration tests. |
| `celebration-child/HitchStream-Player.php` | A (coordinate w/ B on script tag) | Refactored: single `window.HSPlayerConfig` blob, module-script load, CSP header, use `$_GET` not `get_query_var`. |
| `celebration-child/endpoints/cf-live-webhook.php` | B | Replaced by REST route `hitchstream/v1/webhook`. Disk file kept as redirect shim one release. |
| `celebration-child/endpoints/live-state.php` | B | Replaced by REST route `hitchstream/v1/live-state` or a flat-file endpoint (B2.2). Disk file kept as redirect shim one release. |
| `celebration-child/endpoints/CloudFlareEP.php` | B | Replaced by REST route `hitchstream/v1/admin/*`. Default pass-through deleted in B0.2. |
| `celebration-child/functions.php` | B | Webhook helpers moved into `src/HS/**` classes; shims left as one-liners; `disable_all_deprecated_warnings` deleted; `fetch_current_video_uid` unauthenticated AJAX deleted. |
| `celebration-child/inc/render-player-embed.php` | B | New. Shared partial. |
| `celebration-child/*.php` (12 wedding templates) | B | Migrate iframe HTML to shared partial; migrate `$$variable` pattern to explicit meta reads. |
| `HitchStream_Cloudflare/HitchStream-Cloudflare.php` | B | Refactored into `src/**`. Hardcoded streamer API key removed. AJAX handlers nonce + capability-checked. |
| `HitchStream_Cloudflare/hscf-admin.js` | B | Nonces on every AJAX; no `console.log` of sensitive data; inline re-render instead of full page reload. |
| `HitchStream_Cloudflare/src/**` | B | New class structure. |
| `docs/contract.md` | Both | New. Frozen at CP-0. |
| `docs/cloudflare-webhook-format.md` | B | New. Empirical findings from B1.1. |
| `docs/configuration.md` | B | New. Option reference. |
| `docs/runbook.md` | B | New. Day-of-wedding pre-flight and error-code triage. |
| `docs/load-test-results.md` | B | New. B2.7 numbers. |

## Appendix C — Definition of "done"

The v2 rewrite ships when every one of the following is demonstrable:

1. Every box in §5 (Agent A checklist) is checked.
2. Every box in §6 (Agent B checklist) is checked.
3. Every CI gate in §9 passes on main.
4. Every CP in §7 signed off.
5. Three consecutive real weddings run on v2 with zero manual intervention, zero viewer complaints, and a clean activity log.
6. The runbook has been used once by a staff member other than the developers to answer a post-event question without needing a developer.

That last one is the real test. If the system can't be operated without the people who built it, it's not bulletproof.
