# A1.16 — Staging Smoke Test Plan

**Branch:** `a/phase-A1`
**PR:** [#2](https://github.com/d-cornerpin/hitchstreamplayer/pull/2)
**Goal:** Verify all 15 A1 fixes against real staging environment before advancing to A2.

---

## Equipment / Environment Needed

| Item | Why | How to obtain |
|------|-----|---------------|
| Staging WordPress install with child theme deployed from `a/phase-A1` | Run player tests | Deploy PR #2 to staging WP |
| A live Cloudflare Stream live input (active broadcast) on staging | Tests 1, 2, 4, 6, 7 | Use a rig or OBS pushing to the staging live input |
| An idle Cloudflare Stream live input (no broadcast) on staging | Tests 2, 5, 8 | Have the rig disconnected for the idle input |
| An iPhone with iOS < 17.1 (e.g., iPhone 8 running iOS 16.7.x) | Test 6 (B2 native HLS) | Borrow one from the team |
| Ability to programmatically flip staging server state to `reconnecting` | Test 4 | Ask Agent B to set transient state, or use `POST /admin/state` on mock if staging has one |
| A second input whose manifest URL 404s permanently (wrong UID) | Test 5 | Create a live input in staging admin, then manually corrupt its UID in the transient |
| Non-allowlisted origin URL (e.g., `https://evil.example.com/test.m3u8`) | Test 10 | Craft one manually or ask Agent B to return it via mock |

---

## Test Scenarios

### SC-1: Cold load on already-live input

**Setup:**
- Staging URL with `?inputId=<live-input-id>&live=true&autoplay=true&debug=1`
- Cloudflare reports state = `live` with populated `videoUID` and `hlsUrl`
- Browser: Chromium desktop

**Steps:**
1. Load page. Wait 5 s.
2. Verify pre-click state: only poster image + centered play button visible. No spinner, no "waiting", no status text.
3. Click play button.

**Expected viewer experience:**
- Pre-click: silent — poster + play button only.
- After click: status overlay (top-left) shows "preparing", then transitions to "live" once playback starts. Stream plays within ~3 s of click.
- Overlay clears (auto-hides) once playing.

**Debug-panel readout:**
- `state`: starts as `IDLE` (element state, not server state) → `PREPARING` after click → `PLAYING` when playing
- `pollCount`: increments every 10 s
- `source`: `webhook` or `probe`
- `live`: `true`
- `videoUID`: populated
- `engineKind`: `hls.js`
- `correlationId`: present (non-empty)
- `bufferAhead`: > 0 once playing

**Pass/Fail:** PASS if pre-click has zero status text, stream plays within 3 s, overlay clears, all debug fields populate. FAIL otherwise.

---

### SC-2: Cold load on idle input

**Setup:**
- Staging URL with `?inputId=<idle-input-id>&live=true&autoplay=true&debug=1`
- Cloudflare reports state = `idle` (no active broadcast)
- Browser: Chromium desktop

**Steps:**
1. Load page. Wait 10 s.
2. Verify pre-click state.
3. Click play button. Wait 20 s.

**Expected viewer experience:**
- Pre-click: only poster + play button. No status text whatsoever.
- After click: no abrupt cut or error. Status overlay shows "waiting" (non-cause-claiming). Poster remains the initial poster.
- No `_attemptAutoplay(video)` called on empty `<video>`.

**Debug-panel readout:**
- `state`: remains `IDLE`
- `videoUID`: `null`
- `hlsUrl`: `null`
- `errorCode`: `null`
- `pollCount`: increments every 10 s
- `source`: `webhook` (from transient)

**Pass/Fail:** PASS if no status text pre-click, `videoUID` and `hlsUrl` are null, poll continues at 10 s, status is "waiting" (not "error"). FAIL if any cause-claiming text appears.

---

### SC-3: Mid-event handover (ceremony → reception)

**Setup:**
- Page loaded, stream playing (SC-1 state).
- Viewer has clicked play; video is PLAYING.

**Steps:**
1. While playing, server sends a `live` event with a **new `videoUID`** (simulates streamer moving to reception on a new input).
2. Observe playback.

**Expected viewer experience:**
- Minimal-to-no black frame between handover.
- Stream resumes on the new `videoUID` without requiring a viewer click.
- `hasPlayedOnce` remains `true` (gesture persists across idle/live transitions).

**Debug-panel readout:**
- `videoUID`: updates to the new UID
- `hlsUrl`: updates to the new manifest URL
- `engineKind`: `hls.js`
- `correlationId`: new per-request value
- No state transition to `IDLE` or `FATAL` during handover

**Pass/Fail:** PASS if playback resumes with < 500 ms black frame, no click required. FAIL if player cuts to idle poster, shows error, or requires click.

---

### SC-4: Reconnecting flap (live → reconnecting → live)

**Setup:**
- Page loaded, stream playing (SC-1 state).

**Steps:**
1. While playing, server reports `state: reconnecting`. Wait 30 s.
2. Then server reports `state: live` again (streamer reconnected).

**Expected viewer experience:**
- Buffer drains but playback continues from buffer during reconnecting — no abrupt cut.
- If buffer depletes below 2 s, fatal timer should fire (B12 watchdog).
- When stream recovers, if buffer is exhausted: player shows idle poster then resumes when `live` returns (drain-to-idle behavior).
- Fatal timer must NOT fire prematurely (no false fatal during reconnecting with warm buffer).

**Debug-panel readout:**
- `state`: `PLAYING` during buffer drain, transitions to `IDLE` if buffer exhausted
- `source`: `webhook` (state from webhook)
- `live`: `false` during reconnecting, `true` after recovery

**Pass/Fail:** PASS if no false fatal, buffer drain behavior correct, playback resumes on recovery. FAIL if player fatals during reconnecting with sufficient buffer, or fails to resume on recovery.

---

### SC-5: Fatal — bad inputId / permanently-404ing manifest

**Setup:**
- Staging URL with `?inputId=<bad-input-id>&live=true&autoplay=true&debug=1`
- Cloudflare reports `live` with a UID whose manifest always 404s.

**Steps:**
1. Load page. Wait 65 s.

**Expected viewer experience:**
- Status overlay shows "fatal" / "error" message.
- Poster transitions to fatal poster (red/black).
- FATAL state reached within ~60 s (40 attempts x 1.5 s + startup delay).

**Debug-panel readout:**
- `state`: `FATAL`
- `errorCode`: populated (Cloudflare error code)
- `probeAttempts`: reaches 40
- `engineKind`: `hls.js`

**Pass/Fail:** PASS if FATAL reached within 65 s, fatal poster visible, no infinite spinner. FAIL if player remains in PREPARING past 65 s or shows incorrect poster.

---

### SC-6: iPhone native HLS (B2 verification)

**Setup:**
- iPhone with iOS < 17.1 (or Safari with MSE disabled via `about:config` equivalent).
- Staging URL with `?inputId=<live-input-id>&live=true&autoplay=true&debug=1`
- Server reports `live` with valid `videoUID` and `hlsUrl`.

**Steps:**
1. Load page.
2. Click play.

**Expected viewer experience:**
- Video plays immediately — no 45-s wait for fatal timer.
- Live stream plays correctly (audio + video).
- No `Hls.isSupported()` → no crash.

**Debug-panel readout:**
- `engineKind`: `native-hls`
- `state`: `PLAYING` (after user click + loadedmetadata)
- `correlationId`: present

**Pass/Fail:** PASS if playback starts without fatal timer firing and video plays. FAIL if player sits in PREPARING until fatal, crashes, or shows black screen.

---

### SC-7: Debug panel completeness

**Setup:**
- Staging URL with `?debug=1`
- Server reports `live` state.

**Steps:**
1. Load page. Wait 30 s. Click play.
2. Verify every debug-panel field populates and updates.

**Debug-panel fields (all must be visible):**

| Field | Expected content |
|-------|-----------------|
| `state` | `IDLE` / `PREPARING` / `PLAYING` / `FATAL` |
| `bufferAhead` | Seconds of buffer ahead (e.g., `4.2 s`) |
| `inProgress` | `true` during loading |
| `clicked` | `yes` after user gesture |
| `latency` | HLS latency (e.g., `3.1 s`) |
| `live` | `true` / `false` |
| `videoUID` | Populated string or `null` |
| `pollCount` | Incrementing integer |
| `errorCode` | `null` or error code string |
| `source` | `webhook` / `probe` / `coalesced` |
| `correlationId` | Per-request UUID |
| `engineKind` | `hls.js` or `native-hls` |
| `ringBufferTail` | Last 5 logged errors |

**Pass/Fail:** PASS if all fields present, non-empty (or `null` where appropriate), and `pollCount` increments every 10 s. FAIL if any field is missing, blank, or stale.

---

### SC-8: Page visibility (A1.13)

**Setup:**
- Page loaded, stream playing (SC-1 state).

**Steps:**
1. Switch browser tab away (hidden) for 35 s.
2. Switch back (visible).
3. Observe behavior in first 5 s after return.

**Expected viewer experience:**
- Playback resumes smoothly (no black frame or freeze).
- Poll fires within 1 s of tab return (immediate poll on visible).
- HLS re-syncs to live edge (calls `startLoad(-1)`).
- Status overlay shows correct state (no "paused" or "idle" text).

**Debug-panel readout:**
- `pollCount`: jumps (one extra poll for the immediate poll)
- `state`: `PLAYING`
- `bufferAhead`: may drop but recovers

**Pass/Fail:** PASS if poll fires immediately, playback smooth, no stale status text. FAIL if poll stacks, playback hangs, or status shows stale text.

---

### SC-9: Element disconnect/reconnect (A1.6)

**Setup:**
- Page loaded, element connected.

**Steps:**
1. Load page. Wait 30 s (several poll cycles).
2. Open browser console. Watch for errors.
3. Remove `<hs-video>` from DOM (via DevTools Elements panel).
4. Wait 5 s.
5. Dispatch `document.click` events via DevTools console (10 rapid clicks).
6. Re-insert `<hs-video>` element. Load fresh.

**Expected viewer experience:**
- No errors in console after element removal.
- No handler from the old instance fires when dispatching `document.click`.
- New instance works cleanly — no cross-talk with old instance state.

**Debug-panel readout:**
- All fields populate on new instance.
- `pollCount` starts at 0 (fresh instance).

**Pass/Fail:** PASS if no console errors after disconnect, no handlers fire on the old element, new instance works. FAIL if any handler fires after disconnect, or old instance leaks state.

---

### SC-10: Origin allowlist (A1.9 / B14)

**Setup:**
- Staging server modified to return `hlsUrl: "https://evil.example.com/fake.m3u8"` for a live poll response (or mock server).

**Steps:**
1. Load page with a live input that returns the evil `hlsUrl`.
2. Click play.

**Expected viewer experience:**
- Player does NOT call `loadSource` on the evil URL.
- Viewer sees idle poster or "waiting" — not a blank screen from a wrong origin.
- Debug panel logs the rejection.

**Debug-panel readout:**
- `hlsUrl` rejection logged
- `engineKind`: empty or `null` (no engine loaded)
- `state`: `IDLE` or `PREPARING` (not `PLAYING`)

**Pass/Fail:** PASS if evil URL rejected, debug panel shows rejection, no engine loaded. FAIL if player attempts to load from evil origin.

---

## Post-Test Actions

After each test passes:
1. Document results in this file (append `RESULTS` section with PASS/FAIL per scenario).
2. Get sign-off from a second human reviewer.
3. Then advance to A2 (state machine extraction).

If any test fails:
1. Note the failure in this file.
2. Fix the issue on a/phase-A1 (new commit).
3. Re-deploy and re-test.
4. Never advance to A2 with a failed test.
