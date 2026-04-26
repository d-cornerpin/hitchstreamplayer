// PrebufferGate.js unit tests — A3.5
// Run: node --test celebration-child/js/__tests__/PrebufferGate.test.js

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import * as PG from '../../js/HSPlayer/PrebufferGate.js';

// ─── getConservativeThroughput ──

describe('getConservativeThroughput', () => {
  it('empty array returns 0', () => {
    assert.equal(PG.getConservativeThroughput([]), 0);
  });

  it('single sample returns that sample', () => {
    assert.equal(PG.getConservativeThroughput([5000]), 5000);
  });

  it('20th percentile of sorted array', () => {
    // 10 samples: index = floor(10 * 0.2) - 1 = 1
    const samples = [1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000];
    assert.equal(PG.getConservativeThroughput(samples), 2000);
  });

  it('unsorted input returns correct 20th percentile', () => {
    const samples = [10000, 5000, 2000, 8000, 3000, 6000, 1000, 9000, 4000, 7000];
    // Sorted: 1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000
    // 20th percentile = 2000
    assert.equal(PG.getConservativeThroughput(samples), 2000);
  });
});

// ─── getCurrentBitrate ──

describe('getCurrentBitrate', () => {
  it('undefined hls returns 0', () => {
    assert.equal(PG.getCurrentBitrate(null), 0);
  });

  it('hls with levels returns current level bitrate', () => {
    const hls = {
      currentLevel: 0,
      levels: [{ bitrate: 2500000 }],
    };
    assert.equal(PG.getCurrentBitrate(hls), 2500000);
  });

  it('hls with no bitrate returns 0', () => {
    const hls = { currentLevel: 0, levels: [{}] };
    assert.equal(PG.getCurrentBitrate(hls), 0);
  });
});

// ─── getSegmentDuration ──

describe('getSegmentDuration', () => {
  it('undefined hls returns 4 (default)', () => {
    assert.equal(PG.getSegmentDuration(null), 4);
  });

  it('hls with level details returns targetduration', () => {
    const hls = {
      currentLevel: 0,
      levels: [{ details: { targetduration: 6 } }],
    };
    assert.equal(PG.getSegmentDuration(hls), 6);
  });

  it('hls with missing targetduration returns 4', () => {
    const hls = { currentLevel: 0, levels: [{}] };
    assert.equal(PG.getSegmentDuration(hls), 4);
  });
});

// ─── shouldStartPlayback ──

describe('shouldStartPlayback', () => {
  const baseCtx = {
    bufferAhead: 10,
    bufferedSegments: 3,
    ready: true,
    hasUserGesture: true,
    prebufferStartTs: 0,
    thresholdSecs: 10,
    headroom: 2.0,
    hls: null,
    throughputSamples: [],
  };

  it('no gesture returns false', () => {
    assert.equal(PG.shouldStartPlayback({ ...baseCtx, hasUserGesture: false }).shouldStart, false);
  });

  it('not ready returns false', () => {
    assert.equal(PG.shouldStartPlayback({ ...baseCtx, ready: false }).shouldStart, false);
  });

  it('sufficient buffer returns true', () => {
    const result = PG.shouldStartPlayback({ ...baseCtx, bufferAhead: 10, thresholdSecs: 10 });
    assert.equal(result.shouldStart, true);
    assert.equal(result.reason, 'enoughBuffer');
  });

  it('insufficient buffer returns false', () => {
    const result = PG.shouldStartPlayback({ ...baseCtx, bufferAhead: 5, thresholdSecs: 10 });
    assert.equal(result.shouldStart, false);
    assert.equal(result.reason, 'insufficientBuffer');
  });

  it('timeout reaches forces start even without enough buffer', () => {
    const result = PG.shouldStartPlayback({
      ...baseCtx,
      bufferAhead: 5,
      thresholdSecs: 10,
      prebufferStartTs: Date.now() - 61000, // 61s ago
    });
    assert.equal(result.shouldStart, true);
    assert.equal(result.reason, 'enoughBuffer');
  });

  it('insufficient segments prevents start even with time threshold', () => {
    const result = PG.shouldStartPlayback({
      ...baseCtx,
      bufferAhead: 15,
      bufferedSegments: 1,
      thresholdSecs: 10,
    });
    assert.equal(result.shouldStart, false);
    assert.equal(result.reason, 'insufficientBuffer');
  });
});

// ─── computeGateContext ──

describe('computeGateContext', () => {
  it('zero buffer returns zero bufferAhead', () => {
    const ctx = PG.computeGateContext({
      hls: null,
      currentTime: 0,
      buffered: [],
      throughputSamples: [],
    });
    assert.equal(ctx.bufferAhead, 0);
    assert.equal(ctx.bufferedSegments, 0);
  });

  it('thick buffer with known levels computes threshold', () => {
    const hls = {
      currentLevel: 0,
      levels: [{ bitrate: 5000000, details: { targetduration: 6 } }],
    };
    const ctx = PG.computeGateContext({
      hls,
      currentTime: 5,
      buffered: [{ start: () => 0, end: () => 15 }],
      throughputSamples: [1000000, 2000000, 3000000],
    });
    assert.ok(ctx.bufferAhead > 0);
    assert.ok(ctx.thresholdSecs >= 10); // MIN_PREBUFFER_SECONDS
    assert.ok(ctx.headroom >= 0);
  });

  it('thin buffer with high throughput raises threshold', () => {
    const hls = {
      currentLevel: 0,
      levels: [{ bitrate: 5000000, details: { targetduration: 6 } }],
    };
    const ctx = PG.computeGateContext({
      hls,
      currentTime: 5,
      buffered: [{ start: () => 0, end: () => 7 }], // 2s ahead
      throughputSamples: [1000000, 1500000, 2000000], // 20th percentile = 1M
    });
    // headroom = 1M / 5M = 0.2 → threshold = 28s (weak headroom bucket)
    assert.ok(ctx.thresholdSecs >= 20);
    assert.ok(ctx.headroom < 0.5);
  });

  it('no throughput samples uses MIN_PREBUFFER_SECONDS base', () => {
    const hls = {
      currentLevel: 0,
      levels: [{ bitrate: 5000000, details: { targetduration: 6 } }],
    };
    const ctx = PG.computeGateContext({
      hls,
      currentTime: 5,
      buffered: [{ start: () => 0, end: () => 15 }],
      throughputSamples: [1000], // only 1 sample, below MIN_THROUGHPUT_SAMPLES
    });
    // Base threshold = MIN_PREBUFFER_SECONDS = 10
    // But segment-floor = 3 * 6 = 18 overrides
    assert.equal(ctx.thresholdSecs, 18); // max(10, 18) = 18
    assert.equal(ctx.headroom, 0); // below MIN_THROUGHPUT_SAMPLES
  });
});

// ─── mapHeadroomToThreshold (via computeGateContext) ──

describe('headroom threshold mapping', () => {
  const hls = {
    currentLevel: 0,
    levels: [{ bitrate: 5000000, details: { targetduration: 6 } }],
  };

  function thresholdForHeadroom(headroomBps) {
    const samples = Array(3).fill(headroomBps);
    const ctx = PG.computeGateContext({
      hls,
      currentTime: 5,
      buffered: [{ start: () => 0, end: () => 30 }],
      throughputSamples: samples,
    });
    return ctx.thresholdSecs;
  }

  it('headroom >= 2.0 → threshold uses 12s base (capped by segment-floor)', () => {
    const thr = thresholdForHeadroom(10000000); // throughput 10Mbps vs bitrate 5Mbps = 2.0
    // Base threshold = 12s, but segment-floor (3 * segDur = 18) dominates
    assert.ok(thr >= 12, 'base threshold should be >= 12s');
    assert.ok(thr <= 18, 'should not exceed segment-floor of 18s');
  });

  it('headroom < 1.0 → threshold ~28s', () => {
    const thr = thresholdForHeadroom(400000); // throughput 400kbps vs bitrate 5Mbps = 0.08
    assert.ok(thr >= 20);
  });
});
