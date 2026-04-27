// utils/safe.js — HitchStream Player v2
// Wraps functions in try/catch with ring-buffered error tracking.
// Replaces every bare catch (_) {} across the player (A4.6 hard gate).

const RING_MAX = 20;

let _ring = [];

/**
 * Run fn, catch errors, push to ring buffer.
 * @param {string} label - Descriptive label for the operation
 * @param {function} fn - Function to execute
 * @param {*} [onErrorReturn] - Value to return on error (default: undefined)
 * @returns {*} fn's return value on success, onErrorReturn on error
 */
export function safe(label, fn, onErrorReturn) {
  try {
    return fn();
  } catch (err) {
    _ring.push({ timestamp: Date.now(), label, error: err.message });
    if (_ring.length > RING_MAX) _ring.shift();
    if (typeof window !== 'undefined' && window.HSPlayerConfig?.debug) {
      console.warn('[hs-video] safe:', label, err);
    }
    return onErrorReturn;
  }
}

/**
 * Return the last 5 errors from the ring buffer for debug panel display.
 * @returns {Array} Last 5 error entries
 */
export function getSafeRing() {
  return _ring.slice(-5);
}
