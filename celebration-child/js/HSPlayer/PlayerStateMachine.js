// PlayerStateMachine.js — HitchStream Player v2
// Pure state-transition module. No DOM, no fetch, no timers, no class.
// Import: import { transition, STATE, STATUS } from './PlayerStateMachine.js'

import { STATE, STATUS, CF_ERROR_MESSAGES } from './constants.js';

// ─── Helper: check if error code produces viewer-facing text ──

function isViewerFacingError(errorCode) {
  return errorCode != null && errorCode in CF_ERROR_MESSAGES;
}

// ─── Internal: handle poll events from IDLE state ──

function handleIdlePoll(payload) {
  const { state, videoUID, hlsUrl, errorCode, source } = payload;

  switch (state) {
    case 'live':
      if (videoUID && hlsUrl) {
        return { nextState: STATE.PREPARING, sideEffects: [
          { type: 'loadHls', payload: { url: hlsUrl } },
          { type: 'showStatus', payload: STATUS.PREPARING },
        ]};
      }
      // Live but missing fields — treat as idle (contract §4.1).
      return { nextState: STATE.IDLE, sideEffects: [] };

    case 'reconnecting':
      // While idle, reconnecting is a no-op.
      return { nextState: STATE.IDLE, sideEffects: [] };

    case 'idle':
      return { nextState: STATE.IDLE, sideEffects: [] };

    case 'error':
      // Error logged to debug panel; UX stays idle.
      return { nextState: STATE.IDLE, sideEffects: [
        { type: 'logError', payload: { errorCode, source } },
      ]};

    default:
      return { nextState: STATE.IDLE, sideEffects: [] };
  }
}

// ─── Internal: handle poll events from PREPARING state ──

function handlePreparingPoll(payload, context) {
  const { state, videoUID, hlsUrl, errorCode, source } = payload;

  switch (state) {
    case 'live':
      if (videoUID && hlsUrl) {
        // Already preparing — no state change.
        return { nextState: STATE.PREPARING, sideEffects: [] };
      }
      return { nextState: STATE.IDLE, sideEffects: [
        { type: 'setPoster', payload: { which: context.hasPlayedOnce ? 'idle' : 'initial' } },
        { type: 'showStatus', payload: STATUS.WAITING },
      ]};

    case 'reconnecting':
      return { nextState: STATE.PREPARING, sideEffects: [] };

    case 'idle':
      return { nextState: STATE.IDLE, sideEffects: [
        { type: 'setPoster', payload: { which: context.hasPlayedOnce ? 'idle' : 'initial' } },
        { type: 'showStatus', payload: context.userGestureUnlocked ? STATUS.PAUSED : STATUS.WAITING },
      ]};

    case 'error':
      return { nextState: STATE.IDLE, sideEffects: [
        { type: 'logError', payload: { errorCode, source } },
        { type: 'setPoster', payload: { which: context.hasPlayedOnce ? 'idle' : 'initial' } },
        { type: 'showStatus', payload: isViewerFacingError(errorCode) ? STATUS.ERROR : STATUS.WAITING },
      ]};

    default:
      return { nextState: STATE.PREPARING, sideEffects: [] };
  }
}

// ─── Internal: handle poll events from PLAYING state ──

function handlePlayingPoll(payload, context) {
  const { state, videoUID, hlsUrl, errorCode, source } = payload;

  switch (state) {
    case 'live':
      // Check if videoUID changed (ceremony → reception handover).
      if (videoUID && videoUID !== context.currentVideoUID) {
        return { nextState: STATE.PLAYING, sideEffects: [
          { type: 'handover', payload: { newVideoUID: videoUID } },
        ]};
      }
      // Same stream — no state change.
      return { nextState: STATE.PLAYING, sideEffects: [] };

    case 'reconnecting':
      // While playing, reconnecting → drain to idle if buffer depleted.
      if (context.hasBufferedContent === false || context.bufferAhead < 0.5) {
        return { nextState: STATE.IDLE, sideEffects: [
          { type: 'setPoster', payload: { which: 'idle' } },
          { type: 'showStatus', payload: STATUS.PAUSED },
        ]};
      }
      // Keep playing — drain handled by element layer.
      return { nextState: STATE.PLAYING, sideEffects: [
        { type: 'showStatus', payload: STATUS.RECONNECTING },
      ]};

    case 'idle':
      if (context.hasBufferedContent && context.bufferAhead > 0.5) {
        // A1.12: drain buffer instead of tearing down.
        return { nextState: STATE.PLAYING, sideEffects: [
          { type: 'drainToIdle' },
          { type: 'showStatus', payload: STATUS.PAUSED },
        ]};
      }
      // Buffer exhausted — transition to idle.
      return { nextState: STATE.IDLE, sideEffects: [
        { type: 'destroyHls' },
        { type: 'setPoster', payload: { which: context.hasPlayedOnce ? 'idle' : 'initial' } },
        { type: 'showStatus', payload: STATUS.PAUSED },
      ]};

    case 'error':
      if (context.hasBufferedContent && context.bufferAhead > 0.5) {
        return { nextState: STATE.PLAYING, sideEffects: [
          { type: 'drainToIdle' },
          { type: 'showStatus', payload: isViewerFacingError(errorCode) ? STATUS.ERROR : STATUS.PAUSED },
          { type: 'logError', payload: { errorCode, source } },
        ]};
      }
      return { nextState: STATE.IDLE, sideEffects: [
        { type: 'destroyHls' },
        { type: 'setPoster', payload: { which: context.hasPlayedOnce ? 'idle' : 'initial' } },
        { type: 'showStatus', payload: isViewerFacingError(errorCode) ? STATUS.ERROR : STATUS.WAITING },
        { type: 'logError', payload: { errorCode, source } },
      ]};

    default:
      return { nextState: STATE.PLAYING, sideEffects: [] };
  }
}

// ─── Main transition function ──

/**
 * Compute next state and side-effects for a given state transition.
 *
 * @param {object} input
 * @param {string}  input.currentState  — one of STATE.*
 * @param {object}  input.event         — { type: string, payload: object }
 * @param {object}  input.context       — { hasPlayedOnce: boolean, userGestureUnlocked: boolean, bufferAhead: number, hasBufferedContent: boolean, currentVideoUID: string|null }
 * @returns {{ nextState: string, sideEffects: Array<{type: string, payload?: object}> }}
 */
export function transition({ currentState, event, context }) {
  const { type, payload } = event;

  // FATAL is terminal — no events transition out.
  if (currentState === STATE.FATAL) {
    return { nextState: STATE.FATAL, sideEffects: [] };
  }

  switch (currentState) {

    case STATE.IDLE:
      if (type === 'poll') {
        return handleIdlePoll(payload);
      }
      if (type === 'clickPlay') {
        return { nextState: STATE.IDLE, sideEffects: [{ type: 'showStatus', payload: STATUS.WAITING }] };
      }
      return { nextState: STATE.IDLE, sideEffects: [] };

    case STATE.PREPARING:
      if (type === 'poll') {
        return handlePreparingPoll(payload, context);
      }
      if (type === 'prebufferReady') {
        return { nextState: STATE.PLAYING, sideEffects: [
          { type: 'startPlayback' },
          { type: 'showStatus', payload: STATUS.PREPARING },
        ]};
      }
      if (type === 'videoUIDChanged') {
        return { nextState: STATE.PREPARING, sideEffects: [
          { type: 'rebuildHls', payload: { videoUID: payload.newVideoUID } },
        ]};
      }
      return { nextState: STATE.PREPARING, sideEffects: [] };

    case STATE.PLAYING:
      if (type === 'poll') {
        return handlePlayingPoll(payload, context);
      }
      if (type === 'bufferDrained') {
        return { nextState: STATE.IDLE, sideEffects: [
          { type: 'destroyHls' },
          { type: 'setPoster', payload: { which: context.hasPlayedOnce ? 'idle' : 'initial' } },
          { type: 'showStatus', payload: STATUS.PAUSED },
        ]};
      }
      if (type === 'videoUIDChanged') {
        // Handover: element applies engine swap.
        return { nextState: STATE.PLAYING, sideEffects: [
          { type: 'handover', payload: { newVideoUID: payload.newVideoUID } },
        ]};
      }
      if (type === 'networkError') {
        return { nextState: STATE.PLAYING, sideEffects: [
          { type: 'showStatus', payload: STATUS.RECONNECTING },
        ]};
      }
      return { nextState: STATE.PLAYING, sideEffects: [] };

    default:
      return { nextState: currentState, sideEffects: [] };
  }
}

// ─── Re-exports for convenience ──

export { STATE, STATUS };
