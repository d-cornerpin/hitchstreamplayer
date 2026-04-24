/**
 * Generate a minimal valid MPEG-TS packet (188 bytes, no PCR/PAT/PMT —
 * just a continuous stream).  Hls.js will attempt to parse it; it won't
 * find real frames, but the manifest parsing and pipeline wiring work.
 * For integration testing the player's state machine we only need the
 * pipeline to load successfully — real video frames are not required.
 */
function makeTsPacket() {
  const buf = Buffer.alloc(188, 0x47); // 0x47 = MPEG-TS sync byte
  // Add a random PID (start with TS header)
  buf.writeUInt16BE(0x2000, 1); // PID = 0x2000 (PCR)
  buf[3] = 0x10; // PID flag + continuity counter placeholder
  return buf;
}

/**
 * Build a minimal PES packet from a TS continuity counter.
 * PES header: 0x000001 + stream_id (0xC0 for video, 0xC1 for audio)
 */
function makePesPacket(streamId = 0xC0) {
  const header = Buffer.from([0x00, 0x00, 0x01, streamId]);
  const body = Buffer.alloc(188 - 4, 0x00);
  return Buffer.concat([header, body]);
}

function makeTsSegment(videoPts = 0, audioPts = 0) {
  const pesVideo = makePesPacket(0xC0);
  const pesAudio = makePesPacket(0xC1);
  const payload = Buffer.concat([pesVideo, pesAudio, Buffer.alloc(188 - 8, 0)]);
  return payload;
}

// ─── Mock server ────────────────────────────────────────────────

const express = require('express');
const fs = require('fs');
const crypto = require('crypto');
const path = require('path');

const app = express();
app.use(express.json());

const PORT = process.env.MOCK_PORT || 3456;
const STATE_FILE = path.join(__dirname, 'mock-state.json');

// ─── State helpers ──────────────────────────────────────────────

function readState() {
  try {
    return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
  } catch {
    return { state: 'idle', videoUID: null, hlsUrl: null, errorCode: null, source: 'webhook', ts: 0 };
  }
}

function writeState(obj) {
  const copy = { ...obj };
  if (!copy.etag) {
    copy.etag = crypto.randomUUID().slice(0, 8);
  }
  copy.ts = copy.ts || Math.floor(Date.now() / 1000);
  fs.writeFileSync(STATE_FILE, JSON.stringify(copy, null, 2));
  return copy;
}

function hashState(obj) {
  const strip = { ...obj };
  delete strip.etag; // ETag is separate
  return JSON.stringify(strip, Object.keys(strip).sort());
}

// ─── Mock HLS manifest (minimal but parseable by Hls.js) ───────

function buildManifest(videoUID) {
  const segmentCount = 6;
  const targetDuration = 4;
  const playlists = [
    `#EXTM3U`,
    `#EXT-X-VERSION:3`,
    `#EXT-X-TARGETDURATION:${targetDuration}`,
    `#EXT-X-MEDIA-SEQUENCE:0`,
    `#EXT-X-PLAYLIST-TYPE:EVENT`,
  ];
  for (let i = 0; i < segmentCount; i++) {
    playlists.push(`#EXTINF:${targetDuration}.0,`);
    playlists.push(`${i + 1}.ts`);
  }
  playlists.push(`#EXT-X-ENDLIST`);
  return playlists.join('\n') + '\n';
}

// ─── API endpoint ───────────────────────────────────────────────

// GET /live-state?inputId=...
app.get('/live-state', (req, res) => {
  const inputId = req.query.inputId;
  if (!inputId || !/^[A-Za-z0-9_-]+$/.test(inputId)) {
    return res.status(400).json({ error: 'Missing or invalid inputId', code: 'invalid_input_id' });
  }

  const correlationId = crypto.randomUUID();
  res.set('X-HS-Correlation-Id', correlationId);
  res.set('Cache-Control', 'no-store');

  const state = readState();

  // ETag / 304
  const etag = `"${state.etag}"`;
  const ifNoneMatch = req.headers['if-none-match'];
  if (ifNoneMatch && ifNoneMatch === etag) {
    return res.status(304).end();
  }
  res.set('ETag', etag);

  // Build response body
  const body = {
    state: state.state,
    videoUID: state.videoUID,
    hlsUrl: state.hlsUrl,
    errorCode: state.errorCode ?? null,
    source: state.source,
    ts: state.ts,
  };

  res.json(body);
});

// ─── Mock HLS content ──────────────────────────────────────────

// GET /live/:inputId/:videoUID/manifest/video.m3u8
app.get('/live/:inputId/:videoUID/manifest/video.m3u8', (req, res) => {
  const { inputId, videoUID } = req.params;
  res.set('Content-Type', 'application/x-mpegURL');
  res.send(buildManifest(videoUID));
});

// GET /live/:inputId/:videoUID/media/:seg.ts
app.get('/live/:inputId/:videoUID/media/:seg.ts', (req, res) => {
  const segNum = parseInt(req.params.seg.replace('.ts', ''), 10);
  res.set('Content-Type', 'video/mp2t');
  res.send(makeTsSegment(segNum * 4, segNum * 4));
});

// ─── Admin endpoint (to set mock state) ────────────────────────

// POST /admin/state with JSON body
app.post('/admin/state', (req, res) => {
  const body = req.body;
  const state = readState();
  const newState = { ...state, ...body };
  if (!body.etag) {
    newState.etag = crypto.randomUUID().slice(0, 8);
  }
  // Enforce contract: idle/error → null hlsUrl/videoUID unless explicitly set
  if (newState.state === 'idle' || newState.state === 'error') {
    if (!('videoUID' in body)) newState.videoUID = null;
    if (!('hlsUrl' in body)) newState.hlsUrl = null;
  }
  writeState(newState);
  res.json(newState);
});

// ─── Start ─────────────────────────────────────────────────────

app.listen(PORT, () => {
  console.log(`Mock server listening on :${PORT}`);
  console.log(`State file: ${STATE_FILE}`);
});
