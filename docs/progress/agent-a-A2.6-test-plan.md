# A2.6 — State Machine Staging Smoke Test Plan

**Branch:** `a/phase-A2`
**Goal:** Verify all A2 state machine changes against real staging environment.
**Deploy strategy:** End-of-project deploy (gates met via test plan).

---

## Test Scenarios

### SC-1: IDLE→PREPARING→PLAYING cold load

**Setup:** Live input, fresh page load, `?debug=1`

**Steps:**
1. Load page with no prior interaction.
2. Verify pre-click: only poster + play button (no status text).
3. Click play button.
4. Observe state transitions: IDLE→PREPARING→PLAYING.

**Verification:**
- Debug panel shows: `state: IDLE → PREPARING → PLAYING`
- Status overlay shows: (nothing) → "preparing" → clears when playing
- Poster remains initial poster until playing starts
- `engineKind: hls.js`
- HLS stream loads from server-provided `hlsUrl` (validated against allowlist)

**Pass/Fail:** PASS if all transitions occur correctly. FAIL if any state is skipped or wrong.

---

### SC-2: Live→Idle handoff (poll-driven teardown)

**Setup:** Stream playing (from SC-1), then server reports `state: idle`.

**Steps:**
1. Load page with live input, click play, verify PLAYING.
2. Stop stream on server (reports `state: idle`, `videoUID: null`, `hlsUrl: null`).
3. Wait for next poll cycle.

**Verification:**
- State transitions: PLAYING→IDLE (via state machine)
- Side effects executed: `destroyHls`, `setPoster(idle)`, `showStatus(paused)`
- HLS engine destroyed, video paused
- Poster changes to idle poster (hasPlayedOnce=true)
- Poll continues at 10s interval

**Pass/Fail:** PASS if all side effects execute correctly. FAIL if engine lingers, wrong poster, or poll stops.

---

### SC-3: Idle-while-playing drain-to-idle (A1.12)

**Setup:** Stream playing with >0.5s buffer ahead.

**Steps:**
1. Load page with live input, click play, verify PLAYING.
2. Stop stream on server (reports `state: idle`).
3. Wait for poll cycle.
4. Observe drain behavior before teardown.

**Verification:**
- State stays PLAYING (not IDLE) while buffer drains
- `drainToIdle` side effect fires (sets `_drainingToIdle=true`, shows "paused")
- After buffer drains: `_executeIdleTeardown()` fires via `ended` event
- Final poster: idle (hasPlayedOnce=true)

**Pass/Fail:** PASS if drain-to-idle behavior observed. FAIL if immediate teardown without drain.

---

### SC-4: Reconnecting recovery

**Setup:** Stream playing.

**Steps:**
1. Load page with live input, click play, verify PLAYING.
2. Server reports `state: reconnecting`.
3. Observe status (should show "reconnecting").
4. Server reports `state: live` again.

**Verification:**
- Reconnecting: status shows "reconnecting", state stays PLAYING
- Recovery: status shows "live", state stays PLAYING
- No state transition to IDLE during reconnecting (buffer sufficient)
- HLS stream continues from buffer

**Pass/Fail:** PASS if reconnecting is UI-only. FAIL if state changes or stream restarts.

---

### SC-5: videoUID handover (ceremony→reception)

**Setup:** Stream playing on one input.

**Steps:**
1. Load page with live input (ceremony), click play, verify PLAYING.
2. Server reports new `videoUID` (reception) with new `hlsUrl`.
3. Observe handover behavior.

**Verification:**
- State stays PLAYING (not IDLE→PREPARING cycle)
- `handover` side effect fires: old HLS destroyed, new HLS loaded
- `_currentVideoUID` updated to new UID
- Playback resumes with minimal black frame
- Gesture state preserved (hasPlayedOnce=true)

**Pass/Fail:** PASS if seamless handover. FAIL if teardown/rebuild cycle or gesture loss.

---

### SC-6: Error code handling (viewer-facing vs diagnostic)

**Setup:** Stream in PREPARING state.

**Steps:**
1. Load page with live input, click play.
2. Server reports `state: error` with `errorCode: ERR_MISSING_SUBSCRIPTION`.
3. Verify overlay shows "error" status.
4. Server reports `state: error` with `errorCode: ERR_GOP_OUT_OF_RANGE`.
5. Verify overlay shows "waiting" (not viewer-facing).

**Verification:**
- ERR_MISSING_SUBSCRIPTION: status shows "error" (viewer-facing)
- ERR_GOP_OUT_OF_RANGE: status shows "waiting" (diagnostic only)
- Debug panel logs both errors

**Pass/Fail:** PASS if only viewer-facing errors produce overlay error. FAIL if all errors show overlay.

---

### SC-7: FATAL terminal state absorption

**Setup:** Stream in PREPARING state.

**Steps:**
1. Load page with live input, click play.
2. Trigger fatal condition (e.g., 404 manifest via bad inputId).
3. Verify FATAL state reached.
4. Send any event (poll=live, poll=idle, clickPlay).
5. Verify no state transition out of FATAL.

**Verification:**
- State enters FATAL
- `startFatal` side effect executes (enterFatalState: destroy engine, pause video, show fatal poster, hide overlay)
- All subsequent events return FATAL (no transition)
- Fatal poster visible, no overlay

**Pass/Fail:** PASS if FATAL is truly terminal. FAIL if any event transitions out of FATAL.

---

### SC-8: prepareToPlay guard (no premature buffer)

**Setup:** Page loaded with live input, no click.

**Steps:**
1. Load page. Wait 30s (several poll cycles).
2. Verify no HLS engine loaded (preparing didn't start).
3. Click play button.
4. Verify state transitions: IDLE→PREPARING (via clickPlay+poll=live).

**Verification:**
- Pre-click: `engineKind` empty, no network requests to HLS manifest
- Post-click: PREPARING state, HLS load initiated
- `userGestureUnlocked` set after click, allows poll=live to trigger load

**Pass/Fail:** PASS if pre-click suppresses HLS load. FAIL if HLS loads before user gesture.

---

### SC-9: Buffer threshold boundary (0.5s drain decision)

**Setup:** Stream playing with controlled buffer levels.

**Steps:**
1. Load page with live input, click play, verify PLAYING.
2. Force buffer below 0.5s (e.g., throttle network).
3. Server reports `state: idle`.
4. Observe transition decision.

**Verification:**
- Buffer < 0.5s: transitions to IDLE (not drainToIdle)
- Side effects: `destroyHls`, `setPoster`, `showStatus`
- Buffer > 0.5s: stays PLAYING with `drainToIdle`

**Pass/Fail:** PASS if threshold decision is correct. FAIL if wrong drain decision.

---

### SC-10: State machine as single source of truth

**Setup:** All possible state + event combinations.

**Steps:**
1. Exercise all 40 unit test scenarios against staging.
2. Compare observed behavior with unit test expectations.
3. Verify no divergence between unit test and staging behavior.

**Verification:**
- All 44 unit tests pass on mock server
- Staging behavior matches unit test expectations for all exercised paths
- No additional state transitions on staging that unit tests don't cover

**Pass/Fail:** PASS if all unit tests match staging behavior. FAIL if any divergence.

---

## Post-Test Actions

After each test passes:
1. Document results in this file (append RESULTS section).
2. Advance to A3.

If any test fails:
1. Note the failure.
2. Fix on a/phase-A2.
3. Re-deploy and re-test.
