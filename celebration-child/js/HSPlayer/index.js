// index.js — HitchStream Player v2 entry point
// Wires all modules, defines HSVideoElement class, registers <hs-video>.

import { transition } from './PlayerStateMachine.js';
import {
  STATE, FATAL_TIMEOUT_MS, MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS,
  MANIFEST_PROBE_MAX_ATTEMPTS, MIN_THROUGHPUT_SAMPLES, MAX_THROUGHPUT_SAMPLES, PREBUFFER_TIMEOUT_MS,
  HLS_ORIGIN_ALLOWLIST_REGEX, DEBUG_ERROR_MESSAGES,
  RECONNECT_WATCHDOG_INTERVAL_MS, RECONNECT_WATCHDOG_BUFFER_THRESHOLD, RECONNECT_WATCHDOG_FATAL_TTL,
} from './constants.js';
import { createLivePoller } from './LivePoller.js';
import { probeManifest } from './ManifestProbe.js';
import { createEngine } from './EngineFactory.js';
import { HlsEngine } from './HlsEngine.js';
import { NativeHlsEngine } from './NativeHlsEngine.js';
import { TimerRegistry } from './utils/timers.js';
import { safe, getSafeRing } from './utils/safe.js';
import { UiController } from './UiController.js';
import { StatusOverlay } from './StatusOverlay.js';
import { DebugPanel } from './DebugPanel.js';
import { PosterManager } from './PosterManager.js';
import { GestureUnlock } from './GestureUnlock.js';

// Expose safe ring for debug panel
if (typeof window !== 'undefined') window.getSafeRing = getSafeRing;

// ─── HSPlayerElement class ───

export class HSVideoElement extends HTMLElement {
  constructor() {
    super();
    this._init();
  }

  _init() {
    this.playerState = STATE.IDLE;
    this.debugMode = false;
    this.videoEl = null; this.overlayEl = null; this.debugPanelEl = null;
    this.statusMessageEl = null;
    this.pendingPlayRequest = false; this.prebufferStartTs = 0;
    this.throughputSamples = []; this.probeAttempts = 0;
    this.currentStreamUrl = null; this.ingestFalseCount = 0; this.hasPlayedOnce = false;
    this.pollCount = 0; this.liveStatus = null; this.videoUID = null;
    this.currentVideoUID = null;
    this._networkErrorRecoveryAttempts = 0; this.fatalTimer = null;
    this._reconnectWatchdogTimer = null; this._reconnectWatchdogActive = false;
    this._drainingToIdle = false;
    this.mediaErrorRecoveryAttempts = 0; this.currentStatusType = null;
    this.fatalBufferLevel = null; this.fatalTimerStart = null;
    this.latestLiveHlsUrl = null; this.playerMode = null;
    this.streamCurrentlyLive = false; this._destroyed = false;
    this.lastAudioPts = null; this.lastVideoPts = null;
    this.audioDriftFrameThreshold = 4; this.audioSyncIssueActive = false;
    this.timers = new TimerRegistry();
    this._currentEngine = null; this._livePoller = null;
    this._probeAbortController = null; this.ui = null;
    this.statusOverlay = null; this.debugPanel = null;
    this.posterMgr = null; this.gestureUnlock = null;
    safe('debug-mode', () => {
      const dbg = window?.location?.search ? new URLSearchParams(window.location.search).get('debug') || '' : '';
      if (['1','true','yes'].includes(dbg.toLowerCase())) this.debugMode = true;
    });
  }

  debugLog(...m) { if (this.debugMode) console.log('[hs-video]', ...m); }
  debugError(...m) { if (this.debugMode) console.error('[hs-video]', ...m); }

  static get observedAttributes() { return ['poster-initial','poster-idle','poster-fatal']; }

  attributeChangedCallback(name, oldV, newV) {
    safe('attrChanged', () => {
      const t = { 'poster-initial':'initial','poster-idle':'idle','poster-fatal':'fatal' }[name];
      if (t && newV) this.setPoster(t, newV);
    });
  }

  connectedCallback() {
    console.log('[hs] connectedCallback');
    safe('connectedCallback', () => {
      this.ui = new UiController();
      this.ui.createShadowRoot(this);
      this.ui.showPlayButton(true);
      [this.videoEl, this.overlayEl, this.statusMessageEl, this.debugPanelEl] =
        [this.ui.videoEl, this.ui.overlayEl, this.ui.statusMessageEl, this.ui.debugPanelEl];
      this.statusOverlay = new StatusOverlay(this.ui.statusMessageEl, this.timers);
      this.debugPanel = new DebugPanel(this.ui.debugPanelEl);
      this.posterMgr = new PosterManager();
      const a = {};
      ['poster-initial','poster-idle','poster-fatal'].forEach(k => {
        a[k] = this.getAttribute(k);
      });
      this.posterMgr.init(window?.HSPlayerConfig, a);
      // Surface the initial poster on the <video> element immediately so the
      // viewer sees the poster image before any state machine transition.
      if (this.videoEl && this.posterMgr.initial) this.videoEl.poster = this.posterMgr.initial;
      this.gestureUnlock = new GestureUnlock(this);
      // Hook the play button directly (shadow DOM target retargeting hides
      // shadow internals from document-level listeners). Document fallback
      // also runs so any user gesture anywhere counts.
      this.gestureUnlock.attachPlayButton(this.ui.playButtonEl);
      this.gestureUnlock.start();
      this.gestureUnlock.promise.then(() => {
        if (this._destroyed) return;
        this.ui.hidePlayButton();
        if (this.statusOverlay) this.statusOverlay.gestureUnlocked = true;
      });
      // Set hasPlayedOnce the first time the video actually starts playing.
      // The state machine reads this to flip from initial poster → idle poster
      // (§10 preserved behavior #3).
      if (this.videoEl) {
        this.videoEl.addEventListener('playing', () => {
          this.hasPlayedOnce = true;
          this._clearFatalTimer();
        }, { once: true });
      }
    });
  }

  disconnectedCallback() {
    safe('disconnectedCallback', () => {
      this._destroyed = true;
      this._clearFatalTimer();
      if (this._livePoller) { this._livePoller.stop(); this._livePoller = null; }
      if (this._probeAbortController) { this._probeAbortController.abort(); this._probeAbortController = null; }
      this.timers.dispose(); this.timers = new TimerRegistry();
      if (this.gestureUnlock) this.gestureUnlock.resolve();
    });
  }

  setPoster(which, url) {
    safe('setPoster', () => { this.posterMgr.set(which, url); if (this.videoEl) this.videoEl.poster = url; });
  }

  setApiInfo({ inputId, isLive, autoplay=true, posterInitialURL, posterIdleURL, posterFatalURL }) {
    console.log('[hs] setApiInfo called with:', { inputId, isLive });
    if (this._destroyed) return;
    safe('setApiInfo', () => {
      if (!window?.HSPlayerConfig?.endpoints?.liveState) { this._enterFatal(); return; }
      if (!window.HSPlayerConfig?.cloudflare?.customerCode) { this._enterFatal(); return; }
      this.debugLog('API info:', { inputId, isLive, autoplay });
      this.inputId = inputId; this.playerMode = isLive ? 'live' : 'vod'; this.autoplay = autoplay;
      if (posterInitialURL) this.posterMgr.initial = posterInitialURL;
      if (posterIdleURL) this.posterMgr.idle = posterIdleURL;
      if (posterFatalURL) this.posterMgr.fatal = posterFatalURL;
      // Branch on mode. Live mode starts polling; VOD mode loads the video
      // directly. setApiInfo runs AFTER connectedCallback in the typical
      // PHP-driven flow, so connectedCallback can't make this decision —
      // it has to happen here.
      if (this.playerMode === 'live') {
        this.startPolling();
      } else if (this.playerMode === 'vod' && this.inputId) {
        this.loadVideoDirectly();
      }
    });
  }

  // ── Live path ──

  startPolling() {
    console.log('[hs] startPolling inputId:', this.inputId, 'mode:', this.playerMode, 'endpoint:', window?.HSPlayerConfig?.endpoints?.liveState);
    safe('startPolling', () => {
      if (!this.inputId) { console.log('[hs] startPolling: no inputId, aborting'); return; }
      if (!this.hasPlayedOnce) this.statusOverlay.updateStatus('waiting');
      if (!this._livePoller) {
        this._livePoller = createLivePoller({
          inputId: this.inputId,
          endpoint: window.HSPlayerConfig.endpoints.liveState,
          onEvent: (e) => { console.log('[hs] poll event:', JSON.stringify(e)); this._handlePollEvent(e); },
        });
        console.log('[hs] poller created');
      }
      this._livePoller.start();
      console.log('[hs] poller started');
    });
  }

  stopPolling() {
    safe('stopPolling', () => { if (this._livePoller) { this._livePoller.stop(); this._livePoller = null; } });
  }

  _handlePollEvent(evt) {
    safe('_handlePollEvent', () => {
      if (evt.type === 'noChange') return;
      if (evt.type === 'poll') {
        const { state, isLive, videoUID, hlsUrl, errorCode, source, pollCount } = evt.payload;
        console.log('[hs] poll event:', { state, isLive, videoUID, hlsUrl: !!hlsUrl, errorCode, pollCount });
        this.pollCount = pollCount; this.streamCurrentlyLive = isLive;
        this._updateDebugPanel({ liveStatus: isLive, videoUID, pollCount: this.pollCount, error_code: errorCode, source });
        if (isLive && videoUID) {
          const url = (hlsUrl && HSVideoElement.isValidHlsUrl(hlsUrl)) ? hlsUrl
            : `https://customer-${this.posterMgr.customerCode}.cloudflarestream.com/${videoUID}/manifest/video.m3u8`;
          this.latestLiveHlsUrl = url; this.ingestFalseCount = 0; this.currentVideoUID = videoUID;
          this._stopReconnectWatchdog();
          const r = transition({ currentState: this.playerState, event: { type:'poll', payload:{ state:'live', videoUID, hlsUrl:url, source } }, context: this._ctx() });
          this.playerState = r.nextState; this._dispatchEffects(r.sideEffects);
        } else {
          const ev = errorCode ? { type:'poll', payload:{ state:'error', errorCode, source } }
            : { type:'poll', payload:{ state:'idle', videoUID:null, hlsUrl:null } };
          this.ingestFalseCount = (this.ingestFalseCount||0)+1;
          this._stopReconnectWatchdog();
          const r = transition({ currentState: this.playerState, event: ev, context: this._ctx() });
          this.playerState = r.nextState; this._dispatchEffects(r.sideEffects);
        }
        if (state === 'reconnecting' && this.playerState === STATE.PLAYING) {
          this._startReconnectWatchdog();
          this.statusOverlay.updateStatus('reconnecting');
        }
      }
      if (evt.type === 'error') this.debugError('Poller error:', evt.payload.error);
      if (evt.type === 'backoff') this.debugLog('Poller backoff:', evt.payload.delay, 'ms');
    });
  }

  managePlayerState(desiredState) {
    safe('managePlayerState', () => {
      const r = transition({ currentState: this.playerState, event:{ type:'poll', payload:{ state:desiredState } }, context: this._ctx() });
      this.playerState = r.nextState; this._dispatchEffects(r.sideEffects);
    });
  }

  // ── VOD path ──

  loadVideoDirectly() {
    safe('loadVideoDirectly', () => {
      const url = `https://customer-${this.posterMgr.customerCode}.cloudflarestream.com/${this.inputId}/manifest/video.m3u8`;
      this._createEngine(url);
      if (!this._currentEngine) { this._enterFatal(); return; }
      if (this._currentEngine instanceof HlsEngine) {
        this._currentEngine.on('manifestParsed', () => { this.autoplay && this._attemptAutoplay(); });
      }
    });
  }

  // ── Engine ──

  prepareToPlay(streamUrl) { safe('prepareToPlay', () => { this.currentStreamUrl = streamUrl; this.loadStream(streamUrl); }); }

  loadStream(streamUrl) {
    safe('loadStream', () => {
      if (!HSVideoElement.isValidHlsUrl(streamUrl)) { this.debugError('Invalid HLS URL:', streamUrl); this._enterFatal(); return; }
      if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; }
      this._createEngine(streamUrl);
      if (!this._currentEngine) { this._enterFatal(); return; }
      this._currentEngine.on('error', (_e, d) => this._onEngineError(d));
    });
  }

  _createEngine(streamUrl) {
    safe('_createEngine', () => {
      this._currentEngine = createEngine({
        audioDriftFrameThreshold: this.audioDriftFrameThreshold || 4,
        debugLog: m => this.debugLog(m), debugError: m => this.debugError(m),
        updateStatus: s => this.statusOverlay.updateStatus(s), currentStatusType: this.currentStatusType,
        onAudioDrift: () => {}, onThroughputSample: bps => {
          safe('throughput', () => { this.throughputSamples.push(bps); if (this.throughputSamples.length > MAX_THROUGHPUT_SAMPLES) this.throughputSamples.shift(); });
        },
      });
      this._currentEngine.attachMedia(this.videoEl);
      this._currentEngine.loadSource(streamUrl);
    });
  }

  _onEngineError(d) {
    safe('onEngineError', () => {
      if (!d?.fatal) return;
      if (d.type === 'MEDIA_ERROR') {
        if (this.mediaErrorRecoveryAttempts < MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS) { this._currentEngine?.recoverMediaError(); this.mediaErrorRecoveryAttempts++; }
        else this._enterFatal();
      } else if (d.type === 'NETWORK_ERROR') {
        const recov = ['manifestLoadError','manifestLoadTimeOut','levelLoadError','levelLoadTimeOut','fragLoadError','fragLoadTimeOut'];
        if (this._networkErrorRecoveryAttempts < 2 && recov.includes(d.details)) {
          const b = this._networkErrorRecoveryAttempts === 0 ? 2000 : 5000;
          this._networkErrorRecoveryAttempts++;
          this.timers.setTimeout(() => { this._currentEngine?.startLoad(-1); }, b);
        } else this._enterFatal();
      } else this._enterFatal();
    });
  }

  // ── Prebuffer ──

  tryStartPlayback() {
    safe('tryStartPlayback', () => {
      if (!this.pendingPlayRequest) return;
      const v = this.videoEl; if (!v) return;
      const ready = v.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA;
      let bufferAhead = v?.buffered?.length ? Math.max(0, (v.buffered.end(0)||0) - v.currentTime) : 0;
      let segDur = 4, thresholdSecs = 10, headroom = 0;
      if (this._currentEngine instanceof HlsEngine) { const l = this._currentEngine.levels?.[this._currentEngine.currentLevel]; if (l) segDur = l.details?.targetduration || 4; }
      if (this.throughputSamples.length >= MIN_THROUGHPUT_SAMPLES) headroom = this.throughputSamples[this.throughputSamples.length-1] / (this._currentEngine?.levels?.[this._currentEngine.currentLevel]?.bitrate || 1);
      thresholdSecs = headroom >= 2 ? Math.max(10,3*segDur) : headroom >= 1.5 ? Math.max(12,3*segDur) : headroom >= 1.2 ? Math.max(15,3*segDur) : headroom >= 1.0 ? Math.max(20,3*segDur) : Math.max(28,3*segDur);
      const segs = segDur > 0 ? bufferAhead / segDur : 0;
      if (ready && bufferAhead >= thresholdSecs && segs >= 3 && (bufferAhead >= thresholdSecs || (this.prebufferStartTs > 0 && (Date.now() - this.prebufferStartTs >= PREBUFFER_TIMEOUT_MS))) && this.gestureUnlock?.isUnlocked) {
        this.pendingPlayRequest = false;
        v.play().catch(e => this.debugError('Play failed:', e));
      }
      this._updateDebugPanel({ bufferAhead, inProgress: ready, clicked: !!this.gestureUnlock?.isUnlocked });
    });
  }

  _probeManifestAndStart(url) {
    safe('probeManifestAndStart', () => {
      if (this._currentEngine === null || this.playerState === STATE.FATAL) return;
      this.probeAttempts++;
      if (this.probeAttempts > MANIFEST_PROBE_MAX_ATTEMPTS) { this._enterFatal(); return; }
      probeManifest(url, { maxAttempts: MANIFEST_PROBE_MAX_ATTEMPTS - this.probeAttempts + 1, initialDelayMs: this.probeAttempts===1?5000:0, intervalMs: 1500, abortSignal: this._probeAbortController?.signal })
        .then(() => { this.timers.setTimeout(() => { this._currentEngine?.startLoad(-1); this.pendingPlayRequest = true; this.prebufferStartTs = Date.now(); this.playerState = STATE.PREPARING; this.statusOverlay.updateStatus('preparing'); this.tryStartPlayback(); }, 2000); })
        .catch(() => {});
    });
  }

  // ── Fatal ──

  _enterFatal() {
    safe('enterFatal', () => {
      this._stopReconnectWatchdog();
      this.timers.clearTimeout(this.fatalTimer); this.fatalTimer = null;
      this.stopPolling();
      if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; }
      this.videoEl?.pause();
      this.setPoster('fatal', this.posterMgr.fatal);
      this.playerState = STATE.FATAL;
      this.statusOverlay.updateStatus('error');
    });
  }

  // ── Reconnect watchdog ──

  _startReconnectWatchdog() {
    safe('startReconnectWatchdog', () => {
      this._stopReconnectWatchdog();
      this._reconnectWatchdogActive = true;
      this._reconnectWatchdogTimer = this.timers.setInterval(() => {
        if (!this._reconnectWatchdogActive) return;
        const v = this.videoEl;
        if (!v?.buffered?.length) return;
        const ahead = Math.max(0, (v.buffered.end(0)||0) - v.currentTime);
        if (ahead < RECONNECT_WATCHDOG_BUFFER_THRESHOLD) {
          this.timers.clearTimeout(this.fatalTimer); this.fatalTimer = null;
          this.timers.setTimeout(() => this._enterFatal(), RECONNECT_WATCHDOG_FATAL_TTL);
        }
      }, RECONNECT_WATCHDOG_INTERVAL_MS);
    });
  }

  _stopReconnectWatchdog() {
    safe('stopReconnectWatchdog', () => {
      this._reconnectWatchdogActive = false;
      this.timers.clearInterval(this._reconnectWatchdogTimer); this._reconnectWatchdogTimer = null;
    });
  }

  // ── Handover ──

  _handover(newVideoUID) {
    safe('handover', () => {
      const newUrl = `https://customer-${this.posterMgr.customerCode}.cloudflarestream.com/${newVideoUID}/manifest/video.m3u8`;
      if (this._currentEngine instanceof NativeHlsEngine) {
        // Native HLS: no dual-engine. Reassign video.src.
        this.videoEl.src = newUrl;
        this.videoEl.load();
        this.currentStreamUrl = newUrl;
        this.latestLiveHlsUrl = newUrl;
        this.currentVideoUID = newVideoUID;
        return;
      }
      // HlsEngine: dual-engine handover.
      const oldEngine = this._currentEngine;
      const newEngine = createEngine({
        audioDriftFrameThreshold: this.audioDriftFrameThreshold || 4,
        debugLog: m => this.debugLog(m), debugError: m => this.debugError(m),
        updateStatus: s => this.statusOverlay.updateStatus(s), currentStatusType: this.currentStatusType,
        onAudioDrift: () => {}, onThroughputSample: () => {},
      });
      if (!newEngine) { this._enterFatal(); return; }
      newEngine.on('error', (_e, d) => this._onEngineError(d));
      newEngine.on('manifestParsed', () => {
        if (this._destroyed) { newEngine.destroy(); return; }
        // Swap engines.
        this._currentEngine = newEngine;
        this.currentStreamUrl = newUrl;
        this.latestLiveHlsUrl = newUrl;
        this.currentVideoUID = newVideoUID;
        this._drainingToIdle = false;
        this.pendingPlayRequest = true;
        this.prebufferStartTs = Date.now();
        this.playerState = STATE.PREPARING;
        this.statusOverlay.updateStatus('preparing');
        // Destroy old engine after 2s for audio cross-fade.
        this.timers.setTimeout(() => { oldEngine.destroy(); }, 2000);
      });
      newEngine.attachMedia(this.videoEl);
      newEngine.loadSource(newUrl);
    });
  }

  // ── Fatal timer (PREPARING → FATAL on timeout) ──

  _startFatalTimer() {
    safe('startFatalTimer', () => {
      if (this.fatalTimer) return; // already running
      this.fatalTimerStart = Date.now();
      this.fatalTimer = this.timers.setTimeout(() => {
        this.fatalTimer = null;
        if (this.playerState !== STATE.PLAYING && this.playerState !== STATE.FATAL) {
          this.debugError('Fatal timer fired — stuck in', this.playerState, 'for', FATAL_TIMEOUT_MS, 'ms');
          this._enterFatal();
        }
      }, FATAL_TIMEOUT_MS);
    });
  }

  _clearFatalTimer() {
    safe('clearFatalTimer', () => {
      if (this.fatalTimer) { this.timers.clearTimeout(this.fatalTimer); this.fatalTimer = null; }
      this.fatalTimerStart = null;
    });
  }

  // ── Helpers ──

  _ctx() {
    const v = this.videoEl; let ba = 0;
    if (v?.buffered?.length) ba = Math.max(0, (v.buffered.end(0)||0) - v.currentTime);
    return { hasPlayedOnce: !!this.hasPlayedOnce, userGestureUnlocked: !!this.gestureUnlock?.isUnlocked, bufferAhead: ba, hasBufferedContent: !!this.latestLiveHlsUrl, currentVideoUID: this.currentVideoUID };
  }

  _dispatchEffects(effects) { for (const fx of effects) safe('effects', () => {
    if (fx.type === 'loadHls') {
      const url = fx.payload.url || `https://customer-${this.posterMgr.customerCode}.cloudflarestream.com/${fx.payload.videoUID}/manifest/video.m3u8`;
      this.currentStreamUrl = url; this.latestLiveHlsUrl = url; this.loadStream(url);
      // Entering PREPARING — start fatal timer so the player can't sit forever
      // on a stuck PREPARING (manifest 404 outside the probe window, dead
      // network, etc.). The 'playing' event handler clears it on success.
      this._startFatalTimer();
    }
    if (fx.type === 'destroyHls') { if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; } this.videoEl?.pause(); this.currentStreamUrl = null; this._clearFatalTimer(); }
    if (fx.type === 'startPlayback') { this.videoEl?.play(); this._clearFatalTimer(); }
    if (fx.type === 'setPoster') { this.setPoster(fx.payload.which, fx.payload.url || this.posterMgr[fx.payload.which]); }
    if (fx.type === 'setErrorPoster') { if (this.videoEl) this.videoEl.poster = this.posterMgr.fatal; }
    if (fx.type === 'showStatus') { this.statusOverlay.updateStatus(fx.payload); }
    if (fx.type === 'startFatal') { this._enterFatal(); }
    if (fx.type === 'drainToIdle') { this._drainingToIdle = true; }
    if (fx.type === 'logError') {
      const msg = fx.payload && DEBUG_ERROR_MESSAGES[fx.payload.errorCode];
      if (msg) this.debugError(`[${fx.payload.errorCode}]`, msg);
    }
    if (fx.type === 'handover') { this._handover(fx.payload.newVideoUID); }
  }); }

  _updateDebugPanel(d) { safe('debugPanel', () => { if (this.debugPanelEl) this.debugPanel.update({ state: this.playerState, ...d }); }); }

  _attemptAutoplay() { safe('autoplay', () => { this.videoEl.play().catch(() => { safe('autoplay-retry', () => { this.videoEl.muted = true; this.videoEl.play(); }); }); }); }

  static isValidHlsUrl(url) { return typeof url === 'string' && HLS_ORIGIN_ALLOWLIST_REGEX.test(url); }
}

// ── Registration ──
customElements.define('hs-video', HSVideoElement);
