// PosterManager.js — HitchStream Player v2
// Single owner of poster state. Eliminates mutable module-level POSTER_* lets (B17 fix).
// Priority: attribute > config > default.

import {
  DEFAULT_POSTER_INITIAL_URL,
  DEFAULT_POSTER_IDLE_URL,
  DEFAULT_POSTER_FATAL_URL,
} from './constants.js';

// No silent fallback to a hardcoded customer code per plan §B3.4. If
// HSPlayerConfig.cloudflare.customerCode is not provided, customerCode stays
// null and setApiInfo() in index.js will _enterFatal() before any URL is
// constructed using it. This surfaces misconfiguration to the operator
// instead of silently rolling on a code that points at someone else's
// Cloudflare account.

export class PosterManager {
  constructor() {
    this.initial = null;
    this.idle = null;
    this.fatal = null;
    this.customerCode = null;
  }

  init(cfg, attrs) {
    if (!cfg) cfg = {};
    this.customerCode = cfg?.cloudflare?.customerCode || null;
    this.initial = this._resolve(cfg?.posters?.initial, attrs?.['poster-initial'], DEFAULT_POSTER_INITIAL_URL);
    this.idle = this._resolve(cfg?.posters?.idle, attrs?.['poster-idle'], DEFAULT_POSTER_IDLE_URL);
    this.fatal = this._resolve(cfg?.posters?.fatal, attrs?.['poster-fatal'], DEFAULT_POSTER_FATAL_URL);
  }

  set(which, url) {
    if (!url) return;
    if (which === 'initial') this.initial = url;
    else if (which === 'idle') this.idle = url;
    else if (which === 'fatal') this.fatal = url;
  }

  current() { return { initial: this.initial, idle: this.idle, fatal: this.fatal }; }

  _resolve(cfgVal, attrVal, defaultVal) {
    if (cfgVal) return cfgVal;
    if (attrVal) return attrVal;
    return defaultVal;
  }
}
