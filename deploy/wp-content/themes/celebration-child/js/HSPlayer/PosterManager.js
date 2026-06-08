// PosterManager.js — HitchStream Player v2
// Holds the Cloudflare customer code used to build manifest URLs. The legacy
// image-poster fields were removed — the slotted logo card + animated backdrop
// (in the player page + UiController) are the poster now.

export class PosterManager {
  constructor() {
    this.customerCode = null;
  }

  init(cfg) {
    // No silent fallback to a hardcoded customer code: if it's missing,
    // customerCode stays null and setApiInfo() in index.js _enterFatal()s before
    // building any URL — surfacing the misconfiguration to the operator instead
    // of streaming from someone else's Cloudflare account.
    this.customerCode = cfg?.cloudflare?.customerCode || null;
  }
}
