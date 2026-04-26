// Custom video player web component using Hls.js (live and VOD)

// Safe-op helper: run fn, catch errors silently, log once if debug mode is on.
// Replace the oldest pattern of try { ... } catch (_) {} that hides real bugs.
if (typeof window.__hsVideoDebug__ !== 'undefined' && window.__hsVideoDebug__) {
    // When debug is enabled, log the error.
    window._safe = fn => { try { return fn(); } catch (e) { console.warn('[hs-video] safe op failed:', e); } };
} else {
    window._safe = fn => { try { return fn(); } catch (_) {} };
}

// Poster image URLs — overridden by URL params or page-level playerDefaults
let POSTER_INITIAL_URL = 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Initial_Default.png';
let POSTER_IDLE_URL = 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Idle_Default.png';
let POSTER_FATAL_URL = 'https://hitchstream.com/wp-content/uploads/2025/09/Poster_fatal_2.png';
// Cloudflare customer identifier for VOD host — overridden by URL param customerID or page-level playerDefaults
let CLOUDFLARE_CUSTOMER_ID = 'juu1r5es4cbffqjf';
// Dynamic prebuffer controls
const MIN_PREBUFFER_SECONDS = 10;           // minimum seconds before considering start
const MIN_PREBUFFER_SEGMENTS = 3;           // minimum segments before considering start
const MIN_THROUGHPUT_SAMPLES = 3;           // minimum frag samples to trust throughput
const PREBUFFER_TIMEOUT_MS = 60000;         // start anyway after this wait
const MAX_THROUGHPUT_SAMPLES = 10;          // cap for stored throughput samples

// HLS origin allowlist regex (contract §4.3). Only Cloudflare Stream URLs match.
const HLS_ORIGIN_ALLOWLIST_REGEX =
    /^https:\/\/customer-[a-z0-9]{12,20}\.cloudflarestream\.com\/[A-Za-z0-9]+\/manifest\/video\.m3u8(\?.*)?$/;

// Audio/Video sync threshold.  If the difference between audio and video
// timestamps exceeds this number of video frames, the player will
// display an "Audio sync issue" message in the status overlay.  Adjust
// this constant to tune drift tolerance (e.g. 4 frames at 30fps ≈ 0.133s).
const AUDIO_DRIFT_FRAMES_THRESHOLD = 4;

// Removed audio drift recovery constants.  The player now simply
// displays a sync-issue message whenever audio/video drift exceeds
// AUDIO_DRIFT_FRAMES_THRESHOLD and clears it once drift is within
// tolerance.  No automatic recovery attempts are performed.
// Timers and state
const POLL_INITIAL_DELAY_MS = 3000;
const LATENCY_LOG_INTERVAL_MS = 15000;
const GATE_CHECK_INTERVAL_MS = 1000;
// State machine — imports from pure transition module (A2.5).
import { transition } from './HSPlayer/PlayerStateMachine.js';

// A3: Extracted modules
import { createLivePoller } from './HSPlayer/LivePoller.js';
import { computeGateContext, shouldStartPlayback } from './HSPlayer/PrebufferGate.js';
import { probeManifest } from './HSPlayer/ManifestProbe.js';
import { createEngine, isHlsSupported } from './HSPlayer/EngineFactory.js';
import { HlsEngine } from './HSPlayer/HlsEngine.js';
import { NativeHlsEngine } from './HSPlayer/NativeHlsEngine.js';

// LivePoller integration
let _livePoller = null;
// Engine tracking
let _currentEngine = null;
// Manifest probe AbortController
let _probeAbortController = null;

// Player state constants.  In addition to IDLE and PLAYING, PREPARING represents
// the period after the manifest is parsed but before playback actually starts.
// FATAL indicates an unrecoverable error has occurred and the player is
// displaying the fatal poster. When in FATAL state the player will not
// attempt to recover automatically; the viewer should refresh the page.
const STATE = {
    IDLE: 'IDLE',
    PLAYING: 'PLAYING',
    PREPARING: 'PREPARING',
    FATAL: 'FATAL'
};

// Maximum time (ms) to wait for playback after starting preparation before
// declaring a fatal condition. If the lifecycle endpoint continues to report
// the stream as live but playback still hasn't begun within this window, the
// player will show the fatal poster and enter FATAL state.
    // Time to wait for playback to start after a play attempt before declaring
    // a fatal error.  The timer does not begin until after the prebuffer gate
    // requests play, so this can be longer than typical startup.  A value of
    // 45000ms (45s) gives the browser and network ample time to begin
    // rendering before showing the fatal poster.
    const FATAL_TIMEOUT_MS = 45000;

// Maximum number of media error recovery attempts before giving up and
// entering the fatal state. Media errors include decode or codec failures
// that might be recoverable. If this count is exceeded without successful
// playback, the player displays the fatal poster.
const MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS = 3;

// ─── State machine helpers (A2.5) ───

/** Build context object for the state machine. */
function _buildContext(el) {
    const video = el.videoEl || el.shadowRoot?.querySelector('video');
    let hasBufferedContent = false;
    let bufferAhead = 0;
    try {
        if (video?.buffered?.length) {
            for (let i = 0; i < video.buffered.length; i++) {
                if (video.buffered.end(i) > video.currentTime + 0.5) { hasBufferedContent = true; break; }
            }
            bufferAhead = Math.max(0, (video.buffered.end(0) || 0) - video.currentTime);
        }
    } catch (_) {}
    return {
        hasPlayedOnce: !!el.hasPlayedOnce,
        userGestureUnlocked: !!el.userGestureUnlocked,
        bufferAhead,
        hasBufferedContent,
        currentVideoUID: el._currentVideoUID || null,
    };
}

/** Dispatch side-effects returned by the state machine. */
function _dispatchEffects(el, effects) {
    for (const effect of effects) {
        switch (effect.type) {
            case 'loadHls':
                el.currentStreamUrl = effect.payload.url;
                el.latestLiveHlsUrl = effect.payload.url;
                el.ingestFalseCount = 0;
                el.loadStream(effect.payload.url);
                break;
            case 'destroyHls':
                if (el._currentEngine) { try { el._currentEngine.destroy(); } catch (_) {} el._currentEngine = null; }
                { const video = el.videoEl || el.shadowRoot?.querySelector('video'); try { video?.pause(); } catch(_) {} }
                el.currentStreamUrl = null;
                el.latestLiveHlsUrl = null;
                break;
            case 'rebuildHls':
                el.currentStreamUrl = effect.payload.videoUID;
                if (el._currentEngine) { try { el._currentEngine.destroy(); } catch (_) {} el._currentEngine = null; }
                el.loadStream(el.latestLiveHlsUrl);
                break;
            case 'startPlayback':
                { const video = el.videoEl || el.shadowRoot?.querySelector('video'); try { video?.play(); } catch(_) {} }
                break;
            case 'setPoster':
                {
                    const posterMap = { initial: 'poster-initial', idle: 'poster-idle', fatal: 'poster-fatal' };
                    const attr = posterMap[effect.payload.which] || 'poster-initial';
                    const url = el.getAttribute(attr) || (effect.payload.which === 'initial' ? POSTER_INITIAL_URL : (effect.payload.which === 'idle' ? POSTER_IDLE_URL : POSTER_FATAL_URL));
                    el.setPoster(url, effect.payload.which);
                }
                break;
            case 'showStatus':
                el.updateStatus(effect.payload);
                break;
            case 'handover':
                el._currentVideoUID = effect.payload.newVideoUID;
                if (el._currentEngine) {
                    el._currentEngine.destroy();
                    el._currentEngine = null;
                    el.loadStream(el.latestLiveHlsUrl);
                }
                break;
            case 'drainToIdle':
                {
                    const video = el.videoEl || el.shadowRoot?.querySelector('video');
                    el._drainingToIdle = true;
                    el.updateStatus('paused');
                    try {
                        video?.addEventListener('ended', () => {
                            if (!el._drainingToIdle) return;
                            el._drainingToIdle = false;
                            el._executeIdleTeardown();
                        }, { once: true });
                    } catch (_) {}
                }
                break;
            case 'logError':
                el.debugError('Poll error:', effect.payload.errorCode, 'source:', effect.payload.source);
                break;
            case 'startFatal':
                el.enterFatalState();
                break;
        }
    }
}

class HSVideoElement extends HTMLElement {
    // Set up component state and optional debug mode
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this.playerState = STATE.IDLE;
        this.pollingInterval = null;
        this.debugMode = false;
        // deprecated: recoveryAttempts was replaced by mediaErrorRecoveryAttempts
        this.videoEl = null;
        this.playButtonEl = null;
        this.overlayEl = null;
        this._onPlaying = null;
        this._onClickPlayButton = null;
        this.debugPanelEl = null;
        this.debugOverlayData = {};
        this.userGestureUnlocked = false; // set true after fake play click
        this.pendingPlayRequest = false;  // request to start playback when ready
        this.bufferGateInterval = null;   // interval ID for prebuffer gating
        this.prebufferStartTs = 0;        // timestamp when gating began
        this.throughputSamples = [];      // recent throughput samples (bps)
        this.probeAttempts = 0; // manifest probe attempt counter
        this.currentStreamUrl = null;      // URL currently loaded into Hls.js
        this.ingestFalseCount = 0;         // consecutive polls reporting ingest not live
        this.hasPlayedOnce = false;        // flips true after the first real playback
        // Initialize client-side polling counters for lifecycle endpoint
        this.pollCount = 0;
        this.liveStatus = null;
        this.videoUID = null;

        this._networkErrorRecoveryAttempts = 0; // A1.15 — post-network-error recovery budget
        // Timer ID for fatal condition detection. If non-null, a countdown is
        // underway to trigger a fatal state if playback does not begin in
        // a reasonable amount of time after manifest attachment. Cleared on
        // successful playback or when transitioning to IDLE.
        this.fatalTimer = null;

        // Count of how many times we've attempted to recover from a fatal
        // media error. When this reaches MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS
        // without a successful recovery, the player enters the fatal state.
        this.mediaErrorRecoveryAttempts = 0;
        
        // ------------------------------------------------------------------------------------------------
        // Status message overlay variables
        //
        // A tiny status indicator appears in the top-left corner of the
        // player to communicate live-stream status to the viewer.  The
        // `currentStatusType` tracks which message is currently shown to
        // avoid unnecessary re-rendering.  `statusEllipsisInterval`
        // drives the animated ellipsis for "waiting" and "preparing"
        // messages.  `statusFadeTimeout` and `statusHideTimeout` control
        // the fade-out timing for transient messages like "Live" or
        // "Paused/Ended".
        this.currentStatusType = null;
        this.statusMessageEl = null;
        this.statusEllipsisInterval = null;
        this.statusFadeTimeout = null;
        this.statusHideTimeout = null;

        // Track buffer level at the start of a fatal countdown.  When
        // buffering progresses (i.e. bufferAhead increases by a
        // threshold), the fatal timer is reset so slow networks do not
        // prematurely trigger a fatal state.  fatalBufferLevel is set
        // when the fatal timer starts, and fatalTimerStart records
        // when it was initiated.
        this.fatalBufferLevel = null;
        this.fatalTimerStart = null;

        // Latest live HLS manifest URL reported by the lifecycle API.  When
        // the stream is live but the viewer has not yet clicked, we
        // record this URL and defer loading until the user interacts.
        this.latestLiveHlsUrl = null;

        // Disambiguate player mode (live-mode vs VOD-mode) from stream
        // live state.  this.playerMode is set once by setApiInfo and never
        // changes — it tells the player HOW to behave (polling with prebuffer
        // vs direct video load).  this.streamCurrentlyLive is set by the poll
        // callback and reflects the current Cloudflare state.
        this.playerMode = null;
        this.streamCurrentlyLive = false;
        this._listenerController = null; // A1.6 — all listeners use its signal
        this._drainingToIdle = false; // A1.12 — keep playing while buffer drains on idle transition
        this._destroyed = false;

        // Track the most recent audio and video Presentation Time Stamps (PTS)
        // observed during fragment parsing.  These values are updated when
        // FRAG_PARSING_DATA events fire for audio and video samples.  They
        // are used to estimate the drift between audio and video streams.
        this.lastAudioPts = null;
        this.lastVideoPts = null;
        // Use the configured global threshold for audio/video drift.
        // See AUDIO_DRIFT_FRAMES_THRESHOLD constant defined above.
        this.audioDriftFrameThreshold = AUDIO_DRIFT_FRAMES_THRESHOLD;

        // Track whether an audio sync issue is currently being signalled.
        // When drift exceeds the configured frame threshold during playback,
        // updateStatus('syncIssue') will be called.  When drift falls within
        // tolerance, the message is cleared by calling updateStatus('none').
        this.audioSyncIssueActive = false;
        try {
            if (typeof window !== 'undefined' && window.location && window.location.search) {
                const params = new URLSearchParams(window.location.search);
                const dbg = (params.get('debug') || '').toLowerCase();
                if (['1', 'true', 'yes'].includes(dbg)) this.debugMode = true;
            }
        } catch (e) {}
    }

    // Debug log helpers (only prints when debug is on)
    debugLog(...messages) { if (this.debugMode) console.log(...messages); }
    debugError(...errors) { if (this.debugMode) console.error(...errors); }

    // Validate an HLS URL against the §4.3 origin allowlist.
    static isValidHlsUrl(url) {
        if (typeof url !== 'string') return false;
        return HLS_ORIGIN_ALLOWLIST_REGEX.test(url);
    }

    // Watch for poster attribute changes
    static get observedAttributes() { return ['poster-initial', 'poster-idle', 'poster-fatal']; }
    attributeChangedCallback(name, oldValue, newValue) {
        this.debugLog(`Attribute ${name} changed from ${oldValue} to ${newValue}`);
        const typeMap = { 'poster-initial': 'initial', 'poster-idle': 'idle', 'poster-fatal': 'fatal' };
        const type = typeMap[name];
        if (type && newValue) this.setPoster(newValue, type);
    }

    // Receive config from the page and start live or VOD
    setApiInfo({
        inputId,
        isLive,
        autoplay = true,
        posterInitialURL,
        posterIdleURL,
        customerID,
        posterFatalURL
    }) {
        if (this._destroyed) return;

        // Fail loud: assert window.HSPlayerConfig exists (B21 fix).
        if (typeof window === 'undefined' || !window.HSPlayerConfig || typeof window.HSPlayerConfig !== 'object') {
            this.debugError('FATAL: window.HSPlayerConfig is missing or invalid. Cannot initialize player.');
            // Set fatal poster even if shadow DOM isn't ready yet.
            try { this.setPoster(POSTER_FATAL_URL, 'fatal'); } catch(_) {}
            return;
        }
        const cfg = window.HSPlayerConfig;
        if (!cfg.endpoints || !cfg.endpoints.liveState) {
            this.debugError('FATAL: window.HSPlayerConfig.endpoints.liveState is missing.');
            try { this.setPoster(POSTER_FATAL_URL, 'fatal'); } catch(_) {}
            return;
        }
        if (!cfg.cloudflare || !cfg.cloudflare.customerCode) {
            this.debugError('FATAL: window.HSPlayerConfig.cloudflare.customerCode is missing.');
            try { this.setPoster(POSTER_FATAL_URL, 'fatal'); } catch(_) {}
            return;
        }

        this.debugLog('API Information set:', { inputId, isLive, autoplay });
        this.inputId = inputId;
        this.playerMode = isLive ? 'live' : 'vod';
        this.autoplay = autoplay;
        // Override hardcoded defaults with provided values
        if (posterInitialURL) POSTER_INITIAL_URL = posterInitialURL;
        if (posterIdleURL) POSTER_IDLE_URL = posterIdleURL;
        if (customerID) CLOUDFLARE_CUSTOMER_ID = customerID;
        if (posterFatalURL) POSTER_FATAL_URL = posterFatalURL;

        if (this.isConnected) {
            if (this.playerMode === 'live') this.startPolling();
            else this.loadVideoDirectly();
        }
    }

    // Load and play a VOD stream directly
    loadVideoDirectly() {
        if (this._destroyed) return;
        this.debugLog('VOD Detected...');
        const video = this.videoEl || this.shadowRoot.querySelector('video');
        const playButton = this.playButtonEl || this.shadowRoot.querySelector('.play-button');
        const overlay = this.overlayEl || this.shadowRoot.querySelector('.overlay');
        const streamUrl = `https://customer-${CLOUDFLARE_CUSTOMER_ID}.cloudflarestream.com/${this.inputId}/manifest/video.m3u8`;
        this.debugLog('Loading VOD URL:', streamUrl);
        video.controls = true;

        // Use EngineFactory to pick the right engine
        _currentEngine = createEngine({
            audioDriftFrameThreshold: this.audioDriftFrameThreshold || 4,
            debugLog: (msg) => this.debugLog(msg),
            debugError: (msg) => this.debugError(msg),
            updateStatus: (s) => this.updateStatus(s),
            currentStatusType: this.currentStatusType || 'none',
            onAudioDrift: (drift) => {},
            onThroughputSample: (bps) => {
                try {
                    this.throughputSamples = this.throughputSamples || [];
                    this.throughputSamples.push(bps);
                    if (this.throughputSamples.length > 10) this.throughputSamples.shift();
                } catch (_) {}
            },
        });

        if (!_currentEngine) {
            this.debugError('No HLS support (neither Hls.js nor native).');
            const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
            this.playerState = r.nextState;
            _dispatchEffects(this, r.sideEffects);
            return;
        }

        _currentEngine.attachMedia(video);
        _currentEngine.loadSource(streamUrl);

        // Listen for manifest parsed / loaded metadata to start autoplay
        if (_currentEngine instanceof HlsEngine) {
            _currentEngine.on('manifestParsed', () => {
                if (this.autoplay) this._attemptAutoplay(video);
                else this.debugLog('Autoplay is disabled; video is loaded but not playing automatically.');
            });
        } else if (_currentEngine instanceof NativeHlsEngine) {
            video.addEventListener('loadedmetadata', () => {
                if (this.autoplay) this._attemptAutoplay(video);
                else this.debugLog('Autoplay is disabled; video is loaded but not playing automatically.');
            }, { once: true });
        }
    }

    // Poll the server for live stream status
    startPolling() {
        if (this._destroyed) return;
        if (!this.inputId) {
            this.debugLog('Missing inputId; polling not started.');
            return;
        }
        // Show waiting status immediately if haven't played
        if (!this.hasPlayedOnce) this.updateStatus('waiting');
        
        // Create LivePoller if not already created
        if (!_livePoller) {
            _livePoller = createLivePoller({
                inputId: this.inputId,
                endpoint: window.liveStateEndpoint,
                onEvent: (evt) => {
                    this._handlePollEvent(evt);
                },
                debugLog: (msg) => this.debugLog(msg),
                debugError: (msg) => this.debugError(msg),
            });
        }
        _livePoller.start();
    }

    stopPolling() {
        this.debugLog('Stopping CloudFlare API polling.');
        if (_livePoller) { _livePoller.stop(); }
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
        this._pollTimeoutId = null;
    }

    /** Handle poll events from LivePoller — delegates to state machine */
    _handlePollEvent(evt) {
        if (evt.type === 'noChange') {
            // 304 response — no state change, just update poll count
            this.debugLog('Poll: 304 Not Modified');
            return;
        }

        if (evt.type === 'poll') {
            const { state, isLive, videoUID, hlsUrl, errorCode, source, pollCount } = evt.payload;
            this.pollCount = pollCount;
            this.streamCurrentlyLive = isLive;

            // Debug panel update
            this._updateDebugPanel({ liveStatus: isLive, videoUID: videoUID, pollCount: this.pollCount, error_code: errorCode, source });

            if (isLive && videoUID) {
                // Live: resolve HLS URL
                let resolvedHlsUrl = null;
                if (hlsUrl && HSVideoElement.isValidHlsUrl(hlsUrl)) {
                    resolvedHlsUrl = hlsUrl;
                } else if (hlsUrl && !HSVideoElement.isValidHlsUrl(hlsUrl)) {
                    this.debugError('Rejected invalid hlsUrl from server:', hlsUrl);
                }
                if (!resolvedHlsUrl) {
                    resolvedHlsUrl = `https://customer-${CLOUDFLARE_CUSTOMER_ID}.cloudflarestream.com/${videoUID}/manifest/video.m3u8`;
                }
                this.latestLiveHlsUrl = resolvedHlsUrl;
                this.ingestFalseCount = 0;

                const result = transition({
                    currentState: this.playerState,
                    event: { type: 'poll', payload: { state: 'live', videoUID, hlsUrl: resolvedHlsUrl, source } },
                    context: _buildContext(this),
                });
                this.playerState = result.nextState;
                this._updateDebugPanel({ liveStatus: isLive, videoUID, pollCount: this.pollCount, error_code: errorCode, source });
                _dispatchEffects(this, result.sideEffects);
            } else {
                // Non-live: error or idle
                const event = errorCode
                    ? { type: 'poll', payload: { state: 'error', errorCode, source } }
                    : { type: 'poll', payload: { state: 'idle', videoUID: null, hlsUrl: null } };
                this.ingestFalseCount = (this.ingestFalseCount || 0) + 1;
                this.debugLog('lifecycle indicates not live yet (count=' + this.ingestFalseCount + ')');

                const result = transition({
                    currentState: this.playerState,
                    event,
                    context: _buildContext(this),
                });
                this.playerState = result.nextState;
                this._updateDebugPanel({ liveStatus: isLive, videoUID: null, pollCount: this.pollCount, error_code: errorCode, source });
                _dispatchEffects(this, result.sideEffects);
            }
        }

        if (evt.type === 'error') {
            this.debugError('Poller error:', evt.payload.error);
        }

        if (evt.type === 'backoff') {
            this.debugLog('Poller backoff:', evt.payload.delay + 'ms after', evt.payload.error, 'consecutive errors');
        }
    }



    // Switch UI and playback between IDLE and PLAYING (A2.5 — delegated to state machine).
    managePlayerState(desiredState, streamUrl = '') {
        if (this._destroyed) return;
        this.debugLog(`Desired player state: ${desiredState}`);
        // Build event from desired state; state machine overrides based on context.
        const event = { type: 'poll', payload: { state: desiredState } };
        const result = transition({ currentState: this.playerState, event, context: _buildContext(this) });
        this.playerState = result.nextState;
        this._updateDebugPanel({});
        _dispatchEffects(this, result.sideEffects);
    }

    // Internal: execute idle teardown (extracted for drain support).
    _executeIdleTeardown() {
        const video = this.videoEl || this.shadowRoot.querySelector('video');
        video.controls = false;
        this.currentStreamUrl = null;
        this.latestLiveHlsUrl = null;
        if (this._currentEngine) { this._currentEngine.destroy(); this._currentEngine = null; }
        video.pause();
        this.lastAudioPts = null;
        this.lastVideoPts = null;
        this.audioSyncIssueActive = false;
        const posterUrl = this.hasPlayedOnce ? this.getAttribute('poster-idle') : this.getAttribute('poster-initial');
        this.setPoster(posterUrl, this.hasPlayedOnce ? 'idle' : 'initial');
        try {
            if (this.overlayEl) this.overlayEl.style.display = 'block';
            if (this.playButtonEl && !this.userGestureUnlocked) {
                this.playButtonEl.style.display = 'block';
            }
        } catch (e) { console.error('[hs-video] fatal error:', e); }
        this.startPolling();
        this.pendingPlayRequest = false;
        if (this.bufferGateInterval) { try { clearInterval(this.bufferGateInterval); } catch (_) {} this.bufferGateInterval = null; }
        try {
            if (this.hasPlayedOnce) {
                this.updateStatus('paused');
            } else {
                this.updateStatus('waiting');
            }
        } catch (e) { console.error('[hs-video] fatal error:', e); }
    }

    // Begin preparing to play without flipping state to PLAYING yet
    prepareToPlay(streamUrl) {
        if (this._destroyed) return;
        const video = this.videoEl || this.shadowRoot.querySelector('video');
        this.debugLog('Attaching media and preparing to buffer');
        // Do not show controls yet; only when playback actually starts
        // this.stopPolling(); // keep discovery polling running during playback
        try { this.currentStreamUrl = streamUrl; } catch(_){}
            this.loadStream(streamUrl);
            // The fatal countdown is now started when we actually attempt to play,
            // not during preparation.  This avoids triggering the fatal state
            // during the prebuffer period which may last up to PREBUFFER_TIMEOUT_MS.
            // State remains whatever it was (typically IDLE) until manifest is parsed
    }

    // Configure Hls.js and start live playback
    loadStream(streamUrl) {
        if (this._destroyed) return;
        // Validate URL against §4.3 origin allowlist (defense in depth).
        if (!HSVideoElement.isValidHlsUrl(streamUrl)) {
            this.debugError('Rejected invalid HLS URL in loadStream:', streamUrl);
            const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
            this.playerState = r.nextState;
            _dispatchEffects(this, r.sideEffects);
            return;
        }

        const video = this.videoEl || this.shadowRoot.querySelector('video');
        if (_currentEngine) _currentEngine.destroy();

        // Reset media error recovery attempts for this session
        this.mediaErrorRecoveryAttempts = 0;
        this.throughputSamples = [];
        this.lastAudioPts = null;
        this.lastVideoPts = null;

        // Use engine factory to pick the right engine (A3 — HlsEngine or NativeHlsEngine)
        _currentEngine = createEngine({
            audioDriftFrameThreshold: this.audioDriftFrameThreshold || 4,
            debugLog: (msg) => this.debugLog(msg),
            debugError: (msg) => this.debugError(msg),
            updateStatus: (s) => this.updateStatus(s),
            currentStatusType: this.currentStatusType || 'none',
            onAudioDrift: (drift) => { /* handled via status overlay */ },
            onThroughputSample: (bps) => {
                try {
                    this.throughputSamples = this.throughputSamples || [];
                    this.throughputSamples.push(bps);
                    if (this.throughputSamples.length > 10) this.throughputSamples.shift();
                } catch (_) {}
            },
        });

        if (!_currentEngine) {
            // No HLS support (neither Hls.js nor native)
            this.debugError('No HLS support (neither Hls.js nor native).');
            const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
            this.playerState = r.nextState;
            _dispatchEffects(this, r.sideEffects);
            return;
        }

        // Attach media and set source
        _currentEngine.attachMedia(video);
        _currentEngine.loadSource(streamUrl);

        // Native HLS path — fatal timer for time-to-first-frame (no prebuffer gate)
        if (_currentEngine instanceof NativeHlsEngine) {
            this._nativeHlsTimerId = setTimeout(() => {
                this._nativeHlsTimerId = null;
                if (this.playerState !== STATE.PLAYING && this.playerState !== STATE.IDLE && this.playerState !== STATE.FATAL) {
                    this.debugLog('Native HLS: time-to-first-frame timeout; entering fatal state.');
                    const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
                    this.playerState = r.nextState;
                    _dispatchEffects(this, r.sideEffects);
                }
            }, FATAL_TIMEOUT_MS);

            video.addEventListener('loadedmetadata', () => {
                this.debugLog('Native HLS: loadedmetadata fired; attempting autoplay.');
                if (this._nativeHlsTimerId) {
                    clearTimeout(this._nativeHlsTimerId);
                    this._nativeHlsTimerId = null;
                }
                if (this.autoplay) this._attemptAutoplay(video);
            }, { once: true });
        }

        // Register engine error handler
        _currentEngine.on('error', (_event, data) => {
            if (this._destroyed || !data?.fatal) return;
            const errType = data.type;

            if (errType === 'MEDIA_ERROR') {
                this.debugError('Fatal media error encountered. Attempting recovery...');
                if (this.mediaErrorRecoveryAttempts < MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS) {
                    try { _currentEngine?.recoverMediaError(); } catch (e) { console.error('[hs-video] recoverMediaError failed:', e); }
                    this.mediaErrorRecoveryAttempts++;
                } else {
                    this.debugError('Max media error recovery attempts reached. Entering fatal state.');
                    const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
                    this.playerState = r.nextState;
                    _dispatchEffects(this, r.sideEffects);
                }
            } else if (errType === 'NETWORK_ERROR') {
                const recoverableDetails = [
                    'manifestLoadError', 'manifestLoadTimeOut',
                    'levelLoadError', 'levelLoadTimeOut',
                    'fragLoadError', 'fragLoadTimeOut',
                ];
                if (this._networkErrorRecoveryAttempts < 2 && recoverableDetails.includes(data.details)) {
                    this.debugLog('Network error (recoverable), attempt', this._networkErrorRecoveryAttempts + 1);
                    const backoff = this._networkErrorRecoveryAttempts === 0 ? 2000 : 5000;
                    this._networkErrorRecoveryAttempts++;
                    setTimeout(() => {
                        try { _currentEngine?.startLoad(-1); } catch (_) {}
                    }, backoff);
                } else {
                    this.debugError('Max network error recovery attempts reached. Entering fatal state.');
                    this._networkErrorRecoveryAttempts = 0;
                    const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
                    this.playerState = r.nextState;
                    _dispatchEffects(this, r.sideEffects);
                }
            } else {
                this.debugError('Unrecoverable error encountered. Entering fatal state.');
                const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
                this.playerState = r.nextState;
                _dispatchEffects(this, r.sideEffects);
            }
        });
    }

    // Prebuffer gate: if user clicked and we have enough buffered media, start playback.
    // (A3.6 — uses PrebufferGate pure functions)
    tryStartPlayback() {
        if (this._destroyed) return;
        if (!this.pendingPlayRequest) return;
        const video = this.videoEl || this.shadowRoot.querySelector('video');
        if (!video) return;
        const hasUserGesture = !!this.userGestureUnlocked;

        // Compute gate context using PrebufferGate
        const gateCtx = computeGateContext({
            hls: _currentEngine instanceof HlsEngine ? _currentEngine : null,
            currentTime: video.currentTime,
            buffered: video.buffered ? Array.from(video.buffered).map(b => ({ start: b.start.bind(b), end: b.end.bind(b) })) : [],
            throughputSamples: this.throughputSamples || [],
        });

        // Compute ready state
        const ready = video.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA;
        const bufferedSegments = gateCtx.segDur > 0 ? gateCtx.bufferAhead / gateCtx.segDur : 0;

        const playResult = shouldStartPlayback({
            bufferAhead: gateCtx.bufferAhead,
            bufferedSegments,
            ready,
            hasUserGesture,
            prebufferStartTs: this.prebufferStartTs,
            thresholdSecs: gateCtx.thresholdSecs,
            headroom: gateCtx.headroom,
            hls: _currentEngine instanceof HlsEngine ? _currentEngine : null,
            throughputSamples: this.throughputSamples || [],
        });

        // Update debug panel
        this._updateDebugPanel({ 
            bufferAhead: gateCtx.bufferAhead, 
            inProgress: ready, 
            clicked: hasUserGesture 
        });

        // Apply gentle level capping if headroom looks weak
        if (playResult.headroom < 1.2 && (this.throughputSamples?.length || 0) >= MIN_THROUGHPUT_SAMPLES) {
            try {
                if (Array.isArray(_currentEngine?.levels) && _currentEngine.levels.length) {
                    const cur = _currentEngine.currentLevel;
                    const cap = Math.max(0, typeof cur === 'number' && cur >= 0 ? cur - 1 : 0);
                    _currentEngine.autoLevelCapping = cap;
                }
            } catch (e) { console.error('[hs-video] fatal error:', e); }
        }

        // Start fatal timer when first making progress
        try {
            if (this.userGestureUnlocked && this.fatalTimer) {
                const thresholdProgress = 2;
                const lastLevel = (typeof this.fatalBufferLevel === 'number') ? this.fatalBufferLevel : 0;
                if (gateCtx.bufferAhead > lastLevel + thresholdProgress) {
                    this.startFatalTimer(gateCtx.bufferAhead);
                }
            }
        } catch (e) { console.error('[hs-video] fatal error:', e); }

        if (playResult.shouldStart && (gateCtx.bufferAhead >= gateCtx.thresholdSecs || this.prebufferStartTs > 0 && (Date.now() - this.prebufferStartTs >= PREBUFFER_TIMEOUT_MS))) {
            clearInterval(this.bufferGateInterval);
            this.bufferGateInterval = null;
            this.pendingPlayRequest = false;
            try { _currentEngine?.autoLevelCapping = -1; } catch (_) {}
            try { this.startFatalTimer(gateCtx.bufferAhead); } catch (_) {}
            video.play().catch(err => console.error('Playback start failed:', err));
        }
    }

    /** Manifest probe with A1.4 attempt cap (A3.6 — uses ManifestProbe module) */
    _probeManifestAndStart(streamUrl) {
        // Stop probing if engine destroyed or fatal
        if (_currentEngine === null || this.playerState === STATE.FATAL) return;

        this.probeAttempts++;

        if (this.probeAttempts > MANIFEST_PROBE_MAX_ATTEMPTS) {
            this.debugError('Manifest probe exceeded', MANIFEST_PROBE_MAX_ATTEMPTS, 'attempts; entering fatal state.');
            if (_probeAbortController) { _probeAbortController.abort(); _probeAbortController = null; }
            const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
            this.playerState = r.nextState;
            _dispatchEffects(this, r.sideEffects);
            return;
        }

        // Use the probeManifest module (with module defaults: 40 cap, 1.5s interval, 5s initial delay)
        probeManifest(streamUrl, {
            maxAttempts: MANIFEST_PROBE_MAX_ATTEMPTS - this.probeAttempts + 1,
            initialDelayMs: this.probeAttempts === 1 ? 5000 : 0,
            intervalMs: 1500,
            abortSignal: _probeAbortController?.signal,
        }).then(() => {
            // Give origin a brief grace period before starting playback
            setTimeout(() => {
                _currentEngine?.startLoad(-1);
                this.pendingPlayRequest = true;
                this.prebufferStartTs = Date.now();
                this.playerState = STATE.PREPARING;
                this._updateDebugPanel({});
                try { this.updateStatus('preparing'); } catch (e) {}
                try {
                    if (this.userGestureUnlocked && !this.fatalTimer) this.startFatalTimer();
                } catch (e) {}
                this.tryStartPlayback();
            }, 2000);
        }).catch(() => {
            // probeManifest rejects on cap hit or abort — fatal handled in the cap check above
        });
    }

    // Update debug overlay text (visible only when debug is enabled)
    _updateDebugPanel({ bufferAhead, inProgress, clicked, latency, liveStatus, videoUID, pollCount, error_code, source } = {}) {
        if (!this.enableDebug || !this.debugPanelEl) return;
        try {
            // Merge incremental fields into stored overlay data
            if (typeof this.debugOverlayData !== 'object' || !this.debugOverlayData) this.debugOverlayData = {};
            if (typeof bufferAhead !== 'undefined') this.debugOverlayData.bufferAhead = bufferAhead;
            if (typeof inProgress !== 'undefined') this.debugOverlayData.inProgress = inProgress;
            if (typeof clicked !== 'undefined') this.debugOverlayData.clicked = clicked;
            if (typeof latency !== 'undefined') this.debugOverlayData.latency = latency;
            if (typeof liveStatus !== 'undefined') this.debugOverlayData.liveStatus = liveStatus;
            if (typeof videoUID !== 'undefined') this.debugOverlayData.videoUID = videoUID;
            if (typeof pollCount !== 'undefined') this.debugOverlayData.pollCount = pollCount;
            if (typeof error_code !== 'undefined') this.debugOverlayData.error_code = error_code;
            if (typeof source !== 'undefined') this.debugOverlayData.source = source;

            const bufVal = this.debugOverlayData.bufferAhead;
            const buf = typeof bufVal === 'number' && isFinite(bufVal) ? bufVal.toFixed(1) : '—';
            const progVal = this.debugOverlayData.inProgress;
            const prog = typeof progVal === 'boolean' ? (progVal ? 'yes' : 'no') : '—';
            const ckVal = this.debugOverlayData.clicked;
            const ck = typeof ckVal === 'boolean' ? (ckVal ? 'yes' : 'no') : '—';
            const latVal = this.debugOverlayData.latency;
            const lat = typeof latVal === 'number' && isFinite(latVal) ? latVal.toFixed(2) : '—';
            const state = this.playerState || STATE.IDLE;
            const liveVal = this.debugOverlayData.liveStatus;
            const live = typeof liveVal === 'boolean' ? (liveVal ? 'yes' : 'no') : '—';
            const vid = this.debugOverlayData.videoUID || '—';
            const polls = (typeof this.debugOverlayData.pollCount === 'number') ? this.debugOverlayData.pollCount : '—';
            const errCode = this.debugOverlayData.error_code || '—';
            const src = this.debugOverlayData.source || '—';
            this.debugPanelEl.textContent = `state: ${state}\nprebuffer: ${buf}s\nIn Progress: ${prog}\nclicked: ${ck}\nlatency: ${lat}s\nlive: ${live}\nvideoUID: ${vid}\npolls: ${polls}\nerror_code: ${errCode}\nsource: ${src}`;
        } catch (e) { console.error('[hs-video] fatal error:', e); }
    }

    /**
     * Start a countdown to a fatal state.  This is triggered whenever the
     * player begins to prepare for playback (manifest attached but before
     * actual playing).  If playback does not commence within
     * FATAL_TIMEOUT_MS milliseconds, the player will transition to the
     * fatal state.  The timer is cleared automatically when playback
     * starts or when the stream goes idle.
     */
    /**
     * Begin or restart a fatal countdown.  If an initialBufferAhead
     * value is provided, it is stored so that subsequent increases in
     * bufferAhead can reset the fatal timer.  This prevents slow
     * buffering from prematurely triggering the fatal state.
     *
     * @param {number|null} initialBufferAhead The amount of buffered media
     *        (in seconds) at the moment the fatal timer is started.
     */
    startFatalTimer(initialBufferAhead = null) {
        if (this._destroyed) return;
        try {
            // Do not start a timer if already in the FATAL state
            if (this.playerState === STATE.FATAL) return;
            // Cancel any existing fatal countdown
            if (this.fatalTimer) {
                clearTimeout(this.fatalTimer);
                this.fatalTimer = null;
            }
            // Record buffer level and start time
            if (typeof initialBufferAhead === 'number' && !isNaN(initialBufferAhead)) {
                this.fatalBufferLevel = initialBufferAhead;
            } else if (this.fatalBufferLevel === null) {
                // default when unknown
                this.fatalBufferLevel = 0;
            }
            this.fatalTimerStart = Date.now();
            this.debugLog('Starting fatal countdown timer');
            this.fatalTimer = setTimeout(() => {
                try {
                    // Only enter fatal if we're not already playing or idle and
                    // the player is not destroyed.  Avoid false positives if
                    // playback actually started.
                    if (this.playerState !== STATE.PLAYING && this.playerState !== STATE.IDLE && this.playerState !== STATE.FATAL) {
                        const r = transition({ currentState: this.playerState, event: { type: 'fatal' }, context: _buildContext(this) });
                        this.playerState = r.nextState;
                        _dispatchEffects(this, r.sideEffects);
                    }
                } catch (e) { console.error('[hs-video] fatal error:', e); }
            }, FATAL_TIMEOUT_MS);
        } catch (e) { console.error('[hs-video] fatal error:', e); }
    }

    /**
     * Clear any pending fatal countdown.  Invoked when playback begins
     * successfully or when the stream transitions back to idle.
     */
    clearFatalTimer() {
        try {
            if (this.fatalTimer) {
                clearTimeout(this.fatalTimer);
                this.fatalTimer = null;
                this.debugLog('Cleared fatal countdown timer');
            }
            // Reset buffer-level tracking when clearing the fatal timer
            this.fatalBufferLevel = null;
            this.fatalTimerStart = null;
        } catch (e) { console.error('[hs-video] fatal error:', e); }
    }

    /**
     * Enter the fatal state and display the fatal poster.  This stops
     * polling and playback, destroys the Hls.js instance, and resets
     * internal timers.  Once in the fatal state the player will not
     * automatically recover; the viewer must refresh the page.
     */
    enterFatalState() {
        if (this._destroyed) return;
        try {
            if (this.playerState === STATE.FATAL) return;
            this.debugLog('Fatal error encountered; entering fatal state');
            // Cancel timers
            this.clearFatalTimer();
            if (this.bufferGateInterval) {
                try { clearInterval(this.bufferGateInterval); } catch (_) {}
                this.bufferGateInterval = null;
            }

            if (this.latencyLogInterval) {
                try { clearInterval(this.latencyLogInterval); } catch (_) {}
                this.latencyLogInterval = null;
            }
            // Stop polling so we do not attempt to reattach automatically
            try { this.stopPolling(); } catch (_) {}
            // No separate audio drift detection teardown needed
            // Reset audio/video PTS trackers when entering fatal state
            this.lastAudioPts = null;
            this.lastVideoPts = null;
            this.audioSyncIssueActive = false;
            // Destroy any existing engine instance
            if (this._currentEngine) {
                try { this._currentEngine.destroy(); } catch (_) {}
                this._currentEngine = null;
            }
            const video = this.videoEl || this.shadowRoot.querySelector('video');
            // Pause playback and hide controls
            try { video.pause(); } catch (_) {}
            try { video.controls = false; } catch (_) {}
                // Display fatal poster; allow override via attribute
                try {
                    const posterUrl = this.getAttribute('poster-fatal');
                    this.setPoster(posterUrl, 'fatal');
                } catch (e) { console.error('[hs-video] fatal error:', e); }
                // Hide overlay and play button so the fatal poster is visible
                try {
                    if (this.overlayEl) this.overlayEl.style.display = 'none';
                    if (this.playButtonEl) this.playButtonEl.style.display = 'none';
                } catch (e) { console.error('[hs-video] fatal error:', e); }
            // Reset prebuffer and recovery state
            this.pendingPlayRequest = false;
            this.prebufferStartTs = 0;
            this.throughputSamples = [];
            this.currentStreamUrl = null;
            this.ingestFalseCount = 0;
            this.mediaErrorRecoveryAttempts = 0;
            // Mark fatal state and update overlay
            this.playerState = STATE.FATAL;
            this._updateDebugPanel({});
            // Show an error status to the viewer
            try { this.updateStatus('error'); } catch (_) {}
        } catch (e) { console.error('[hs-video] fatal error:', e); }
    }

    // UI helpers
    _hideUi() {
        try { if (this.playButtonEl) this.playButtonEl.style.display = 'none'; } catch(_) {}
        try { if (this.overlayEl) this.overlayEl.style.display = 'none'; } catch(_) {}
    }

    // Audio drift detection helpers (setupAudioDriftDetection, checkAudioDrift,
    // clearAudioDriftDetection) have been removed.  Drift is now monitored
    // directly via fragment PTS values (see FRAG_PARSING_DATA handler).

    _attemptAutoplay(video, onSuccess) {
        video.play().then(() => {
            this.debugLog('Playback started successfully.');
            if (typeof onSuccess === 'function') onSuccess();
        }).catch(error => {
            this.debugError('Error attempting to start playback:', error);
            try { video.muted = true; } catch(_) {}
            video.play().then(() => {
                this.debugLog('Playback started successfully after muting.');
                if (typeof onSuccess === 'function') onSuccess();
            }).catch(err2 => {
                this.debugError('Error attempting to start muted playback:', err2);
            });
        });
    }

    /**
     * Display an animated status message in the top-left corner.  The text
     * cycles through a growing ellipsis (e.g. "Waiting...", "Waiting..",
     * "Waiting.") every 500ms to convey activity.  This helper will
     * cancel any existing animation and show the message until another
     * status is requested.
     *
     * @param {string} baseText The base text (without dots) to display.
     */
    showAnimatedStatus(baseText) {
        // Do nothing if no status element or text unchanged
        if (!this.statusMessageEl) return;
        // If currently animating a different message, clear it
        this.stopStatusAnimation();
        // Immediately show the first frame and reset fade
        this.statusMessageEl.textContent = baseText + '...';
        this.statusMessageEl.style.display = 'block';
        this.statusMessageEl.style.opacity = '1';
        this.statusMessageEl.classList.remove('fade-out');
        let idx = 0;
        // Start animation interval for ellipsis; each tick cycles 0-3 dots
        this.statusEllipsisInterval = setInterval(() => {
            idx = (idx + 1) % 4;
            const dots = '.'.repeat(idx);
            this.statusMessageEl.textContent = baseText + dots;
        }, 500);
    }

    /**
     * Stop any ongoing ellipsis animation and clear the interval.  Does not
     * hide or modify the status element; primarily used internally.
     */
    stopStatusAnimation() {
        if (this.statusEllipsisInterval) {
            clearInterval(this.statusEllipsisInterval);
            this.statusEllipsisInterval = null;
        }
    }

    /**
     * Display a transient status message that stays on screen for a few
     * seconds and then fades away.  Useful for signalling that the stream
     * has gone live or has ended/paused.  When called, this cancels any
     * ongoing ellipsis animation and any pending hide/fade timers.
     *
     * @param {string} text The message to display.
     * @param {number} durationMs Duration before fading begins (default 3000ms).
     */
    showStatusMessage(text, durationMs = 3000) {
        if (!this.statusMessageEl) return;
        // Stop any animated ellipsis
        this.stopStatusAnimation();
        // Cancel existing fade/hide timers
        if (this.statusFadeTimeout) { clearTimeout(this.statusFadeTimeout); this.statusFadeTimeout = null; }
        if (this.statusHideTimeout) { clearTimeout(this.statusHideTimeout); this.statusHideTimeout = null; }
        // Set message and show immediately
        this.statusMessageEl.textContent = text;
        this.statusMessageEl.style.display = 'block';
        this.statusMessageEl.style.opacity = '1';
        this.statusMessageEl.classList.remove('fade-out');
        // Schedule fade-out after specified duration
        this.statusFadeTimeout = setTimeout(() => {
            // Initiate CSS fade
            if (this.statusMessageEl) this.statusMessageEl.classList.add('fade-out');
            // Hide completely after fade duration (0.5s)
            this.statusHideTimeout = setTimeout(() => {
                if (this.statusMessageEl) {
                    this.statusMessageEl.style.display = 'none';
                    this.statusMessageEl.classList.remove('fade-out');
                    // Note: do not reset currentStatusType here; updateStatus handles it
                }
            }, 500);
        }, durationMs);
    }

    /**
     * Hide any visible status message immediately and clear animations.
     */
    hideStatusMessage() {
        if (!this.statusMessageEl) return;
        this.stopStatusAnimation();
        if (this.statusFadeTimeout) { clearTimeout(this.statusFadeTimeout); this.statusFadeTimeout = null; }
        if (this.statusHideTimeout) { clearTimeout(this.statusHideTimeout); this.statusHideTimeout = null; }
        this.statusMessageEl.style.display = 'none';
        this.statusMessageEl.style.opacity = '1';
        this.statusMessageEl.classList.remove('fade-out');
        this.currentStatusType = 'none';
    }

    /**
     * Update the status overlay based on a type string.  Possible values
     * include 'waiting', 'preparing', 'live', 'paused', 'error', and
     * 'none'.  Repeated calls with the same type do nothing.  On
     * transition, the appropriate message or animation is displayed.
     *
     * @param {string} type The status type to display.
     */
    updateStatus(type) {
        if (this._destroyed) return;
        try {
                if (!type) type = 'none';
                const previousType = this.currentStatusType;
                const allowSyncStatus = !!(this.enableDebug || this.debugMode);
                if (type === 'syncIssue' && !allowSyncStatus) {
                    if (previousType === 'syncIssue') {
                        type = 'none';
                    } else {
                        return;
                    }
                }
                // If the user has not yet interacted with the player (play button not clicked),
                // hide any status indicator.  Do not record the type so that it will be
                // displayed when the user clicks and updateStatus is called again.
                if (!this.userGestureUnlocked) {
                    // Hide the status overlay entirely
                    this.hideStatusMessage();
                    // Set current type to 'none' so future updates will render
                    this.currentStatusType = 'none';
                    return;
                }
                if (this.currentStatusType === type) return;
                this.currentStatusType = type;
                switch (type) {
                case 'waiting':
                    this.showAnimatedStatus('Waiting for stream');
                    break;
                case 'preparing':
                    this.showAnimatedStatus('Preparing to stream');
                    break;
                case 'live':
                    this.showStatusMessage('Live');
                    break;
                case 'reconnecting':
                    this.showAnimatedStatus('Reconnecting');
                    break;
                case 'paused':
                    this.showStatusMessage('Paused/Ended');
                    break;
                case 'error':
                    this.showStatusMessage('Error');
                    break;
                case 'syncIssue':
                    // Display an animated sync issue message while drift persists.
                    // Use ellipsis animation to suggest an ongoing problem.
                    this.showAnimatedStatus('Audio sync issue');
                    break;
                case 'none':
                default:
                    this.hideStatusMessage();
                    break;
            }
        } catch (e) { console.error('[hs-video] fatal error:', e); }
    }
}

customElements.define('hs-video', HSVideoElement);
