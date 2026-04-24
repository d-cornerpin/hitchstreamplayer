# Session Handoff — HitchStream Player v2

**Last updated:** 2026-04-24
**Written by:** Claude (Opus 4.7, 1M context) on the assistant side of a planning conversation with David (@d-cornerpin) — the owner of HitchStream, a wedding live-streaming business.

**Purpose:** if you are a fresh Claude Code session on a different machine, read this document before responding to the user's next request. It captures conversation-level context that isn't visible in git history — decisions made, misreads corrected, user preferences, and open threads.

---

## 1. What this project is

HitchStream is a professional wedding live-streaming business. The flagship product is **HSPlayer** — a custom `<hs-video>` web component built in-house that plays Cloudflare Stream live HLS (and pre-recorded VODs) inside an iframe embedded on wedding-event web pages. It is **not** the Cloudflare-provided player. Viewers use it during real weddings; reliability expectations are production-grade.

The current state of the codebase was inherited from a sequence of increasingly senior developers. A v2 rebuild is underway to fix critical bugs and harden everything for reliability.

Read `PROJECT.md` and `CLAUDE.md` at the repo root for the codebase-level context. Read `HitchStream_Player_v2.md` at the repo root for the full rebuild plan (~830 lines).

## 2. How the work is structured

Two AI coding agents are working in parallel, each owning one workstream:

- **Agent A** owns §5 of the plan — the `<hs-video>` web component and the iframe page template.
- **Agent B** owns §6 of the plan — the webhook receiver, live-state endpoint, admin plugin, and wedding templates.

Both agents run in Claude Code pointed at a local Ollama server hosting Qwen 3.6, 256k context configured on Ollama (though Claude Code's effective context assumption may be lower — see §8).

Agents coordinate through:
- The frozen contract in §4 of the plan
- Scheduled integration checkpoints (CP-0 through CP-5) in §7
- Their own progress files at `docs/progress/agent-a.md` and `docs/progress/agent-b.md`
- Mock fixtures at `docs/progress/agent-a-fixtures/*.json` (Agent A's work; Agent B validates against them at B2.8)

David relays messages between the agents and me. Agents do not talk to each other directly.

## 3. Current state (as of handoff)

### CP-0 is signed
Both agents confirmed §4 in their progress files. Five amendments folded into §6 based on Agent A's review (committed in `ee4c98a`):

- **B1.3a** — On `/lifecycle` API failure, webhook handler must NOT update the transient. Writing `state=live` with empty `videoUID` violates §4.1.
- **B1.4a** — On coalesced responses, `ts` must reflect the original probe write time.
- **B2.2a** — Flat-file writes must be atomic (write `.tmp`, then `rename()`).
- **B4.10a** — Backward-compat shims must validate §4 shape before returning.
- **B5.6a** — Error-alert codes read from `HSCF_alert_codes` option, not hardcoded.

### Agent A status
- Phase A0 (preparation) COMPLETE. All six checkboxes checked.
- Has built a mock contract server at `celebration-child/js/__tests__/mock-server/` with ETag/304 and correlation-ID support.
- Has reproduced the iPhone-native-HLS bug (B2 from §2) with Playwright.
- Has committed 8 fixture JSON files under `docs/progress/agent-a-fixtures/`.
- **Cleared to start A1** as of last user interaction. Next checkbox: A1.1 (disambiguate `this.isLive`).

### Agent B status
- Phase B0 (emergency security triage) COMPLETE. PR #1 (https://github.com/d-cornerpin/hitchstreamplayer/pull/1) awaits human security review and deploy.
- **Cleared to start B1** code work in parallel. B1.7 (staging test) has to wait until PR #1 is deployed; everything before that is pure code work.
- Next checkbox: B1.1 (empirical verification of Cloudflare webhook signature format — requires ngrok + a Cloudflare dashboard test).

### User's outstanding action items
1. Human security review of PR #1 before merge.
2. Rotate the streamer API key on `streamer1.hitchstream.com` (old value `72c020a8d042a1f549b548311d1e4577` was in the initial commit; not a Cloudflare key — it's an internal service key).
3. Configure `HSCF_webhook_secret` in the WordPress admin before merging PR #1 (otherwise webhook receiver rejects everything once deployed).
4. Merge & deploy PR #1.

## 4. User profile (important)

David is the business owner and technical product lead. He is:

- **Direct and low-patience with hand-waving.** Says "that's confusing" or "you missed the point" when a response is vague or misses intent. Trust these corrections; don't dig in.
- **Product-focused over architecture-aesthetic.** Doesn't want clever architecture for its own sake. Wants what works for his 20–50-viewer weddings on a 4GB droplet.
- **Preserves hard-earned product decisions even when they look like bugs.** Several "bugs" I flagged turned out to be intentional UX choices (see §5). When in doubt, ask before "fixing."
- **Uses checklist-style hard gates.** Agents must respect phase gates in §5/§6 and not advance until all checkboxes in a phase are done.
- **Prefers concrete prompts he can copy-paste** over abstract recommendations. When he asks "what should I say to my agents," give him exact text, not guidance.

### User's infrastructure

- WordPress site on a DigitalOcean 4GB/80GB Ubuntu droplet, shared with a handful of low-traffic small sites.
- Cloudflare Stream for ingest/transcoding/HLS delivery.
- An internal Node.js service at `streamer1.hitchstream.com` for placeholder-stream operations (holds an RTMPS placeholder when no live event is active).
- Weddings are 30 min to 6 hours, 20–50 concurrent online guests per event.

## 5. Misreads I corrected during this conversation (do NOT re-introduce)

These are critical. Earlier in the conversation I misread the product intent several times. David corrected each. If a fresh Claude reads the plan and the progress files, these corrections ARE baked in — but the rationale isn't, and a new session could regress without understanding why.

1. **"Waiting for stream..." shown pre-click is WRONG.** Before the viewer clicks play, the player shows ONLY the poster image and the play button. No status text, no spinner, no loader. This is deliberate product UX. The `updateStatus()` guard on `userGestureUnlocked` is a feature, not a bug. After click, status overlay becomes permitted.

2. **"Stop polling during playback" is WRONG.** The stream can flap at any time during a wedding — streamer cuts between ceremony and reception (new `videoUID` on restart), Starlink/cellular drops, deliberate pauses to move equipment. The player cannot know the reason. So polling continues at ~10s throughout the entire event. At the user's scale (50 viewers × 10s × 6hr), this is trivially cheap with a lightweight endpoint.

3. **"Be right back" / cause-specific messaging during cuts is WRONG.** The player has no way to know whether a cut is deliberate, accidental, or terminal. The vague idle poster (logo only) is deliberately ambiguous — it works for every cause. Do not add text that claims a cause the player cannot know.

4. **The hardcoded API key in the plugin is NOT a Cloudflare key.** It is the `X-API-KEY` for `streamer1.hitchstream.com` (internal Node.js service). Blast radius is limited to that service. Still must be rotated and moved to an admin-configurable WP option — but correctly describe what it is.

5. **Webhooks are the primary source of truth, polling is the transport.** David does not want to replace polling with SSE/WebSocket/Workers at this scale. Polling is fine; webhook-primary just means the webhook writes state to the transient/flat-file, and polling reads that cached state.

## 6. Non-obvious design decisions worth preserving

From §10 of the plan, these are the hard-earned product behaviors that must survive the rewrite:

1. Pre-click silence (poster + play button only).
2. Vague idle poster during cuts (no cause-claiming text).
3. Two-poster system keyed on `hasPlayedOnce` (initial vs. idle poster).
4. Debug panel (top-right, `?debug=1`) preserved.
5. Conservative Hls.js tuning (smoothness over low-latency — correct for weddings).
6. Prebuffer gate with 20th-percentile throughput estimation (right for weak cellular).
7. Fatal-timer reset on buffer progress.
8. CORS pre-probe of manifest before `hls.loadSource`.
9. Muted-retry autoplay fallback.
10. VOD mode as separate, simpler path.
11. Iframe embed at 16:9 (never inline).
12. 10-second polling throughout the event (see misread #2 above).
13. Webhooks as source of truth, polling as transport.

## 7. Agent operational notes

### Compaction handling
If an agent gets compacted mid-work, tell them to `/continue` with this prompt:

```
Continue. Before resuming, do these three things in order:

1. Re-read HitchStream_Player_v2.md (your workstream section —
   §5 if you're Agent A, §6 if you're Agent B).

2. Read your progress file (docs/progress/agent-a.md or
   agent-b.md). The top of the file tells you which phase and
   checkbox you were on. Trust that file over your
   post-compaction memory.

3. Run `git log --oneline -10` and `git status` to see what's
   committed vs. in flight. If there are uncommitted changes,
   those are work you started but didn't finish.

Then resume. If anything is unclear, stop and ask — don't guess.
```

### Progress file discipline
Both agents update their progress file after every checkbox completion and commit it alongside the work. Commit messages follow `progress: A1.3 complete` or `A1: fix B3 isLive conflation` style.

### Gate discipline
Phases have hard gates. Agents do not advance to the next phase until every checkbox in the current phase is done AND the stated exit criterion (usually a staging smoke test) is passed.

### Branch naming
- Agent A: `a/phase-A1`, `a/phase-A2`, etc.
- Agent B: `b/phase-B0`, `b/phase-B1`, etc.
- Each phase is a separate PR.

## 8. Claude Code environment specifics

Agents run on Claude Code pointed at Ollama hosting Qwen 3.6. Ollama is configured with 256k context, but Claude Code's internal assumption for the model may be lower (no user-facing way to see or override this). This means:

- Compactions may happen earlier than Ollama's actual 256k would require.
- There is no "run through compaction silently" flag in Claude Code. Compaction always stops and needs `/continue`.
- User's mitigation is the progress-file pattern (state survives compaction) + wide permission allowlists (so routine tool calls don't stop for approval).
- `.claude/settings.json` has `autoCompactEnabled: false` discussed but not deployed — user may or may not have flipped this.

## 9. Key commits in the repo's recent history

Ordered newest first:

| Commit | Description |
|---|---|
| `ee4c98a` | Plan: CP-0 signed; five amendments folded into §6 |
| `d7ef565` | Agent B: progress — B0 code complete, awaiting PR review |
| `2d7bae6` | Agent B: B0 emergency security hotfix (webhook bypass, AJAX hardening, streamer key removal) |
| `9857303` | Agent A: B-side review findings + mock fixtures |
| `4a3c33d` | Agent A: progress — A0 complete |
| `b07a269` | Agent A: A0 preparation — mock server, function mapping, B2 repro |
| `423b9a5` | Contract: CP-0 clarifications (idle hlsUrl=null, 304 handling, errorCode pass-through, correlation-id read-only, source values) |

PR #1: https://github.com/d-cornerpin/hitchstreamplayer/pull/1 — Agent B's B0 security hotfix, awaiting human review.

## 10. What the next user interaction is likely to be

Likely possibilities, in rough order of probability:

1. **An agent reports a checkbox completion or asks a question.** The user pastes the agent's message and asks for guidance. Respond with specific technical guidance tied to the relevant plan section.
2. **User asks for copy-paste prompts** to relay to an agent. Give exact text, not guidance.
3. **User reports PR #1 deployment status.** If merged + deployed, Agent B can run B1.7 (staging test) and then finish B1. User also needs to do key rotation and webhook-secret configuration.
4. **User asks an architecture or product question.** Respond tersely. Watch for the misreads in §5 above.
5. **User asks an operational question** (Claude Code features, Ollama, GitHub, etc.). These are fine; just answer.

## 11. Conventions for responding to David

- **Keep responses concise.** Multi-page responses annoy him unless he specifically asked for depth.
- **Use copy-paste blocks for prompts-to-agents** — he literally copies and pastes them.
- **Admit misreads quickly.** Don't explain away; correct and move on.
- **Don't invent bugs.** If you're about to flag something in the current player code as broken, double-check it's not an intentional product decision first (see §5, §6).
- **Prefer editing existing files** (plan, progress files) over creating new ones, unless a new file is genuinely warranted.
- **Commit your edits to the plan.** When David approves an amendment to `HitchStream_Player_v2.md`, commit it to main with a clear message and push, so both agents see it on next pull.

## 12. Open threads as of handoff

None that are blocking. The agents have clear instructions (continue A1 / B1). User has three operational items on their plate. Nothing is waiting on me.

The next thing I am likely to do is respond to an agent's A1.1 or B1.1 report.

---

**If you are reading this as a fresh session:** you now have enough context to continue. Start by confirming to the user what you understand the current state to be, then ask what they'd like to do.
