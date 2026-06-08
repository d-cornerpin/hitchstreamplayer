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

// Player state machine value → plain-language sentence.
const PLAYER_PLAIN = {
  IDLE: 'Waiting for the stream to start',
  PREPARING: 'Getting the video ready…',
  PLAYING: 'Playing',
  FATAL: 'Stopped — please refresh the page',
};

// Where the state we're showing actually came from (honest after the server fix).
const SOURCE_PLAIN = {
  webhook: 'Webhook (instant)',
  probe: 'Cloudflare check (~10s)',
  coalesced: 'Cloudflare check (recent)',
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
    this._timer = null;
  }

  get panelEl() { return this.el; }

  setVideoEl(v) { this._videoEl = v; }

  /** Merge in event-driven data (a poll result) and re-render. */
  update(overrides) {
    Object.assign(this._data, overrides || {});
    this.render();
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
      : '○ Not started yet';

    const playerPlain = PLAYER_PLAIN[playerState] || playerState;
    const sound = (soundOn === null) ? '—' : (soundOn ? 'On' : 'Muted — tap the video for sound');
    const updatedBy = SOURCE_PLAIN[d.source] || '—';
    const polls = (typeof d.pollCount === 'number') ? `${d.pollCount} (every 10s)` : '—';
    const err = d.error_code ? esc(d.error_code) : 'None';

    const row = (k, val) => `<div class="row"><span class="k">${k}</span><span class="v">${val}</span></div>`;

    this.el.innerHTML =
      `<h4>What's happening</h4>` +
      row('Stream', esc(stream)) +
      row('Player', esc(playerPlain)) +
      row('Picture', esc(picture)) +
      row('Sound', esc(sound)) +
      row('Buffer ready', buf === '—' ? '—' : esc(`${buf}s`)) +
      row('Updated by', esc(updatedBy)) +
      row('Status checks', esc(polls)) +
      row('Problem', err) +
      `<div class="sep"></div><h4>Technical</h4><div class="tech">` +
      row('Player state', esc(playerState)) +
      row('Video', `ready ${esc(ready)}/4 · paused ${esc(paused)} · muted ${esc(muted)}`) +
      row('Engine', esc(this._engineKind())) +
      row('Stream ID', esc(d.inputId || '—')) +
      row('Video ID', esc(d.videoUID ? `${String(d.videoUID).slice(0, 12)}…` : '—')) +
      `</div>`;
  }
}
