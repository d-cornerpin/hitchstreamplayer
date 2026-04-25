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

export const FATAL_TIMEOUT_MS = 45000;

export const PREBUFFER_TIMEOUT_MS = 60000;

export const MANIFEST_PROBE_INTERVAL_MS = 1500;
export const MANIFEST_PROBE_INITIAL_DELAY_MS = 5000;
export const MANIFEST_PROBE_MAX_ATTEMPTS = 40;

export const LATENCY_LOG_INTERVAL_MS = 15000;
export const GATE_CHECK_INTERVAL_MS = 1000;

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
