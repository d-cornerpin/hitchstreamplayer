// EngineFactory.js — HitchStream Player v2
// Factory to pick between Hls.js and native HLS based on browser capabilities.
// A1.2 (B2) — iPhone fallback to native HLS when Hls.js is not supported.

import { HlsEngine } from './HlsEngine.js';
import { NativeHlsEngine } from './NativeHlsEngine.js';

/**
 * Create a playback engine based on browser capabilities.
 * @param {object} [opts] - Engine options passed to HlsEngine
 * @returns {HlsEngine|NativeHlsEngine} Engine instance
 */
export function createEngine(opts = {}) {
  // If Hls.js is supported, use it.
  if (typeof Hls !== 'undefined' && Hls.isSupported()) {
    return new HlsEngine(opts);
  }

  // Check if Safari supports native HLS
  const testVideo = document?.createElement?.('video') || { canPlayType: () => '' };
  if (testVideo.canPlayType?.('application/vnd.apple.mpegurl')?.includes('maybe') ||
      testVideo.canPlayType?.('application/vnd.apple.mpegurl')?.includes('probably')) {
    return new NativeHlsEngine();
  }

  // Neither Hls.js nor native HLS — caller will need to handle this case
  // (should enter fatal state).
  return null;
}

/** Check if browser supports any HLS playback */
export function isHlsSupported() {
  if (typeof Hls !== 'undefined' && Hls.isSupported()) return true;
  const testVideo = document?.createElement?.('video') || { canPlayType: () => '' };
  return !!testVideo.canPlayType?.('application/vnd.apple.mpegurl');
}
