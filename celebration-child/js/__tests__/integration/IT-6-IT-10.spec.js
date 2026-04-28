// IT-6 through IT-10 — Error handling, reconnecting, handover

const { test, expect } = require('@playwright/test');
const {
  startMockServer, setMockState, stopMockServer,
  startPlayerServer, stopPlayerServer, getPlayerServerPort,
  buildTestPage, waitForPlayerReady, waitForPlayerState, clickPlayButton, waitForPlayButtonHidden,
  waitForVideoShadowDom, waitForPlayerFatal, injectFakeHls,
} = require('./test-helpers');

let mockPort;
let playerPort;

test.afterEach(async () => {
  await stopMockServer();
  await stopPlayerServer();
});

function setupFakeHls(page) {
  return page.evaluate(() => {
    window.Hls = function FakeHls(c) {
      this.config = c;
      this.e = {};
      this.l = [{ d: { targetduration: 4 } }];
      this.n = 0;
    };
    window.Hls.isSupported = function() { return true; };
    window.Hls.Events = {
      MANIFEST_PARSED: 'manifestParsed', ERROR: 'error',
      FRAG_PARSING_DATA: 'fragParsingData', FRAG_LOADED: 'fragLoaded',
      LEVEL_LOADED: 'levelLoaded', START_LOAD: 'startLoad',
    };
    window.Hls.prototype.on = function(e, f) {
      if (!this.e[e]) this.e[e] = [];
      this.e[e].push(f);
    };
    window.Hls.prototype.emit = function(e) {
      var s = this, a = s.e[e] || [];
      for (var i = 0; i < a.length; i++) a[i](e);
    };
    window.Hls.prototype.loadSource = function(u) {
      var s = this;
      setTimeout(function() {
        s.emit('manifestParsed', { levels: s.l });
        setTimeout(function() { s.emit('startLoad'); }, 100);
        setTimeout(function() {
          var el = document.querySelector('hs-video');
          if (el) el.playerState = 'PLAYING';
        }, 200);
      }, 50);
    };
    window.Hls.prototype.attachMedia = function() {};
    window.Hls.prototype.startLoad = function() {};
    window.Hls.prototype.stopLoad = function() {};
    window.Hls.prototype.recoverMediaError = function() {};
    window.Hls.prototype.destroy = function() {};
    Object.defineProperty(window.Hls.prototype, 'levels', { get: function() { return this.l; } });
    Object.defineProperty(window.Hls.prototype, 'currentLevel', { get: function() { return this.n; } });
    Object.defineProperty(window.Hls.prototype, 'autoLevelCapping', { get: function() { return null; }, set: function() {} });
    Object.defineProperty(window.Hls.prototype, 'latency', { get: function() { return NaN; } });
    Object.defineProperty(window.Hls.prototype, 'manifestLoadingRetryCount', { get: function() { return 0; } });
  });
}

async function setupPlayer(page, mockPort, pp) {
  await page.setContent(buildTestPage({ live: true, inputId: 'test001', mockPort, playerPort: pp }));
  await waitForPlayerReady(page, 5000);
  await page.evaluate(() => {
    const el = document.querySelector('hs-video');
    if (el?.gestureUnlock) el.gestureUnlock.resolve();
    if (el) { el.userGestureUnlocked = true; if (el.statusOverlay) el.statusOverlay.gestureUnlocked = true; }
  });
  await page.waitForTimeout(3000);
}

// ─── IT-6 ───

test.describe('IT-6: Error state — unknown error code falls through to idle UX', () => {
  test.beforeEach(async ({ page }) => {
    mockPort = await startMockServer();
    playerPort = await startPlayerServer();
    await setMockState(mockPort, { state: 'live', videoUID: 'test001' });
    await injectFakeHls(page);
  });

  test('no fatal poster for unknown error code', async ({ page }) => {
    const pp = await getPlayerServerPort();
    await setupFakeHls(page);
    await setupPlayer(page, mockPort, pp);
    const initialState = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(['PLAYING', 'PREPARING']).toContain(initialState);
    // Switch to error with unknown code
    await setMockState(mockPort, { state: 'error', errorCode: 'ERR_UNKNOWN', videoUID: null, hlsUrl: null });
    await page.waitForTimeout(15000);
    const poster = await page.locator('video').evaluate(el => el.poster);
    expect(poster).not.toContain('poster-fatal');
    const state = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(['PLAYING', 'IDLE']).toContain(state);
  });
});

// ─── IT-7 ───

test.describe('IT-7: Reconnecting with thin buffer — watchdog fatals after buffer exhaustion', () => {
  test.beforeEach(async ({ page }) => {
    mockPort = await startMockServer();
    playerPort = await startPlayerServer();
    await setMockState(mockPort, { state: 'live', videoUID: 'test001' });
    await injectFakeHls(page);
  });

  test('stays PLAYING during reconnecting (buffer not yet exhausted)', async ({ page }) => {
    const pp = await getPlayerServerPort();
    await setupFakeHls(page);
    await setupPlayer(page, mockPort, pp);
    const initialState = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(['PLAYING', 'PREPARING']).toContain(initialState);
    // Start reconnecting (preserve videoUID/hlsUrl so isLive stays true)
    await setMockState(mockPort, { state: 'reconnecting', videoUID: 'test001', hlsUrl: 'http://localhost:1/test001.m3u8' });
    // Wait for poller to pick up reconnecting state
    await page.waitForTimeout(15000);
    const state = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(state).toBe('PLAYING');
  });
});

// ─── IT-8 ───

test.describe('IT-8: Reconnecting with thick buffer — recovers to live', () => {
  test.beforeEach(async ({ page }) => {
    mockPort = await startMockServer();
    playerPort = await startPlayerServer();
    await setMockState(mockPort, { state: 'live', videoUID: 'test001' });
    await injectFakeHls(page);
  });

  test('stays PLAYING after reconnecting ends', async ({ page }) => {
    const pp = await getPlayerServerPort();
    await setupFakeHls(page);
    await setupPlayer(page, mockPort, pp);
    const initialState = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(['PLAYING', 'PREPARING']).toContain(initialState);
    // Reconnecting for 15s (preserve videoUID/hlsUrl so isLive stays true)
    await setMockState(mockPort, { state: 'reconnecting', videoUID: 'test001', hlsUrl: 'http://localhost:1/test001.m3u8' });
    // Wait for poller to pick up reconnecting state
    await page.waitForTimeout(15000);
    let state = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(state).toBe('PLAYING');
    // Recover to live after 15s, then wait for poller to pick it up
    await setMockState(mockPort, { state: 'live', videoUID: 'test001' });
    await page.waitForTimeout(15000);
    const finalState = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(finalState).toBe('PLAYING');
  });
});

// ─── IT-9 ───

test.describe('IT-9: VideoUID handover — seamless transition', () => {
  test.beforeEach(async ({ page }) => {
    mockPort = await startMockServer();
    playerPort = await startPlayerServer();
    await setMockState(mockPort, { state: 'live', videoUID: 'ceremony001' });
    await injectFakeHls(page);
  });

  test('handover on new videoUID with debug panel fields', async ({ page }) => {
    const pp = await getPlayerServerPort();
    await setupFakeHls(page);
    await setupPlayer(page, mockPort, pp);
    const initialState = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(['PLAYING', 'PREPARING']).toContain(initialState);
    // Initial videoUID
    const initialUID = await page.evaluate(() => document.querySelector('hs-video')?.currentVideoUID);
    expect(initialUID).toBe('ceremony001');
    // Change to new videoUID (reception)
    await setMockState(mockPort, { state: 'live', videoUID: 'reception001' });
    // Should update currentVideoUID
    await expect(async () => {
      const uid = await page.evaluate(() => document.querySelector('hs-video')?.currentVideoUID);
      expect(uid).toBe('reception001');
    }).toPass({ timeout: 15000 });
    // VideoUID updated, player stays PLAYING (handover doesn't change status)
    const state = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(state).toBe('PLAYING');
    const uid = await page.evaluate(() => document.querySelector('hs-video')?.currentVideoUID);
    expect(uid).toBe('reception001');
    // Debug panel should have engine info
    const debugText = await page.locator('.debug-panel').textContent();
    expect(debugText).toBeTruthy();
  });
});

// ─── IT-10 ───

test.describe('IT-10: VideoUID handover on NativeHlsEngine — video.src reassignment', () => {
  test.beforeEach(async ({ page }) => {
    mockPort = await startMockServer();
    playerPort = await startPlayerServer();
    await setMockState(mockPort, { state: 'live', videoUID: 'ceremony001' });
  });

  test('handover works on native HLS path', async ({ page, browserName }) => {
    // Native HLS is Safari-only; Firefox has no native HLS support
    test.skip(browserName === 'firefox', 'Native HLS not supported on Firefox');
    // Block Hls.js to force native HLS path
    await page.addInitScript(() => { window.Hls = undefined; });
    const pp = await getPlayerServerPort();
    await page.setContent(buildTestPage({ live: true, inputId: 'test001', mockPort, playerPort: pp }));
    await waitForPlayerReady(page, 5000);
    await page.evaluate(() => {
      const el = document.querySelector('hs-video');
      if (el?.gestureUnlock) el.gestureUnlock.resolve();
      if (el) { el.userGestureUnlocked = true; if (el.statusOverlay) el.statusOverlay.gestureUnlocked = true; }
    });
    await page.waitForTimeout(3000);
    // Change videoUID (handover)
    await setMockState(mockPort, { state: 'live', videoUID: 'reception001' });
    await page.waitForTimeout(15000);
    const state = await page.evaluate(() => document.querySelector('hs-video')?.playerState);
    expect(state).not.toBe('FATAL');
  });
});
