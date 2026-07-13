# Runbook — live-state polling (static-file architecture)

*Last updated: 2026-07-13 (polling-load brief, tasks 1–3).*

## How live-state flows now

```
                        ┌─ Cloudflare webhook (instant, on transitions) ─┐
                        │   cf-live-webhook.php → StateWriter            │
                        ▼                                                │
   wp-content/hs-state/{inputId}.json   ◄── REST probe rewrite ──┐       │
        (static JSON, atomic writes)                             │       │
                        ▲                                        │       │
     VIEWERS poll this  │ every 10s — Apache static,     DROPLET REFRESHER
     file directly      │ ZERO PHP, any audience size    curls REST every ~10s
                        │                                per input (~0.3 req/s
                        │                                of PHP, total)
```

- **Viewers** fetch `/wp-content/hs-state/{inputId}.json` every 10s
  (`LivePoller.js` `fileEndpoint`). Apache serves it statically — **viewer
  count does not scale PHP load.** 404 = "no state yet" = idle (not an error).
- **Webhooks** (Cloudflare Notifications → `cf-live-webhook.php`) write the
  file **instantly on transitions** (connected/disconnected/errored).
- **The refresher** (below) curls the REST endpoint
  `/wp-json/hitchstream/v1/live-state?inputId={id}` every ~10s per input;
  when REST's cache is stale (>12s) it re-probes Cloudflare and rewrites the
  file. This is the freshness heartbeat / missed-webhook backstop.
- **The stall watchdog** in the player verifies "still live?" before
  rebuilding, HERD-SAFELY: it reads the static file first (zero PHP, safe for
  the whole audience at once) and only escalates to REST — after 0–8s random
  jitter — when the file is missing/stale, or claims "live" with a `ts` older
  than 12s (a stale "live" is the one verdict that could replay the tail, so
  it always gets ground-truthed). A common-mode encoder drop therefore costs
  ~zero PHP, not one REST hit per viewer.

## The refresher is load-bearing for THREE things

1. **Flat-file freshness backstop.** Webhooks cover transitions, but delivery
   isn't guaranteed. The refresher's REST curls make the endpoint re-probe
   Cloudflare and rewrite the file when it's >12s stale.
2. **The error-alert tick.** The REST handler runs `hs_check_error_pending()`
   — the debounced **Live Stream Error / Recovered** emails depend on
   something hitting REST regularly. Viewers no longer do; the refresher does.
3. **wp-cron liveness.** wp-cron only fires on PHP requests. With viewers off
   PHP, the refresher's curls are the steady traffic that gives WordPress
   regular chances to run scheduled events (alert re-checks, transient
   cleanup, etc.). Other site traffic helps, but don't rely on it mid-event.

**Therefore: the refresher must curl REST, never read the static file.** This
is a hard requirement; it's also commented at the top of the script.

## Degraded modes (decided, by design)

| Failure | What happens |
|---|---|
| Refresher dead | Transitions **still flow** (webhooks write the file directly). Only probe-detected freshness stops. Alert emails degrade to wp-cron timing. |
| Missed webhook + refresher dead | The file goes stale — viewers keep believing it. Mid-play viewers self-correct: the stall watchdog's REST check refuses to rebuild a stopped stream (drain to logo). Idle viewers wait until either recovers. |
| Static file deleted / never written | Viewers poll a 404 → treated as idle, normal cadence (no backoff). First webhook/probe recreates it (and `.htaccess` self-heals via StateWriter). |
| **NEW-INPUT GAP** — brand-new Live Input, file never written | ⚠️ The refresher only iterates files that already exist, and only the first webhook/probe creates one. Missed first webhook + no file = viewers 404-as-idle forever with **no probe backstop**. **This is why the priming curl in the event-day checklist below is MANDATORY for every new Live Input.** |
| **NO client-side REST fallback on stale `ts`** (polling path) | Deliberate. A stale-file fallback in the 10s poll loop would re-thunder the herd onto PHP exactly when the box is struggling. The static file is truth for polling. (The stall watchdog's rare, jittered, stale-gated REST escalation is the one sanctioned exception — see above.) |

## Event-day checklist (before guests arrive)

For **every** Live Input that will stream today — especially newly created
ones:

- [ ] **Prime the input's state file (MANDATORY for new inputs):**
  ```bash
  curl -s "https://hitchstream.com/wp-json/hitchstream/v1/live-state?inputId=<INPUT_ID>" >/dev/null
  ```
  This creates `hs-state/<INPUT_ID>.json`, which (a) enrolls the input in the
  refresher's loop and (b) restores the probe backstop. Without it, a new
  input whose first webhook is missed shows viewers "idle" forever.
- [ ] **Confirm the file exists and is fresh:**
  ```bash
  curl -s "https://hitchstream.com/wp-content/hs-state/<INPUT_ID>.json"   # expect JSON, not 404
  ```
- [ ] **Confirm the refresher is running:**
  `systemctl is-active hs-live-state-refresher` → `active`.
- [ ] **Confirm revalidation headers:**
  ```bash
  curl -sI "https://hitchstream.com/wp-content/hs-state/<INPUT_ID>.json" | grep -i cache-control
  ```
  Expect `Cache-Control: no-cache`. (The player also sends `cache: 'no-store'`
  client-side, so a missing header is degraded-but-safe — fix it per the
  deploy sequence's STOP gate when convenient, don't panic mid-event.)
- [ ] Optional: `?debug=1` smoke — start/stop the placeholder stream, watch
  idle → live → drain-to-logo.

## Install on the droplet (by hand, over SSH)

Copy the two files from this repo's `droplet/` dir, then:

```bash
# 1. the script
sudo cp hs-live-state-refresher.sh /usr/local/bin/hs-live-state-refresher.sh
sudo chmod 755 /usr/local/bin/hs-live-state-refresher.sh

# 2. the unit
sudo cp hs-live-state-refresher.service /etc/systemd/system/hs-live-state-refresher.service
sudo systemctl daemon-reload
sudo systemctl enable --now hs-live-state-refresher

# 3. verify
systemctl status hs-live-state-refresher --no-pager
journalctl -u hs-live-state-refresher -n 20 --no-pager   # curl failures log here
```

Stop/start: `sudo systemctl stop|start hs-live-state-refresher`.
Logs on failure only (silent when healthy).

## First-deploy sequence (with STOP gate)

1. `./deploy.sh` — ships theme + plugin. Safe before the refresher exists
   (webhooks write transitions; REST stays intact).
2. Prime one input's file (creates `hs-state/` + `.htaccess`):
   ```bash
   curl -s "https://hitchstream.com/wp-json/hitchstream/v1/live-state?inputId=<INPUT_ID>" >/dev/null
   ```
3. **STOP GATE — verify the cache header:**
   ```bash
   curl -sI "https://hitchstream.com/wp-content/hs-state/<INPUT_ID>.json" | grep -i cache-control
   ```
   - Expect `Cache-Control: no-cache`.
   - **Empty result = STOP.** The `.htaccess` is being ignored (most likely
     `AllowOverride None` on wp-content) or mod_headers is off. Fix at the
     vhost level, then re-run the grep before continuing:
     ```apache
     # in the hitchstream.com vhost (Virtualmin: Services → Configure Website
     # → Edit Directives), then: apachectl configtest && systemctl reload apache2
     <Directory /home/admin_hitchstream/public_html/wp-content/hs-state>
         Header set Cache-Control "no-cache"
     </Directory>
     ```
   - Context: the player also sends `cache: 'no-store'` on every poll fetch,
     so correctness no longer depends on this header — but it's required
     defense-in-depth (and protects any non-fetch consumer), so the gate
     stays. Re-verify after the hosting migration.
4. Install + start the refresher (previous section).
5. `?debug=1` smoke test with the placeholder stream: idle → live within one
   poll, stop → drain to logo, no replay.
6. During the next real stream: `watch 'ps -C php-cgi --no-headers | wc -l'`
   stays flat while viewers join.

## Ops notes

- **Active inputs** = every `{id}.json` in
  `/home/admin_hitchstream/public_html/wp-content/hs-state/`. Files appear on
  an input's first webhook/probe. **To retire an input, delete its `.json`.**
- **Freshness envelope:** file `ts` oscillates 0–~22s old (10s refresher
  cadence vs REST's 12s freshness window). The player JS never reads `ts`;
  content only changes on real transitions, which webhooks push instantly.
- **Don't chase 304s:** StateWriter's atomic tmp+rename gives the file a new
  inode/mtime every rewrite, so ETags churn and most polls are small 200s.
  That's fine — the success metric is **php-cgi count staying flat**, not 304s.
- **Load check during an event:**
  `watch 'ps -C php-cgi --no-headers | wc -l'` on the droplet — should stay
  flat regardless of viewers. Static-file polls appear in Apache's access log
  as `/wp-content/hs-state/*.json` with no php-cgi spawn.
- **After the hosting migration:** update `STATE_DIR`/`ENDPOINT` paths in
  `/usr/local/bin/hs-live-state-refresher.sh` if the docroot or domain
  changes, and reinstall the unit on the new box.
