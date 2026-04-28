# A5.5 — Seamless Mid-Event Handover + Reconnecting Watchdog Test Plan

**Branch:** `a/phase-A5`
**Goal:** Verify handover and reconnecting watchdog work correctly on staging.
**Deploy strategy:** End-of-project deploy (gates met via test plan).

---

## Prerequisite

1. Start mock server: `node celebration-child/js/__tests__/mock-server/`
2. Open test player page in Chrome pointed at mock server
3. Verify `?debug=1` in URL

---

## Test Scenarios

### SC-1: Wedding-shape handover — ceremony → cut → 10min idle → reception starts on new videoUID

**Setup:** Live input, `?debug=1`. Configure mock server to:
1. Start with `state: live, videoUID: "ceremony01"` — stream playing
2. After 30s of PLAYING, switch to `state: idle` (ceremony ends)
3. Wait 10min (player drains buffer)
4. Switch to `state: live, videoUID: "reception01"` (reception starts)

**Steps:**
1. Load page, click play.
2. Wait for first poll showing `ceremony01` as live.
3. Mock server sends `idle` (ceremony ends).
4. Wait for buffer drain (player stays in PLAYING, draining).
5. After buffer drains, mock sends `live` with `reception01`.
6. Observe handover behavior.

**Verification:**
- Debug panel shows `videoUID` changing from `ceremony01` to `reception01`
- `correlationId` is present and unique per poll request
- `engineKind` matches platform (hls.js or native-hls)
- Black frame between streams is < 500ms (ideally zero if warm buffer)
- Status shows: `live` → `paused` (drain) → `preparing` (handover) → `live` (reception)
- `ringBufferTail` shows `—` during handover (no errors)
- Audio cross-fade occurs (old engine destroyed after 2s)
- No state transition to FATAL during handover
- `pollCount` increments correctly through handover
- `source` field correctly reflects poll source
- `clicked` remains true (gesture persisted through entire cycle)

**Pass/Fail:** PASS if handover completes seamlessly with < 500ms black frame. FAIL if visible tear-down/rebuild, FATAL state, or videoUID not updated.

---

### SC-2: Reconnecting with thin buffer — mock returns reconnecting for 2 minutes during playback

**Setup:** Live input, `?debug=1`, stream already playing.

**Steps:**
1. Stream playing normally.
2. Mock server returns `state: reconnecting` for 120 consecutive polls (2 minutes).
3. Player has < 2s of buffer (simulate thin buffer).
4. After 2 minutes, mock returns `state: idle` (reconnection failed).

**Verification:**
- Status overlay shows "reconnecting" during reconnecting phase
- Watchdog starts within 1s of first reconnecting poll
- Watchdog checks buffer every 1s
- When buffer < 2.0s, fatal timer extends to 90s each tick
- After 90s of empty buffer, player transitions to FATAL
- FATAL poster displays, status shows "error"
- Watchdog stops on recovery (if mock returns `live` before 90s)
- Debug panel shows `error_code: —` (no error code for reconnecting)
- After reconnecting ends (idle), player properly transitions

**Pass/Fail:** PASS if watchdog prevents freeze-forever and fatals after 90s of buffer starvation. FAIL if player hangs indefinitely or fatals too early.

---

### SC-3: iPhone/WebKit native HLS — entire flow works on native engine path

**Setup:** iPhone-class device (iOS < 17.1 or Safari with MSE disabled), `?debug=1`.

**Steps:**
1. Load page, click play.
2. Verify native HLS engine used.
3. Mock server triggers videoUID change (ceremony → reception).
4. Observe handover behavior.

**Verification:**
- Debug panel shows `engineKind: native-hls`
- Video plays within 3s (no 45s fatal timer)
- `currentVideoUID` updates correctly during handover
- Handover uses `video.src = newUrl` + `video.load()` path (not dual-engine)
- No crashes or Hls.js errors
- Status transitions correct: live → preparing → live
- After handover, video continues playing (or shows appropriate status)
- Gesture persists through entire flow (pre-gesture suppression correct)
- Poster transitions correct: initial → idle (drain) → idle

**Pass/Fail:** PASS if native HLS path works end-to-end. FAIL if crashes, hangs, or wrong engineKind.

---

## Post-Test Actions

After all tests pass:
1. Document results in this file
2. Advance to A6
3. Add screenshot/recording to PR body

If any test fails:
1. Note the failure
2. Fix on `a/phase-A5`
3. Re-deploy and re-test
