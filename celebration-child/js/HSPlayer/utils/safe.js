// utils/safe.js — HitchStream Player v2
// Wraps functions in try/catch with ring-buffered error tracking.
// Replaces every bare catch (_) {} across the player (A4.6 hard gate).
//
// Design: errors ALWAYS log to console.error so production failures surface
// to ops without requiring ?debug=1. The ring is module-scoped — when multiple
// <hs-video> instances coexist on a page, they share the ring (the debug panel
// is per-instance, so each panel shows the most recent 5 entries regardless of
// which instance produced them). For typical wedding pages with one player,
// this is invisible. Multi-player pages would see cross-instance debug data,
// which is a debug-only concern (no functional behavior depends on the ring).

const RING_MAX = 20;

let _ring = [];

/**
 * Run fn, catch errors, push to ring buffer, always log to console.error.
 * @param {string} label - Descriptive label for the operation
 * @param {function} fn - Function to execute
 * @param {*} [onErrorReturn] - Value to return on error (default: undefined)
 * @returns {*} fn's return value on success, onErrorReturn on error
 */
export function safe(label, fn, onErrorReturn) {
  try {
    return fn();
  } catch (err) {
    _ring.push({
      timestamp: Date.now(),
      label,
      error: err && err.message ? err.message : String(err),
      stack: err && err.stack ? err.stack : null,
    });
    if (_ring.length > RING_MAX) _ring.shift();
    // Always log. Hiding errors behind a debug flag turned production into a
    // black box during the audit; this restores visibility unconditionally.
    // Verbose stack only in debug.
    if (typeof console !== 'undefined' && typeof console.error === 'function') {
      const isDebug = typeof window !== 'undefined' && window?.HSPlayerConfig?.debug;
      if (isDebug) {
        console.error('[hs-video] safe:', label, err);
      } else {
        console.error('[hs-video]', label, err && err.message ? err.message : err);
      }
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

/**
 * Clear the ring (used by tests and on player reset).
 */
export function clearSafeRing() {
  _ring = [];
}
