// livepoller-static.test.mjs — verifies LivePoller's static-file polling mode
// (task 2 of the polling-load brief). Plain node, no framework:
//   node celebration-child/js/__tests__/livepoller-static.test.mjs
//
// Covers:
//   A. file mode builds ${fileEndpoint}${inputId}.json and emits a live poll
//   B. file-mode 404 → idle poll event ('file-missing'), NEVER error/backoff
//   C. REST-mode 404 → still the error path (the idle-ing is gated to file mode)
//   D. file-mode 304 → noChange
//   E. repeated 5xx → backoff still engages (regression guard)

import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const { createLivePoller } = await import(join(here, '..', 'HSPlayer', 'LivePoller.js'));

// Collapse all timer delays so 10s poll cadence runs in milliseconds.
const realSetTimeout = globalThis.setTimeout;
globalThis.setTimeout = (fn, ms, ...rest) => realSetTimeout(fn, Math.min(ms ?? 0, 5), ...rest);

let failures = 0;
const assert = (cond, label) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`);
  if (!cond) failures++;
};

const mkRes = ({ status = 200, body = null, etag = null }) => ({
  status,
  ok: status >= 200 && status < 300,
  headers: { get: (h) => (h.toLowerCase() === 'etag' ? etag : null) },
  json: async () => body,
});

// Run a poller against a scripted sequence of responses; resolve with events.
function run({ responses, pollerOpts, until }) {
  return new Promise((resolve) => {
    const events = [];
    const urls = [];
    const fetchOpts = [];
    let i = 0;
    globalThis.fetch = async (url, opts) => {
      urls.push(String(url));
      fetchOpts.push(opts || {});
      const r = responses[Math.min(i++, responses.length - 1)];
      if (r.reject) throw new Error(r.reject);
      return mkRes(r);
    };
    const poller = createLivePoller({
      inputId: 'abc123',
      endpoint: 'https://site.test/wp-json/hitchstream/v1/live-state',
      onEvent: (e) => {
        events.push(e);
        if (until(events)) { poller.destroy(); resolve({ events, urls, fetchOpts }); }
      },
      ...pollerOpts,
    });
    poller.start();
    realSetTimeout(() => { poller.destroy(); resolve({ events, urls, fetchOpts }); }, 4000); // safety net
  });
}

const FILE_BASE = 'https://site.test/wp-content/hs-state/';
const liveBody = { state: 'live', videoUID: 'uid1', hlsUrl: 'https://x/m.m3u8', errorCode: null, source: 'webhook', ts: 1 };

// A. file mode: URL shape + live poll event
{
  const { events, urls, fetchOpts } = await run({
    responses: [{ status: 200, body: liveBody, etag: '"e1"' }],
    pollerOpts: { fileEndpoint: FILE_BASE },
    until: (ev) => ev.some((e) => e.type === 'poll'),
  });
  const p = events.find((e) => e.type === 'poll');
  assert(urls[0] === `${FILE_BASE}abc123.json`, 'A1: file mode fetches {base}{inputId}.json');
  assert(fetchOpts[0] && fetchOpts[0].cache === 'no-store', 'A4: poll fetch uses cache no-store (browser cache can never answer a poll)');
  assert(p && p.payload.isLive === true && p.payload.videoUID === 'uid1', 'A2: live file body → isLive poll event');
  assert(p && p.payload.source === 'webhook', 'A3: source passed through from file');
}

// B. file-mode 404 → idle, never backoff (even many in a row)
{
  const { events } = await run({
    responses: [{ status: 404 }],
    pollerOpts: { fileEndpoint: FILE_BASE },
    until: (ev) => ev.filter((e) => e.type === 'poll').length >= 4,
  });
  const polls = events.filter((e) => e.type === 'poll');
  assert(polls.length >= 4 && polls.every((p) => p.payload.state === 'idle' && p.payload.isLive === false), 'B1: repeated file 404s → idle polls');
  assert(polls[0].payload.source === 'file-missing', 'B2: 404 poll carries file-missing source');
  assert(!events.some((e) => e.type === 'error' || e.type === 'backoff'), 'B3: file 404 never hits error/backoff');
}

// C. REST-mode 404 → error path (gate check)
{
  const { events } = await run({
    responses: [{ status: 404 }],
    pollerOpts: {},
    until: (ev) => ev.some((e) => e.type === 'error' || e.type === 'backoff'),
  });
  assert(events.some((e) => e.type === 'error' || e.type === 'backoff'), 'C1: REST 404 still errors (idle-ing gated to file mode)');
  assert(!events.some((e) => e.type === 'poll'), 'C2: REST 404 emits no idle poll');
}

// D. file-mode 304 → noChange
{
  const { events } = await run({
    responses: [{ status: 200, body: liveBody, etag: '"e1"' }, { status: 304 }],
    pollerOpts: { fileEndpoint: FILE_BASE },
    until: (ev) => ev.some((e) => e.type === 'noChange'),
  });
  assert(events.some((e) => e.type === 'noChange'), 'D1: 304 → noChange event');
}

// E. repeated 5xx → backoff engages (regression guard)
{
  const { events } = await run({
    responses: [{ status: 500 }],
    pollerOpts: { fileEndpoint: FILE_BASE },
    until: (ev) => ev.some((e) => e.type === 'backoff'),
  });
  assert(events.some((e) => e.type === 'backoff'), 'E1: consecutive 5xx still reaches backoff');
}

console.log(failures === 0 ? '\nALL TESTS PASSED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
