// DebugPanel.js — HitchStream Player v2
// Top-right debug panel rendering overlay data.
// Existing fields + new fields: correlationId, engineKind, ringBufferTail.

export class DebugPanel {
  constructor(debugPanelEl) {
    this.el = debugPanelEl;
    this._data = {};
    this.correlationId = null;
    this.engineKind = null;
  }

  get panelEl() { return this.el; }

  update(overrides) {
    Object.assign(this._data, overrides);
    if (!this.el) return;
    const d = this._data;
    const buf = (typeof d.bufferAhead === 'number' && isFinite(d.bufferAhead))
      ? d.bufferAhead.toFixed(1) : '—';
    const prog = typeof d.inProgress === 'boolean' ? (d.inProgress ? 'yes' : 'no') : '—';
    const ck = typeof d.clicked === 'boolean' ? (d.clicked ? 'yes' : 'no') : '—';
    const lat = (typeof d.latency === 'number' && isFinite(d.latency)) ? d.latency.toFixed(2) : '—';
    const live = typeof d.liveStatus === 'boolean' ? (d.liveStatus ? 'yes' : 'no') : '—';
    const vid = d.videoUID || '—';
    const polls = (typeof d.pollCount === 'number') ? d.pollCount : '—';
    const err = d.error_code || '—';
    const src = d.source || '—';
    const cid = this.correlationId || '—';
    const ek = this.engineKind || '—';
    this.el.textContent = [
      `state: ${d.state || '—'}`,
      `prebuffer: ${buf}s`,
      `In Progress: ${prog}`,
      `clicked: ${ck}`,
      `latency: ${lat}s`,
      `live: ${live}`,
      `videoUID: ${vid}`,
      `polls: ${polls}`,
      `error_code: ${err}`,
      `source: ${src}`,
      `correlationId: ${cid}`,
      `engineKind: ${ek}`,
    ].join('\n');
  }

  setCorrelationId(id) {
    this.correlationId = id;
  }

  setEngineKind(kind) {
    this.engineKind = kind;
  }

  get ringBufferTail() {
    return typeof window !== 'undefined' && window.getSafeRing ? window.getSafeRing() : [];
  }
}
