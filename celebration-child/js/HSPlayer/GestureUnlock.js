// GestureUnlock.js — HitchStream Player v2
// Promise that resolves on first play button click OR document click/touchstart/keydown.
// AbortController pattern: listeners auto-remove on resolve or element disconnect.

export class GestureUnlock {
  constructor(element) {
    this.element = element;
    this._promise = new Promise((resolve) => { this._resolveFn = resolve; });
    this._controller = new AbortController();
    this._resolved = false;
    this._controller.signal.addEventListener('abort', () => {
      this._resolved = true;
      if (this._resolveFn) { this._resolveFn(); this._resolveFn = null; }
    });
  }

  start() {
    if (this._resolved) return;
    const sig = this._controller.signal;
    this._onClick = (e) => this._onGesture(e.target);
    document.addEventListener('click', this._onClick, { signal: sig, passive: true });
    document.addEventListener('touchstart', this._onClick, { signal: sig, passive: true });
    document.addEventListener('keydown', this._onClick, { signal: sig, passive: true });
  }

  resolve() {
    if (this._resolved) return;
    this._controller.abort();
  }

  get isUnlocked() { return this._resolved; }
  get promise() { return this._promise; }

  _onGesture(target) {
    if (target?.classList?.contains('play-button') || target?.tagName === 'VIDEO') {
      this._resolved = true;
      if (this._resolveFn) { this._resolveFn(); this._resolveFn = null; }
    }
  }
}
