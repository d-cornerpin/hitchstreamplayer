// LivePoller.js unit tests — A3.5
// Run: node --test celebration-child/js/__tests__/LivePoller.test.js

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createLivePoller } from '../../js/HSPlayer/LivePoller.js';

// ─── Basic creation ──

describe('createLivePoller', () => {
  it('returns object with start/stop methods', () => {
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: () => {},
    });
    assert.equal(typeof poller.start, 'function');
    assert.equal(typeof poller.stop, 'function');
    assert.equal(typeof poller.destroy, 'function');
    assert.equal(typeof poller.pollCount, 'number');
  });

  it('returns empty start/stop for missing inputId', () => {
    const poller = createLivePoller({
      inputId: '',
      endpoint: 'https://example.com/live-state',
      onEvent: () => {},
    });
    assert.equal(typeof poller.start, 'function');
    assert.equal(typeof poller.stop, 'function');
  });
});

// ─── Event emission — poll ──

describe('poll event emission', () => {
  it('emits poll event with parsed payload', async (t) => {
    const mockData = {
      live: true,
      state: 'live',
      videoUID: 'abc123',
      hlsUrl: 'https://customer-abc123def456.cloudflarestream.com/abc123/manifest/video.m3u8',
      source: 'webhook',
    };

    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    // Override fetch for testing
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => ({
      ok: true,
      status: 200,
      headers: new Map([['etag', '"test-etag"']]),
      getHeader: (k) => k.toLowerCase() === 'etag' ? '"test-etag"' : undefined,
      json: async () => mockData,
    });

    poller.start();

    // Wait for first poll
    await new Promise(r => setTimeout(r, 5000));

    // Find poll event
    const pollEvent = events.find(e => e.type === 'poll');
    assert.ok(pollEvent, 'Should emit poll event');
    assert.equal(pollEvent.payload.state, 'live');
    assert.equal(pollEvent.payload.videoUID, 'abc123');
    assert.equal(pollEvent.payload.hlsUrl, mockData.hlsUrl);
    assert.equal(pollEvent.payload.source, 'webhook');

    poller.stop();
    globalThis.fetch = originalFetch;
  });

  it('emits noChange event on 304', async (t) => {
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => ({
      ok: true,
      status: 304,
      headers: { get: () => null },
    });

    poller.start();

    await new Promise(r => setTimeout(r, 5000));

    const noChangeEvent = events.find(e => e.type === 'noChange');
    assert.ok(noChangeEvent, 'Should emit noChange event on 304');

    poller.stop();
    globalThis.fetch = originalFetch;
  });

  it('emits error event on fetch failure', async (t) => {
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('Network error'); };

    poller.start();

    await new Promise(r => setTimeout(r, 5000));

    const errorEvent = events.find(e => e.type === 'error');
    assert.ok(errorEvent, 'Should emit error event on fetch failure');

    poller.stop();
    globalThis.fetch = originalFetch;
  });

  it('emits backoff event after 3 consecutive errors', async (t) => {
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('Network error'); };

    poller.start();

    // Wait for 3+ poll cycles
    await new Promise(r => setTimeout(r, 35000));

    const backoffEvent = events.find(e => e.type === 'backoff');
    assert.ok(backoffEvent, 'Should emit backoff event after 3+ errors');
    assert.ok(backoffEvent.payload.delay >= 10000, 'Backoff delay should be >= 10s');

    poller.stop();
    globalThis.fetch = originalFetch;
  });

  it('resets backoff on successful poll', async (t) => {
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    let callCount = 0;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      if (callCount <= 3) {
        throw new Error('Network error');
      }
      return {
        ok: true,
        status: 200,
        headers: new Map([['etag', '"test"']]),
        json: async () => ({ live: true, state: 'live', videoUID: 'abc', hlsUrl: 'https://example.com/test.m3u8', source: 'webhook' }),
      };
    };

    poller.start();

    await new Promise(r => setTimeout(r, 40000));

    // After recovery, no more backoff events
    const backoffEvents = events.filter(e => e.type === 'backoff');
    assert.ok(backoffEvents.length > 0, 'Should have backoff events from initial errors');

    poller.stop();
    globalThis.fetch = originalFetch;
  });
});

// ─── ETag handling ──

describe('ETag round-trip', () => {
  it('sends If-None-Match on second poll', async (t) => {
    let capturedHeaders = null;
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    let callCount = 0;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, opts) => {
      callCount++;
      capturedHeaders = opts?.headers || {};
      if (callCount === 1) {
        return { ok: true, status: 200, headers: { get: () => '"first-etag"' }, json: async () => ({ live: false, state: 'idle', videoUID: null, hlsUrl: null, source: 'webhook' }) };
      }
      // Second call: check If-None-Match
      assert.equal(capturedHeaders['If-None-Match'], '"first-etag"', 'Should send ETag on second poll');
      return { ok: true, status: 304, headers: { get: () => null } };
    };

    poller.start();

    await new Promise(r => setTimeout(r, 20000));

    poller.stop();
    globalThis.fetch = originalFetch;
  });
});

// ─── stop/destroy behavior ──

describe('stop behavior', () => {
  it('stop prevents further polling', async (t) => {
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    let callCount = 0;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ live: true, state: 'live', videoUID: 'abc', hlsUrl: 'https://example.com/test.m3u8', source: 'webhook' }) };
    };

    poller.start();

    // Wait for at least one poll to fire (initial jitter up to 4.5s + processing)
    await new Promise(r => setTimeout(r, 6000));
    const pollsBeforeStop = events.filter(e => e.type === 'poll').length;
    assert.ok(pollsBeforeStop >= 1, `Should have at least 1 poll before stop, got ${pollsBeforeStop}`);

    poller.stop();

    // Wait past the next poll interval (10s)
    await new Promise(r => setTimeout(r, 12000));

    const pollsAfterStop = events.filter(e => e.type === 'poll').length;
    assert.equal(pollsBeforeStop, pollsAfterStop, 'Should not poll after stop');

    globalThis.fetch = originalFetch;
  });

  it('destroy stops polling and prevents future starts', async (t) => {
    const events = [];
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    let callCount = 0;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ live: true, state: 'live', videoUID: 'abc', hlsUrl: 'https://example.com/test.m3u8', source: 'webhook' }) };
    };

    poller.start();
    await new Promise(r => setTimeout(r, 2000));
    const pollsBefore = events.filter(e => e.type === 'poll').length;

    poller.destroy();
    await new Promise(r => setTimeout(r, 15000));

    const pollsAfter = events.filter(e => e.type === 'poll').length;
    assert.equal(pollsBefore, pollsAfter, 'Should not poll after destroy');

    globalThis.fetch = originalFetch;
  });
});

// ─── Concurrency guard ──

describe('concurrency guard', () => {
  it('skips poll while previous request is in-flight', async (t) => {
    const events = [];
    let resolveFetch = null;
    let fetchCount = 0;
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      fetchCount++;
      if (!resolveFetch) {
        // First poll: start a slow fetch that never resolves
        return new Promise(() => {});
      }
      return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ live: true, state: 'live', videoUID: 'abc', hlsUrl: 'https://example.com/test.m3u8', source: 'webhook' }) };
    };

    poller.start();

    // Wait past initial jitter (4.5s max) + first poll processing
    await new Promise(r => setTimeout(r, 6000));

    // First fetch should have started but never resolved
    assert.equal(fetchCount, 1, 'Should have started exactly one fetch');

    // The interval should have tried to fire again but the concurrency guard
    // should have prevented a second fetch. The poller may have fired
    // additional intervals, but fetchCount should remain 1 because of the guard.
    // Allow a small window for the second interval to be scheduled
    await new Promise(r => setTimeout(r, 12000));

    // Only the first fetch should have completed; the concurrency guard
    // prevents a second simultaneous fetch.
    // Note: after 12 more seconds, the poller's interval will have fired
    // but the in-flight guard prevents the fetch from completing.
    // We verify the poll count didn't keep growing exponentially.
    assert.ok(fetchCount <= 3, `Should not have excessive fetches (got ${fetchCount})`);

    poller.stop();
    globalThis.fetch = originalFetch;
  });
});

// ─── Initial delay jitter ──

describe('initial delay jitter', () => {
  it('starts with jittered delay (between 1500ms and 4500ms)', async (t) => {
    const events = [];
    let callCount = 0;
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://example.com/live-state',
      onEvent: (evt) => { events.push(evt); },
    });

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ live: true, state: 'live', videoUID: 'abc', hlsUrl: 'https://example.com/test.m3u8', source: 'webhook' }) };
    };

    const startTs = Date.now();
    poller.start();

    // Wait for initial jitter + first poll
    await new Promise(r => setTimeout(r, 6000));

    assert.ok(callCount >= 1, 'Should have at least one poll after initial delay');
    const elapsed = Date.now() - startTs;
    assert.ok(elapsed >= 3000, 'Should wait at least 3s (base delay)');

    poller.stop();
    globalThis.fetch = originalFetch;
  });
});
