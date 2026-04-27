// StatusOverlay.js — HitchStream Player v2
// Top-left status widget with animated ellipsis, transient messages, fade timers.
// Pre-gesture suppression (status only shows after userGestureUnlocked) is INTENTIONAL UX.

import { STATUS } from './constants.js';

const ELLIPSIS_INTERVAL_MS = 500;
const STATUS_FADE_OUT_MS = 500;

const STATUS_TEXTS = {
  waiting: 'Waiting for stream',
  preparing: 'Preparing to stream',
  live: 'Live',
  reconnecting: 'Reconnecting',
  paused: 'Paused/Ended',
  error: 'Error',
  syncIssue: 'Audio sync issue',
};

const ANIMATED_TYPES = new Set(['waiting', 'preparing', 'reconnecting', 'syncIssue']);
const TRANSIENT_TYPES = new Set(['live', 'paused', 'error']);

export class StatusOverlay {
  constructor(statusMessageEl, timerRegistry) {
    this.statusMessageEl = statusMessageEl;
    this.timers = timerRegistry;
    this.currentStatusType = null;
    this.statusEllipsisInterval = null;
    this._gestureUnlocked = false;
  }

  get gestureUnlocked() { return this._gestureUnlocked; }
  set gestureUnlocked(val) { this._gestureUnlocked = val; }

  showAnimatedStatus(baseText) {
    if (!this.statusMessageEl) return;
    this.stopStatusAnimation();
    this.statusMessageEl.textContent = baseText + '...';
    this.statusMessageEl.style.display = 'block';
    this.statusMessageEl.style.opacity = '1';
    this.statusMessageEl.classList.remove('fade-out');
    let idx = 0;
    this.statusEllipsisInterval = this.timers.setInterval(() => {
      idx = (idx + 1) % 4;
      this.statusMessageEl.textContent = baseText + '.'.repeat(idx);
    }, ELLIPSIS_INTERVAL_MS);
  }

  stopStatusAnimation() {
    if (this.statusEllipsisInterval) {
      this.timers.clearInterval(this.statusEllipsisInterval);
      this.statusEllipsisInterval = null;
    }
  }

  showStatusMessage(text, durationMs = 3000) {
    if (!this.statusMessageEl) return;
    this.stopStatusAnimation();
    this._clearFadeTimers();
    this.statusMessageEl.textContent = text;
    this.statusMessageEl.style.display = 'block';
    this.statusMessageEl.style.opacity = '1';
    this.statusMessageEl.classList.remove('fade-out');
    this._fadeTimeout = this.timers.setTimeout(() => {
      if (this.statusMessageEl) this.statusMessageEl.classList.add('fade-out');
      this._hideTimeout = this.timers.setTimeout(() => {
        if (this.statusMessageEl) {
          this.statusMessageEl.style.display = 'none';
          this.statusMessageEl.classList.remove('fade-out');
        }
      }, STATUS_FADE_OUT_MS);
    }, durationMs);
  }

  hideStatusMessage() {
    if (!this.statusMessageEl) return;
    this.stopStatusAnimation();
    this._clearFadeTimers();
    this.statusMessageEl.style.display = 'none';
    this.statusMessageEl.style.opacity = '1';
    this.statusMessageEl.classList.remove('fade-out');
    this.currentStatusType = 'none';
  }

  updateStatus(type) {
    if (!type) type = 'none';
    if (!this._gestureUnlocked) {
      this.hideStatusMessage();
      this.currentStatusType = 'none';
      return;
    }
    if (this.currentStatusType === type) return;
    this.currentStatusType = type;

    if (ANIMATED_TYPES.has(type)) {
      this.showAnimatedStatus(STATUS_TEXTS[type] || type);
    } else if (TRANSIENT_TYPES.has(type)) {
      this.showStatusMessage(STATUS_TEXTS[type] || type);
    } else {
      this.hideStatusMessage();
    }
  }

  _clearFadeTimers() {
    if (this._fadeTimeout) { this.timers.clearTimeout(this._fadeTimeout); this._fadeTimeout = null; }
    if (this._hideTimeout) { this.timers.clearTimeout(this._hideTimeout); this._hideTimeout = null; }
  }
}
