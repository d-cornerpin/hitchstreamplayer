// PrebufferGate.js — HitchStream Player v2
// Pure functions for prebuffer gate decisions. No timers, no DOM.
// The element calls shouldStartPlayback(context) periodically (GATE_CHECK_INTERVAL_MS).

import {
  MIN_PREBUFFER_SECONDS,
  MIN_PREBUFFER_SEGMENTS,
  MIN_THROUGHPUT_SAMPLES,
  PREBUFFER_TIMEOUT_MS,
  MAX_THROUGHPUT_SAMPLES,
} from './constants.js';

/**
 * Estimate 20th-percentile throughput from samples (bps).
 * @param {number[]} samples
 * @returns {number} bps or 0
 */
export function getConservativeThroughput(samples) {
  const arr = (samples || []).slice();
  if (arr.length === 0) return 0;
  arr.sort((a, b) => a - b);
  const idx = Math.max(0, Math.floor(arr.length * 0.2) - 1);
  return arr[idx];
}

/**
 * Map headroom ratio to threshold seconds using heuristic buckets.
 * @param {number} headroom - ratio of throughput / current level bitrate
 * @param {number} segDur - segment duration in seconds
 * @returns {number} threshold in seconds
 */
function mapHeadroomToThreshold(headroom, segDur) {
  let thr;
  if (headroom >= 2.0) thr = 10;
  else if (headroom >= 1.5) thr = 12;
  else if (headroom >= 1.2) thr = 15;
  else if (headroom >= 1.0) thr = 20;
  else thr = 28;
  const segsMin = Math.max(MIN_PREBUFFER_SEGMENTS, 3) * segDur;
  thr = Math.max(thr, MIN_PREBUFFER_SECONDS, segsMin);
  return thr;
}

/**
 * Get current level bitrate from HLS engine.
 * @param {object} hls - Hls.js instance (optional)
 * @returns {number} bitrate bps or 0
 */
export function getCurrentBitrate(hls) {
  try {
    const lvl = hls?.levels?.[hls.currentLevel] || hls?.levels?.[0];
    const br = lvl?.bitrate;
    return typeof br === 'number' && br > 0 ? br : 0;
  } catch (_) { return 0; }
}

/**
 * Get segment duration from HLS engine.
 * @param {object} hls - Hls.js instance (optional)
 * @returns {number} segment duration in seconds or 4
 */
export function getSegmentDuration(hls) {
  try {
    const lvl = hls?.levels?.[hls.currentLevel] || hls?.levels?.[0];
    const td = lvl?.details?.targetduration;
    return typeof td === 'number' && td > 0 ? td : 4;
  } catch (_) { return 4; }
}

/**
 * Check if enough buffer for playback start.
 * @param {object} ctx
 * @param {number}  ctx.bufferAhead - seconds of buffer ahead of currentTime
 * @param {number}  ctx.bufferedSegments - seconds of buffered content
 * @param {number}  ctx.ready - video.readyState >= HAVE_FUTURE_DATA
 * @param {number}  ctx.hasUserGesture - boolean
 * @param {number}  ctx.prebufferStartTs - timestamp when prebuffering started (0 if none)
 * @param {number}  ctx.thresholdSecs - computed threshold
 * @param {number}  ctx.headroom - throughput/headroom ratio
 * @param {object}  ctx.hls - Hls.js instance (optional, for level capping)
 * @param {number[]} ctx.throughputSamples - throughput samples in bps
 * @returns {{ shouldStart: boolean, reason: string }}
 */
export function shouldStartPlayback(ctx) {
  const { bufferAhead, bufferedSegments, ready, hasUserGesture, prebufferStartTs, thresholdSecs, headroom, hls, throughputSamples } = ctx;

  if (!hasUserGesture) return { shouldStart: false, reason: 'noGesture' };
  if (!ready) return { shouldStart: false, reason: 'notReady' };

  const enoughBuffer = bufferAhead >= thresholdSecs && bufferedSegments >= MIN_PREBUFFER_SEGMENTS;
  const timeoutReached = prebufferStartTs > 0 && (Date.now() - prebufferStartTs >= PREBUFFER_TIMEOUT_MS);

  if (!enoughBuffer && !timeoutReached) return { shouldStart: false, reason: 'insufficientBuffer' };

  // Apply gentle level capping if headroom looks weak
  if (headroom < 1.2 && throughputSamples?.length >= MIN_THROUGHPUT_SAMPLES) {
    try {
      if (Array.isArray(hls?.levels) && hls.levels.length) {
        const cur = hls.currentLevel;
        const cap = Math.max(0, typeof cur === 'number' && cur >= 0 ? cur - 1 : 0);
        hls.autoLevelCapping = cap;
      }
    } catch (_) {}
  }

  return { shouldStart: true, reason: 'enoughBuffer' };
}

/**
 * Compute the full prebuffer gate context for a poll=live response.
 * @param {object} opts
 * @param {object} opts.hls - Hls.js instance (optional)
 * @param {number} opts.currentTime - video currentTime
 * @param {number[]} opts.buffered - HTMLMediaElement.buffered-like array of {start, end}
 * @param {number[]} opts.throughputSamples
 * @returns {{ bufferAhead, bufferedSegments, thresholdSecs, headroom, segDur }}
 */
export function computeGateContext(opts) {
  const { hls, currentTime, buffered, throughputSamples: samples } = opts;
  const segDur = getSegmentDuration(hls);

  // Measure buffer ahead of currentTime
  let bufferAhead = 0;
  if (buffered?.length) {
    for (let i = 0; i < buffered.length; i++) {
      const start = buffered[i].start?.(0) ?? buffered[i].start;
      const end = buffered[i].end?.(0) ?? buffered[i].end;
      if (typeof start === 'number' && typeof end === 'number' && currentTime >= start && currentTime <= end) {
        bufferAhead = Math.max(0, end - currentTime);
        break;
      }
    }
    if (bufferAhead === 0 && buffered.length > 0) {
      const last = buffered[buffered.length - 1];
      const end = last.end?.(0) ?? last.end;
      bufferAhead = Math.max(0, (typeof end === 'number' ? end : 0) - currentTime);
    }
  }

  const bufferedSegments = segDur > 0 ? bufferAhead / segDur : 0;

  // Compute threshold
  let thresholdSecs = Math.max(MIN_PREBUFFER_SECONDS, MIN_PREBUFFER_SEGMENTS * segDur);
  let headroom = 0;

  if ((samples?.length || 0) >= MIN_THROUGHPUT_SAMPLES) {
    const tp = getConservativeThroughput(samples);
    const br = getCurrentBitrate(hls);
    headroom = (tp > 0 && br > 0) ? (tp / br) : 0;
    thresholdSecs = mapHeadroomToThreshold(headroom, segDur);
  }

  return { bufferAhead, bufferedSegments, thresholdSecs, headroom, segDur };
}
