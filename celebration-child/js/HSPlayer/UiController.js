// UiController.js — HitchStream Player v2
// Owns the shadow DOM HTML + CSS + overlay show/hide. Stateless: creates fresh DOM each call.

export class UiController {
  constructor() {
    this.videoEl = null;
    this.playButtonEl = null;
    this.overlayEl = null;
    this.debugPanelEl = null;
    this.statusMessageEl = null;
  }

  createShadowRoot(hostEl) {
    const shadow = hostEl.attachShadow({ mode: 'open' });
    shadow.innerHTML = this._buildHTML();
    this.videoEl = shadow.querySelector('video');
    this.playButtonEl = shadow.querySelector('.play-button');
    this.overlayEl = shadow.querySelector('.overlay');
    this.debugPanelEl = shadow.querySelector('.debug-panel');
    this.statusMessageEl = shadow.querySelector('.status-message');
    return shadow;
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
        .overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: none; z-index: 10; cursor: pointer; background: transparent; }
        .play-button { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 72px; height: 72px; border-radius: 50%; border: none; background: rgba(255,255,255,0.9); cursor: pointer; z-index: 20; display: none; justify-content: center; align-items: center; }
        .play-button::after { content: ''; display: block; width: 0; height: 0; border-top: 18px solid transparent; border-bottom: 18px solid transparent; border-left: 30px solid #000; margin-left: 6px; }
        .status-message { position: absolute; top: 12px; left: 12px; z-index: 15; background: rgba(0,0,0,0.6); color: #fff; padding: 6px 12px; border-radius: 4px; font-family: sans-serif; font-size: 14px; display: none; opacity: 1; transition: opacity 0.5s ease; }
        .status-message.fade-out { opacity: 0; }
        .debug-panel { position: absolute; top: 12px; right: 12px; z-index: 15; background: rgba(0,0,0,0.75); color: #0f0; padding: 8px; border-radius: 4px; font-family: monospace; font-size: 11px; display: none; white-space: pre; }
      </style>
      <video playsinline></video>
      <div class="overlay"></div>
      <button class="play-button" aria-label="Play"></button>
      <div class="status-message"></div>
      <div class="debug-panel"></div>
    `;
  }
}
