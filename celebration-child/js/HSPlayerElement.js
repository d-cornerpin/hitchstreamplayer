// Custom video player web component using Hls.js (live and VOD)
// Poster image URLs (edit these to change defaults)
const POSTER_INITIAL_URL = 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Initial_Default.png';
const POSTER_IDLE_URL = 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Idle_Default.png';
const POSTER_FATAL_URL = 'https://hitchstream.com/wp-content/uploads/2025/09/Poster_fatal_2.png';
// Cloudflare customer identifier for VOD host
const CLOUDFLARE_CUSTOMER_ID = 'juu1r5es4cbffqjf';
// Dynamic prebuffer controls
const MIN_PREBUFFER_SECONDS = 10;           // minimum seconds before considering start
const MIN_PREBUFFER_SEGMENTS = 3;           // minimum segments before considering start
const MIN_THROUGHPUT_SAMPLES = 3;           // minimum frag samples to trust throughput
const PREBUFFER_TIMEOUT_MS = 60000;         // start anyway after this wait
const MAX_THROUGHPUT_SAMPLES = 10;          // cap for stored throughput samples

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
const POLL_INTERVAL_MS = 10000;
const LATENCY_LOG_INTERVAL_MS = 15000;
const GATE_CHECK_INTERVAL_MS = 1000;
const MANIFEST_PROBE_INTERVAL_MS = 1500;
const INITIAL_MANIFEST_DELAY_MS = 5000; // wait before first manifest probe
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

class HSVideoElement extends HTMLElement {
    // Set up component state and optional debug mode
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this.playerState = STATE.IDLE;
        this.hls = null;
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
        this.manifestProbeInterval = null; // timer id for manifest probe
        this.healthPollInterval = null;   // timer id for health polling during playback
        this.currentStreamUrl = null;      // URL currently loaded in Hls.js
        this.ingestFalseCount = 0;         // consecutive polls reporting not live
        this.currentStreamUrl = null;      // URL currently loaded into Hls.js
        this.ingestFalseCount = 0;         // consecutive polls reporting ingest not live
        this.hasPlayedOnce = false;        // flips true after the first real playback
        // Initialize client-side polling counters for lifecycle endpoint
        this.pollCount = 0;
        this.liveStatus = null;
        this.videoUID = null;

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

    // Watch for poster attribute changes
    static get observedAttributes() { return ['poster-initial', 'poster-idle']; }
    attributeChangedCallback(name, oldValue, newValue) {
        this.debugLog(`Attribute ${name} changed from ${oldValue} to ${newValue}`);
        if (name === 'poster-initial' && this.playerState === 'IDLE') this.setPoster(newValue);
    }

    // Receive config from the page and start live or VOD
    setApiInfo({
        inputId,
        isLive,
        autoplay = true
    }) {
        this.debugLog('API Information set:', { inputId, isLive, autoplay });
        this.inputId = inputId;
        this.isLive = isLive;
        this.autoplay = autoplay;

        if (this.isConnected) {
            if (this.isLive) this.startPolling(); else this.loadVideoDirectly();
        }
    }

    // Load and play a VOD stream directly
    loadVideoDirectly() {
        this.debugLog('VOD Detected...');
        const video = this.videoEl || this.shadowRoot.querySelector('video');
        const playButton = this.playButtonEl || this.shadowRoot.querySelector('.play-button');
        const overlay = this.overlayEl || this.shadowRoot.querySelector('.overlay');
        const streamUrl = `https://customer-${CLOUDFLARE_CUSTOMER_ID}.cloudflarestream.com/${this.inputId}/manifest/video.m3u8`;
        this.debugLog('Loading VOD URL:', streamUrl);
        video.controls = true;

        if (Hls.isSupported()) {
            if (this.hls) this.hls.destroy();
            this.hls = new Hls();
            this.hls.loadSource(streamUrl);
            this.hls.attachMedia(video);
                this.hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    // For VOD, attempt autoplay as soon as the manifest is parsed.
                    // Do not hide the overlay here; allow the 'playing' event
                    // to trigger UI hiding so the poster remains until actual playback.
                    if (this.autoplay) this._attemptAutoplay(video);
                    else this.debugLog('Autoplay is disabled; video is loaded but not playing automatically.');
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = streamUrl;
                if (this.autoplay) {
                    video.addEventListener('loadedmetadata', () => {
                        // Attempt autoplay without hiding the UI immediately.
                        // The 'playing' event handler will hide the overlay when playback begins.
                        this._attemptAutoplay(video);
                    });
                } else {
                    this.debugLog('Autoplay is disabled; video is loaded but not playing automatically.');
                }
            }
    }

    // Poll the server for live stream status
    startPolling() {
        // Poll Cloudflare's lifecycle endpoint directly from the client.
        if (!this.inputId) {
            this.debugLog('Missing inputId; polling not started.');
            return;
        }
        const pollLifecycle = () => {
            this.debugLog('Polling Cloudflare lifecycle endpoint...');
            try { this.pollCount++; } catch (_) {}
            // Poll the WordPress endpoint that serves webhook-updated state (with Cloudflare probe fallback).
        const lifecycleUrl = `${window.liveStateEndpoint}?inputId=${encodeURIComponent(this.inputId)}`;
            fetch(lifecycleUrl, { method: 'GET', mode: 'cors', credentials: 'omit' })
                .then(res => res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`)))
                .then(data => {
                    const isLive   = data && typeof data.live === 'boolean' ? data.live : false;
                    const vid      = data && typeof data.videoUID === 'string' ? data.videoUID : null;
                    const rawState = data && data.state ? data.state : null;
                    const error_code = data && data.error_code ? data.error_code : null;
                    const source   = data && data.source ? data.source : null;
                        // update debug overlay
                        this._updateDebugPanel({ liveStatus: isLive, videoUID: vid, pollCount: this.pollCount, error_code, source });
                        // update instance-level live flag so onClickPlayButton reflects the current stream state
                        this.isLive = isLive;

                        // Handle reconnecting state from webhook — show status but keep streaming.
                        if (rawState === 'reconnecting') {
                            if (this.playerState === STATE.PLAYING) {
                                this.updateStatus('reconnecting');
                            } else {
                                // Not yet playing — if we have a live URL saved, keep trying to prepare.
                                if (this.latestLiveHlsUrl) {
                                    this.updateStatus('reconnecting');
                                }
                            }
                        } else if (isLive && vid) {
                        // A live stream is available. Save the HLS manifest URL so we can
                        // begin loading it later when the viewer clicks the play button.
                        const hlsUrl = `https://customer-${CLOUDFLARE_CUSTOMER_ID}.cloudflarestream.com/${vid}/manifest/video.m3u8`;
                        this.latestLiveHlsUrl = hlsUrl;
                        this.ingestFalseCount = 0;
                        // Update the top-left status indicator. If we're already playing,
                        // show "live". If the viewer has clicked, we're preparing; otherwise
                        // remain in the waiting state so the poster stays visible.
                        if (this.playerState === STATE.PLAYING) {
                            this.updateStatus('live');
                        } else {
                            if (this.userGestureUnlocked) {
                                this.updateStatus('preparing');
                            } else {
                                this.updateStatus('waiting');
                            }
                        }
                        // Only begin preparing (loading the HLS stream) once the viewer has
                        // interacted with the player and we're not in the fatal state. This
                        // avoids starting the buffer prematurely when the page is opened while
                        // the stream happens to be live.
                        if (this.userGestureUnlocked && this.playerState !== STATE.FATAL) {
                            if (!this.hls || this.currentStreamUrl !== hlsUrl) {
                                this.prepareToPlay(hlsUrl);
                            }
                        }
                    } else {
                        // Not live; update status accordingly
                        // Check for error_code from webhooks (display before default idle handling).
                        if (error_code) {
                            const errorMessages = {
                                'ERR_GOP_OUT_OF_RANGE': 'Stream issue — restarting...',
                                'ERR_UNSUPPORTED_VIDEO_CODEC': 'Stream codec error',
                                'ERR_UNSUPPORTED_AUDIO_CODEC': 'Audio stream error',
                                'ERR_STORAGE_QUOTA_EXHAUSTED': 'Service temporarily unavailable',
                                'ERR_MISSING_SUBSCRIPTION': 'Service unavailable',
                            };
                            this.currentErrorMessage = errorMessages[error_code] || 'Stream error';
                            this.updateStatus('error');
                        }
                        this.ingestFalseCount = (this.ingestFalseCount || 0) + 1;
                        this.debugLog('lifecycle indicates not live yet (count=' + this.ingestFalseCount + ')');
                        // If this is the first not-live check, show waiting indicator
                        if (this.ingestFalseCount < 2) {
                            // show waiting status only if we haven't yet played
                            if (!this.hasPlayedOnce) {
                                this.updateStatus('waiting');
                            }
                        }
                        if (this.ingestFalseCount >= 2) {
                            // Clear any fatal countdown if stream goes idle
                            try { this.clearFatalTimer(); } catch (_) {}
                            if (this.hls) { try { this.hls.destroy(); } catch (_) {} this.hls = null; }
                            const v = (this.videoEl || this.shadowRoot.querySelector('video'));
                            try { v.pause(); } catch (_) {}
                            // No separate audio drift detection teardown needed
                            // Reset audio/video PTS trackers on return to idle
                            this.lastAudioPts = null;
                            this.lastVideoPts = null;
                            this.audioSyncIssueActive = false;
                            try { v.controls = false; } catch (_) {}
                                this.currentStreamUrl = null;
                                // Clear any saved live URL because the stream is no longer live
                                this.latestLiveHlsUrl = null;
                            // Choose poster based on whether we have played before
                            try {
                                const posterUrl = this.hasPlayedOnce ? this.getAttribute('poster-idle') : this.getAttribute('poster-initial');
                                this.setPoster(posterUrl, this.hasPlayedOnce ? 'idle' : 'initial');
                            } catch (_) {}
                            // Show overlay again so the poster is visible. Only show
                            // the play button if the user has not yet interacted
                            // with the player. Once userGestureUnlocked becomes true
                            // (after the first click), the play button remains hidden
                            // when returning to idle.
                            try {
                                if (this.overlayEl) this.overlayEl.style.display = 'block';
                                if (this.playButtonEl && !this.userGestureUnlocked) {
                                    this.playButtonEl.style.display = 'block';
                                }
                            } catch (_) {}
                            try { this.playerState = STATE.IDLE; this._updateDebugPanel({}); } catch (_) {}
                            // Show paused/ended or waiting message after returning to idle
                            if (this.hasPlayedOnce) {
                                this.updateStatus('paused');
                            } else {
                                this.updateStatus('waiting');
                            }
                        }
                    }
                })
                .catch(err => {
                    this.debugError('Error fetching lifecycle status:', err);
                });
        };
        // clear any existing polling and schedule new polling
        if (this.pollingInterval) clearInterval(this.pollingInterval);
        // Immediately show a waiting status if we haven't yet played; this
        // provides instant feedback before the first network poll.
        try {
            if (!this.hasPlayedOnce) this.updateStatus('waiting');
        } catch (_) {}
        setTimeout(() => {
            this.pollingInterval = setInterval(pollLifecycle, POLL_INTERVAL_MS);
            pollLifecycle();
        }, POLL_INITIAL_DELAY_MS);
    }

    // Stop polling for live status
    stopPolling() {
        this.debugLog('Stopping CloudFlare API polling.');
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
    }


    // Switch UI and playback between IDLE and PLAYING
    managePlayerState(newState, streamUrl = '') {
        this.debugLog(`Changing player state from ${this.playerState} to ${newState}`);
        const video = this.videoEl || this.shadowRoot.querySelector('video');

        if (newState === STATE.PLAYING && this.playerState !== STATE.PLAYING) {
            this.debugLog('Stream found, preparing to play...');
            video.controls = true;
            // this.stopPolling(); // keep discovery polling running during playback
            // load immediately; prebuffer gating will handle smooth start
            this.debugLog('Attaching media and preparing to buffer');
            this.loadStream(streamUrl);
        } else if (newState === STATE.IDLE && this.playerState !== STATE.IDLE) {
            this.debugLog('Stream is not live. Poster displayed.');
            video.controls = false;
            if (this.hls) { this.hls.destroy(); this.hls = null; }
            video.pause();
            // No separate audio drift detection teardown needed when leaving the playing state
            // Reset audio/video PTS trackers on return to idle
            this.lastAudioPts = null;
            this.lastVideoPts = null;
            this.audioSyncIssueActive = false;
            // Use initial poster until the first successful playback; then use idle poster
            const posterUrl = this.hasPlayedOnce ? this.getAttribute('poster-idle') : this.getAttribute('poster-initial');
            this.setPoster(posterUrl, this.hasPlayedOnce ? 'idle' : 'initial');
                    // Show overlay again when returning to idle so the poster remains
                    // visible. Only show the play button on the very first page load
                    // before the user has clicked. After userGestureUnlocked becomes
                    // true, the play button stays hidden when returning to idle.
                    try {
                        if (this.overlayEl) this.overlayEl.style.display = 'block';
                        if (this.playButtonEl && !this.userGestureUnlocked) {
                            this.playButtonEl.style.display = 'block';
                        }
                    } catch (_) {}
            this.startPolling();
            // reset gating state when returning to IDLE
            this.pendingPlayRequest = false;
            if (this.bufferGateInterval) { try { clearInterval(this.bufferGateInterval); } catch (_) {} this.bufferGateInterval = null; }

            // Update the status message based on whether we've played before
            try {
                if (this.hasPlayedOnce) {
                    this.updateStatus('paused');
                } else {
                    this.updateStatus('waiting');
                }
            } catch (_) {}
        }
        this.playerState = newState;
        this._updateDebugPanel({});
    }

    // Begin preparing to play without flipping state to PLAYING yet
    prepareToPlay(streamUrl) {
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
        const video = this.videoEl || this.shadowRoot.querySelector('video');
        if (this.hls) this.hls.destroy();

            // Reset media error recovery attempts for this session
            this.mediaErrorRecoveryAttempts = 0;

            // Holds the HLS manifest URL for a live stream when detected.
            // This allows the player to defer loading and buffering until the
            // user clicks the play button. It is reset when the stream goes idle
            // or a new live session begins.
            this.latestLiveHlsUrl = null;

            this.hls = new Hls({
            // Prefer smoothness over low-latency; build a deeper buffer
            lowLatencyMode: false,
            liveSyncMode: 'buffered',
            liveSyncDuration: 20,
            liveMaxLatencyDuration: 60,
            maxLiveSyncPlaybackRate: 1.005,
            // Allow a large forward buffer for stability
            maxBufferLength: 90,
            maxMaxBufferLength: 300,
            maxBufferSize: 100 * 1000 * 1000,
            maxBufferHole: 1,
            backBufferLength: 120,
            enableWorker: true,
            enableSoftwareAES: true,
            alignMediaSync: true,
            // Increase tolerance for audio/video drift. A higher value allows
            // Hls.js to drop or insert additional audio frames to keep
            // synchronization when timestamp jitter occurs upstream. The default
            // value (1) proved too strict for certain encoder chains, so we
            // expand it to allow up to 30 audio frames of drift before
            // correction. Adjust this if you observe persistent sync issues.
            maxAudioFramesDrift: 30,
            // Conservative startup
            startLevel: 0,
            capLevelToPlayerSize: true,
            startOnSegmentBoundary: true,
            startFragPrefetch: true,
            autoStartLoad: false,
            manifestLoadingMaxRetry: 5,
            manifestLoadingRetryDelay: 1500,
            manifestLoadingMaxRetryTimeout: 60000,
            levelLoadingMaxRetry: 5,
            levelLoadingRetryDelay: 1500,
            levelLoadingMaxRetryTimeout: 60000,
            audioTrackLoadingMaxRetry: 5,
            audioTrackLoadingRetryDelay: 1500,
            audioTrackLoadingMaxRetryTimeout: 60000,
            fragLoadingMaxRetry: 10,
            fragLoadingRetryDelay: 2000,
            fragLoadingMaxRetryTimeout: 60000,
            manifestLoadingMaxRetry: 10,
            manifestLoadingRetryDelay: 2000,
            manifestLoadingMaxRetryTimeout: 60000,
            startPosition: -1,
        });

        // Listen for fragment parsing data events to track audio/video PTS and
        // detect when the audio drifts significantly from the video.  When a
        // drift larger than `audioDriftFrameThreshold` frames is observed
        // during playback, a "Audio sync issue" message will be displayed in
        // the status overlay.  When the drift falls back below the
        // threshold, the message will be cleared.
        this.hls.on(Hls.Events.FRAG_PARSING_DATA, (event, data) => {
            try {
                if (!data || !Array.isArray(data.samples) || data.samples.length === 0) return;
                // Convert PTS from 90kHz clock to seconds.  Use the last sample
                // of the fragment to better capture accumulated drift.  If the
                // last sample is unavailable, fall back to the first sample.
                const timeScale = 90000;
                if (data.type === 'audio') {
                    const sample = data.samples[data.samples.length - 1] || data.samples[0];
                    const pts = sample?.pts;
                    if (typeof pts === 'number') this.lastAudioPts = pts / timeScale;
                } else if (data.type === 'video') {
                    const sample = data.samples[data.samples.length - 1] || data.samples[0];
                    const pts = sample?.pts;
                    if (typeof pts === 'number') this.lastVideoPts = pts / timeScale;
                }
                // Only evaluate drift during active playback after user gesture
                if (this.playerState === STATE.PLAYING && this.userGestureUnlocked && this.lastAudioPts != null && this.lastVideoPts != null) {
                    const drift = Math.abs(this.lastAudioPts - this.lastVideoPts);
                    // Determine the current estimated frame rate.  Many HLS levels
                    // provide a `framerate` in the level details; fall back to
                    // 30fps if unavailable.  Use this to translate frames to
                    // seconds.
                    let fps = 30;
                    try {
                        const level = this.hls?.levels?.[this.hls.currentLevel] || this.hls?.levels?.[0];
                        const details = level?.details;
                        const fr = details?.framerate;
                        if (typeof fr === 'number' && fr > 0) fps = fr;
                    } catch (_) {}
                    const thresholdSeconds = (this.audioDriftFrameThreshold / fps);
                    const shouldShowSyncIssue = !!(this.enableDebug || this.debugMode);
                    if (drift > thresholdSeconds) {
                        if (shouldShowSyncIssue) {
                            // Show the sync issue message if not already displayed
                            if (this.currentStatusType !== 'syncIssue') {
                                this.updateStatus('syncIssue');
                            }
                        } else if (this.currentStatusType === 'syncIssue') {
                            // Debug is off; ensure any prior sync message is cleared
                            this.updateStatus('none');
                        }
                    } else if (this.currentStatusType === 'syncIssue') {
                        // Drift is within acceptable tolerance; clear the sync message
                        this.updateStatus('none');
                    }
                }
            } catch (_) {}
        });

        // reset throughput samples for a fresh session
        this.throughputSamples = [];
        this.hls.attachMedia(video);

        // Track throughput from fragment loads for dynamic prebuffering
        this.hls.on(Hls.Events.FRAG_LOADED, (_evt, data) => {
            try {
                const stats = data?.stats || {};
                const loaded = stats.loaded || 0; // bytes
                // prefer detailed loading timestamps if present
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
                    this.throughputSamples.push(bps);
                    if (this.throughputSamples.length > MAX_THROUGHPUT_SAMPLES) this.throughputSamples.shift();
                }
            } catch (_) {}
        });

        this.hls.on(Hls.Events.MANIFEST_PARSED, () => {
            // Manifest parsed — start prebuffer gating; remain PREPARING until actual playback starts
            this.pendingPlayRequest = true;
            this.prebufferStartTs = Date.now();
            this.playerState = STATE.PREPARING;
            this._updateDebugPanel({});
            // Show preparing status indicator
            try { this.updateStatus('preparing'); } catch (_) {}
            // If the viewer has already interacted (userGestureUnlocked), start the fatal timer
            try {
                if (this.userGestureUnlocked && !this.fatalTimer) this.startFatalTimer();
            } catch (_) {}
            this.tryStartPlayback();

            // Optional debug-only latency visibility
            if (this.enableDebug) {
                try {
                    if (this.latencyLogInterval) clearInterval(this.latencyLogInterval);
                    this.latencyLogInterval = setInterval(() => {
                        try {
                            const latencyVal = typeof this.hls?.latency === 'number' ? this.hls.latency : NaN;
                            this._updateDebugPanel({ latency: latencyVal });
                        } catch (_) {}
                    }, LATENCY_LOG_INTERVAL_MS);
                } catch (_) {}
            }
        });

            // Handle Hls.js error events.  Fatal errors trigger recovery attempts
            // or transition to the fatal state when unrecoverable.
            this.hls.on(Hls.Events.ERROR, (event, data) => {
                try {
                    if (!data || !data.fatal) return;
                    const errType = data.type;
                    // Media errors can often be recovered.  Attempt recovery up
                    // to MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS times.
                    if (errType === Hls.ErrorTypes.MEDIA_ERROR) {
                        this.debugError('Fatal media error encountered. Attempting recovery...');
                        if (this.mediaErrorRecoveryAttempts < MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS) {
                            try { this.hls.recoverMediaError(); } catch (_) {}
                            this.mediaErrorRecoveryAttempts++;
                        } else {
                            this.debugError('Max media error recovery attempts reached. Entering fatal state.');
                            this.enterFatalState();
                        }
                    } else {
                        // Network and other fatal errors are considered unrecoverable.
                        // Enter the fatal state so the user can refresh the page.
                        this.debugError('Unrecoverable error encountered. Entering fatal state.');
                        this.enterFatalState();
                    }
                } catch (_) {}
            });

        // Probe manifest CORS/readiness before starting Hls.js loading
        this._probeManifestAndStart(streamUrl);
    }

        // Set the poster image (or default) on the video element
        setPoster(url, type) {
            const video = this.videoEl || this.shadowRoot.querySelector('video');
            // Explicit URL always takes precedence
            let finalUrl = url;
            if (!finalUrl) {
                let defaultUrl;
                if (type === 'initial') {
                    defaultUrl = POSTER_INITIAL_URL;
                } else if (type === 'fatal') {
                    defaultUrl = POSTER_FATAL_URL;
                } else {
                    // idle or unspecified
                    defaultUrl = POSTER_IDLE_URL;
                }
                finalUrl = defaultUrl;
            }
            // Apply poster to the video element
            video.setAttribute('poster', finalUrl);
            // Also update the overlay poster image so it stays visible while
            // the video is loading.  Not every connectedCallback may have
            // overlayPosterEl yet (e.g. before connected), so guard.
            try {
                if (this.overlayPosterEl) this.overlayPosterEl.src = finalUrl;
            } catch (_) {}
        }

    // Render the player UI, wire events, and initialize playback
    connectedCallback() {
        this.shadowRoot.innerHTML = `
    <style>
        :host { display: block; position: relative; width: 100%; }
        video { width: 100%; aspect-ratio: 16 / 9; }
        .overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            /* Use an opaque background to ensure the video does not show through
               the overlay while loading. The poster will be displayed via the
               nested img element. */
            background: #000;
            z-index: 1;
        }
        .overlay img.overlay-poster {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .play-button { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); cursor: pointer; background: url('https://hitchstream.com/wp-content/uploads/2024/04/playbutton.png') no-repeat center center; background-size: contain; width: 40%; height: 40%; z-index: 2; }
        .debug-panel { position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.8); color: #fff; font: 12px/1.4 -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Oxygen, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif; padding: 6px 8px; border-radius: 4px; z-index: 3; pointer-events: none; white-space: pre; }

        /* Status message overlay (top-left) */
        .status-message {
            position: absolute;
            top: 8px;
            left: 8px;
            color: #fff;
            font: 12px/1.4 -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Oxygen, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif;
            background: rgba(0, 0, 0, 0.6);
            padding: 4px 6px;
            border-radius: 3px;
            z-index: 4;
            pointer-events: none;
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        .status-message.fade-out {
            opacity: 0;
        }
    </style>
    <video playsinline></video>
        <div class="overlay"><img class="overlay-poster" /></div>
        <div class="play-button"></div>
    <div class="debug-panel" style="display:none"></div>
    <!-- Status message overlay; appears in the top-left corner to
         communicate stream status.  Hidden by default. -->
    <div class="status-message" style="display:none"></div>
    `;

        this.videoEl = this.shadowRoot.querySelector('video');
        this.playButtonEl = this.shadowRoot.querySelector('.play-button');
            this.overlayEl = this.shadowRoot.querySelector('.overlay');
            // Poster image element nested within the overlay.  This displays
            // the current poster while the video is loading.  It ensures
            // the poster remains visible even after the video attaches and
            // the native poster attribute is dropped.
            this.overlayPosterEl = this.shadowRoot.querySelector('.overlay .overlay-poster');
        const video = this.videoEl;
        const playButton = this.playButtonEl;
        const overlay = this.overlayEl;
        this.debugPanelEl = this.shadowRoot.querySelector('.debug-panel');
        // Reference the status message element for live status overlay
        this.statusMessageEl = this.shadowRoot.querySelector('.status-message');

        const initialPoster = this.getAttribute('poster-initial');
        this.setPoster(initialPoster, 'initial');

        if (this.autoplay) {
            video.setAttribute('autoplay', '');
            video.setAttribute('playsinline', '');
        } else {
            video.removeAttribute('autoplay');
        }

        // (removed crossorigin attribute; not needed for this flow)

        video.controls = false;

        const onPlaying = () => {
            // Playback has started; cancel any pending fatal timeout
            try { this.clearFatalTimer(); } catch (_) {}
            // When the 'playing' event fires, the video has just rendered the
            // first frame. To avoid exposing a frozen first frame while the
            // buffer is still filling, wait for the next 'timeupdate' event
            // (which indicates the playback position has advanced) before
            // hiding the overlay.  Use the { once: true } option so the
            // listener is removed automatically.
            const videoEl = this.videoEl;
            const handleTimeUpdate = () => {
                try {
                    if (videoEl) videoEl.removeEventListener('timeupdate', handleTimeUpdate);
                } catch (_) {}
                // Hide the overlay and show controls now that playback has
                // progressed beyond the first frame
                this._hideUi();
                try { this.videoEl.controls = true; } catch(_) {}
                try { this.hasPlayedOnce = true; } catch(_) {}
                try { this.playerState = STATE.PLAYING; this._updateDebugPanel({}); } catch(_) {}
                // Indicate that the stream is now live for a brief moment
                try { this.updateStatus('live'); } catch (_) {}
                // Audio drift detection is handled via fragment PTS; no explicit setup needed.
            };
            try {
                if (videoEl) videoEl.addEventListener('timeupdate', handleTimeUpdate, { once: true });
            } catch (_) {
                // Fallback: if adding the listener fails, hide the UI immediately
                handleTimeUpdate();
            }
        };

            const onClickPlayButton = () => {
                // Register user gesture (do NOT mark PLAYING yet).  We only hide the
                // play button here and leave the overlay in place until the
                // video actually begins playing.  This prevents the poster from
                // disappearing too early and exposing a still frame during
                // buffering.
                try { if (this.playButtonEl) this.playButtonEl.style.display = 'none'; } catch(_) {}
                this.userGestureUnlocked = true;
                if (this.isLive) {
                    // For live streams, begin loading the discovered live URL only when
                    // the viewer clicks the play button.  If a live URL was already
                    // discovered via polling, load it now; otherwise, it will be
                    // prepared automatically on the next successful poll.
                    try {
                        const hlsUrl = this.latestLiveHlsUrl;
                        if (hlsUrl && (!this.hls || this.currentStreamUrl !== hlsUrl)) {
                            this.prepareToPlay(hlsUrl);
                        }
                    } catch (_) {}
                    // Defer hiding the overlay until playback starts (handled in onPlaying)
                    this.pendingPlayRequest = true;
                    if (!this.prebufferStartTs) this.prebufferStartTs = Date.now();
                    // The fatal timer will begin once the manifest is parsed and prebuffer gate requests playback.
                    this.tryStartPlayback();
                } else {
                    // For VOD, attempt autoplay on user gesture. Do not hide the
                    // overlay here; the 'playing' event listener will hide it
                    // once playback actually begins.
                    this._attemptAutoplay(video);
                }
            };

        this._onPlaying = onPlaying;
        this._onClickPlayButton = onClickPlayButton;
        video.addEventListener('playing', this._onPlaying);
        if (playButton) playButton.addEventListener('click', this._onClickPlayButton);

        if (this.autoplay) {
            document.addEventListener('click', this._onClickPlayButton, { once: true });
            document.addEventListener('touchstart', this._onClickPlayButton, { once: true });
            document.addEventListener('keydown', this._onClickPlayButton, { once: true });
        }

        

        if (this.debugMode) {
            try { this.setAttribute('debug', ''); } catch (e) {}
        }

        try { this.enableDebug = this.hasAttribute('debug') || this.debugMode || this.enableDebug; } catch (e) { this.enableDebug = this.debugMode || this.enableDebug; }

        if (this.enableDebug) this.debugLog('[hs-video] debug enabled — verbose logging active');

        // Show/hide debug overlay and set initial text
        try { if (this.debugPanelEl) this.debugPanelEl.style.display = this.enableDebug ? 'block' : 'none'; } catch(_){}
        this._updateDebugPanel({});

        if (this.inputId && this.isLive) {
            this.startPolling();
        } else if (this.inputId && !this.isLive) {
            this.loadVideoDirectly();
        }
    }

    // Clean up timers and Hls.js when element is removed
    disconnectedCallback() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
        if (this.bufferGateInterval) {
            try { clearInterval(this.bufferGateInterval); } catch (_) {}
            this.bufferGateInterval = null;
        }
        if (this.latencyLogInterval) {
            try { clearInterval(this.latencyLogInterval); } catch (_) {}
            this.latencyLogInterval = null;
        }
        if (this.manifestProbeInterval) {
            try { clearInterval(this.manifestProbeInterval); } catch (_) {}
            this.manifestProbeInterval = null;
        }
        try {
            if (this.videoEl && this._onPlaying) this.videoEl.removeEventListener('playing', this._onPlaying);
            if (this.playButtonEl && this._onClickPlayButton) this.playButtonEl.removeEventListener('click', this._onClickPlayButton);
        } catch (_) {}
        this.debugPanelEl = null;
        if (this.hls) {
            this.hls.destroy();
        }
    }

    // If user clicked and we have enough buffered media, start playback
    tryStartPlayback() {
        try {
            if (!this.pendingPlayRequest) return;
            const video = this.videoEl || this.shadowRoot.querySelector('video');
            const hasUserGesture = !!this.userGestureUnlocked;

            // measure buffered ahead of currentTime
            const getBufferAhead = () => {
                try {
                    const t = video.currentTime;
                    const buf = video.buffered;
                    for (let i = 0; i < buf.length; i++) {
                        const start = buf.start(i), end = buf.end(i);
                        if (t >= start && t <= end) return Math.max(0, end - t);
                    }
                    return buf.length ? Math.max(0, buf.end(buf.length - 1) - t) : 0;
                } catch (_) { return 0; }
            };

            // Estimate segment duration (seconds)
            const getSegmentDuration = () => {
                try {
                    const lvl = this.hls?.levels?.[this.hls.currentLevel] || this.hls?.levels?.[0];
                    const td = lvl?.details?.targetduration;
                    return typeof td === 'number' && td > 0 ? td : 4;
                } catch (_) { return 4; }
            };

            // Conservative throughput (20th percentile of recent samples)
            const getConservativeThroughput = () => {
                try {
                    const arr = (this.throughputSamples || []).slice();
                    if (arr.length === 0) return 0;
                    arr.sort((a,b) => a-b);
                    const idx = Math.max(0, Math.floor(arr.length * 0.2) - 1);
                    return arr[idx];
                } catch (_) { return 0; }
            };

            // Current level bitrate (bps)
            const getCurrentBitrate = () => {
                try {
                    const lvl = this.hls?.levels?.[this.hls.currentLevel] || this.hls?.levels?.[0];
                    const br = lvl?.bitrate;
                    return typeof br === 'number' && br > 0 ? br : 0;
                } catch (_) { return 0; }
            };

            // Map headroom to threshold seconds
            const mapHeadroomToThreshold = (headroom, segDur) => {
                let thr;
                if (headroom >= 2.0) thr = 10;
                else if (headroom >= 1.5) thr = 12;
                else if (headroom >= 1.2) thr = 15;
                else if (headroom >= 1.0) thr = 20;
                else thr = 28;
                const segsMin = Math.max(MIN_PREBUFFER_SEGMENTS, 3) * segDur;
                thr = Math.max(thr, MIN_PREBUFFER_SECONDS, segsMin);
                return thr;
            };

            const attemptStart = () => {
                const bufferAhead = getBufferAhead();
                const ready = video.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA;
                const segDur = getSegmentDuration();
                const bufferedSegments = segDur > 0 ? bufferAhead / segDur : 0;

                let threshold = Math.max(MIN_PREBUFFER_SECONDS, MIN_PREBUFFER_SEGMENTS * segDur);
                if ((this.throughputSamples?.length || 0) >= MIN_THROUGHPUT_SAMPLES) {
                    const tp = getConservativeThroughput();
                    const br = getCurrentBitrate();
                    const headroom = (tp > 0 && br > 0) ? (tp / br) : 0;
                    threshold = mapHeadroomToThreshold(headroom, segDur);

                    // Optional gentle capping if headroom looks weak before start
                    try {
                        if (headroom < 1.2 && Array.isArray(this.hls?.levels) && this.hls.levels.length) {
                            const cur = this.hls.currentLevel;
                            const cap = Math.max(0, typeof cur === 'number' && cur >= 0 ? cur - 1 : 0);
                            this.hls.autoLevelCapping = cap;
                        }
                    } catch (_) {}

                    if (this.enableDebug) {
                        try { this.debugLog(`[hs-video] gate: headroom=${headroom.toFixed(2)} thr=${threshold.toFixed(1)}s seg=${segDur}s buf=${bufferAhead.toFixed(1)}s segs=${bufferedSegments.toFixed(1)}`); } catch (_) {}
                    }
                }

                // Update on-screen debug panel instead of console
                this._updateDebugPanel({ bufferAhead, inProgress: ready, clicked: hasUserGesture });

                const enoughBuffer = bufferAhead >= threshold && bufferedSegments >= MIN_PREBUFFER_SEGMENTS;
                const timeoutReached = this.prebufferStartTs && (Date.now() - this.prebufferStartTs >= PREBUFFER_TIMEOUT_MS);

                // If a fatal timer is running, reset it whenever buffering makes
                // meaningful progress.  This avoids triggering the fatal
                // overlay on slow connections where the buffer is steadily
                // filling.  A threshold of ~2 seconds prevents rapid
                // restarts on minor fluctuations.
                try {
                    if (this.userGestureUnlocked && this.fatalTimer) {
                        const thresholdProgress = 2; // seconds
                        const lastLevel = (typeof this.fatalBufferLevel === 'number') ? this.fatalBufferLevel : 0;
                        if (bufferAhead > lastLevel + thresholdProgress) {
                            this.startFatalTimer(bufferAhead);
                        }
                    }
                } catch (_) {}
                if (hasUserGesture && ready && (enoughBuffer || timeoutReached)) {
                        clearInterval(this.bufferGateInterval);
                        this.bufferGateInterval = null;
                        this.pendingPlayRequest = false;
                        try { this.hls.autoLevelCapping = -1; } catch (_) {}
                        // Start fatal countdown now that we are attempting to play.  If
                        // playback does not begin within FATAL_TIMEOUT_MS, the player
                        // will enter the fatal state.  This avoids false fatal
                        // triggers during prebuffering.
                    try { this.startFatalTimer(bufferAhead); } catch (_) {}
                        video.play().catch(err => console.error('Playback start failed:', err));
                }
            };

            if (!this.bufferGateInterval) {
                this.bufferGateInterval = setInterval(attemptStart, GATE_CHECK_INTERVAL_MS);
                attemptStart();
            }
        } catch (_) {}
    }

    // Probe the manifest URL via fetch (CORS) and start Hls.js once reachable
    _probeManifestAndStart(streamUrl) {
        const attempt = async () => {
            try {
                const sep = streamUrl.includes('?') ? '&' : '?';
                const probeUrl = `${streamUrl}${sep}_cb=${Date.now()}`;
                const res = await fetch(probeUrl, { method: 'GET', mode: 'cors', credentials: 'omit', cache: 'no-store' });
                if (res && res.ok) {
                    if (this.manifestProbeInterval) { clearInterval(this.manifestProbeInterval); this.manifestProbeInterval = null; }
                    // Give the origin a brief grace period before starting Hls.js loads
                    this.hls.loadSource(streamUrl);
                    setTimeout(() => {
                        try { this.hls.startLoad(-1); } catch(_) { this.hls.startLoad(); }
                    }, 2000);
                    return;
                }
            } catch (_) { /* not ready yet */ }
        };
        // reset and start after initial delay (skip if server said ready), then fixed interval probes
        if (this.manifestProbeInterval) { try { clearInterval(this.manifestProbeInterval); } catch(_) {} }
        const delay = this.serverPlaylistReady ? 0 : INITIAL_MANIFEST_DELAY_MS;
        setTimeout(() => {
            attempt();
            this.manifestProbeInterval = setInterval(attempt, MANIFEST_PROBE_INTERVAL_MS);
        }, delay);
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
        } catch (_) {}
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
                        this.enterFatalState();
                    }
                } catch (_) {}
            }, FATAL_TIMEOUT_MS);
        } catch (_) {}
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
        } catch (_) {}
    }

    /**
     * Enter the fatal state and display the fatal poster.  This stops
     * polling and playback, destroys the Hls.js instance, and resets
     * internal timers.  Once in the fatal state the player will not
     * automatically recover; the viewer must refresh the page.
     */
    enterFatalState() {
        try {
            if (this.playerState === STATE.FATAL) return;
            this.debugLog('Fatal error encountered; entering fatal state');
            // Cancel timers
            this.clearFatalTimer();
            if (this.bufferGateInterval) {
                try { clearInterval(this.bufferGateInterval); } catch (_) {}
                this.bufferGateInterval = null;
            }
            if (this.manifestProbeInterval) {
                try { clearInterval(this.manifestProbeInterval); } catch (_) {}
                this.manifestProbeInterval = null;
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
            // Destroy any existing Hls.js instance
            if (this.hls) {
                try { this.hls.destroy(); } catch (_) {}
                this.hls = null;
            }
            const video = this.videoEl || this.shadowRoot.querySelector('video');
            // Pause playback and hide controls
            try { video.pause(); } catch (_) {}
            try { video.controls = false; } catch (_) {}
                // Display fatal poster; allow override via attribute
                try {
                    const posterUrl = this.getAttribute('poster-fatal');
                    this.setPoster(posterUrl, 'fatal');
                } catch (_) {}
                // Hide overlay and play button so the fatal poster is visible
                try {
                    if (this.overlayEl) this.overlayEl.style.display = 'none';
                    if (this.playButtonEl) this.playButtonEl.style.display = 'none';
                } catch (_) {}
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
        } catch (_) {}
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
        } catch (_) {}
    }
}

customElements.define('hs-video', HSVideoElement);
