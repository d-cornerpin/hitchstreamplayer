// LivePoller.js — HitchStream Player v2
// Polling loop with AbortController + per-request timeout, ETag/304 handling,
// and exponential backoff on consecutive errors.
//
// Implementation note: uses a chained setTimeout pattern (not setInterval) so
// that exponential backoff actually changes the wait between polls. setInterval
// with a stale delay variable would never adjust the cadence.

import {
  POLL_INITIAL_DELAY_MS,
  POLL_INTERVAL_MS,
  POLL_BACKOFF_MAX_MS,
  POLL_BACKOFF_AFTER_ERRORS,
  POLL_BACKOFF_JITTER_MS,
  POLL_TIMEOUT_MS,
} from './constants.js';

// Event names emitted by LivePoller
const POLL = 'poll';
const ERROR = 'error';
const BACKOFF = 'backoff';
const RECOVERED = 'recovered';

/**
 * Create a LivePoller instance.
 * @param {object} opts
 * @param {string} opts.inputId - Cloudflare input ID
 * @param {string} opts.endpoint - WordPress live-state endpoint URL
 * @param {function} opts.onEvent - Callback for events: { type, payload }
 * @param {function} [opts.debugLog] - Debug logger
 * @param {function} [opts.debugError] - Debug error logger
 */
export function createLivePoller(opts) {
  const { inputId, endpoint, onEvent, debugLog = () => {}, debugError = () => {} } = opts;

  let _nextTimeoutId = null;
  let _pollTimeoutId = null;
  let _inFlight = false;
  let _consecutivePollErrors = 0;
  let _nextPollDelayMs = POLL_INTERVAL_MS;
  let _destroyed = false;
  let _started = false;
  let _lastETag = null;
  let _pollCount = 0;

  if (!inputId) {
    debugLog('LivePoller: missing inputId; polling not started.');
    return { start: () => {}, stop: () => {}, destroy: () => {}, get pollCount() { return 0; } };
  }

  /** Schedule the next poll. Reads _nextPollDelayMs each time so backoff actually applies. */
  const scheduleNext = (delayMs) => {
    if (_destroyed) return;
    if (_nextTimeoutId) clearTimeout(_nextTimeoutId);
    _nextTimeoutId = setTimeout(() => {
      _nextTimeoutId = null;
      poll().finally(() => {
        // After each poll, schedule the next using the (possibly updated) delay.
        if (!_destroyed) scheduleNext(_nextPollDelayMs);
      });
    }, delayMs);
  };

  /** Make a single poll request */
  const poll = async () => {
    if (_destroyed) return;
    if (_inFlight) {
      debugLog('LivePoller: poll skipped — previous request still in-flight.');
      return;
    }

    _inFlight = true;
    _pollCount++;

    const controller = new AbortController();
    if (_pollTimeoutId) clearTimeout(_pollTimeoutId);
    _pollTimeoutId = setTimeout(() => controller.abort(), POLL_TIMEOUT_MS);

    const lifecycleUrl = `${endpoint}?inputId=${encodeURIComponent(inputId)}`;
    const headers = {};
    if (_lastETag) headers['If-None-Match'] = _lastETag;

    try {
      const res = await fetch(lifecycleUrl, {
        method: 'GET',
        mode: 'cors',
        credentials: 'omit',
        signal: controller.signal,
        headers,
      });

      clearTimeout(_pollTimeoutId);
      _pollTimeoutId = null;

      // 304 Not Modified per contract §4.1
      if (res.status === 304) {
        _inFlight = false;
        if (_consecutivePollErrors > 0) {
          _consecutivePollErrors = 0;
          _nextPollDelayMs = POLL_INTERVAL_MS;
          onEvent({ type: RECOVERED, payload: {} });
        }
        onEvent({ type: 'noChange', payload: { pollCount: _pollCount } });
        return;
      }

      const etag = res.headers.get('etag');
      if (etag) _lastETag = etag;

      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      const data = await res.json();

      _inFlight = false;
      if (_consecutivePollErrors > 0) {
        _consecutivePollErrors = 0;
        _nextPollDelayMs = POLL_INTERVAL_MS;
        onEvent({ type: RECOVERED, payload: {} });
      }

      const rawState = data && data.state ? data.state : null;
      const liveStates = ['live', 'reconnected', 'new_configuration_accepted', 'reconnecting'];
      const isLive = liveStates.includes(rawState);
      const videoUID = data && typeof data.videoUID === 'string' ? data.videoUID : null;
      const errorCode = data && data.errorCode ? data.errorCode : null;
      const source = data && data.source ? data.source : null;
      const hlsUrl = data && data.hlsUrl ? data.hlsUrl : null;

      onEvent({
        type: POLL,
        payload: {
          state: rawState,
          isLive,
          videoUID,
          hlsUrl,
          errorCode,
          source,
          pollCount: _pollCount,
        },
      });
    } catch (err) {
      if (_pollTimeoutId) { clearTimeout(_pollTimeoutId); _pollTimeoutId = null; }
      _inFlight = false;
      _consecutivePollErrors++;

      if (_consecutivePollErrors >= POLL_BACKOFF_AFTER_ERRORS) {
        // Exponential backoff. n=2 → 2x, n=3 → 4x, etc., capped at POLL_BACKOFF_MAX_MS.
        const backoffMs = Math.min(
          POLL_INTERVAL_MS * Math.pow(2, _consecutivePollErrors - 1),
          POLL_BACKOFF_MAX_MS
        );
        _nextPollDelayMs = backoffMs;
        onEvent({ type: BACKOFF, payload: { errorCount: _consecutivePollErrors, delay: backoffMs, error: err.message } });
      } else {
        onEvent({ type: ERROR, payload: { error: err.message } });
      }
    }
  };

  /** Start polling. First poll fires immediately; subsequent polls chain via scheduleNext. */
  const start = () => {
    if (_destroyed || _started) return;
    if (!inputId) return;
    _started = true;

    // Initial poll has a small jittered delay to avoid thundering herd when
    // many viewers load the page at once.
    const jitter = (Math.random() - 0.5) * 2 * POLL_BACKOFF_JITTER_MS;
    const initialDelay = Math.max(0, POLL_INITIAL_DELAY_MS + jitter);

    setTimeout(() => {
      if (_destroyed) return;
      poll().finally(() => {
        if (!_destroyed) scheduleNext(_nextPollDelayMs);
      });
    }, initialDelay);
  };

  /** Stop polling (can be restarted with start). */
  const stop = () => {
    if (_nextTimeoutId) { clearTimeout(_nextTimeoutId); _nextTimeoutId = null; }
    if (_pollTimeoutId) { clearTimeout(_pollTimeoutId); _pollTimeoutId = null; }
    _inFlight = false;
    _consecutivePollErrors = 0;
    _nextPollDelayMs = POLL_INTERVAL_MS;
    _started = false;
  };

  /** Mark poller as destroyed (prevents future polling) */
  const destroy = () => {
    _destroyed = true;
    stop();
  };

  return { start, stop, destroy, get pollCount() { return _pollCount; } };
}

export { POLL, ERROR, BACKOFF, RECOVERED };
