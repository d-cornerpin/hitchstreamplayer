// GestureUnlock.js — HitchStream Player v2
// Resolves on first user gesture (play button click, document click, touch, or
// key press). The promise satisfies browser autoplay-policy requirements.
//
// Two listening surfaces:
//   1. The shadow-DOM play button element, hooked directly via attachPlayButton()
//      when the UI is mounted. This is the primary path — clicks on the play
//      button are retargeted to the host across the shadow boundary, so the
//      previous document-level classList check could not see them.
//   2. Document-level click/touchstart/keydown as a fallback so any user
//      interaction anywhere unlocks playback (still satisfies autoplay policy).
//
// Both surfaces share an AbortController so the first gesture removes both.

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

  /**
   * Hook a play button (or any element inside the shadow DOM) as a primary
   * unlock surface. Call once UI is mounted.
   */
  attachPlayButton(playButtonEl) {
    if (this._resolved || !playButtonEl) return;
    const handler = () => this._resolve();
    playButtonEl.addEventListener('click', handler, { signal: this._controller.signal });
    playButtonEl.addEventListener('touchstart', handler, { signal: this._controller.signal, passive: true });
  }

  /**
   * Start listening at the document level as a fallback. Any click/touch/keydown
   * counts as a valid user gesture per browser autoplay policy, regardless of
   * where in the page it landed.
   */
  start() {
    if (this._resolved) return;
    const sig = this._controller.signal;
    const handler = () => this._resolve();
    document.addEventListener('click', handler, { signal: sig, passive: true });
    document.addEventListener('touchstart', handler, { signal: sig, passive: true });
    document.addEventListener('keydown', handler, { signal: sig, passive: true });
  }

  /** Programmatic unlock (used by tests and forced UI flows). */
  resolve() { this._resolve(); }

  get isUnlocked() { return this._resolved; }
  get promise() { return this._promise; }

  _resolve() {
    if (this._resolved) return;
    this._controller.abort();
  }
}
