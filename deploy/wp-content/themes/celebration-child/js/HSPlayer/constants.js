// constants.js — HitchStream Player v2
// Pure constants with no imports. Imported by all HSPlayer modules.

// ─── Player state enum ───────────────────────────────────────────────
export const STATE = {
  IDLE: 'IDLE',
  PLAYING: 'PLAYING',
  PREPARING: 'PREPARING',
  FATAL: 'FATAL',
};

// ─── Status text enum (what the overlay displays) ────────────────────
export const STATUS = {
  NONE: 'none',
  WAITING: 'waiting',
  PREPARING: 'preparing',
  LIVE: 'live',
  RECONNECTING: 'reconnecting',
  PAUSED: 'paused',
  ERROR: 'error',
};

// ─── Timing constants ───────────────────────────────────────────────
export const POLL_INITIAL_DELAY_MS = 3000;
export const POLL_INTERVAL_MS = 10000;
export const POLL_BACKOFF_MAX_MS = 60000;
export const POLL_BACKOFF_AFTER_ERRORS = 3;
export const POLL_BACKOFF_JITTER_MS = 1500;
export const POLL_TIMEOUT_MS = 8000;

// Must stay LONGER than PREBUFFER_TIMEOUT_MS: on a slow connection the prebuffer
// gate's own 60s timeout makes a final playback attempt — the fatal timer must
// not fire first, or viewers on weak venue Wi-Fi get a false "please refresh"
// right as the stream was about to start.
export const FATAL_TIMEOUT_MS = 75000;

export const PREBUFFER_TIMEOUT_MS = 60000;

export const MANIFEST_PROBE_INTERVAL_MS = 1500;
export const MANIFEST_PROBE_INITIAL_DELAY_MS = 5000;
export const MANIFEST_PROBE_MAX_ATTEMPTS = 40;

export const LATENCY_LOG_INTERVAL_MS = 15000;
export const GATE_CHECK_INTERVAL_MS = 1000;

// ─── Poster crossfade (UI polish) ───────────────────────────────────
// Duration of the poster<->video crossfade, and how many seconds of buffer
// must remain when a stream stops before we begin fading back to the poster
// (so the fade completes while there is still video to play under it).
export const POSTER_CROSSFADE_MS = 5000;
// VOD is tap-to-play, so its poster→video reveal should feel immediate, not the
// slow cinematic crossfade live uses while it buffers.
export const VOD_CROSSFADE_MS = 500;
export const POSTER_FADEOUT_LEAD_SECONDS = 10;
// Hold the poster after playback starts until the picture ramps up to at least
// this many vertical pixels (hides the low-res ABR warm-up), or until the
// max-wait elapses (so a genuinely low-bandwidth viewer is still revealed).
export const POSTER_REVEAL_MIN_HEIGHT = 720;
export const POSTER_REVEAL_MAX_WAIT_MS = 10000;

// Under-logo poster messages. Shown ONLY after the play button is pressed, and
// ONLY until the stream has played once (after that we can't know why a stream
// stopped, so we make no claim — no text). Cleared naturally on page refresh.
// Each is a pool — the player picks one at random when it enters that state.
export const POSTER_MESSAGES = {
  idle: [
    'Waiting for an incoming stream',
    'The celebration is about to begin',
    'Saving you a seat',
    'The happy couple will be with you shortly',
    'Getting ready for the big moment',
    'Love is on its way',
    'Almost time to celebrate',
    'Your front-row seat is ready',
    'Gathering everyone together',
    'The moment is almost here',
  ],
  preparing: [
    'Stream will begin in a moment',
    'Setting the scene',
    'Finding our place',
    'Cueing things up',
    'Getting the picture just right',
    'Bringing you in',
    'Almost ready',
    'Here we go',
    'Tuning in',
    'Just a moment',
  ],
  recovering: [
    'Sorry. One sec',
    'Be right back',
    'Pardon the interruption',
    'Catching our breath',
    'Hang tight',
    'Back in a blink',
    'Smoothing things out',
    'Reconnecting',
    "Don't go anywhere",
    'Just a quick moment',
  ],
  fatal: [
    "Something's not quite right — please refresh the page",
    "Let's try that again — please refresh the page",
    'We hit a snag — please refresh the page to reconnect',
    'Lost the connection — please refresh the page to rejoin',
    'A little hiccup — please refresh the page',
    'Please refresh the page to rejoin the celebration',
    'To get back to the love, please refresh the page',
    'Looks like we stalled — please refresh the page',
    'Our apologies — please refresh the page to continue',
    'One quick fix: please refresh the page',
  ],
};
// How long the under-logo message takes to fade out as the video reveals
// (a bit shorter than the poster crossfade so the text clears first).
export const POSTER_MESSAGE_FADE_MS = 3000;

// Recovery after the stream has already played once: resume at a small buffer
// with a quick fade, then let Hls.js refill the deep buffer in the background.
export const FAST_RECOVERY_PREBUFFER_SECONDS = 5;
export const FAST_RECOVERY_FADE_MS = 1000;
// If the buffer's leading edge stops advancing while the stream is live, the
// feed has stalled (dead edge) — recover after this long, without waiting for
// the buffer to drain. Must exceed the segment interval to avoid false alarms.
export const STALL_RECOVERY_MS = 6000;
// Once the feed is confirmed stalled, keep playing the existing buffer down to
// about this many seconds before showing the "one sec" card and rebuilding —
// so the viewer sees as much of the stream as possible first.
export const RECOVERY_BUFFER_FLOOR_SECONDS = 5;

// ─── Prebuffer gate constants ───────────────────────────────────────
export const MIN_PREBUFFER_SECONDS = 10;
export const MIN_PREBUFFER_SEGMENTS = 3;
export const MIN_THROUGHPUT_SAMPLES = 3;
export const MAX_THROUGHPUT_SAMPLES = 10;

// ─── Audio/video drift ──────────────────────────────────────────────
export const AUDIO_DRIFT_FRAMES_THRESHOLD = 4;

// ─── Media error recovery ───────────────────────────────────────────
export const MAX_MEDIA_ERROR_RECOVERY_ATTEMPTS = 3;
export const MAX_NETWORK_ERROR_RECOVERY_ATTEMPTS = 2;
export const NETWORK_ERROR_RECOVERY_BACKOFF_MS = [2000, 5000];

export const RECONNECT_WATCHDOG_INTERVAL_MS = 1000;
export const RECONNECT_WATCHDOG_BUFFER_THRESHOLD = 2.0;
export const RECONNECT_WATCHDOG_FATAL_TTL = 90000;
export const RECOVERABLE_NETWORK_DETAILS = [
  'manifestLoadError',
  'manifestLoadTimeOut',
  'levelLoadError',
  'levelLoadTimeOut',
  'fragLoadError',
  'fragLoadTimeOut',
];

// ─── Poster URL defaults (overridden by HSPlayerConfig.posters or URL params) ───
export const DEFAULT_POSTER_INITIAL_URL =
  'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Initial_Default.png';
export const DEFAULT_POSTER_IDLE_URL =
  'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Idle_Default.png';
export const DEFAULT_POSTER_FATAL_URL =
  'https://hitchstream.com/wp-content/uploads/2025/09/Poster_fatal_2.png';

// ─── HLS origin allowlist regex (contract §4.3) ─────────────────────
export const HLS_ORIGIN_ALLOWLIST_REGEX =
  /^https:\/\/customer-[a-z0-9]{12,20}\.cloudflarestream\.com\/[A-Za-z0-9]+\/manifest\/video\.m3u8(\?.*)?$/;

// ─── Hls.js configuration (single frozen config object) ─────────────
export const HLS_CONFIG = {
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
  // Increase tolerance for audio/video drift
  maxAudioFramesDrift: 30,
  // Conservative startup
  startLevel: 0,
  capLevelToPlayerSize: true,
  startOnSegmentBoundary: true,
  startFragPrefetch: true,
  autoStartLoad: false,
  // Retry config (deduplicated — A1.7/B15 fix)
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
};

// ─── Viewer-facing error messages (from contract §4.4 errorMessages) ─
// Only these codes produce viewer-facing text. All others fall through to idle UX.
export const CF_ERROR_MESSAGES = {
  ERR_STORAGE_QUOTA_EXHAUSTED: 'Service unavailable',
  ERR_MISSING_SUBSCRIPTION: 'Service unavailable',
};

// ─── Debug-panel error messages (non-viewer-facing, for diagnostics) ─
// Mapped from the poll callback in the original player.
export const DEBUG_ERROR_MESSAGES = {
  ERR_GOP_OUT_OF_RANGE: 'Stream issue — restarting...',
  ERR_UNSUPPORTED_VIDEO_CODEC: 'Stream codec error',
  ERR_UNSUPPORTED_AUDIO_CODEC: 'Audio stream error',
  ERR_STORAGE_QUOTA_EXHAUSTED: 'Service temporarily unavailable',
  ERR_MISSING_SUBSCRIPTION: 'Service unavailable',
};
