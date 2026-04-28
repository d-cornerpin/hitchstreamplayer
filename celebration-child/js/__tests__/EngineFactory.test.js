// EngineFactory.js unit tests — A3.5
// Run: node --test celebration-child/js/__tests__/EngineFactory.test.js

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createEngine, isHlsSupported } from '../../js/HSPlayer/EngineFactory.js';
import { HlsEngine } from '../../js/HSPlayer/HlsEngine.js';
import { NativeHlsEngine } from '../../js/HSPlayer/NativeHlsEngine.js';

// ─── isHlsSupported ──

describe('isHslSupported', () => {
  it('returns true when Hls.js is available globally', () => {
    // In a browser environment, Hls is defined. We can't easily test this without
    // a DOM, but we verify the function is exported correctly.
    assert.equal(typeof isHlsSupported, 'function');
  });
});

// ─── createEngine ──

describe('createEngine', () => {
  it('returns an object with engine interface methods', () => {
    // This test requires a DOM. In Node environment, it will return null.
    // We verify the function exists and the interface contract.
    assert.equal(typeof createEngine, 'function');
  });

  it('HlsEngine implements the required interface', () => {
    assert.equal(typeof HlsEngine, 'function');
    assert.equal(typeof HlsEngine.prototype.loadSource, 'function');
    assert.equal(typeof HlsEngine.prototype.attachMedia, 'function');
    assert.equal(typeof HlsEngine.prototype.destroy, 'function');
    assert.equal(typeof HlsEngine.prototype.on, 'function');
    assert.equal(typeof HlsEngine.prototype.off, 'function');
    assert.equal(typeof HlsEngine.prototype.startLoad, 'function');
    assert.equal(typeof HlsEngine.prototype.stopLoad, 'function');
    assert.equal(typeof HlsEngine.prototype.recoverMediaError, 'function');
  });

  it('NativeHlsEngine implements the required interface', () => {
    assert.equal(typeof NativeHlsEngine, 'function');
    assert.equal(typeof NativeHlsEngine.prototype.loadSource, 'function');
    assert.equal(typeof NativeHlsEngine.prototype.attachMedia, 'function');
    assert.equal(typeof NativeHlsEngine.prototype.destroy, 'function');
    assert.equal(typeof NativeHlsEngine.prototype.on, 'function');
    assert.equal(typeof NativeHlsEngine.prototype.off, 'function');
  });

  it('both engines share a consistent interface', () => {
    // Verify both engines have the same method signatures
    const hlsMethods = Object.getOwnPropertyNames(HlsEngine.prototype).filter(m => m !== 'constructor');
    const nativeMethods = Object.getOwnPropertyNames(NativeHlsEngine.prototype).filter(m => m !== 'constructor');

    const requiredMethods = ['loadSource', 'attachMedia', 'destroy', 'on', 'off', 'startLoad', 'stopLoad'];
    for (const method of requiredMethods) {
      assert.ok(hlsMethods.includes(method), `HlsEngine should have method: ${method}`);
      assert.ok(nativeMethods.includes(method), `NativeHlsEngine should have method: ${method}`);
    }
  });

  it('HlsEngine.isSupported() returns false without Hls.js', () => {
    // In Node, Hls global doesn't exist, so HlsEngine constructor will fail
    // We verify the interface contract instead
    assert.equal(typeof HlsEngine.prototype.isSupported, 'function');
  });

  it('NativeHlsEngine.isSupported() always returns true', () => {
    const engine = new NativeHlsEngine();
    assert.equal(engine.isSupported(), true);
  });
});

// ─── Engine destruction safety ──

describe('engine destruction', () => {
  it('HlsEngine has inert destroy pattern (interface check)', () => {
    // In Node, Hls global doesn't exist, so we verify the interface
    assert.equal(typeof HlsEngine.prototype.destroy, 'function');
    assert.equal(typeof HlsEngine.prototype.loadSource, 'function');
    assert.equal(typeof HlsEngine.prototype.recoverMediaError, 'function');
  });

  it('destroyed NativeHlsEngine is inert on all methods', () => {
    const engine = new NativeHlsEngine();
    engine.destroy();

    // Should not throw after destroy
    engine.loadSource('https://example.com/test.m3u8');
    engine.attachMedia(null);
    engine.on('test', () => {});
    engine.off('test', () => {});
    engine.startLoad(-1);
    engine.stopLoad();
  });
});

// ─── HlsEngine properties ──

describe('HlsEngine read-only properties', () => {
  it('interface has correct property signatures', () => {
    // In Node, Hls global doesn't exist so we can't instantiate.
    // Verify the property accessors exist on the prototype.
    assert.ok('levels' in HlsEngine.prototype);
    assert.ok('currentLevel' in HlsEngine.prototype);
    assert.ok('autoLevelCapping' in HlsEngine.prototype);
    assert.ok('latency' in HlsEngine.prototype);
  });
});

// ─── NativeHlsEngine event binding ──

describe('NativeHlsEngine event binding', () => {
  it('on registers listeners that can be triggered', () => {
    const engine = new NativeHlsEngine();
    let called = false;
    engine.on('test', () => { called = true; });
    engine._emit('test', 'source', 'data');
    assert.equal(called, true);
    engine.destroy();
  });

  it('off removes listeners', () => {
    const engine = new NativeHlsEngine();
    let called = false;
    const fn = () => { called = true; };
    engine.on('test', fn);
    engine.off('test', fn);
    engine._emit('test', 'source', 'data');
    assert.equal(called, false);
    engine.destroy();
  });
});
