// ManifestProbe.js — HitchStream Player v2
// Manifest probe loop with attempt cap. Returns a promise that resolves on first 200 or rejects on cap hit.
// A1.4 — 40 attempts × 1.5s interval = ~60s cap.

import {
  MANIFEST_PROBE_INTERVAL_MS,
  MANIFEST_PROBE_INITIAL_DELAY_MS,
  MANIFEST_PROBE_MAX_ATTEMPTS,
} from './constants.js';

/**
 * Probe a manifest URL until it returns 200 or the attempt cap is hit.
 * @param {string} url - Manifest URL to probe
 * @param {object} [opts]
 * @param {number} [opts.maxAttempts] - Max probe attempts (default: MANIFEST_PROBE_MAX_ATTEMPTS)
 * @param {number} [opts.intervalMs] - Probe interval in ms (default: MANIFEST_PROBE_INTERVAL_MS)
 * @param {number} [opts.initialDelayMs] - Initial delay before first probe (default: MANIFEST_PROBE_INITIAL_DELAY_MS)
 * @param {AbortSignal} [opts.signal] - AbortController signal to cancel mid-probe
 * @returns {Promise<Response>} Resolves with first successful response, rejects on cap hit or abort
 */
export function probeManifest(url, opts = {}) {
  const {
    maxAttempts = MANIFEST_PROBE_MAX_ATTEMPTS,
    intervalMs = MANIFEST_PROBE_INTERVAL_MS,
    initialDelayMs = MANIFEST_PROBE_INITIAL_DELAY_MS,
    signal = null,
  } = opts;

  return new Promise((resolve, reject) => {
    let attempts = 0;
    let probeInterval = null;

    if (signal?.aborted) {
      reject(new Error('Probe aborted before start'));
      return;
    }

    signal?.addEventListener('abort', () => {
      if (probeInterval) clearInterval(probeInterval);
      reject(new Error('Probe aborted'));
    }, { once: true });

    const attempt = async () => {
      if (signal?.aborted) return;
      attempts++;

      if (attempts > maxAttempts) {
        if (probeInterval) clearInterval(probeInterval);
        reject(new Error(`Probe cap hit after ${maxAttempts} attempts`));
        return;
      }

      try {
        const sep = url.includes('?') ? '&' : '?';
        const probeUrl = `${url}${sep}_cb=${Date.now()}`;
        const res = await fetch(probeUrl, { method: 'GET', mode: 'cors', credentials: 'omit', cache: 'no-store' });

        if (res.ok) {
          if (probeInterval) clearInterval(probeInterval);
          resolve(res);
          return;
        }
      } catch (err) {
        // Not ready yet — continue probing
      }
    };

    // Initial delay then start probing
    setTimeout(() => {
      attempt();
      probeInterval = setInterval(attempt, intervalMs);
    }, initialDelayMs);
  });
}
