// PlayerStateMachine unit tests — A2.3
// Run: node --test celebration-child/js/__tests__/PlayerStateMachine.test.js

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { transition, STATE, STATUS } from '../../js/HSPlayer/PlayerStateMachine.js';

// ─── Helpers ──

function makeContext(overrides = {}) {
  return {
    hasPlayedOnce: false,
    userGestureUnlocked: false,
    bufferAhead: 0,
    hasBufferedContent: false,
    currentVideoUID: null,
    ...overrides,
  };
}

function makeEvent(type, payload = {}) {
  return { type, payload };
}

function check(input, expectedState, expectedEffectTypes, ctx) {
  const result = transition({
    currentState: input.currentState,
    event: input.event,
    context: input.context,
  });
  assert.equal(result.nextState, expectedState,
    `transition from ${input.currentState} + event ${input.event.type}`);
  if (expectedEffectTypes) {
    const actualTypes = result.sideEffects.map(s => s.type);
    for (const t of expectedEffectTypes) {
      assert.ok(actualTypes.includes(t),
        `expected side-effect "${t}" in [${actualTypes.join(', ')}]`);
    }
  }
  return result;
}

// ─── Tests ──

describe('PlayerStateMachine', () => {

  // ── IDLE state ──

  describe('STATE.IDLE', () => {

    it('idle + poll=live(videoUID+hlsUrl) → PREPARING', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('poll', { state: 'live', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8', source: 'webhook' }),
        context: makeContext(),
      }, STATE.PREPARING, ['loadHls', 'showStatus']);
    });

    it('idle + poll=live(videoUID but no hlsUrl) → IDLE', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('poll', { state: 'live', videoUID: 'abc123', hlsUrl: null, source: 'webhook' }),
        context: makeContext(),
      }, STATE.IDLE, []);
    });

    it('idle + poll=reconnecting → IDLE', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('poll', { state: 'reconnecting', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext(),
      }, STATE.IDLE, []);
    });

    it('idle + poll=idle → IDLE', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext(),
      }, STATE.IDLE, []);
    });

    it('idle + poll=error → IDLE + logError', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('poll', { state: 'error', errorCode: 'ERR_GOP_OUT_OF_RANGE', source: 'webhook' }),
        context: makeContext(),
      }, STATE.IDLE, ['logError']);
    });

    it('idle + clickPlay → IDLE + showStatus=waiting', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('clickPlay'),
        context: makeContext(),
      }, STATE.IDLE, ['showStatus']);
    });

    it('idle + unknown event → IDLE', () => {
      check({
        currentState: STATE.IDLE,
        event: makeEvent('bufferDrained'),
        context: makeContext(),
      }, STATE.IDLE, []);
    });
  });

  // ── PREPARING state ──

  describe('STATE.PREPARING', () => {

    it('preparing + poll=live(videoUID+hlsUrl) → PREPARING (no change)', () => {
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'live', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext(),
      }, STATE.PREPARING, []);
    });

    it('preparing + poll=live(missing hlsUrl) → IDLE', () => {
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'live', videoUID: 'abc123', hlsUrl: null }),
        context: makeContext(),
      }, STATE.IDLE, ['setPoster', 'showStatus']);
    });

    it('preparing + poll=reconnecting → PREPARING (no change)', () => {
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'reconnecting', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext(),
      }, STATE.PREPARING, []);
    });

    it('preparing + poll=idle → IDLE + idle poster (hasPlayedOnce=true)', () => {
      const ctx = makeContext({ hasPlayedOnce: true, userGestureUnlocked: true });
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: ctx,
      }, STATE.IDLE, ['setPoster', 'showStatus']);
    });

    it('preparing + poll=idle → IDLE + initial poster (hasPlayedOnce=false)', () => {
      const ctx = makeContext({ hasPlayedOnce: false });
      const result = check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: ctx,
      }, STATE.IDLE, ['setPoster', 'showStatus']);
      const posterEffect = result.sideEffects.find(s => s.type === 'setPoster');
      assert.equal(posterEffect.payload.which, 'initial');
    });

    it('preparing + poll=error → IDLE + logError', () => {
      const ctx = makeContext({ hasPlayedOnce: true });
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'error', errorCode: 'ERR_MISSING_SUBSCRIPTION', source: 'webhook' }),
        context: ctx,
      }, STATE.IDLE, ['logError', 'setPoster', 'showStatus']);
    });

    it('preparing + prebufferReady → PLAYING', () => {
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('prebufferReady'),
        context: makeContext({ userGestureUnlocked: true }),
      }, STATE.PLAYING, ['startPlayback', 'showStatus']);
    });

    it('preparing + videoUIDChanged → PREPARING + rebuildHls', () => {
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('videoUIDChanged', { newVideoUID: 'xyz789' }),
        context: makeContext(),
      }, STATE.PREPARING, ['rebuildHls']);
    });
  });

  // ── PLAYING state ──

  describe('STATE.PLAYING', () => {

    it('playing + poll=live(same videoUID) → PLAYING (no change)', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'live', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext({ currentVideoUID: 'abc123' }),
      }, STATE.PLAYING, []);
    });

    it('playing + poll=live(new videoUID) → PLAYING + handover', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'live', videoUID: 'xyz789', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext({ currentVideoUID: 'abc123' }),
      }, STATE.PLAYING, ['handover']);
    });

    it('playing + poll=reconnecting + buffer充足 → PLAYING', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'reconnecting', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 5 }),
      }, STATE.PLAYING, ['showStatus']);
    });

    it('playing + poll=reconnecting + buffer drained → IDLE', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'reconnecting', videoUID: 'abc123', hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0 }),
      }, STATE.IDLE, ['setPoster', 'showStatus']);
    });

    it('playing + poll=idle + buffer充足 → PLAYING + drainToIdle', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 3 }),
      }, STATE.PLAYING, ['drainToIdle', 'showStatus']);
    });

    it('playing + poll=idle + buffer drained → IDLE', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0 }),
      }, STATE.IDLE, ['destroyHls', 'setPoster', 'showStatus']);
    });

    it('playing + poll=idle + low buffer → IDLE', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 0.3 }),
      }, STATE.IDLE, ['destroyHls', 'setPoster', 'showStatus']);
    });

    it('playing + poll=error + buffer充足 → PLAYING + drainToIdle', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'error', errorCode: 'ERR_GOP_OUT_OF_RANGE' }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 3 }),
      }, STATE.PLAYING, ['drainToIdle', 'showStatus', 'logError']);
    });

    it('playing + poll=error + buffer drained → IDLE', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'error', errorCode: 'ERR_GOP_OUT_OF_RANGE' }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0 }),
      }, STATE.IDLE, ['destroyHls', 'setPoster', 'showStatus', 'logError']);
    });

    it('playing + bufferDrained → IDLE', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('bufferDrained'),
        context: makeContext(),
      }, STATE.IDLE, ['destroyHls', 'setPoster', 'showStatus']);
    });

    it('playing + videoUIDChanged → PLAYING + handover', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('videoUIDChanged', { newVideoUID: 'new-uid' }),
        context: makeContext({ currentVideoUID: 'abc123' }),
      }, STATE.PLAYING, ['handover']);
    });

    it('playing + networkError → PLAYING (engine recovers)', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('networkError'),
        context: makeContext({ currentVideoUID: 'abc123' }),
      }, STATE.PLAYING, ['showStatus']);
    });
  });

  // ── FATAL state ──

  describe('STATE.FATAL — terminal', () => {

    it('fatal + poll=live → FATAL (no transition)', () => {
      check({
        currentState: STATE.FATAL,
        event: makeEvent('poll', { state: 'live', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext(),
      }, STATE.FATAL, []);
    });

    it('fatal + poll=idle → FATAL (no transition)', () => {
      check({
        currentState: STATE.FATAL,
        event: makeEvent('poll', { state: 'idle' }),
        context: makeContext(),
      }, STATE.FATAL, []);
    });

    it('fatal + clickPlay → FATAL (no transition)', () => {
      check({
        currentState: STATE.FATAL,
        event: makeEvent('clickPlay'),
        context: makeContext(),
      }, STATE.FATAL, []);
    });

    it('fatal + prebufferReady → FATAL (no transition)', () => {
      check({
        currentState: STATE.FATAL,
        event: makeEvent('prebufferReady'),
        context: makeContext(),
      }, STATE.FATAL, []);
    });
  });

  // ── Mid-event state flapping (contract §4.2 correctness) ──

  describe('Mid-event state flapping', () => {

    it('PLAYING + poll=idle + bufferAhead > 0.5 → drainToIdle (A1.12)', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 3 }),
      }, STATE.PLAYING, ['drainToIdle', 'showStatus']);
      const drainEffect = result.sideEffects.find(s => s.type === 'drainToIdle');
      assert.ok(drainEffect, 'drainToIdle side-effect must be present');
    });

    it('PLAYING + poll=idle + bufferAhead < 0.5 → IDLE with destroyHls', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 0.3 }),
      }, STATE.IDLE, ['destroyHls', 'setPoster', 'showStatus']);
      const destroyEffect = result.sideEffects.find(s => s.type === 'destroyHls');
      assert.ok(destroyEffect, 'destroyHls must be called when buffer is depleted');
    });

    it('IDLE + poll=live + new videoUID → PREPARING + loadHls', () => {
      const result = check({
        currentState: STATE.IDLE,
        event: makeEvent('poll', { state: 'live', videoUID: 'new-uid-456', hlsUrl: 'https://example.com/new.m3u8', source: 'webhook' }),
        context: makeContext({ currentVideoUID: 'old-uid' }),
      }, STATE.PREPARING, ['loadHls', 'showStatus']);
      const loadEffect = result.sideEffects.find(s => s.type === 'loadHls');
      assert.ok(loadEffect, 'loadHls must be called on live transition');
      assert.equal(loadEffect.payload.url, 'https://example.com/new.m3u8');
    });

    it('PLAYING + poll=live(new videoUID) → handover (not idle → live cycle)', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'live', videoUID: 'new-uid', hlsUrl: 'https://example.com/new.m3u8' }),
        context: makeContext({ currentVideoUID: 'old-uid' }),
      }, STATE.PLAYING, ['handover']);
      const handoverEffect = result.sideEffects.find(s => s.type === 'handover');
      assert.ok(handoverEffect, 'handover must be triggered, not full teardown');
      assert.equal(handoverEffect.payload.newVideoUID, 'new-uid');
    });

    it('preparing + poll=error ERR_MISSING_SUBSCRIPTION → showStatus=error (viewer-facing)', () => {
      const result = check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'error', errorCode: 'ERR_MISSING_SUBSCRIPTION', source: 'webhook' }),
        context: makeContext({ hasPlayedOnce: true }),
      }, STATE.IDLE, ['showStatus']);
      const statusEffect = result.sideEffects.find(s => s.type === 'showStatus');
      assert.equal(statusEffect.payload, STATUS.ERROR);
    });

    it('playing + poll=error ERR_GOP_OUT_OF_RANGE → showStatus=paused (not viewer-facing)', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'error', errorCode: 'ERR_GOP_OUT_OF_RANGE' }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0 }),
      }, STATE.IDLE, ['showStatus']);
      const statusEffect = result.sideEffects.find(s => s.type === 'showStatus');
      assert.equal(statusEffect.payload, STATUS.WAITING);
    });

    it('playing + poll=idle + hasPlayedOnce=false → initial poster', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0, hasPlayedOnce: false }),
      }, STATE.IDLE, ['setPoster']);
      const posterEffect = result.sideEffects.find(s => s.type === 'setPoster');
      assert.equal(posterEffect.payload.which, 'initial');
    });

    it('playing + poll=idle + hasPlayedOnce=true → idle poster', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'idle', videoUID: null, hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0, hasPlayedOnce: true }),
      }, STATE.IDLE, ['setPoster']);
      const posterEffect = result.sideEffects.find(s => s.type === 'setPoster');
      assert.equal(posterEffect.payload.which, 'idle');
    });
  });

  // ── Edge cases ──

  describe('Edge cases', () => {

    it('unknown currentState returns self', () => {
      const result = transition({
        currentState: 'UNKNOWN_STATE',
        event: makeEvent('poll', { state: 'idle' }),
        context: makeContext(),
      });
      assert.equal(result.nextState, 'UNKNOWN_STATE');
      assert.equal(result.sideEffects.length, 0);
    });

    it('unknown event type returns self', () => {
      const result = transition({
        currentState: STATE.IDLE,
        event: makeEvent('unknownEventType'),
        context: makeContext(),
      });
      assert.equal(result.nextState, STATE.IDLE);
      assert.equal(result.sideEffects.length, 0);
    });

    it('preparing + poll=live missing videoUID → IDLE', () => {
      check({
        currentState: STATE.PREPARING,
        event: makeEvent('poll', { state: 'live', videoUID: null, hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext(),
      }, STATE.IDLE, ['setPoster', 'showStatus']);
    });

    it('playing + poll=reconnecting + buffer exactly 0.5 → IDLE (not sufficient)', () => {
      check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'reconnecting', videoUID: 'abc123', hlsUrl: null }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: false, bufferAhead: 0.5 }),
      }, STATE.IDLE, ['setPoster', 'showStatus']);
    });

    it('playing + poll=reconnecting + buffer exactly 0.51 → PLAYING (just above threshold)', () => {
      const result = check({
        currentState: STATE.PLAYING,
        event: makeEvent('poll', { state: 'reconnecting', videoUID: 'abc123', hlsUrl: 'https://example.com/manifest.m3u8' }),
        context: makeContext({ currentVideoUID: 'abc123', hasBufferedContent: true, bufferAhead: 0.51 }),
      }, STATE.PLAYING, ['showStatus']);
      const statusEffect = result.sideEffects.find(s => s.type === 'showStatus');
      assert.equal(statusEffect.payload, STATUS.RECONNECTING);
    });
  });
});
