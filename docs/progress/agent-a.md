# Agent A Progress — HitchStream Player v2

**Current phase:** A0 — Preparation
**What I'm actively working on:** A0.6 — CP-0 sign-off with Agent B
**Last updated:** 2026-04-24T10:35:00Z
**Open questions:** See CP-0 section below

---

## CP-0 Status

**§4 has been amended** with CP-0 clarifications (committed to main):
- `hlsUrl` and `videoUID` are `null` when state is `idle` or `error`
- 304 handling: player does not advance state machine
- `errorCode` is server pass-through; player shows text only for codes in `errorMessages` config
- `X-HS-Correlation-Id` is per-request, not echoed back
- `source` describes HOW state was determined, never "no_data"
- Unknown error codes fall through to idle UX

**Agent A status:** ALL A0 items complete. Ready for A1.
**Pending:** Joint CP-0 sign-off with Agent B before A1 starts.

---

## A0 — Preparation

- [x] **A0.1** Read §1, §2, §4, §10 of the plan. Read Cloudflare docs.
  **Status:** DONE — Read all sections and Cloudflare docs (Watch a Live Stream.md, Receive Live Webhooks.md)
- [x] **A0.2** Map every function in current HSPlayerElement.js to target modules in §5.3
  **Status:** DONE — comprehensive mapping of ~40 methods across 15 modules (see mapping table below)
- [x] **A0.3** Read HitchStream-Player.php, map config flow
  **Status:** DONE — all PHP→JS data paths identified with v2 mechanism
- [x] **A0.4** Stand up mock contract server
  **Status:** DONE — Express server at `__tests__/mock-server/` with:
  - GET /live-state with ETag/304, X-HS-Correlation-Id, programmable state via POST /admin/state
  - Mock HLS manifest + TS segments
  - Contract enforcement (idle/error → null hlsUrl/videoUID)
- [x] **A0.5** Reproduce B2 bug with Playwright
  **Status:** DONE — test at `B2-repro.spec.js` confirms player does NOT reach PLAYING when Hls.isSupported()=false (bug confirmed)
- [ ] **A0.6** Sign off on §4 at CP-0 joint session with Agent B
  **Status:** PENDING — awaiting Agent B joint session

## A1 — In-place critical fixes

- [ ] **A1.1** Fix B3 — Disambiguate `this.isLive`
- [ ] **A1.2** Fix B2 — Add native HLS fallback to live path
- [ ] **A1.3** Fix B4 — Single-fire guard on onClickPlayButton
- [ ] **A1.4** Fix B5 — Cap manifest probe attempts
- [ ] **A1.5** Fix B6 — AbortController + timeout + concurrency guard on polling
- [ ] **A1.6** Fix B7 — Clean up all listeners on disconnect
- [ ] **A1.7** Fix B15 — Deduplicate Hls.js config keys
- [ ] **A1.8** Fix B16 — Complete observedAttributes
- [ ] **A1.9** Fix B14 — Trust server hlsUrl + origin allowlist
- [ ] **A1.10** Fix B21 — Fail loud on missing config
- [ ] **A1.11** Fix B20 — Deduplicate idle teardown
- [ ] **A1.12** Idle-while-playing drains buffer
- [ ] **A1.13** Page visibility handling
- [ ] **A1.14** Overlay-hide race fallback timer
- [ ] **A1.15** Post-network-error recovery
- [ ] **A1.16** Staging smoke test

## A2 — State machine extraction

- [ ] **A2.1** Create constants.js
- [ ] **A2.2** Create PlayerStateMachine.js (pure)
- [ ] **A2.3** Create unit tests (min 30 cases)
- [ ] **A2.4** All unit tests pass
- [ ] **A2.5** Refactor HSVideoElement to call transition()
- [ ] **A2.6** Staging smoke test

## A3 — Extract polling, prebuffer, manifest probe, HLS engine

- [ ] **A3.1** Create LivePoller.js
- [ ] **A3.2** Create PrebufferGate.js
- [ ] **A3.3** Create ManifestProbe.js
- [ ] **A3.4** Create HlsEngine.js, NativeHlsEngine.js, EngineFactory.js
- [ ] **A3.5** Unit tests for PrebufferGate, LivePoller, ManifestProbe
- [ ] **A3.6** Refactor HSVideoElement to delegate to modules
- [ ] **A3.7** Staging smoke test + iPhone-class device

## A4 — Extract UI modules

- [ ] **A4.1** Create StatusOverlay.js
- [ ] **A4.2** Create DebugPanel.js
- [ ] **A4.3** Create PosterManager.js
- [ ] **A4.4** Create GestureUnlock.js
- [ ] **A4.5** Create UiController.js
- [ ] **A4.6** Create utils/safe.js, grep out bare catches
- [ ] **A4.7** Create utils/timers.js
- [ ] **A4.8** Refactor into HSVideoElement.js (<300 lines) + index.js
- [ ] **A4.9** Update HitchStream-Player.php
- [ ] **A4.10** Staging smoke test

## A5 — Seamless mid-event handover and resume

- [ ] **A5.1** Gesture persistence audit
- [ ] **A5.2** Handover between videoUIDs
- [ ] **A5.3** Reconnecting watchdog
- [ ] **A5.4** Recording auto-swap (optional)
- [ ] **A5.5** Staging smoke test

## A6 — Full integration test suite

- [ ] **A6.1** Create integration test directory
- [ ] **A6.2** Implement 20 integration tests
- [ ] **A6.3** All 20 tests pass on Chromium + WebKit + Firefox
- [ ] **A6.4** Real-device pass checklist
- [ ] **A6.5** CI gate: grep checks

---

## A0.2 — Function Mapping (current → target module)

### Constants & Module-Level State
| Current Location | Current Name | Lines | Target Module | Notes |
|---|---|---|---|---|
| File-scope | `POSTER_INITIAL_URL`, `POSTER_IDLE_URL`, `POSTER_FATAL_URL` | 13-15 | `constants.js` (defaults) → `PosterManager.js` (runtime) | Mutable `let` → B17 fix needed |
| File-scope | `CLOUDFLARE_CUSTOMER_ID` | 17 | `constants.js` (default) → `HSPlayerConfig.cloudflare.customerCode` | B17 fix |
| File-scope | `MIN_PREBUFFER_SECONDS`, `MIN_PREBUFFER_SEGMENTS`, `MIN_THROUGHPUT_SAMPLES`, `PREBUFFER_TIMEOUT_MS`, `MAX_THROUGHPUT_SAMPLES` | 19-23 | `constants.js` | Pure constants, no changes |
| File-scope | `AUDIO_DRIFT_FRAMES_THRESHOLD` | 29 | `constants.js` | Pure constant |
| File-scope | `POLL_INITIAL_DELAY_MS`, `POLL_INTERVAL_MS`, `LATENCY_LOG_INTERVAL_MS`, `GATE_CHECK_INTERVAL_MS`, `MANIFEST_PROBE_INTERVAL_MS`, `INITIAL_MANIFEST_DELAY_MS` | 36-41 | `constants.js` | Pure constants |
| File-scope | `STATE` enum | 47-52 | `constants.js` | Pure enum export |
| File-scope | `FATAL_TIMEOUT_MS` | 63 | `constants.js` | Pure constant |
| File-scope | `MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS` | 69 | `constants.js` | Pure constant |
| File-scope | `_safe()` global helper | 5-10 | `utils/safe.js` | Replace with per-instance ring buffer |

### HSVideoElement Class — Core
| Method | Lines | Target Module | Notes |
|---|---|---|---|
| `constructor()` | 73-167 | `HSVideoElement.js` | Initialize all instance state; debug mode check stays here |
| `debugLog()` / `debugError()` | 170-171 | `HSVideoElement.js` | Small helpers, stay in-element |
| `static get observedAttributes()` | 174 | `HSVideoElement.js` → `PosterManager.js` | Needs fixing (B16) |
| `attributeChangedCallback()` | 175-178 | `HSVideoElement.js` → `PosterManager.js` | Needs fixing (B16) |
| `setApiInfo()` | 181-203 | `HSVideoElement.js` | **Major change** — will read from `HSPlayerConfig` instead of params |
| `loadVideoDirectly()` | 206-239 | `HlsEngine.js` + `NativeHlsEngine.js` + `EngineFactory.js` | VOD path uses engine abstraction |

### HSVideoElement — Live Path
| Method | Lines | Target Module | Notes |
|---|---|---|---|
| `startPolling()` | 242-382 | `LivePoller.js` + `HSVideoElement.js` | **Extract poll loop to LivePoller.js** (A1.5); element just calls start()/stop() |
| `stopPolling()` | 385-389 | `LivePoller.js` | |
| `managePlayerState()` | 393-443 | `PlayerStateMachine.js` + `HSVideoElement.js` | State machine computes transition; element dispatches side effects |
| `prepareToPlay()` | 446-457 | `HSVideoElement.js` + `EngineFactory.js` | Thin wrapper that calls engine.loadSource() |
| `loadStream()` | 460-658 | `HlsEngine.js` + `NativeHlsEngine.js` | **Biggest method** — Hls.js config, event handlers, manifest probe all move |
| `setPoster()` | 661-685 | `PosterManager.js` | Poster logic extracted |

### HSVideoElement — UI
| Method | Lines | Target Module | Notes |
|---|---|---|---|
| `connectedCallback()` | 688-860 | `UiController.js` + `HSVideoElement.js` | Shadow DOM/CSS → UiController; event wiring → GestureUnlock + HSVideoElement |
| `disconnectedCallback()` | 863-888 | `HSVideoElement.js` + `utils/timers.js` | Clean up via AbortController + TimerRegistry |
| `setApiInfo` (line 174) | 891-1018 | `PrebufferGate.js` + `HSVideoElement.js` | Pure math → PrebufferGate.js |

### HSVideoElement — Playback Helpers
| Method | Lines | Target Module | Notes |
|---|---|---|---|
| `tryStartPlayback()` | 891-1018 | `PrebufferGate.js` | Pure function → extracted; element calls `shouldStartPlayback()` |
| `_probeManifestAndStart()` | 1021-1045 | `ManifestProbe.js` | Extract entire probe loop |
| `_updateDebugPanel()` | 1048-1080 | `DebugPanel.js` | Extract entirely |

### HSVideoElement — Timer Management
| Method | Lines | Target Module | Notes |
|---|---|---|---|
| `startFatalTimer()` | 1099-1128 | `HSVideoElement.js` + `utils/timers.js` | Timer registry handles disposal |
| `clearFatalTimer()` | 1134-1145 | `HSVideoElement.js` + `utils/timers.js` | |
| `enterFatalState()` | 1153-1210 | `PlayerStateMachine.js` | Side effects from state machine |

### HSVideoElement — UI Helpers
| Method | Lines | Target Module | Notes |
|---|---|---|---|
| `_hideUi()` | 1213-1216 | `UiController.js` | |
| `_attemptAutoplay()` | 1222-1236 | `HSVideoElement.js` + `GestureUnlock.js` | Autoplay logic stays in element; gesture via GestureUnlock |
| `showAnimatedStatus()` | 1247-1264 | `StatusOverlay.js` | |
| `stopStatusAnimation()` | 1270-1275 | `StatusOverlay.js` | |
| `showStatusMessage()` | 1286-1311 | `StatusOverlay.js` | |
| `hideStatusMessage()` | 1316-1325 | `StatusOverlay.js` | |
| `updateStatus()` | 1335-1389 | `StatusOverlay.js` | |

### Config Flow (A0.3 — HitchStream-Player.php)
| Source | Flows Into | Today's Mechanism | v2 Mechanism |
|---|---|---|---|
| `live_state_url` (PHP) | `liveStateEndpoint` (global var) | Raw `<script>` global | `HSPlayerConfig.endpoints.liveState` |
| `serverIsLive` (PHP) | `serverIsLive` (global var) | Raw `<script>` global | `HSPlayerConfig.server.isLive` |
| `hsPlayerNonce` (PHP) | `hsPlayerNonce` (global var) | Raw `<script>` global | Not needed for player (no auth on poll) |
| `cf_customer_id` (WP option) | `playerDefaults.customerID` → JS `CLOUDFLARE_CUSTOMER_ID` | Raw `<script>` + mutable `let` | `HSPlayerConfig.cloudflare.customerCode` |
| `poster_initial/idle/fatal` (WP option) | `playerDefaults` → URL param override | Raw `<script>` + URL param | `HSPlayerConfig.posters.*` |
| URL params (`inputId`, `live`, `autoplay`, `customerID`, poster params) | `setApiInfo()` params | `URLSearchParams` in iframe | All via `HSPlayerConfig` (one global read) |
| `?debug=1` URL param | Constructor reads `window.location.search` | Direct read in constructor | `HSPlayerConfig.debug` |

### Contract Review Notes (CP-0 prep)
**§4 fields I need to flag as potentially ambiguous:**

1. **`source: "coalesced"`** (contract §4.1) — The contract says `source` can be `"webhook" | "probe" | "coalesced"`. Need to confirm: does the server always set `source`? What happens during the transition from probe → webhook as source of truth?

2. **`hlsUrl` null when idle** (contract §4.1) — The contract shows `hlsUrl` as `"string" | null`. When state is `idle`, should `hlsUrl` be `null` or the last known HLS URL? The current player behavior (A1.12) saves `latestLiveHlsUrl` across idle transitions.

3. **`errorCode` mapping** (contract §4.2) — The player currently has custom text for each error code in the poll callback. The contract only lists two that surface visually (`ERR_STORAGE_QUOTA_EXHAUSTED`, `ERR_MISSING_SUBSCRIPTION`). Need Agent B to confirm: do ALL error codes from Cloudflare get passed through, or only the ones in the contract?

4. **ETag / `If-None-Match`** (contract §4.1) — The contract says the server returns ETag. The player currently doesn't handle 304 responses. Should the player skip state updates on 304?

5. **`X-HS-Correlation-Id`** (contract §4.1) — Agent A includes it in debug panel. Need to confirm whether the player should also send it back as a request header on subsequent polls. **RESOLVED:** read-only, per-request, not echoed back.

6. **`server.isLive` default** — Contract says default is `false`. In the current PHP code, `hs_compute_server_live_state()` can return truthy. Need to confirm this maps 1:1. **RESOLVED:** `server.isLive` is a hint to the player (avoids first-poll delay if already live), not authoritative. The poll callback is the source of truth.

### B-side review findings

I reviewed §6 (Agent B's workstream) against §4 and §10. Here are the issues I spotted:

#### B1.3 — `live_input.connected` with failed `/lifecycle`
**Issue:** The plan says: if `/lifecycle` fails, store `state` with empty `videoUID`. But if the event is `live_input.connected`, the server would write `state: "live"` with `videoUID: null/""`. This violates the contract (§4.1: `live` must have `videoUID` populated).
**Recommendation:** If `/lifecycle` fails, **do not update the transient**. Log the error and wait for the next webhook. A transient may already hold the correct state. Writing `live` + `videoUID: null` is worse than keeping the old transient.

#### B2.2 — Flat file atomic writes
**Issue:** The plan recommends writing a flat JSON file per input for zero-DB reads. No mention of atomic writes (write to temp file then rename()). Without this, a polling endpoint could read a partially-written file.
**Recommendation:** Add atomic write requirement to B2.2.

#### B1.4 — Timestamp on coalesced state
**Issue:** When serving coalesced result, what should `ts` be? The plan doesn't specify.
**Clarification:** `ts` must reflect when the data was originally written, NOT the current time. Otherwise the player can't detect stale data.

#### B4.10 — Backward-compat shims could mask contract violations
**Issue:** The plan keeps `hs_compute_server_live_state()` as a thin delegate. If the old function returns data in the old format (e.g., `videoUID: null` while `state: "live"`), the delegate preserves that wrong data.
**Recommendation:** Shims should validate against §4 shape before delegating, and log a warning if data is malformed.

#### B5.6 — Error email hardcodes codes
**Issue:** B5.6 hardcodes `ERR_STORAGE_QUOTA_EXHAUSTED` and `ERR_MISSING_SUBSCRIPTION` for email alerts. The contract says these are in `errorMessages` config.
**Recommendation:** Read from config or document that these two are fixed and won't change.

---

### Mock fixtures (A0.4)

Fixture files in `docs/progress/agent-a-fixtures/` serve as a concrete spec for Agent B to validate their endpoint against during B2.8:

| Fixture | Scenario | Key contract point tested |
|---|---|---|
| `live.json` | Stream actively broadcasting | `videoUID` + `hlsUrl` populated, `source: "webhook"` |
| `reconnecting.json` | Brief disconnect, session held | Same `videoUID`/`hlsUrl` as preceding `live` |
| `idle.json` | No active broadcast | `videoUID` = null, `hlsUrl` = null (contract enforced) |
| `error-gop.json` | ERR_GOP_OUT_OF_RANGE | errorCode present, idle UX (not in errorMessages) |
| `error-quota.json` | ERR_STORAGE_QUOTA_EXHAUSTED | errorCode present, visible error (IS in errorMessages) |
| `handover-new-uid.json` | New videoUID (ceremony → reception) | Both `videoUID` and `hlsUrl` are NEW values |
| `304-response.json` | 304 Not Modified | No body, poll counter incremented, ETag stored |
| `coalesced.json` | Single-flight lock result | `source: "coalesced"`, `ts` from original probe time |
