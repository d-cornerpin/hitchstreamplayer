# A3.7 — Module Extraction Staging Smoke Test Plan

**Branch:** `a/phase-A3`
**Goal:** Verify all A3 module extractions work correctly on staging.
**Deploy strategy:** End-of-project deploy (gates met via test plan).

---

## Test Scenarios

### SC-1: Polling via LivePoller — live input cold load

**Setup:** Live input, fresh page load, `?debug=1`

**Steps:**
1. Load page. Verify LivePoller starts within 1.5-4.5s (jittered delay).
2. Verify first poll response processed correctly.
3. Observe state transitions via debug panel.

**Verification:**
- Debug panel shows pollCount incrementing every 10s
- No duplicate polls (concurrency guard works)
- Status overlay shows correct states (IDLE→PREPARING→PLAYING)
- ETag stored and sent on subsequent polls
- 304 responses correctly handled (no state change, poll continues)

**Pass/Fail:** PASS if polling works correctly. FAIL if duplicate polls, missed 304 handling, or pollCount doesn't increment.

---

### SC-2: Polling — reconnecting state

**Setup:** Stream playing.

**Steps:**
1. While playing, server reports `state: reconnecting`.
2. Wait 30s.
3. Server reports `state: live` again.

**Verification:**
- LivePoller continues polling at normal interval during reconnecting
- Status overlay shows "reconnecting" during reconnecting
- Status overlay shows "live" after recovery
- No backoff triggered for reconnecting (not an error)

**Pass/Fail:** PASS if poll continues normally. FAIL if poll stops or backoff triggered.

---

### SC-3: Polling — error handling and backoff

**Setup:** Stream playing.

**Steps:**
1. Server returns HTTP errors (500) for 3 consecutive polls.
2. Observe exponential backoff behavior.
3. Server returns success.

**Verification:**
- After 3 consecutive errors, poll interval doubles (10s → 20s → 40s → 60s cap)
- Error logged to debug panel
- After recovery, poll interval resets to 10s
- State machine correctly handles error codes from poll response

**Pass/Fail:** PASS if backoff works correctly. FAIL if poll continues at 10s during errors or doesn't reset after recovery.

---

### SC-4: Prebuffer gate — correct timing

**Setup:** Live input, click play.

**Steps:**
1. Click play button.
2. Observe prebuffer gate decision-making in debug panel.
3. Verify buffer thresholds before playback starts.

**Verification:**
- Debug panel shows bufferAhead increasing
- Headroom computation visible in debug log
- Gate threshold adapts based on throughput samples
- Playback starts when `bufferAhead >= threshold` AND `bufferedSegments >= 3`
- Timeout forces start after 60s regardless of buffer

**Pass/Fail:** PASS if playback starts at correct buffer thresholds. FAIL if playback starts too early (choppy) or too late (unneed delay).

---

### SC-5: Engine selection — Hls.js vs native HLS

**Setup:** Test on multiple browsers/devices.

**Steps:**
1. Desktop Chrome: verify HlsEngine used.
2. Safari/macOS: verify engine selection.
3. iPhone < 17.1 (or MSE disabled): verify native HLS fallback.

**Verification:**
- Desktop: `engineKind: hls.js` in debug panel
- Safari (with MSE): `engineKind: hls.js` if Hls.js supported
- iPhone < 17.1: `engineKind: native-hls`, video plays without 45s fatal timer
- Engine interface consistent across implementations (loadSource, attachMedia, destroy, on, off, startLoad, stopLoad, recoverMediaError)

**Pass/Fail:** PASS if correct engine selected on each platform. FAIL if Hls.js used on non-MSE Safari (crash) or native HLS not used on iPhone < 17.1 (45s fatal).

---

### SC-6: Manifest probe — first-success path

**Setup:** Live input with slow-manifest server (first response 404, then 200).

**Steps:**
1. Click play.
2. Verify manifest probe loop starts.
3. Observe first successful probe triggering HLS load.

**Verification:**
- Probe starts after 5s initial delay (default)
- Probes every 1.5s with cache-busting
- First 200 response triggers `hls.loadSource()` and `hls.startLoad(-1)`
- Probe interval cleared after success
- Debug panel shows probeCount incrementing

**Pass/Fait:** PASS if manifest loads on first success. FAIL if probe continues past success or doesn't trigger loadSource.

---

### SC-7: Manifest probe — cap-reached path

**Setup:** Live input with manifest that always 404s (bad inputId).

**Steps:**
1. Click play.
2. Wait for probe cap (40 attempts × 1.5s = 60s).

**Verification:**
- Probe continues for exactly 40 attempts
- After cap hit, state transitions to FATAL
- Fatal poster displayed
- No infinite probe loop

**Pass/Fail:** PASS if probe stops at 40 and enters FATAL. FAIL if infinite probing.

---

### SC-8: Engine factory — seamless handover (videoUID change)

**Setup:** Stream playing on one input.

**Steps:**
1. While playing, server sends new videoUID.
2. Verify engine destruction and recreation.

**Verification:**
- Old engine destroyed (Hls.js destroyed, video paused)
- New engine created and attached to same video element
- `loadSource()` called on new engine with new hlsUrl
- Playback resumes with < 500ms black frame
- No state transition to IDLE (handover path)

**Pass/Fail:** PASS if seamless handover. FAIL if tear-down/rebuild cycle.

---

### SC-9: Engine error recovery — Hls.js

**Setup:** Stream playing with Hls.js.

**Steps:**
1. Trigger MEDIA_ERROR (simulate codec failure).
2. Observe recovery attempts.
3. Trigger repeat enough times to hit MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS.

**Verification:**
- MEDIA_ERROR: `hls.recoverMediaError()` called (1st attempt)
- MEDIA_ERROR again: `hls.recoverMediaError()` called (2nd attempt)
- MEDIA_ERROR third time: transitions to FATAL (3 attempts exhausted)
- NETWORK_ERROR with recoverable details: retry with 2s/5s backoff
- NETWORK_ERROR with non-recoverable details or after 2 retries: FATAL

**Pass/Fail:** PASS if recovery attempts match constants. FAIL if wrong number of retries or wrong backoff timing.

---

### SC-10: iPhone native HLS — end-to-end (B2 fix verified)

**Setup:** iPhone with iOS < 17.1 (e.g., iPhone 8 running iOS 16.7.x).

**Steps:**
1. Load staging URL with live input, `?debug=1`.
2. Click play button.

**Verification:**
- `engineKind: native-hls` in debug panel
- Video plays within 3s of click (no 45s fatal timer)
- Live stream plays correctly (audio + video)
- No Hls.js crash (no `Hls is not defined` error)
- Status overlay shows correct states
- Poster transitions: initial → live (when playing)

**Pass/Fail:** PASS if video plays immediately without fatal timer. FAIL if 45s wait, black screen, or crash.

---

### SC-11: Module boundaries — no cross-contamination

**Setup:** All modules loaded.

**Steps:**
1. Verify no module touches the DOM directly except HlsEngine and NativeHlsEngine.
2. Verify LivePoller only emits events (no DOM).
3. Verify PrebufferGate is pure function (no side effects).
4. Verify ManifestProbe returns Promise (no DOM).

**Verification:**
- LivePoller: only emits `onEvent()` calls
- PrebufferGate: only pure functions, no global state mutation
- ManifestProbe: only `fetch()` and Promise, no DOM
- HlsEngine: touches DOM (attachMedia) — expected
- NativeHlsEngine: touches DOM (video.src) — expected

**Pass/Fail:** PASS if modules respect boundaries. FAIL if LivePoller or PrebufferGate touches DOM.

---

## Post-Test Actions

After each test passes:
1. Document results in this file (append RESULTS section).
2. Advance to A4.

If any test fails:
1. Note the failure.
2. Fix on a/phase-A3.
3. Re-deploy and re-test.
