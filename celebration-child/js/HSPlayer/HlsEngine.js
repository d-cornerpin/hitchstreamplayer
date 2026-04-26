// HlsEngine.js — HitchStream Player v2
// Hls.js engine wrapper. Implements the same interface as NativeHlsEngine.
// Handles fragment parsing (PTS tracking), throughput monitoring, and error recovery.

import { HLS_CONFIG } from './constants.js';

/**
 * Hls.js engine — wraps Hls.js with a consistent interface alongside NativeHlsEngine.
 */
export class HlsEngine {
  /**
   * @param {object} opts
   * @param {number} opts.audioDriftFrameThreshold - frames of drift before sync message
   * @param {function} opts.debugLog - debug logger
   * @param {function} opts.debugError - debug error logger
   * @param {function} opts.updateStatus - status overlay updater
   * @param {string} opts.currentStatusType - current status type
   * @param {function} opts.onAudioDrift - callback when drift detected
   * @param {function} opts.onThroughputSample - callback for throughput sample
   * @param {number} opts.maxThroughputSamples - cap for throughput samples
   */
  constructor(opts = {}) {
    this._Hls = Hls; // global Hls (loaded externally)
    this._hls = new this._Hls(HLS_CONFIG);
    this._audioDriftFrameThreshold = opts.audioDriftFrameThreshold || 4;
    this._debugLog = opts.debugLog || (() => {});
    this._debugError = opts.debugError || (() => {});
    this._updateStatus = opts.updateStatus || (() => {});
    this._currentStatusType = opts.currentStatusType || 'none';
    this._onAudioDrift = opts.onAudioDrift || (() => {});
    this._onThroughputSample = opts.onThroughputSample || (() => {});
    this._maxThroughputSamples = opts.maxThroughputSamples || 10;
    this._listeners = {};
    this._destroyed = false;
    this._setupListeners();
  }

  _setupListeners() {
    // Fragment parsing data — track audio/video PTS for drift detection
    this._hls.on(this._Hls.Events.FRAG_PARSING_DATA, (event, data) => {
      if (this._destroyed || !data?.samples?.length) return;
      const timeScale = 90000;
      if (data.type === 'audio') {
        const sample = data.samples[data.samples.length - 1] || data.samples[0];
        const pts = sample?.pts;
        if (typeof pts === 'number') this._lastAudioPts = pts / timeScale;
      } else if (data.type === 'video') {
        const sample = data.samples[data.samples.length - 1] || data.samples[0];
        const pts = sample?.pts;
        if (typeof pts === 'number') this._lastVideoPts = pts / timeScale;
      }
    });

    // Fragment loaded — track throughput
    this._hls.on(this._Hls.Events.FRAG_LOADED, (_evt, data) => {
      if (this._destroyed) return;
      const stats = data?.stats || {};
      const loaded = stats.loaded || 0;
      const loading = stats.loading || {};
      let ms = 0;
      if (typeof loading.start === 'number' && typeof loading.end === 'number' && loading.end > loading.start) {
        ms = loading.end - loading.start;
      } else {
        const tload = stats.tload || 0;
        const tfirst = stats.tfirst || stats.trequest || 0;
        ms = (tload && tfirst && tload > tfirst) ? (tload - tfirst) : 0;
      }
      if (loaded > 0 && ms > 0) {
        const bps = (loaded * 8) / (ms / 1000);
        this._throughputSamples = this._throughputSamples || [];
        this._throughputSamples.push(bps);
        if (this._throughputSamples.length > this._maxThroughputSamples) this._throughputSamples.shift();
        this._onThroughputSample(bps);
      }
    });

    // HLS errors
    this._hls.on(this._Hls.Events.ERROR, (event, data) => {
      if (this._destroyed) return;
      if (!data || !data.fatal) return;
      this._emit('error', event, data);
    });
  }

  /** @returns {boolean} Hls.js is supported */
  isSupported() { return !!this._Hls?.isSupported?.(); }

  /** Set stream URL */
  loadSource(url) {
    if (this._destroyed) return;
    this._hls.loadSource(url);
  }

  /** Attach media element */
  attachMedia(video) {
    if (this._destroyed) return;
    this._hls.attachMedia(video);
  }

  /** Register event listener */
  on(event, fn) {
    if (!this._listeners[event]) this._listeners[event] = [];
    this._listeners[event].push(fn);
    // Also forward to Hls.js
    this._hls.on(this._Hls.Events[event.toUpperCase()], fn);
  }

  /** Remove event listener */
  off(event, fn) {
    if (!this._listeners[event]) return;
    this._listeners[event] = this._listeners[event].filter(f => f !== fn);
  }

  /** Start load from live edge */
  startLoad(startPosition) {
    if (this._destroyed) return;
    try { this._hls.startLoad(startPosition); } catch (e) {
      try { this._hls.startLoad(); } catch (_) {}
    }
  }

  /** Stop load */
  stopLoad() {
    if (this._destroyed) return;
    this._hls.stopLoad();
  }

  /** Recover from media error */
  recoverMediaError() {
    if (this._destroyed) return;
    this._hls.recoverMediaError();
  }

  /** Destroy engine and clean up */
  destroy() {
    this._destroyed = true;
    if (this._hls) {
      this._hls.destroy();
    }
    this._listeners = {};
  }

  /** Emit stored event listeners */
  _emit(event, source, data) {
    if (!this._listeners[event]) return;
    for (const fn of this._listeners[event]) {
      try { fn(source, data); } catch (e) { console.error('[hs-video] hls engine event handler error:', e); }
    }
  }

  // --- Read-only properties ---
  get levels() { return this._hls?.levels || []; }
  get currentLevel() { return this._hls?.currentLevel ?? -1; }
  get autoLevelCapping() { return this._hls?.autoLevelCapping ?? null; }
  set autoLevelCapping(val) { if (this._hls) this._hls.autoLevelCapping = val; }
  get latency() { return this._hls?.latency ?? NaN; }
  get manifestLoadingRetryCount() { return this._hls?.manifestLoadingRetryCount ?? 0; }
}
