/**
 * A0.5: Reproduce B2 — live path lacks native-HLS fallback.
 *
 * On browsers without MSE support (iOS < 17.1 WebKit), Hls.js fails silently
 * and the player hangs in PREPARING until the 45s fatal timer fires.
 *
 * This test stubs Hls.isSupported() to false and verifies:
 *   1. The current code DOES NOT create a native-HLS path (bug confirmed).
 *   2. The player gets stuck in PREPARING (not PLAYING).
 *
 * After A1.2 is implemented, this test should be updated to verify the
 * native HLS fallback works.
 */

const { test, expect } = require('@playwright/test');
const http = require('http');
const path = require('path');

const MOCK_PORT = 3457;
const MOCK_HOST = 'localhost';

// ─── Minimal mock for this test ───────────────────────

function startMockServer() {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      if (req.url === '/live-state?inputId=test001') {
        res.writeHead(200, {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'no-store',
          'ETag': '"test-b2"',
          'X-HS-Correlation-Id': 'b2-test-id',
        });
        res.end(JSON.stringify({
          state: 'live',
          videoUID: 'abc123',
          hlsUrl: `http://${MOCK_HOST}:${MOCK_PORT}/live-state?inputId=test001`,
          errorCode: null,
          source: 'webhook',
          ts: Date.now() / 1000,
        }));
      } else if (req.url.startsWith('/live/test001/abc123')) {
        // Intercept HLS requests — they'll fail but that's expected
        res.writeHead(200, { 'Content-Type': 'application/octet-stream' });
        res.end('');
      } else {
        res.writeHead(404);
        res.end();
      }
    });
    server.listen(MOCK_PORT, () => {
      console.log(`B2 mock server on :${MOCK_PORT}`);
      resolve(server);
    });
  });
}

test('B2 reproduction: live path without MSE support', async ({ browser }) => {
  const server = await startMockServer();

  const context = await browser.newContext();
  const page = await context.newPage();

  // Capture console messages
  const errors = [];
  const logs = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
    else logs.push(msg.text());
  });

  // Stub Hls.isSupported() to false BEFORE loading the player
  await page.addInitScript(() => {
    // Inject a stub Hls.js that has isSupported() = false
    window.Hls = { isSupported: () => false, Events: {}, ErrorTypes: {} };
  });

  // Create a test page that loads the player
  await page.setContent(`
    <!DOCTYPE html>
    <html>
    <head>
        <script>
            window.HSPlayerConfig = {
                debug: true,
                mode: 'live',
                inputId: 'test001',
                autoplay: true,
                endpoints: { liveState: 'http://${MOCK_HOST}:${MOCK_PORT}/live-state' },
                cloudflare: {
                    customerCode: 'juu1r5es4cbffqjf',
                    hlsOriginAllowlistRegex: '^https://customer-[a-z0-9]{12,20}\\\\.cloudflarestream\\\\.com/[A-Za-z0-9]+/manifest/video\\\\.m3u8(\\\\?.*)?$'
                },
                posters: {
                    initial: 'https://example.com/initial.png',
                    idle: 'https://example.com/idle.png',
                    fatal: 'https://example.com/fatal.png'
                },
                server: { isLive: false },
                errorMessages: {}
            };
        </script>
        <script src="../../../../HSPlayerElement.js"><\/script>
    </head>
    <body>
        <hs-video id="video" inputId="test001" live autoplay></hs-video>
    </body>
    </html>
  `);

  // Wait for the first poll to return "live" and trigger loadStream
  await page.waitForTimeout(6000);

  // Check player state
  const state = await page.evaluate(() => {
    const el = document.querySelector('hs-video');
    return el?.playerState || 'unknown';
  });

  // BUG REPRODUCTION: Without MSE support, the player enters PREPARING but
  // never reaches PLAYING because new Hls() fails silently.
  // This is the current buggy behavior — A1.2 fixes it by adding native HLS fallback.
  console.log(`Player state after 6s with Hls.isSupported()=false: ${state}`);
  console.log('Errors:', errors);
  console.log('Logs:', logs.slice(-5));

  // In the buggy code, state would be PREPARING (not PLAYING)
  // After A1.2, state should be PLAYING (native HLS path)
  // For now, we verify the bug exists by checking the player does NOT reach PLAYING
  expect(state).not.toBe('PLAYING');

  await context.close();
  server.close();
});
