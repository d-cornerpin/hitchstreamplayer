// index.js — HitchStream Player v2 entry point
// Wires all modules, defines HSVideoElement class, registers <hs-video>.

import { transition } from './PlayerStateMachine.js';
import {
  STATE, FATAL_TIMEOUT_MS, MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS,
  MANIFEST_PROBE_MAX_ATTEMPTS, MIN_THROUGHPUT_SAMPLES, MAX_THROUGHPUT_SAMPLES, PREBUFFER_TIMEOUT_MS, GATE_CHECK_INTERVAL_MS,
  POSTER_CROSSFADE_MS, POSTER_FADEOUT_LEAD_SECONDS, POSTER_REVEAL_MIN_HEIGHT, POSTER_REVEAL_MAX_WAIT_MS, POSTER_MESSAGES, POSTER_MESSAGE_FADE_MS,
  FAST_RECOVERY_PREBUFFER_SECONDS, FAST_RECOVERY_FADE_MS, STALL_RECOVERY_MS, RECOVERY_BUFFER_FLOOR_SECONDS,
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

// ─── HSVideoElement class ───

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
    this.pendingPlayRequest = false; this.prebufferStartTs = 0; this._gateTimer = null; this._drainMonitorTimer = null; this._revealMonitorTimer = null; this._fastRecovery = false;
    this._stallWatchdogTimer = null; this._lastBufferEnd = 0; this._lastBufferEndTs = 0; this._recovering = false;
    this._currentMsgKey = null; this._currentMsgText = '';
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
    this.debugLog('connectedCallback');
    safe('connectedCallback', () => {
      this.ui = new UiController();
      this.ui.createShadowRoot(this);
      this.ui.showPlayButton(true);
      [this.videoEl, this.overlayEl, this.statusMessageEl, this.debugPanelEl] =
        [this.ui.videoEl, this.ui.overlayEl, this.ui.statusMessageEl, this.ui.debugPanelEl];
      // Top-left status text is retired — the under-logo poster message is now
      // the single status surface. Passing null makes StatusOverlay a safe
      // no-op (revert by restoring this.ui.statusMessageEl if you want it back).
      this.statusOverlay = new StatusOverlay(null, this.timers);
      this.debugPanel = new DebugPanel(this.ui.debugPanelEl);
      if (this.debugMode && this.debugPanelEl) this.debugPanelEl.style.display = 'block';
      this.posterMgr = new PosterManager();
      const a = {};
      ['poster-initial','poster-idle','poster-fatal'].forEach(k => {
        a[k] = this.getAttribute(k);
      });
      this.posterMgr.init(window?.HSPlayerConfig, a);
      // Surface the initial poster on the <video> element immediately so the
      // viewer sees the poster image before any state machine transition.
      // Note: we deliberately do NOT set the native <video poster> attribute.
      // The poster overlay (logo card / poster-img layer) is the real poster;
      // a native poster would bleed through behind it during crossfades when
      // the <video> momentarily has no frame (e.g. on engine rebuild).
      this.ui.setPosterImage(this.posterMgr.initial);
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
        this._updatePosterMessage();
      });
      // Set hasPlayedOnce the first time the video actually starts playing.
      // The state machine reads this to flip from initial poster → idle poster
      // (§10 preserved behavior #3).
      if (this.videoEl) {
        this.videoEl.addEventListener('playing', () => {
          this.hasPlayedOnce = true;
          // Hand the viewer native controls (pause, volume/mute, scrubber,
          // fullscreen) the moment playback actually starts — same as the
          // original player. Toggled back off whenever we return to the poster
          // (idle / fatal / teardown) so they don't show over the logo card.
          if (this.videoEl) this.videoEl.controls = true;
          this._clearFatalTimer();
          // Ground-truth PREPARING → PLAYING. The state machine's prebufferReady
          // path is never dispatched anywhere, so the actual 'playing' media
          // event is what promotes the player to PLAYING (otherwise it sticks
          // in PREPARING forever even while the video plays).
          if (this.playerState === STATE.PREPARING) {
            this.playerState = STATE.PLAYING;
            this.pendingPlayRequest = false;
            this._stopPrebufferGate();
            this._stopDrainToPoster();
            // Keep the poster + "Preparing…" up until the picture ramps up to a
            // sharp resolution, then crossfade in — hides the low-res warm-up.
            this._revealWhenSharp();
            this._startStallWatchdog();
            this._recovering = false;
          }
          this._updatePosterMessage();
        });
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
    safe('setPoster', () => { this.posterMgr.set(which, url); this.ui.setPosterImage(url); });
  }

  setApiInfo({ inputId, isLive, autoplay=true, posterInitialURL, posterIdleURL, posterFatalURL }) {
    this.debugLog('setApiInfo called with:', { inputId, isLive });
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
    this.debugLog('startPolling inputId:', this.inputId, 'mode:', this.playerMode);
    safe('startPolling', () => {
      if (!this.inputId) { this.debugLog('startPolling: no inputId, aborting'); return; }
      if (!this.hasPlayedOnce) this.statusOverlay.updateStatus('waiting');
      if (!this._livePoller) {
        this._livePoller = createLivePoller({
          inputId: this.inputId,
          endpoint: window.HSPlayerConfig.endpoints.liveState,
          onEvent: (e) => this._handlePollEvent(e),
          debugLog: (...m) => this.debugLog(...m),
          debugError: (...m) => this.debugError(...m),
        });
      }
      this._livePoller.start();
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
        this.debugLog('poll event:', { state, isLive, videoUID, hlsUrl: !!hlsUrl, errorCode, pollCount });
        this.pollCount = pollCount; this.streamCurrentlyLive = isLive;
        this._updateDebugPanel({ liveStatus: isLive, videoUID, pollCount: this.pollCount, error_code: errorCode, source });
        if (isLive && videoUID) {
          const url = (hlsUrl && HSVideoElement.isValidHlsUrl(hlsUrl)) ? hlsUrl
            : `https://customer-${this.posterMgr.customerCode}.cloudflarestream.com/${videoUID}/manifest/video.m3u8`;
          this.latestLiveHlsUrl = url; this.ingestFalseCount = 0; this.currentVideoUID = videoUID;
          this._stopReconnectWatchdog();
          if (this._drainingToIdle) {
            this._drainingToIdle = false;
            this._stopDrainToPoster();
            const vEl = this.videoEl;
            const ahead = vEl?.buffered?.length ? Math.max(0, (vEl.buffered.end(0) || 0) - vEl.currentTime) : 0;
            if (this._currentEngine && ahead > 2) {
              // Stream returned while we still have buffer playing — keep the
              // engine and buffer, just stop showing the poster. Hls.js resumes
              // loading the new segments on its own; no rebuild, no re-prebuffer.
              this.ui.fadePoster(0, FAST_RECOVERY_FADE_MS);
            } else {
              // Buffer ran dry / engine stalled — rebuild fast.
              this.ui.showPosterInstant(true);
              this._rebuildLiveStream(url);
            }
          } else {
            const r = transition({ currentState: this.playerState, event: { type:'poll', payload:{ state:'live', videoUID, hlsUrl:url, source } }, context: this._ctx() });
            this.playerState = r.nextState; this._dispatchEffects(r.sideEffects);
          }
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
        // Settled to idle (stream down) → cancel any in-flight recovery probe so
        // it can't later cap out and FATAL.
        if (this.playerState === STATE.IDLE) this._abortProbe();
        this._updatePosterMessage();
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
      // Autoplay on manifestParsed for BOTH engines. NativeHlsEngine maps
      // 'manifestParsed' → the video element's 'loadedmetadata', so Safari/iOS
      // VOD autoplays too (this was previously gated to HlsEngine and never
      // fired on native, leaving iPhone/Safari VOD dead).
      this._currentEngine.on('manifestParsed', () => { this.autoplay && this._attemptAutoplay(); });
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

  // Rebuild the live stream from scratch on the current edge. Used when a
  // stream returns after stopping: the old engine has stalled across the gap,
  // so we destroy it and re-acquire (probe → load → prebuffer → reveal).
  _rebuildLiveStream(url) {
    safe('rebuildLiveStream', () => {
      if (!HSVideoElement.isValidHlsUrl(url)) { this._enterFatal(); return; }
      this._stopPrebufferGate();
      this._stopStallWatchdog();
      this.currentStreamUrl = url; this.latestLiveHlsUrl = url;
      this.probeAttempts = 0; this._networkErrorRecoveryAttempts = 0; this.mediaErrorRecoveryAttempts = 0;
      this._fastRecovery = true; // mid-stream "one sec" recovery → fast path
      this.playerState = STATE.PREPARING;
      this._probeManifestAndStart(url);
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
        else this._failOrIdle();
      } else if (d.type === 'NETWORK_ERROR') {
        const recov = ['manifestLoadError','manifestLoadTimeOut','levelLoadError','levelLoadTimeOut','fragLoadError','fragLoadTimeOut'];
        if (this._networkErrorRecoveryAttempts < 2 && recov.includes(d.details)) {
          const b = this._networkErrorRecoveryAttempts === 0 ? 2000 : 5000;
          this._networkErrorRecoveryAttempts++;
          this.timers.setTimeout(() => { this._currentEngine?.startLoad(-1); }, b);
        } else this._failOrIdle();
      } else this._failOrIdle();
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
      const prebufferTimedOut = this.prebufferStartTs > 0 && (Date.now() - this.prebufferStartTs >= PREBUFFER_TIMEOUT_MS);
      // After the stream has played once, recover fast: a small buffer is enough
      // to resume; Hls.js keeps filling toward the full buffer in the background.
      const minBuf = this._fastRecovery ? FAST_RECOVERY_PREBUFFER_SECONDS : thresholdSecs;
      const minSegs = this._fastRecovery ? 1 : 3;
      // Stream is confirmed playable once we have enough buffer (or the prebuffer
      // timeout elapsed with at least HAVE_FUTURE_DATA).
      const bufferReady = ready && ((bufferAhead >= minBuf && segs >= minSegs) || prebufferTimedOut);
      // Clear the stuck-in-PREPARING fatal timer as soon as the stream is
      // confirmed playable — even while we wait on the user's gesture, so a
      // viewer who is slow to click doesn't trip the fatal timeout.
      if (bufferReady) this._clearFatalTimer();
      if (bufferReady && this.gestureUnlock?.isUnlocked) {
        this.pendingPlayRequest = false;
        this._stopPrebufferGate();
        this._playWithFallback(v);
      }
      this._updateDebugPanel({ bufferAhead, inProgress: ready, clicked: !!this.gestureUnlock?.isUnlocked });
    });
  }

  // Periodic prebuffer-gate tick. tryStartPlayback() only acts once the buffer
  // is ready AND the user has clicked, so it must be polled while we wait. The
  // gate self-stops when pendingPlayRequest clears (playback started or aborted).
  _startPrebufferGate() {
    safe('startPrebufferGate', () => {
      this._stopPrebufferGate();
      this._gateTimer = this.timers.setInterval(() => {
        if (!this.pendingPlayRequest) { this._stopPrebufferGate(); return; }
        this.tryStartPlayback();
      }, GATE_CHECK_INTERVAL_MS);
    });
  }

  _stopPrebufferGate() {
    safe('stopPrebufferGate', () => {
      if (this._gateTimer) { this.timers.clearInterval(this._gateTimer); this._gateTimer = null; }
    });
  }

  // ── Poster crossfade on stream stop ──
  // When a live stream stops the player keeps draining its buffer. Once only
  // POSTER_FADEOUT_LEAD_SECONDS of buffer remain, crossfade back to the poster
  // so it fully covers the video before playback runs dry (no frozen frame).
  _startDrainToPoster() {
    safe('startDrainToPoster', () => {
      this._stopRevealMonitor();
      if (this._drainMonitorTimer) return;
      this._drainMonitorTimer = this.timers.setInterval(() => {
        const v = this.videoEl;
        const ahead = (v?.buffered?.length) ? Math.max(0, (v.buffered.end(0) || 0) - v.currentTime) : 0;
        if (ahead <= POSTER_FADEOUT_LEAD_SECONDS) {
          this.ui.fadePoster(1, POSTER_CROSSFADE_MS);
          this._stopDrainToPoster();
        }
      }, 500);
    });
  }

  _stopDrainToPoster() {
    safe('stopDrainToPoster', () => {
      if (this._drainMonitorTimer) { this.timers.clearInterval(this._drainMonitorTimer); this._drainMonitorTimer = null; }
    });
  }

  // Hold the poster after playback begins until the picture is sharp (the ABR
  // ramp-up means the first couple of segments are low-res), then crossfade.
  _revealWhenSharp() {
    safe('revealWhenSharp', () => {
      this._stopRevealMonitor();
      const start = Date.now();
      // On a reconnect (already played once) get back fast: short crossfade and
      // don't wait for the HD ramp. The first-ever play still waits for sharp.
      const fast = this._fastRecovery;
      const fadeMs = fast ? FAST_RECOVERY_FADE_MS : POSTER_CROSSFADE_MS;
      const reveal = () => {
        this._stopRevealMonitor();
        this.ui.fadePoster(0, fadeMs);
        // Fade the under-logo message out a touch faster than the poster, so it
        // dissolves gracefully as the video appears (instead of vanishing).
        this.ui.fadePosterMessage(0, POSTER_MESSAGE_FADE_MS);
        this.statusOverlay.updateStatus('live');
      };
      const check = () => {
        if (this.playerState !== STATE.PLAYING) { this._stopRevealMonitor(); return; }
        const h = this.videoEl?.videoHeight || 0;
        if (fast || h >= POSTER_REVEAL_MIN_HEIGHT || (Date.now() - start) >= POSTER_REVEAL_MAX_WAIT_MS) reveal();
      };
      this._revealMonitorTimer = this.timers.setInterval(check, 250);
      check();
    });
  }

  _stopRevealMonitor() {
    safe('stopRevealMonitor', () => {
      if (this._revealMonitorTimer) { this.timers.clearInterval(this._revealMonitorTimer); this._revealMonitorTimer = null; }
    });
  }

  // Stall watchdog: independent of the slow status poll, it watches the actual
  // <video>. If playback stops advancing with an empty buffer while the stream
  // is still live, the engine has stalled on a dead edge — rebuild fast.
  _startStallWatchdog() {
    safe('startStallWatchdog', () => {
      this._stopStallWatchdog();
      const v0 = this.videoEl;
      this._lastBufferEnd = (v0?.buffered?.length) ? v0.buffered.end(v0.buffered.length - 1) : 0;
      this._lastBufferEndTs = Date.now();
      this._stallWatchdogTimer = this.timers.setInterval(() => {
        const v = this.videoEl;
        if (!v || this.playerState !== STATE.PLAYING) return;
        const bEnd = v.buffered?.length ? v.buffered.end(v.buffered.length - 1) : 0;
        if (bEnd > this._lastBufferEnd + 0.3) {
          // The feed is still delivering new data — all good.
          this._lastBufferEnd = bEnd; this._lastBufferEndTs = Date.now();
          return;
        }
        // The feed's leading edge has stopped advancing (stalled). Keep playing
        // the buffer we already have — show as much of the stream as possible —
        // and only step in once it's nearly drained, covering the short gap with
        // a "one sec" card while we rebuild on the live edge.
        const ahead = Math.max(0, bEnd - v.currentTime);
        if (ahead <= RECOVERY_BUFFER_FLOOR_SECONDS
            && (Date.now() - this._lastBufferEndTs) >= STALL_RECOVERY_MS
            && this.streamCurrentlyLive && this.latestLiveHlsUrl) {
          this.debugLog('Stall watchdog: buffer nearly drained on a dead feed — recovering');
          this._recovering = true;
          this.ui.fadePoster(1, FAST_RECOVERY_FADE_MS);
          this._rebuildLiveStream(this.latestLiveHlsUrl);
          this._updatePosterMessage();
        }
      }, 1000);
    });
  }

  _stopStallWatchdog() {
    safe('stopStallWatchdog', () => {
      if (this._stallWatchdogTimer) { this.timers.clearInterval(this._stallWatchdogTimer); this._stallWatchdogTimer = null; }
    });
  }

  // Under-logo poster message. Shown only after the play button is pressed and
  // only until the stream has played once — after that we can't know why a
  // stream stopped, so we show nothing. (hasPlayedOnce resets on page refresh.)
  /** Pick a random line from a POSTER_MESSAGES pool. */
  _pickMessage(key) {
    const arr = POSTER_MESSAGES[key];
    if (!Array.isArray(arr) || arr.length === 0) return '';
    return arr[Math.floor(Math.random() * arr.length)];
  }

  _updatePosterMessage() {
    safe('updatePosterMessage', () => {
      // Settling back to idle (e.g. stream truly ended) clears recovery state.
      if (this.playerState === STATE.IDLE) this._recovering = false;
      // PLAYING: the reveal crossfade owns the fade-out. FATAL: _enterFatal owns
      // its own message. Don't disturb either here.
      if (this.playerState === STATE.PLAYING || this.playerState === STATE.FATAL) return;
      let key = null;
      if (this._recovering) {
        // Recovering from a mid-stream stall — shown even though hasPlayedOnce.
        key = 'recovering';
      } else if (this.gestureUnlock?.isUnlocked) {
        // PREPARING means a stream is coming up / re-buffering (including the
        // deep re-buffer after a long outage) → always say "starting shortly".
        // The IDLE "waiting" message only shows before the first-ever play;
        // after that, an idle stream is just the bare logo (no cause claimed).
        if (this.playerState === STATE.PREPARING) key = 'preparing';
        else if (this.playerState === STATE.IDLE && !this.hasPlayedOnce) key = 'idle';
      }
      // Pick a fresh random line only when the message key changes, so it holds
      // steady while we're in a given state (no re-roll on every poll).
      if (key !== this._currentMsgKey) {
        this._currentMsgKey = key;
        this._currentMsgText = key ? this._pickMessage(key) : '';
      }
      this.ui.setPosterMessage(this._currentMsgText);
      this.ui.fadePosterMessage(this._currentMsgText ? 1 : 0, 0);
    });
  }

  _probeManifestAndStart(url) {
    safe('probeManifestAndStart', () => {
      if (this.playerState === STATE.FATAL) return;
      if (!HSVideoElement.isValidHlsUrl(url)) { this.debugError('Invalid HLS URL:', url); this._enterFatal(); return; }
      this.probeAttempts++;
      if (this.probeAttempts > MANIFEST_PROBE_MAX_ATTEMPTS) { this._failOrIdle(); return; }
      if (!this._probeAbortController) this._probeAbortController = new AbortController();
      // Probe the manifest FIRST. The engine is not created until Cloudflare is
      // actually serving a playlist — so a not-yet-live input (HTTP 204) leaves
      // the player waiting in PREPARING instead of handing an empty manifest to
      // Hls.js and instant-fataling. (Preserve-list: "CORS pre-probe before
      // loadSource".)
      probeManifest(url, { maxAttempts: MANIFEST_PROBE_MAX_ATTEMPTS - this.probeAttempts + 1, initialDelayMs: 0, intervalMs: 1500, abortSignal: this._probeAbortController?.signal })
        .then(() => { this.timers.setTimeout(() => {
          if (this._destroyed || this.playerState === STATE.FATAL || this.playerState === STATE.IDLE) return;
          // Manifest confirmed ready — attach the engine, begin fragment
          // loading (autoStartLoad is false), and run the prebuffer gate.
          this.loadStream(url);
          if (!this._currentEngine) return;
          this._currentEngine.startLoad(-1);
          this.pendingPlayRequest = true;
          this.prebufferStartTs = Date.now();
          this.playerState = STATE.PREPARING;
          this.statusOverlay.updateStatus('preparing');
          this._startPrebufferGate();
          this.tryStartPlayback();
          // Manifest is loading — now guard against getting stuck in PREPARING
          // (fragments never buffer). Waiting for the manifest to first appear
          // is handled by the probe's own cap, not this timer.
          this._startFatalTimer();
        }, 300); })
        .catch((err) => {
          if (this._destroyed) return;
          if (err && /abort/i.test(err.message || '')) return;
          // Manifest never came up in the probe window. In live operation this
          // just means the stream is down → idle logo + keep polling, not FATAL.
          this._failOrIdle();
        });
    });
  }

  // ── Fatal ──

  _enterFatal() {
    safe('enterFatal', () => {
      this._stopReconnectWatchdog();
      this._stopPrebufferGate();
      this.timers.clearTimeout(this.fatalTimer); this.fatalTimer = null;
      this.stopPolling();
      if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; }
      this.videoEl?.pause();
      this._stopDrainToPoster();
      this._stopRevealMonitor();
      this._stopStallWatchdog();
      this._recovering = false;
      if (this.videoEl) this.videoEl.controls = false;
      this.setPoster('fatal', this.posterMgr.fatal);
      this.ui.showPosterInstant(true);
      // Fatal uses the same logo card with a random "refresh" line — dots off,
      // since it's an instruction, not a "working on it" state.
      this.ui.setPosterMessage(this._pickMessage('fatal'), false);
      this.ui.fadePosterMessage(1, 0);
      this.playerState = STATE.FATAL;
      this.statusOverlay.updateStatus('error');
    });
  }

  // FATAL is terminal ("please refresh"). During live operation (already played
  // once) most failures are just the stream being down — fall back to the idle
  // logo and keep polling instead, so we re-acquire when it returns.
  _failOrIdle() {
    if (this.hasPlayedOnce) this._recoverToIdle();
    else this._enterFatal();
  }

  _recoverToIdle() {
    safe('recoverToIdle', () => {
      this._stopReconnectWatchdog();
      this._stopPrebufferGate();
      this._stopStallWatchdog();
      this._stopRevealMonitor();
      this._stopDrainToPoster();
      this._abortProbe();
      this._clearFatalTimer();
      this._recovering = false;
      this.pendingPlayRequest = false;
      if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; }
      this.videoEl?.pause();
      if (this.videoEl) this.videoEl.controls = false;
      this.playerState = STATE.IDLE;
      this.setPoster('idle', this.posterMgr.idle);
      this.ui.showPosterInstant(true);
      this._updatePosterMessage();
    });
  }

  _abortProbe() {
    safe('abortProbe', () => {
      if (this._probeAbortController) { this._probeAbortController.abort(); this._probeAbortController = null; }
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
          this.timers.setTimeout(() => this._failOrIdle(), RECONNECT_WATCHDOG_FATAL_TTL);
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
        // Begin fragment loading on the swapped-in engine and run the gate
        // (autoStartLoad is false, so the new engine needs an explicit start).
        this._currentEngine.startLoad(-1);
        this._startPrebufferGate();
        this.tryStartPlayback();
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
          this._failOrIdle();
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
      this.currentStreamUrl = url; this.latestLiveHlsUrl = url;
      this._drainingToIdle = false; this._stopDrainToPoster(); this._stopRevealMonitor(); this.ui.showPosterInstant(true);
      this.probeAttempts = 0; this._networkErrorRecoveryAttempts = 0; this.mediaErrorRecoveryAttempts = 0;
      // A (re)start from idle always uses the full, deep prebuffer — this covers
      // both the first play and coming back from a long outage. Only the
      // mid-stream "one sec" recovery (_rebuildLiveStream) uses the fast path.
      this._fastRecovery = false;
      // Probe the manifest first, then attach the engine + run the prebuffer
      // gate (see _probeManifestAndStart). The engine is NOT created until the
      // manifest is actually serving a playlist, so an unready / 204 manifest
      // leaves the player waiting in PREPARING rather than instant-fataling.
      this._probeManifestAndStart(url);
    }
    if (fx.type === 'destroyHls') { this._drainingToIdle = false; this._recovering = false; this._stopDrainToPoster(); this._stopRevealMonitor(); this._stopStallWatchdog(); if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; } this.videoEl?.pause(); if (this.videoEl) this.videoEl.controls = false; this.currentStreamUrl = null; this._clearFatalTimer(); this.ui.showPosterInstant(true); }
    if (fx.type === 'startPlayback') { this.videoEl?.play(); this._clearFatalTimer(); }
    if (fx.type === 'setPoster') { this.setPoster(fx.payload.which, fx.payload.url || this.posterMgr[fx.payload.which]); }
    if (fx.type === 'setErrorPoster') { if (this.videoEl) this.videoEl.poster = this.posterMgr.fatal; }
    if (fx.type === 'showStatus') { this.statusOverlay.updateStatus(fx.payload); }
    if (fx.type === 'startFatal') { this._enterFatal(); }
    if (fx.type === 'drainToIdle') { this._drainingToIdle = true; this.ui.setPosterImage(this.posterMgr.idle); this._startDrainToPoster(); }
    if (fx.type === 'logError') {
      const msg = fx.payload && DEBUG_ERROR_MESSAGES[fx.payload.errorCode];
      if (msg) this.debugError(`[${fx.payload.errorCode}]`, msg);
    }
    if (fx.type === 'handover') { this._handover(fx.payload.newVideoUID); }
  }); }

  _updateDebugPanel(d) { safe('debugPanel', () => { if (this.debugPanelEl) this.debugPanel.update({ state: this.playerState, ...d }); }); }

  _attemptAutoplay() { safe('autoplay', () => { this._playWithFallback(this.videoEl); }); }

  // Start playback while surviving iOS autoplay rules. An unmuted play() that
  // fires well after the tap (buffer-gated, or VOD autoplay with no tap) is
  // routinely blocked on iOS. Fall back to MUTED — which iOS always allows for
  // an inline (playsinline) video — so the stream still starts instead of
  // freezing on the poster, then auto-unmute on the viewer's next gesture.
  // Native controls (enabled on 'playing') also expose an unmute control.
  _playWithFallback(v) {
    safe('playWithFallback', () => {
      if (!v) return;
      const p = v.play();
      if (p && p.catch) p.catch(() => {
        safe('play-muted', () => {
          v.muted = true;
          const p2 = v.play();
          if (p2 && p2.then) p2.then(() => this._armAutoUnmute()).catch(e => this.debugError('Muted play failed:', e));
        });
      });
    });
  }

  // After a forced-muted start, restore sound on the viewer's next tap/click.
  _armAutoUnmute() {
    safe('armAutoUnmute', () => {
      if (this._autoUnmuteArmed || this._destroyed) return;
      this._autoUnmuteArmed = true;
      const unmute = () => safe('autoUnmute', () => {
        if (this.videoEl) this.videoEl.muted = false;
        this._autoUnmuteArmed = false;
      });
      document.addEventListener('click', unmute, { once: true });
      document.addEventListener('touchstart', unmute, { once: true, passive: true });
    });
  }

  static isValidHlsUrl(url) { return typeof url === 'string' && HLS_ORIGIN_ALLOWLIST_REGEX.test(url); }
}

// ── Registration ──
customElements.define('hs-video', HSVideoElement);
