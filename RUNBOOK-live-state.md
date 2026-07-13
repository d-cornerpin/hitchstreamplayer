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
- **The stall watchdog** in the player still hits REST directly — one rare,
  single-flight request on the drained-buffer recovery path only. Leave it.

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
| **NO client-side REST fallback on stale `ts`** | Deliberate. A stale-file fallback would re-thunder the herd onto PHP exactly when the box is struggling. The static file is truth. |

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
