// utils/timers.js — HitchStream Player v2
// TimerRegistry: every timer in the player registers here.
// disconnectedCallback calls dispose() once. No bare clearInterval/clearTimeout in the element.

export class TimerRegistry {
  constructor() {
    this._intervals = new Set();
    this._timeouts = new Set();
    this._disposed = false;
  }

  setInterval(fn, delay) {
    if (this._disposed) return -1;
    const id = setInterval(() => {
      if (!this._disposed) fn();
    }, delay);
    this._intervals.add(id);
    return id;
  }

  setTimeout(fn, delay) {
    if (this._disposed) return -1;
    const id = setTimeout(() => {
      this._timeouts.delete(id);
      fn();
    }, delay);
    this._timeouts.add(id);
    return id;
  }

  clearInterval(id) {
    clearInterval(id);
    this._intervals.delete(id);
  }

  clearTimeout(id) {
    clearTimeout(id);
    this._timeouts.delete(id);
  }

  dispose() {
    this._disposed = true;
    for (const id of this._intervals) clearInterval(id);
    this._intervals.clear();
    for (const id of this._timeouts) clearTimeout(id);
    this._timeouts.clear();
  }
}
