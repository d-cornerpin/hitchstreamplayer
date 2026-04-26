// LivePoller.js — HitchStream Player v2
// Polling loop with AbortController + timeout, ETag/304 handling, exponential backoff.
// Pure-ish: emits events ({ type, payload }) — does not touch the DOM directly.
// The element subscribes to events and feeds them to the state machine.

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
 * @param {string} [opts.hlsOriginAllowlistRegex] - Regex string for origin validation
 */
export function createLivePoller(opts) {
  const { inputId, endpoint, onEvent, debugLog = () => {}, debugError = () => {} } = opts;

  let _pollFn = null;
  let _pollingInterval = null;
  let _pollTimeoutId = null;
  let _inFlight = false;
  let _consecutivePollErrors = 0;
  let _nextPollDelayMs = POLL_INTERVAL_MS;
  let _destroyed = false;
  let _lastETag = null;

  // Validate input ID
  if (!inputId) {
    debugLog('LivePoller: missing inputId; polling not started.');
    return { start: () => {}, stop: () => {} };
  }

  /** Make a single poll request */
  const poll = async () => {
    if (_destroyed) return;
    if (_inFlight) {
      debugLog('LivePoller: poll skipped — previous request still in-flight.');
      return;
    }

    _inFlight = true;

    // AbortController + timeout per poll
    const controller = new AbortController();
    clearTimeout(_pollTimeoutId);
    _pollTimeoutId = setTimeout(() => controller.abort(), POLL_TIMEOUT_MS);

    const lifecycleUrl = `${endpoint}?inputId=${encodeURIComponent(inputId)}`;

    // Add ETag if we have one from the previous response
    const headers = {};
    if (_lastETag) {
      headers['If-None-Match'] = _lastETag;
    }

    try {
      const res = await fetch(lifecycleUrl, {
        method: 'GET',
        mode: 'cors',
        credentials: 'omit',
        signal: controller.signal,
        headers,
      });

      clearTimeout(_pollTimeoutId);

      // 304 Not Modified per contract §4.1
      if (res.status === 304) {
        _inFlight = false;
        _consecutivePollErrors = 0;
        _nextPollDelayMs = POLL_INTERVAL_MS;
        // Emit a "no change" event — element can skip state machine transition
        onEvent({ type: 'noChange', payload: { pollCount: _pollCount } });
        return;
      }

      // Update ETag for next poll
      const etag = res.headers.get('etag');
      if (etag) _lastETag = etag;

      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }

      const data = await res.json();

      // Reset state
      _inFlight = false;
      _consecutivePollErrors = 0;
      _nextPollDelayMs = POLL_INTERVAL_MS;

      // Parse and emit poll event
      const isLive = data && typeof data.live === 'boolean' ? data.live : false;
      const videoUID = data && typeof data.videoUID === 'string' ? data.videoUID : null;
      const rawState = data && data.state ? data.state : null;
      const errorCode = data && data.error_code ? data.error_code : null;
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
      clearTimeout(_pollTimeoutId);
      _inFlight = false;

      // Exponential backoff after POLL_BACKOFF_AFTER_ERRORS consecutive errors
      _consecutivePollErrors++;
      if (_consecutivePollErrors >= POLL_BACKOFF_AFTER_ERRORS) {
        const backoffMs = Math.min(
          POLL_INTERVAL_MS * Math.pow(2, _consecutivePollErrors - 2),
          POLL_BACKOFF_MAX_MS
        );
        _nextPollDelayMs = backoffMs;
        onEvent({ type: BACKOFF, payload: { error: _consecutivePollErrors, delay: backoffMs } });
      } else {
        onEvent({ type: ERROR, payload: { error: err.message } });
      }
    }
  };

  let _pollCount = 0;

  /** Start polling */
  const start = () => {
    if (_destroyed) return;
    if (!inputId) return;

    // Clear any existing polling
    if (_pollingInterval) clearInterval(_pollingInterval);

    // Add ±1500ms jitter to initial poll delay (fix B6)
    const jitter = (Math.random() - 0.5) * 2 * POLL_BACKOFF_JITTER_MS;
    const jitteredDelay = POLL_INITIAL_DELAY_MS + jitter;

    // Schedule first poll
    setTimeout(() => {
      if (_destroyed) return;
      _pollingInterval = setInterval(poll, _nextPollDelayMs);
      _pollCount++;
      poll();
    }, jitteredDelay);
  };

  /** Stop polling */
  const stop = () => {
    if (_pollingInterval) {
      clearInterval(_pollingInterval);
      _pollingInterval = null;
    }
    if (_pollTimeoutId) {
      clearTimeout(_pollTimeoutId);
      _pollTimeoutId = null;
    }
    _inFlight = false;
    _consecutivePollErrors = 0;
    _nextPollDelayMs = POLL_INTERVAL_MS;
  };

  /** Mark poller as destroyed (prevents future polling) */
  const destroy = () => {
    stop();
    _destroyed = true;
  };

  return { start, stop, destroy, get pollCount() { return _pollCount; } };
}

export { POLL, ERROR, BACKOFF, RECOVERED };
