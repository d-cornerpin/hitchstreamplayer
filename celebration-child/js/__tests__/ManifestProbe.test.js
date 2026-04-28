// ManifestProbe.js unit tests — A3.5
// Run: node --test celebration-child/js/__tests__/ManifestProbe.test.js

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { probeManifest } from '../../js/HSPlayer/ManifestProbe.js';
import { MANIFEST_PROBE_MAX_ATTEMPTS, MANIFEST_PROBE_INTERVAL_MS, MANIFEST_PROBE_INITIAL_DELAY_MS } from '../../js/HSPlayer/constants.js';

// ─── First-success path ──

describe('first-success path', () => {
  it('resolves on first 200 response', async () => {
    const abortController = new AbortController();
    let callCount = 0;

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, opts) => {
      callCount++;
      // Check caching header is not set
      assert.equal(opts?.cache, 'no-store');
      // Check URL has cache-busting param
      assert.ok(url.includes('_cb='), 'URL should have cache-busting param');
      return { ok: true, status: 200 };
    };

    const result = await probeManifest('https://example.com/manifest.m3u8', {
      maxAttempts: 40,
      intervalMs: 100,
      initialDelayMs: 50,
      signal: abortController.signal,
    });

    assert.equal(result.status, 200);
    assert.ok(callCount >= 1);

    globalThis.fetch = originalFetch;
  });

  it('stops probing after first success', async () => {
    let callCount = 0;
    const abortController = new AbortController();

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      return { ok: true, status: 200 };
    };

    await probeManifest('https://example.com/manifest.m3u8', {
      maxAttempts: 40,
      intervalMs: 100,
      initialDelayMs: 50,
      signal: abortController.signal,
    });

    // Should have stopped after first success
    assert.ok(callCount < 5, 'Should stop probing after first success');

    globalThis.fetch = originalFetch;
  });
});

// ─── Cap-reached path ──

describe('cap-reached path', () => {
  it('rejects after maxAttempts failures', async () => {
    const maxAttempts = 5;
    let callCount = 0;
    const abortController = new AbortController();

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      return { ok: false, status: 404 };
    };

    await assert.rejects(
      async () => {
        await probeManifest('https://example.com/manifest.m3u8', {
          maxAttempts,
          intervalMs: 50,
          initialDelayMs: 20,
          signal: abortController.signal,
        });
      },
      { message: `Probe cap hit after ${maxAttempts} attempts` }
    );

    assert.equal(callCount, maxAttempts);

    globalThis.fetch = originalFetch;
  });
});

// ─── Abort mid-probe ──

describe('abort mid-probe', () => {
  it('rejects when AbortController signals abort', async () => {
    const abortController = new AbortController();

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      return new Promise(() => {
        // Never resolves — will be aborted
      });
    };

    setTimeout(() => abortController.abort(), 500);

    await assert.rejects(
      probeManifest('https://example.com/manifest.m3u8', {
        maxAttempts: 40,
        intervalMs: 100,
        initialDelayMs: 200,
        signal: abortController.signal,
      }),
      { message: 'Probe aborted' }
    );

    globalThis.fetch = originalFetch;
  });

  it('rejects immediately if already aborted', async () => {
    const abortController = new AbortController();
    abortController.abort();

    await assert.rejects(
      probeManifest('https://example.com/manifest.m3u8', {
        signal: abortController.signal,
      }),
      { message: 'Probe aborted before start' }
    );
  });
});

// ─── Malformed-manifest handling ──

describe('malformed-manifest handling', () => {
  it('treats non-200 as failure and continues probing', async () => {
    const abortController = new AbortController();
    let callCount = 0;

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
      callCount++;
      if (callCount < 3) {
        return { ok: false, status: 500 }; // Server error
      }
      return { ok: true, status: 200 };
    };

    const result = await probeManifest('https://example.com/manifest.m3u8', {
      maxAttempts: 40,
      intervalMs: 50,
      initialDelayMs: 20,
      signal: abortController.signal,
    });

    assert.equal(result.status, 200);
    assert.equal(callCount, 3); // 2 failures + 1 success

    globalThis.fetch = originalFetch;
  });
});

// ─── Default values ──

describe('default values', () => {
  it('uses MANIFEST_PROBE_MAX_ATTEMPTS as default', () => {
    assert.equal(MANIFEST_PROBE_MAX_ATTEMPTS, 40);
  });

  it('uses MANIFEST_PROBE_INTERVAL_MS as default interval', () => {
    assert.equal(MANIFEST_PROBE_INTERVAL_MS, 1500);
  });

  it('uses MANIFEST_PROBE_INITIAL_DELAY_MS as default delay', () => {
    assert.equal(MANIFEST_PROBE_INITIAL_DELAY_MS, 5000);
  });
});

// ─── Cache busting ──

describe('cache busting', () => {
  it('adds unique _cb parameter per probe', async () => {
    const urls = [];
    let callCount = 0;
    const abortController = new AbortController();

    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url) => {
      callCount++;
      urls.push(url);
      if (callCount < 3) return { ok: false, status: 404 };
      return { ok: true, status: 200 };
    };

    await probeManifest('https://example.com/manifest.m3u8', {
      maxAttempts: 40,
      intervalMs: 50,
      initialDelayMs: 20,
      signal: abortController.signal,
    });

    // Check that URLs have unique _cb values
    const cbValues = urls.map(u => new URL(u).searchParams.get('_cb'));
    const uniqueCbValues = new Set(cbValues);
    assert.equal(uniqueCbValues.size, cbValues.length, 'All _cb values should be unique');

    globalThis.fetch = originalFetch;
  });
});
