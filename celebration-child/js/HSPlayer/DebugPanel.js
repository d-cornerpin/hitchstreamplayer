// DebugPanel.js — HitchStream Player v2
// The single diagnostics panel (top-right), shown only with ?debug=1.
//
// Goals: (1) plain language a non-technical person can read at a glance, plus a
// small technical footer for developers; (2) self-refresh every 1s so the live
// video/buffer/sound readout stays current between the 10s state polls.
//
// Event-driven data (poll results: live, source, videoUID, error, pollCount)
// arrives via update(); live element data (paused/muted/readyState/buffer) is
// read straight off the <video> on each render. There is no longer a second
// panel — the old fixed bottom bar has been removed.

const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]
));

// Format a duration in ms as H:MM:SS (or M:SS under an hour).
const fmtDur = (ms) => {
  const s = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  const p = (n) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${p(m)}:${p(sec)}` : `${m}:${p(sec)}`;
};

// Player state machine value → plain-language sentence.
const PLAYER_PLAIN = {
  IDLE: 'Waiting for the stream',
  PREPARING: 'Getting the video ready…',
  PLAYING: 'Playing',
  FATAL: 'Reconnecting…',
};

// Where the state we're showing actually came from (honest after the server fix).
const SOURCE_PLAIN = {
  webhook: 'Webhook (instant)',
  probe: 'Cloudflare check (~10s)',
  coalesced: 'Cloudflare check (recent)',
  'file-missing': 'No state yet (stream has never started)',
};

export class DebugPanel {
  /**
   * @param {HTMLElement} panelEl  The .debug-panel element.
   * @param {object} [opts]
   * @param {HTMLVideoElement} [opts.videoEl]  The shadow <video> to read live state from.
   * @param {function} [opts.getPlayerState]   Returns the current player-state string.
   */
  constructor(panelEl, opts = {}) {
    this.el = panelEl;
    this._data = {};
    this._videoEl = opts.videoEl || null;
    this._getPlayerState = typeof opts.getPlayerState === 'function' ? opts.getPlayerState : () => null;
    this._getEngineStats = typeof opts.getEngineStats === 'function' ? opts.getEngineStats : () => null;
    this._frame = { lastTotal: 0, lastTime: 0, fps: null }; // for deriving FPS from the decoded-frame counter
    this._everLive = false; // have we seen the stream live at least once this session?
    this._uidCurrent = null; this._uidSince = 0; this._uidRestarts = 0; // current videoUID, when it started, how many times it has changed
    this._timer = null;
  }

  get panelEl() { return this.el; }

  setVideoEl(v) { this._videoEl = v; }

  /** Merge in event-driven data (a poll result) and re-render. */
  update(overrides) {
    Object.assign(this._data, overrides || {});
    if (overrides && overrides.liveStatus === true) this._everLive = true;
    this._trackUid();
    this.render();
  }

  // Track the current videoUID. When it changes to a new one, that's a new stream
  // session (a re-key/glitch or an intentional restart) — bump the restart counter
  // and reset the uptime clock so it always reflects the *current* UID.
  _trackUid() {
    const uid = this._data.videoUID || null;
    if (uid && uid !== this._uidCurrent) {
      if (this._uidCurrent) this._uidRestarts++; // a real change, not the first appearance
      this._uidCurrent = uid;
      this._uidSince = Date.now();
    }
  }

  /** Begin the 1s self-refresh. Call only in debug mode. */
  start() {
    if (this._timer) return;
    this.render();
    this._timer = setInterval(() => this.render(), 1000);
  }

  stop() {
    if (this._timer) { clearInterval(this._timer); this._timer = null; }
  }

  _engineKind() {
    try {
      if (typeof Hls !== 'undefined' && Hls.isSupported && Hls.isSupported()) return 'Hls.js';
    } catch (e) { /* ignore */ }
    return 'Native (Safari)';
  }

  /** Resolution, FPS and dropped frames straight off the <video> element. */
  _videoQuality(v) {
    if (!v) return { res: '—', fps: '—', dropped: '—' };
    const res = (v.videoWidth > 0 && v.videoHeight > 0) ? `${v.videoWidth}×${v.videoHeight}` : '—';

    // Decoded/dropped frame counters: the standard API, with the old WebKit one
    // as a fallback. Both are cumulative since playback began.
    let total = null, dropped = null;
    if (typeof v.getVideoPlaybackQuality === 'function') {
      const q = v.getVideoPlaybackQuality();
      total = q.totalVideoFrames; dropped = q.droppedVideoFrames;
    } else if (typeof v.webkitDecodedFrameCount === 'number') {
      total = v.webkitDecodedFrameCount; dropped = v.webkitDroppedFrameCount || 0;
    }
    if (total == null) return { res, fps: '—', dropped: '—' };

    // FPS = decoded-frame delta over elapsed time. Recompute only when ≥0.5s has
    // passed so irregular render ticks (polls land between 1s ticks) don't jitter it.
    const now = Date.now();
    const s = this._frame;
    if (s.lastTime && now > s.lastTime) {
      const dt = (now - s.lastTime) / 1000;
      if (dt >= 0.5) { s.fps = (total - s.lastTotal) / dt; s.lastTotal = total; s.lastTime = now; }
    } else {
      s.lastTotal = total; s.lastTime = now;
    }
    const fps = (typeof s.fps === 'number') ? `~${Math.max(0, Math.round(s.fps))} fps` : '—';
    const pct = total > 0 ? (dropped / total) * 100 : 0;
    const droppedStr = `${dropped} of ${total} (${pct.toFixed(pct > 0 && pct < 1 ? 2 : 1)}%)`;
    return { res, fps, dropped: droppedStr };
  }

  /** Active rendition / bandwidth / live-latency from the engine (Hls.js only). */
  _engineQuality() {
    let st = null;
    try { st = this._getEngineStats(); } catch (e) { st = null; }
    if (!st) return { engine: this._engineKind(), quality: '—', bandwidth: '—', latency: '—' };

    let quality = '—';
    if (st.levelHeight) {
      quality = `${st.levelHeight}p`;
      if (st.levelBitrate) quality += ` @ ${(st.levelBitrate / 1e6).toFixed(1)} Mbps`;
      if (st.levelAuto) quality += ' (auto)';
    } else if (st.levelAuto && st.levelCount > 0) {
      quality = 'Auto (selecting…)';
    }
    const bandwidth = isFinite(st.bandwidthEstimate) ? `~${(st.bandwidthEstimate / 1e6).toFixed(1)} Mbps` : '—';
    const latency = (isFinite(st.latency) && st.latency > 0) ? `${st.latency.toFixed(1)}s` : '—';
    return { engine: st.engine || this._engineKind(), quality, bandwidth, latency };
  }

  render() {
    if (!this.el) return;
    const d = this._data;
    const v = this._videoEl;
    const playerState = this._getPlayerState() || d.state || '—';

    // ── Live readout off the <video> element ──
    let picture = '—', ready = '—', paused = '—', muted = '—', buf = '—';
    let soundOn = null;
    if (v) {
      ready = (typeof v.readyState === 'number') ? v.readyState : '—';
      paused = v.paused ? 'yes' : 'no';
      muted = v.muted ? 'yes' : 'no';
      soundOn = !v.muted;
      if (v.buffered && v.buffered.length) {
        const ahead = v.buffered.end(v.buffered.length - 1) - v.currentTime;
        if (isFinite(ahead)) buf = Math.max(0, ahead).toFixed(1);
      }
      if (typeof v.readyState === 'number' && v.readyState < 2) picture = 'Loading…';
      else if (v.paused) picture = 'Paused';
      else picture = 'Playing';
    }
    // Fall back to the poll-reported buffer if the element gave none.
    if (buf === '—' && typeof d.bufferAhead === 'number' && isFinite(d.bufferAhead)) {
      buf = Math.max(0, d.bufferAhead).toFixed(1);
    }

    const isLive = d.liveStatus === true;
    const stream = (playerState === 'FATAL') ? '⚠ Problem'
      : isLive ? '● Live now'
      : this._everLive ? '○ Offline — between segments'
      : '○ Not started yet';
    // Uptime of the CURRENT videoUID (resets each new session); '—' while offline.
    const uptime = (isLive && this._uidSince) ? fmtDur(Date.now() - this._uidSince) : '—';

    const playerPlain = PLAYER_PLAIN[playerState] || playerState;
    const sound = (soundOn === null) ? '—' : (soundOn ? 'On' : 'Muted — tap the video for sound');
    const updatedBy = SOURCE_PLAIN[d.source] || '—';
    const polls = (typeof d.pollCount === 'number') ? `${d.pollCount} (every 10s)` : '—';
    const err = d.error_code ? esc(d.error_code) : 'None';

    const vq = this._videoQuality(v);
    const eq = this._engineQuality();

    const row = (k, val) => `<div class="row"><span class="k">${k}</span><span class="v">${val}</span></div>`;

    this.el.innerHTML =
      `<h4>What's happening</h4>` +
      row('Stream', esc(stream)) +
      row('Stream uptime', esc(uptime)) +
      row('Stream restarts', esc(String(this._uidRestarts))) +
      row('Live viewers', (typeof d.liveViewers === 'number') ? esc(String(d.liveViewers)) : '—') +
      row('Player', esc(playerPlain)) +
      row('Picture', esc(picture)) +
      row('Sound', esc(sound)) +
      row('Buffer ready', buf === '—' ? '—' : esc(`${buf}s`)) +
      row('Updated by', esc(updatedBy)) +
      row('Status checks', esc(polls)) +
      row('Problem', err) +
      `<div class="sep"></div><h4>Video quality</h4>` +
      row('Resolution', esc(vq.res)) +
      row('Frame rate', esc(vq.fps)) +
      row('Dropped frames', esc(vq.dropped)) +
      row('Quality level', esc(eq.quality)) +
      row('Bandwidth', esc(eq.bandwidth)) +
      row('Behind live', esc(eq.latency)) +
      `<div class="sep"></div><h4>Technical</h4><div class="tech">` +
      row('Player state', esc(playerState)) +
      row('Video', `ready ${esc(ready)}/4 · paused ${esc(paused)} · muted ${esc(muted)}`) +
      row('Engine', esc(eq.engine)) +
      row('Stream ID', esc(d.inputId || '—')) +
      row('Video ID', esc(d.videoUID ? `${String(d.videoUID).slice(0, 12)}…` : '—')) +
      `</div>`;
  }
}
