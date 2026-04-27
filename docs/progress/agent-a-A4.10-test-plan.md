# A4.10 — UI Module Extraction + Safe() + TimerRegistry Staging Smoke Test Plan

**Branch:** `a/phase-A4`
**Goal:** Verify all A4 modules work correctly end-to-end against mock server, including new UI features, safe() utility, and TimerRegistry.
**Deploy strategy:** End-of-project deploy (gates met via test plan).

---

## E2E Prerequisites

1. Start mock server: `node celebration-child/js/__tests__/mock-server/`
2. Open test player page in Chrome pointed at mock server
3. Verify `?debug=1` in URL for debug panel

---

## Test Scenarios

### SC-1: Poster + play button render pre-click

**Setup:** Cold load, live input, `?debug=1`

**Steps:**
1. Load page.
2. Before clicking anything, observe UI.

**Verification:**
- Initial poster displays correctly
- Play button (white circle with ▶) visible centered on video area
- Status overlay shows "waiting" text
- Debug panel renders (shows state=IDLE, engineKind=—, ringBufferTail=—)
- No video playback (gesture not yet captured)
- No JavaScript errors in console

**Pass/Fail:** PASS if poster, play button, and status all visible pre-click. FAIL if video autoplay happens without gesture.

---

### SC-2: Click play → HLS loads via LivePoller → ManifestProbe → HlsEngine

**Setup:** Live input, click play button.

**Steps:**
1. Click the play button.
2. Observe state transitions and UI changes.

**Verification:**
- Play button disappears after click (gesture captured)
- Status transitions: waiting → preparing → live
- Debug panel shows:
  - `state: PLAYING` (or PREPARING during buffer phase)
  - `engineKind: hls.js` (or `native-hls` on Safari)
  - `correlationId` present (non-empty string from server)
  - `pollCount` incrementing every 10s
  - `live: true`
  - `videoUID` populated
  - `error_code: —` (none)
  - `source` populated (e.g., "webhook" or "coalesced")
  - `ringBufferTail: —` (empty until errors occur)
  - `prebuffer` and `In Progress` visible
  - `clicked` shows true after gesture
- Video plays after prebuffer gate satisfied (bufferAhead >= threshold)
- LivePoller polls every 10s (no duplicates, no race)

**Pass/Fail:** PASS if all 13 fields visible in debug panel, video plays after buffer, no errors. FAIL if any field missing, video doesn't play, or duplicate polls.

---

### SC-3: Status overlay appears post-click (pre-gesture suppression preserved)

**Setup:** Cold load, live input that starts live immediately.

**Steps:**
1. Load page (stream already live, no waiting).
2. Before clicking, verify status.
3. Click play button.
4. Observe status change.

**Verification:**
- **Pre-click:** Status overlay is NOT visible (pre-gesture suppression working)
- **Post-click:** Status shows "live" (or "preparing" briefly)
- Status text fades out after 3s (animated)
- If stream goes idle while not clicked, status does NOT appear (pre-gesture suppression)
- If stream goes idle after clicked, status shows "idle"

**Pass/Fail:** PASS if status text only appears after user gesture. FAIL if status shows text pre-click.

---

### SC-4: Reconnecting state

**Setup:** Stream playing, server reports `reconnecting`.

**Steps:**
1. While playing, server returns `state: reconnecting`.
2. Wait 30s.
3. Server returns `state: live` again.

**Verification:**
- Status overlay shows "reconnecting"
- No backoff triggered (reconnecting is not an error)
- Poll continues at normal 10s interval
- After recovery, status shows "live"
- No state transition to FATAL

**Pass/Fail:** PASS if reconnecting handled gracefully. FAIL if player enters FATAL or poll stops.

---

### SC-5: Error handling and backoff

**Setup:** Server returns errors.

**Steps:**
1. Server returns HTTP 500 for 3 consecutive polls.
2. Observe backoff behavior.
3. Server returns success.

**Verification:**
- After 3 errors, poll interval doubles (10s → 20s → 40s → 60s cap)
- Error code visible in debug panel
- After recovery, poll interval resets to 10s
- Backoff delay shown in debug log (`[hs-video] Poller backoff: XXXX ms`)

**Pass/Fail:** PASS if backoff works correctly. FAIL if poll continues at 10s during errors.

---

### SC-6: Prebuffer gate — correct timing

**Setup:** Live input, click play.

**Steps:**
1. Click play button.
2. Watch debug panel prebuffer values.

**Verification:**
- `prebuffer` shows buffer-in-progress value (seconds)
- `In Progress` shows true while buffer building
- `clicked` shows true (gesture captured)
- Headroom computation adapts threshold:
  - headroom >= 2.0 → threshold = max(10, 3×segDur)
  - headroom >= 1.5 → threshold = max(12, 3×segDur)
  - headroom >= 1.2 → threshold = max(15, 3×segDur)
  - headroom >= 1.0 → threshold = max(20, 3×segDur)
  - headroom < 1.0 → threshold = max(28, 3×segDur)
- Playback starts when `bufferAhead >= threshold` AND `bufferedSegments >= 3`
- Timeout forces start after PREBUFFER_TIMEOUT_MS (60s)

**Pass/Fail:** PASS if playback starts at correct thresholds. FAIL if starts too early (choppy) or too late.

---

### SC-7: Engine selection — Hls.js vs native HLS

**Setup:** Test on multiple browsers.

**Steps:**
1. Desktop Chrome: verify engine.
2. Safari (MSE disabled, e.g., iPhone < 17.1): verify engine.

**Verification:**
- Desktop: debug panel shows `engineKind: hls.js`
- Safari/no-MSE: debug panel shows `engineKind: native-hls`
- `engineKind` appears immediately (not delayed)
- Both engines produce playable video

**Pass/Fail:** PASS if correct engine selected. FAIL if wrong engine type.

---

### SC-8: Manifest probe — first-success path

**Setup:** Live input with slow-manifest server (first 2-3 probes 404, then 200).

**Steps:**
1. Click play.
2. Observe manifest probe loop.

**Verification:**
- First probe after 5s initial delay
- Probes every 1.5s with cache-busting (`_cb=` param)
- First 200 triggers `loadSource()` and `startLoad(-1)`
- Probe interval clears on success
- Debug panel shows progressive probeCount

**Pass/Fail:** PASS if manifest loads on first success. FAIL if probe continues after success.

---

### SC-9: Manifest probe — cap-reached path

**Setup:** Bad inputId that always returns 404 for manifest.

**Steps:**
1. Click play.
2. Wait for probe cap (40 attempts × 1.5s = ~60s).

**Verification:**
- Probe continues for exactly 40 attempts
- After cap, state transitions to FATAL
- Fatal poster displays
- Status shows "error"
- No infinite probe loop

**Pass/Fail:** PASS if FATAL entered at cap. FAIL if infinite probing.

---

### SC-10: Engine factory — seamless handover (videoUID change)

**Setup:** Stream playing on one input.

**Steps:**
1. While playing, server sends new videoUID with new hlsUrl.
2. Verify seamless transition.

**Verification:**
- Old engine destroyed, new engine created
- Same video element reused (no flicker)
- `loadedSource` time < 500ms
- Playback resumes with minimal black frame
- No state transition to IDLE (handover path)
- Debug panel shows updated videoUID

**Pass/Fail:** PASS if seamless handover. FAIL if visible tear-down/rebuild.

---

### SC-11: Engine error recovery — Hls.js

**Setup:** Stream playing with Hls.js.

**Steps:**
1. Trigger MEDIA_ERROR (simulate codec failure via mock).
2. Trigger repeat to hit MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS.

**Verification:**
- MEDIA_ERROR: `recoverMediaError()` called (1st attempt)
- MEDIA_ERROR again: `recoverMediaError()` called (2nd attempt)
- MEDIA_ERROR 3rd time: transitions to FATAL
- NETWORK_ERROR with recoverable details: retry with 2s/5s backoff
- ringBufferTail in debug panel shows last 5 errors

**Pass/Fail:** PASS if recovery attempts match constants. FAIL if wrong retry count.

---

### SC-12: Debug panel — new fields verify correlationId, engineKind, ringBufferTail

**Setup:** Live input, `?debug=1`.

**Steps:**
1. Load page, click play.
2. Inspect all 13 debug panel fields.

**Verification — ALL 13 FIELDS:**
1. `state` — current player state (IDLE/PREPARING/PLAYING/FATAL)
2. `correlationId` — non-empty string from server (per-request unique)
3. `engineKind` — `hls.js` or `native-hls`
4. `ringBufferTail` — `—` when no errors, last 5 errors when errors occur
5. `prebuffer` — buffer-ahead in seconds
6. `In Progress` — true/false
7. `clicked` — true/false (gesture captured)
8. `latency` — stream latency in ms
9. `live` — true/false
10. `videoUID` — current video UID
11. `polls` — poll count
12. `error_code` — error code or `—`
13. `source` — how state was determined (webhook/probe/coalesced)

**Pass/Fail:** PASS if all 13 fields present and populated correctly. FAIL if any field missing or shows incorrect values.

---

### SC-13: UI — shadow DOM creation, play button visible, status overlay works

**Setup:** Cold load.

**Steps:**
1. Inspect DOM via Chrome DevTools.
2. Verify shadow DOM structure.
3. Verify UI behavior.

**Verification:**
- `<hs-video>` has shadow DOM attached (`#shadow-root (open)`)
- Shadow DOM contains: `<video>`, `.play-button`, `.overlay`, `.status-message`, `.debug-panel`
- Play button is a `<button class="play-button">` with CSS triangle (▶)
- Play button visible pre-click, hidden post-click
- Overlay is a `<div class="overlay">` spanning full video area
- Status message appears/disappears with correct text
- Debug panel top-right positioned correctly
- All CSS self-contained in shadow DOM (no global leaks)

**Pass/Fail:** PASS if shadow DOM structure correct and all UI elements visible. FAIL if any element missing or CSS not scoped.

---

### SC-14: Multi-instance isolation

**Setup:** Two `<hs-video>` elements on one page with different inputIds.

**Steps:**
1. Load page with two player instances.
2. Click play on one, then the other.
3. Observe independent behavior.

**Verification:**
- Each player has its own LivePoller (pollCount independent)
- Each player has its own engine (destroying one doesn't affect the other)
- Status overlay of one doesn't affect the other
- Poster of one doesn't affect the other
- Gesture unlock of one doesn't unlock the other
- Debug panel of one doesn't show data from the other

**Pass/Fail:** PASS if instances fully independent. FAIL if any state shared between instances.

---

### SC-15: safe() utility — ring buffer, no bare catches remain

**Setup:** Trigger various error conditions.

**Steps:**
1. Inject an error by calling `window.getSafeRing()` via DevTools console.
2. Trigger errors in player (bad manifest URL, etc.).
3. Grep codebase for bare catches.

**Verification:**
- `window.getSafeRing()` returns last 5 errors (array of objects with label, message, timestamp)
- Ring buffer has max 20 entries (oldest evicted when full)
- Each entry has: `label` (string), `message` (string), `timestamp` (ISO string)
- Console only logs errors when `window.HSPlayerConfig.debug === true`
- **Code check:** `grep -rE "catch\s*\(\s*_\s*\)" celebration-child/js/HSPlayer/` returns zero matches in A4 code (index.js, StatusOverlay.js, DebugPanel.js, PosterManager.js, GestureUnlock.js, utils/safe.js, utils/timers.js, UiController.js)
- `safe()` returns `onErrorReturn` value (or `undefined`) on error

**Pass/Fail:** PASS if ring buffer works and no bare catches in A4 code. FAIL if ring buffer broken or bare catches found.

---

### SC-16: TimerRegistry — all timers tracked, dispose cleans up

**Setup:** Load and unload player.

**Steps:**
1. Load page, start polling, set timeouts.
2. Disconnect element (remove from DOM).
3. Verify timer cleanup.

**Verification:**
- LivePoller interval tracked in TimerRegistry
- Fatal timer tracked in TimerRegistry
- Status fade timeout tracked in TimerRegistry
- After element removed: ALL intervals cleared, ALL timeouts cleared
- No "Cannot clear already-cleared interval" errors
- No memory leaks (check DevTools Performance tab)
- TimerRegistry.dispose() is idempotent (can call twice)

**Pass/Fail:** PASS if all timers cleaned up on disconnect. FAIL if any timer leaks.

---

## E2E Manual Test Checklist

Run these against the mock server:

- [ ] Mock server starts and responds to all endpoints
- [ ] Page loads, poster + play button render pre-click
- [ ] Clicking play loads HLS via LivePoller → ManifestProbe → HlsEngine
- [ ] Status overlay appears post-click with correct text
- [ ] Debug panel populates all 13 fields including correlationId, engineKind, ringBufferTail
- [ ] Pre-gesture suppression works (status hidden before click)
- [ ] Multi-instance isolation verified (two players independent)
- [ ] safe() ring buffer captures errors correctly
- [ ] TimerRegistry cleans up all timers on disconnect
- [ ] No JavaScript errors in console
- [ ] No bare `catch (_) {}` patterns in A4 code (grep verified)

---

## Post-Test Actions

After all tests pass:
1. Document results in this file
2. Advance to A5
3. Add screenshot/recording to PR body

If any test fails:
1. Note the failure
2. Fix on `a/phase-A4`
3. Re-deploy and re-test
