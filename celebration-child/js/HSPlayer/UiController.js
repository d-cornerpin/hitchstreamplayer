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
    this.posterMessageEl = null;
    this.progressEl = null;
    this.progressFillEl = null;
    this._bgPauseTimer = null;
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
    this.posterMessageEl = shadow.querySelector('.poster-message');
    this.progressEl = shadow.querySelector('.poster-progress');
    this.progressFillEl = shadow.querySelector('.poster-progress-fill');

    // Mark the poster as having slotted content (a logo/branding card) so the
    // animated backdrop is shown behind it (.poster.has-slot .poster-bg). Without
    // a slotted card the poster stays a plain black backdrop.
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

  /** Set the under-logo poster message (empty string clears it). animate=false
   *  suppresses the trailing "…" (e.g. the fatal "refresh" instruction). */
  setPosterMessage(text, animate = true) {
    if (!this.posterMessageEl) return;
    this.posterMessageEl.textContent = text || '';
    this.posterMessageEl.classList.toggle('animate', !!text && animate);
  }

  /** Set the prebuffer progress line, 0..1 (the CSS transition eases the motion). */
  setProgress(ratio) {
    if (!this.progressFillEl) return;
    const r = Math.max(0, Math.min(1, ratio || 0));
    this.progressFillEl.style.transform = `scaleX(${r})`;
  }

  /** Show/hide the prebuffer progress line. Hiding also resets it to empty. */
  showProgress(visible) {
    if (!this.progressEl) return;
    this.progressEl.classList.toggle('show', !!visible);
    if (!visible) this.setProgress(0);
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
    if (this._bgPauseTimer) { clearTimeout(this._bgPauseTimer); this._bgPauseTimer = null; }
    // Resume the animated backdrop the instant the poster starts to show.
    if (toOpacity > 0) this._setBgPaused(false);
    this.posterEl.style.transition = `opacity ${Math.max(0, durationMs)}ms ease`;
    // Force a reflow so a transition set in the same tick actually animates.
    void this.posterEl.offsetWidth;
    this.posterEl.style.opacity = String(toOpacity);
    // Once fully faded out (poster hidden behind the video), pause the backdrop
    // so its blur layers stop costing GPU/battery while the stream plays.
    if (toOpacity === 0) this._bgPauseTimer = setTimeout(() => this._setBgPaused(true), Math.max(0, durationMs));
  }

  /** Show/hide the poster immediately with no animation. */
  showPosterInstant(visible) {
    if (!this.posterEl) return;
    if (this._bgPauseTimer) { clearTimeout(this._bgPauseTimer); this._bgPauseTimer = null; }
    this.posterEl.style.transition = 'opacity 0ms';
    this.posterEl.style.opacity = visible ? '1' : '0';
    this._setBgPaused(!visible);
  }

  /** Pause/resume the animated poster backdrop (saves GPU while it's hidden). */
  _setBgPaused(paused) {
    if (this.posterEl) this.posterEl.classList.toggle('bg-paused', paused);
  }

  showOverlay(show) {
    if (this.overlayEl) this.overlayEl.style.display = show ? 'block' : 'none';
  }

  showPlayButton(show) {
    if (this.playButtonEl) this.playButtonEl.style.display = show ? 'block' : 'none';
    // The logo card is the inverse of the play button: hidden while the button
    // invites the first tap, then it fades in the instant play is pressed.
    if (this.posterEl) this.posterEl.classList.toggle('pre-play', show);
  }

  hideOverlay() { this.showOverlay(false); }
  hidePlayButton() { this.showPlayButton(false); }

  _buildHTML() {
    return `
      <style>
        :host { display: block; width: 100%; height: 100%; position: relative; overflow: hidden; }
        video { width: 100%; height: 100%; object-fit: contain; background: #000; }
        .poster { position: absolute; inset: 0; width: 100%; height: 100%; background: #000; opacity: 1; transition: opacity 0ms ease; z-index: 5; pointer-events: none; }

        /* Animated poster backdrop — blurred drifting forms over a warm base,
           heavily darkened. Replaces the flat black behind the logo card. Shown
           only in logo-card (has-slot) mode; paused while the poster is hidden
           so its blur layers don't cost GPU/battery during playback. */
        .poster-bg { position: absolute; inset: 0; overflow: hidden; background: #7d6c52; display: none; }
        .poster.has-slot .poster-bg { display: block; }
        .poster.bg-paused .poster-bg .obj, .poster.bg-paused .poster-bg .bgObj { animation-play-state: paused; }
        .poster-bg .obj { position: absolute; border-radius: 30%; filter: blur(45px); opacity: 0.9; will-change: transform; }
        .poster-bg .person { border-radius: 35%; }
        .poster-bg .bgObj { position: absolute; border-radius: 40%; filter: blur(55px); opacity: 0.6; will-change: transform; }
        .poster-bg .poster-bg-ov { position: absolute; inset: 0; background: rgba(0,0,0,0.85); pointer-events: none; }
        .poster-bg .pDark1 { background: #050505; } .poster-bg .pDark2 { background: #111; } .poster-bg .pDark3 { background: #3a241a; } .poster-bg .pDark4 { background: #0a1f33; } .poster-bg .pDark5 { background: #0f2f0f; } .poster-bg .pDark6 { background: #000; } .poster-bg .pDark7 { background: #0b1620; }
        .poster-bg .bgL1 { background: #fff4d6; } .poster-bg .bgL3 { background: #fff2c2; } .poster-bg .bgG1 { background: #7fa86a; } .poster-bg .bgB1 { background: #e2c49a; }
        .poster-bg .bgPink { background: #ff4fa8; } .poster-bg .bgYellow { background: #ffe45c; }
        .poster-bg .p1 { width: 2vmax; height: 90vmax; top: -5%; animation: hs-walk1 57.6s linear infinite; }
        .poster-bg .p2 { width: 4vmax; height: 110vmax; top: -15%; animation: hs-walk2 62.4s linear infinite; }
        .poster-bg .p3 { width: 6vmax; height: 130vmax; top: -20%; animation: hs-walk3 52.8s linear infinite; }
        .poster-bg .p4 { width: 8vmax; height: 150vmax; top: -25%; animation: hs-walk4 48s linear infinite; }
        .poster-bg .p5 { width: 10vmax; height: 170vmax; top: -30%; animation: hs-walk5 43.2s linear infinite; }
        .poster-bg .p6 { width: 14vmax; height: 180vmax; top: -35%; animation: hs-walk6 38.4s linear infinite; }
        .poster-bg .p7 { width: 18vmax; height: 190vmax; top: -40%; animation: hs-walk7 33.6s linear infinite; }
        .poster-bg .p8 { width: 22vmax; height: 200vmax; top: -45%; animation: hs-walk8 28.8s linear infinite; }
        .poster-bg .p9 { width: 26vmax; height: 210vmax; top: -50%; animation: hs-walk9 26.4s linear infinite; }
        .poster-bg .pMega1 { width: 45vmax; height: 220vmax; top: -60%; animation: hs-walkMega1 36s linear infinite; }
        .poster-bg .pMega2 { width: 60vmax; height: 240vmax; top: -70%; animation: hs-walkMega2 40.8s linear infinite; }
        .poster-bg .bgA { width: 40vmax; height: 60vmax; top: 10%; left: 20%; animation: hs-driftA 84s linear infinite; }
        .poster-bg .bgB { width: 55vmax; height: 85vmax; top: -10%; left: 60%; animation: hs-driftB 108s linear infinite; }
        .poster-bg .bgC { width: 30vmax; height: 50vmax; top: 40%; left: -10%; animation: hs-driftC 132s linear infinite; }
        .poster-bg .bgD { width: 65vmax; height: 75vmax; top: 20%; left: 80%; animation: hs-driftD 114s linear infinite; }
        .poster-bg .bgPinkSpot { width: 50vmax; height: 50vmax; top: 30%; left: 10%; opacity: 0.5; animation: hs-driftPink 120s linear infinite; }
        .poster-bg .bgYellowSpot { width: 45vmax; height: 45vmax; top: -5%; left: 70%; opacity: 0.5; animation: hs-driftYellow 140s linear infinite; }
        @keyframes hs-walk1 { 0% { transform: translate(-180vw, 0); } 100% { transform: translate(180vw, 6vmax); } }
        @keyframes hs-walk2 { 0% { transform: translate(190vw, -4vmax); } 100% { transform: translate(-190vw, 8vmax); } }
        @keyframes hs-walk3 { 0% { transform: translate(-200vw, 3vmax); } 100% { transform: translate(200vw, -5vmax); } }
        @keyframes hs-walk4 { 0% { transform: translate(210vw, -8vmax); } 100% { transform: translate(-210vw, 10vmax); } }
        @keyframes hs-walk5 { 0% { transform: translate(-220vw, -2vmax); } 100% { transform: translate(220vw, 4vmax); } }
        @keyframes hs-walk6 { 0% { transform: translate(230vw, 5vmax); } 100% { transform: translate(-230vw, -6vmax); } }
        @keyframes hs-walk7 { 0% { transform: translate(-240vw, -6vmax); } 100% { transform: translate(240vw, 3vmax); } }
        @keyframes hs-walk8 { 0% { transform: translate(250vw, 8vmax); } 100% { transform: translate(-250vw, -8vmax); } }
        @keyframes hs-walk9 { 0% { transform: translate(-260vw, -3vmax); } 100% { transform: translate(260vw, 7vmax); } }
        @keyframes hs-walkMega1 { 0% { transform: translate(-300vw, -10vmax); } 100% { transform: translate(300vw, 12vmax); } }
        @keyframes hs-walkMega2 { 0% { transform: translate(320vw, 15vmax); } 100% { transform: translate(-320vw, -12vmax); } }
        @keyframes hs-driftA { 0% { transform: translate(0,0); } 100% { transform: translate(20vmax, -15vmax); } }
        @keyframes hs-driftB { 0% { transform: translate(0,0); } 100% { transform: translate(-25vmax, 20vmax); } }
        @keyframes hs-driftC { 0% { transform: translate(0,0); } 100% { transform: translate(15vmax, 25vmax); } }
        @keyframes hs-driftD { 0% { transform: translate(0,0); } 100% { transform: translate(-20vmax, -20vmax); } }
        @keyframes hs-driftPink { 0% { transform: translate(0,0); } 100% { transform: translate(30vmax, 20vmax); } }
        @keyframes hs-driftYellow { 0% { transform: translate(0,0); } 100% { transform: translate(-25vmax, 30vmax); } }
        .poster-slot { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: opacity 0.6s ease; }
        /* Before the first tap the play button stands alone over the backdrop;
           the logo + message stay hidden, then fade in once play is pressed. */
        .poster.pre-play .poster-slot { opacity: 0; }
        .poster-message { margin-top: 2.5%; min-height: 1.3em; max-width: 84%; text-align: center; color: #fff; font-family: 'Josefin Sans', sans-serif; font-weight: 300; font-size: clamp(14px, 2.2vw, 22px); letter-spacing: 0.18em; text-transform: uppercase; opacity: 1; }
        .poster-message::after { content: ''; display: inline-block; width: 1.6em; text-align: left; }
        .poster-message.animate::after { animation: hs-ellipsis 1.6s linear infinite; }
        @keyframes hs-ellipsis { 0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; } 75% { content: '...'; } 100% { content: ''; } }
        /* Thin "about to start" line under the message — fills with the real
           prebuffer load so the wait has a visible, reassuring end. */
        .poster-progress { width: clamp(120px, 32%, 280px); height: 2px; margin-top: 3.5%; background: rgba(255,255,255,0.18); border-radius: 2px; overflow: hidden; opacity: 0; transition: opacity 0.45s ease; }
        .poster-progress.show { opacity: 1; }
        .poster-progress-fill { height: 100%; width: 100%; background: #fff; transform: scaleX(0); transform-origin: left center; transition: transform 0.5s ease; will-change: transform; }
        .overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: none; z-index: 10; cursor: pointer; background: transparent; }
        .play-button { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: auto; height: 40%; padding: 0; border: none; background: transparent; cursor: pointer; z-index: 20; display: none; }
        .play-button svg { display: block; height: 100%; width: auto; filter: drop-shadow(20px 10px 16px rgba(0, 0, 0, 1)); }
        .status-message { position: absolute; top: 12px; left: 12px; z-index: 15; background: rgba(0,0,0,0.6); color: #fff; padding: 6px 12px; border-radius: 4px; font-family: sans-serif; font-size: 14px; display: none; opacity: 1; transition: opacity 0.5s ease; }
        .status-message.fade-out { opacity: 0; }
        .debug-panel { position: absolute; top: 12px; right: 12px; z-index: 15; background: rgba(0,0,0,0.84); color: #c34d5f; padding: 10px 12px; border-radius: 6px; font-family: -apple-system, 'Segoe UI', Roboto, system-ui, sans-serif; font-size: 11px; line-height: 1.5; display: none; max-width: 250px; box-shadow: 0 2px 14px rgba(0,0,0,0.45); }
        .debug-panel h4 { margin: 0 0 3px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; opacity: 0.65; }
        .debug-panel .row { display: flex; justify-content: space-between; gap: 12px; }
        .debug-panel .row .k { opacity: 0.7; white-space: nowrap; }
        .debug-panel .row .v { text-align: right; font-weight: 600; }
        .debug-panel .sep { height: 7px; }
        .debug-panel .tech { opacity: 0.6; font-size: 10px; }
      </style>
      <video playsinline></video>
      <div class="poster">
        <div class="poster-bg" aria-hidden="true">
          <div class="bgObj bgL1 bgA"></div><div class="bgObj bgG1 bgB"></div><div class="bgObj bgL3 bgC"></div><div class="bgObj bgB1 bgD"></div>
          <div class="bgObj bgPink bgPinkSpot"></div><div class="bgObj bgYellow bgYellowSpot"></div>
          <div class="obj pDark1 person p1"></div><div class="obj pDark2 person p2"></div><div class="obj pDark3 person p3"></div><div class="obj pDark4 person p4"></div><div class="obj pDark5 person p5"></div><div class="obj pDark6 person p6"></div><div class="obj pDark7 person p7"></div><div class="obj pDark3 person p8"></div><div class="obj pDark2 person p9"></div>
          <div class="obj pDark1 person pMega1"></div><div class="obj pDark4 person pMega2"></div>
          <div class="poster-bg-ov"></div>
        </div>
        <div class="poster-slot"><slot name="poster"></slot><div class="poster-message"></div><div class="poster-progress"><div class="poster-progress-fill"></div></div></div>
      </div>
      <div class="overlay"></div>
      <button class="play-button" aria-label="Play"><svg viewBox="0 0 162.83 182.99" xmlns="http://www.w3.org/2000/svg"><path fill="#fff" d="M154.62,105.71L24.62,180.77C13.68,187.08,0,179.19,0,166.55V16.44C0,3.8,13.68-4.09,24.62,2.22l130,75.06c10.94,6.32,10.94,22.11,0,28.43Z"/></svg></button>
      <div class="status-message"></div>
      <div class="debug-panel"></div>
    `;
  }
}
