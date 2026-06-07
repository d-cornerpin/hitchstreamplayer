// UiController.js — HitchStream Player v2
// Owns the shadow DOM HTML + CSS + overlay show/hide. Stateless: creates fresh DOM each call.

export class UiController {
  constructor() {
    this.videoEl = null;
    this.playButtonEl = null;
    this.overlayEl = null;
    this.debugPanelEl = null;
    this.statusMessageEl = null;
    this.posterEl = null;
    this.posterImgEl = null;
    this.posterMessageEl = null;
  }

  createShadowRoot(hostEl) {
    const shadow = hostEl.attachShadow({ mode: 'open' });
    shadow.innerHTML = this._buildHTML();
    this.videoEl = shadow.querySelector('video');
    this.playButtonEl = shadow.querySelector('.play-button');
    this.overlayEl = shadow.querySelector('.overlay');
    this.debugPanelEl = shadow.querySelector('.debug-panel');
    this.statusMessageEl = shadow.querySelector('.status-message');
    this.posterEl = shadow.querySelector('.poster');
    this.posterImgEl = shadow.querySelector('.poster-img');
    this.posterMessageEl = shadow.querySelector('.poster-message');

    // If the host provides slotted poster content (a logo/branding card), hide
    // the legacy image-poster layer so the two don't stack — the default poster
    // images carry their own logo/text and would otherwise duplicate it.
    const posterSlot = shadow.querySelector('slot[name="poster"]');
    if (posterSlot) {
      const syncSlot = () => {
        const has = posterSlot.assignedNodes({ flatten: true }).some(
          n => n.nodeType === Node.ELEMENT_NODE || (n.textContent && n.textContent.trim() !== '')
        );
        if (this.posterEl) this.posterEl.classList.toggle('has-slot', has);
      };
      posterSlot.addEventListener('slotchange', syncSlot);
      syncSlot();
    }
    return shadow;
  }

  /** Set the poster image source (does not change its opacity). */
  setPosterImage(url) {
    if (this.posterImgEl && url) this.posterImgEl.src = url;
  }

  /** Set the under-logo poster message (empty string clears it). animate=false
   *  suppresses the trailing "…" (e.g. the fatal "refresh" instruction). */
  setPosterMessage(text, animate = true) {
    if (!this.posterMessageEl) return;
    this.posterMessageEl.textContent = text || '';
    this.posterMessageEl.classList.toggle('animate', !!text && animate);
  }

  /** Fade the under-logo message to a target opacity over a duration. */
  fadePosterMessage(toOpacity, durationMs) {
    if (!this.posterMessageEl) return;
    this.posterMessageEl.style.transition = `opacity ${Math.max(0, durationMs)}ms ease`;
    void this.posterMessageEl.offsetWidth;
    this.posterMessageEl.style.opacity = String(toOpacity);
  }

  /** Crossfade the poster to a target opacity (1 = poster shown, 0 = video shown). */
  fadePoster(toOpacity, durationMs) {
    if (!this.posterEl) return;
    this.posterEl.style.transition = `opacity ${Math.max(0, durationMs)}ms ease`;
    // Force a reflow so a transition set in the same tick actually animates.
    void this.posterEl.offsetWidth;
    this.posterEl.style.opacity = String(toOpacity);
  }

  /** Show/hide the poster immediately with no animation. */
  showPosterInstant(visible) {
    if (!this.posterEl) return;
    this.posterEl.style.transition = 'opacity 0ms';
    this.posterEl.style.opacity = visible ? '1' : '0';
  }

  showOverlay(show) {
    if (this.overlayEl) this.overlayEl.style.display = show ? 'block' : 'none';
  }

  showPlayButton(show) {
    if (this.playButtonEl) this.playButtonEl.style.display = show ? 'block' : 'none';
  }

  hideOverlay() { this.showOverlay(false); }
  hidePlayButton() { this.showPlayButton(false); }

  _buildHTML() {
    return `
      <style>
        :host { display: block; width: 100%; height: 100%; position: relative; overflow: hidden; }
        video { width: 100%; height: 100%; object-fit: contain; background: #000; }
        .poster { position: absolute; inset: 0; width: 100%; height: 100%; background: #000; opacity: 1; transition: opacity 0ms ease; z-index: 5; pointer-events: none; }
        .poster-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
        .poster.has-slot .poster-img { display: none; }
        .poster-slot { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .poster-message { margin-top: 2.5%; min-height: 1.3em; max-width: 84%; text-align: center; color: #fff; font-family: 'Josefin Sans', sans-serif; font-weight: 300; font-size: clamp(14px, 2.2vw, 22px); letter-spacing: 0.18em; text-transform: uppercase; opacity: 1; }
        .poster-message::after { content: ''; display: inline-block; width: 1.6em; text-align: left; }
        .poster-message.animate::after { animation: hs-ellipsis 1.6s linear infinite; }
        @keyframes hs-ellipsis { 0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; } 75% { content: '...'; } 100% { content: ''; } }
        .overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: none; z-index: 10; cursor: pointer; background: transparent; }
        .play-button { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: auto; height: 40%; padding: 0; border: none; background: transparent; cursor: pointer; z-index: 20; display: none; }
        .play-button svg { display: block; height: 100%; width: auto; }
        .status-message { position: absolute; top: 12px; left: 12px; z-index: 15; background: rgba(0,0,0,0.6); color: #fff; padding: 6px 12px; border-radius: 4px; font-family: sans-serif; font-size: 14px; display: none; opacity: 1; transition: opacity 0.5s ease; }
        .status-message.fade-out { opacity: 0; }
        .debug-panel { position: absolute; top: 12px; right: 12px; z-index: 15; background: rgba(0,0,0,0.75); color: #0f0; padding: 8px; border-radius: 4px; font-family: monospace; font-size: 11px; display: none; white-space: pre; }
      </style>
      <video playsinline></video>
      <div class="poster">
        <img class="poster-img" alt="" />
        <div class="poster-slot"><slot name="poster"></slot><div class="poster-message"></div></div>
      </div>
      <div class="overlay"></div>
      <button class="play-button" aria-label="Play"><svg viewBox="0 0 162.83 182.99" xmlns="http://www.w3.org/2000/svg"><path fill="#fff" d="M154.62,105.71L24.62,180.77C13.68,187.08,0,179.19,0,166.55V16.44C0,3.8,13.68-4.09,24.62,2.22l130,75.06c10.94,6.32,10.94,22.11,0,28.43Z"/></svg></button>
      <div class="status-message"></div>
      <div class="debug-panel"></div>
    `;
  }
}
