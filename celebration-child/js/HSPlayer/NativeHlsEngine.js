// NativeHlsEngine.js — HitchStream Player v2
// Native HLS engine (video.src). Implements the same interface as HlsEngine.
// Used on Safari/iOS where Hls.js is not available.

const EVENT_MAP = {
  manifestParsed: 'loadedmetadata',
  error: 'error',
  playing: 'playing',
  pause: 'pause',
  timeupdate: 'timeupdate',
  ended: 'ended',
};

/**
 * Native HLS engine — wraps HTMLMediaElement for live streaming.
 * No prebuffer gate, no manifest probe — browser handles it natively.
 */
export class NativeHlsEngine {
  constructor() {
    this._listeners = {};
    this._video = null;
    this._destroyed = false;
  }

  /** @returns {boolean} Always true for native HLS */
  isSupported() { return true; }

  /** Set stream URL via video.src */
  loadSource(url) {
    if (this._destroyed) return;
    if (this._video) {
      this._video.src = url;
    }
  }

  /** Attach media element — just stores reference for event binding */
  attachMedia(video) {
    if (this._destroyed) return;
    this._video = video;
    // Bind all events
    for (const [hlsEvent, domEvent] of Object.entries(EVENT_MAP)) {
      const handler = (evt) => {
        if (this._destroyed) return;
        this._emit(hlsEvent, evt, evt);
      };
      video.addEventListener(domEvent, handler);
      if (!this._boundHandlers) this._boundHandlers = {};
      this._boundHandlers[hlsEvent] = handler;
    }
  }

  /** Register event listener */
  on(event, fn) {
    if (!this._listeners[event]) this._listeners[event] = [];
    this._listeners[event].push(fn);
  }

  /** Remove event listener */
  off(event, fn) {
    if (!this._listeners[event]) return;
    this._listeners[event] = this._listeners[event].filter(f => f !== fn);
  }

  /** Start load from live edge */
  startLoad(startPosition) {
    if (this._destroyed || !this._video) return;
    if (startPosition === -1) {
      this._video.currentTime = this._video.duration || 0;
    }
  }

  /** Stop load */
  stopLoad() {
    // No-op for native — browser handles it
  }

  /** Recover from media error */
  recoverMediaError() {
    // For native HLS, "recover" means clearing src and re-attaching
    if (this._video) {
      const oldSrc = this._video.src;
      this._video.src = '';
      this._video.load();
      if (oldSrc) this._video.src = oldSrc;
    }
  }

  /** Destroy engine and clean up */
  destroy() {
    this._destroyed = true;
    if (this._video) {
      this._video.pause();
      this._video.src = '';
      // Remove all event listeners
      for (const [hlsEvent, domEvent] of Object.entries(EVENT_MAP)) {
        const handler = this._boundHandlers?.[hlsEvent];
        if (handler) this._video.removeEventListener(domEvent, handler);
      }
      this._video = null;
    }
    this._listeners = {};
    this._boundHandlers = {};
  }

  /** Emit stored event listeners */
  _emit(hlsEvent, source, data) {
    if (!this._listeners[hlsEvent]) return;
    for (const fn of this._listeners[hlsEvent]) {
      try { fn(source, data); } catch (e) { console.error('[hs-video] native engine event handler error:', e); }
    }
  }

  // --- Read-only properties (native HLS doesn't expose these) ---
  get levels() { return []; }
  get currentLevel() { return -1; }
  get autoLevelCapping() { return null; }
  set autoLevelCapping(val) { /* no-op */ }
  get latency() { return NaN; }
  get manifestLoadingRetryCount() { return 0; }
}
