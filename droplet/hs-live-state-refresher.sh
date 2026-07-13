#!/usr/bin/env bash
#
# hs-live-state-refresher — keeps wp-content/hs-state/*.json fresh.
#
# Viewers poll those static JSON files directly (zero PHP), so ONE process —
# this one — hits WordPress on their behalf: every ~10s it curls the live-state
# REST endpoint once per known input. Total WP load: ~1 request per input per
# 10s, independent of audience size.
#
# ── DO NOT "OPTIMIZE" THIS TO READ THE STATIC FILE ────────────────────────────
# It MUST curl the REST endpoint, never the flat file, because the REST handler
# is load-bearing for three things (see RUNBOOK-live-state.md):
#   1. Freshness backstop: REST re-probes Cloudflare /lifecycle when its cache
#      is stale and REWRITES the flat file — that write is the whole point.
#   2. The error-alert tick: the REST handler runs hs_check_error_pending(),
#      which drives the debounced "Live Stream Error" / "Recovered" emails.
#      Without these curls, mid-stream recovery detection degrades to wp-cron.
#   3. wp-cron liveness: with viewers off PHP, these curls are the steady
#      traffic that gives WordPress regular chances to fire scheduled events.
# ──────────────────────────────────────────────────────────────────────────────
#
# "Active inputs" = every {id}.json already in hs-state/ (files are created by
# the first webhook/probe for an input). To retire an old input, delete its
# .json — this loop stops refreshing it. With a handful of inputs this is
# ~0.3 req/s of PHP, total.

set -u

STATE_DIR="/home/admin_hitchstream/public_html/wp-content/hs-state"
ENDPOINT="https://hitchstream.com/wp-json/hitchstream/v1/live-state"
INTERVAL_SECONDS=10

while true; do
  for f in "$STATE_DIR"/*.json; do
    [ -e "$f" ] || continue                          # empty dir → glob is literal
    id="$(basename "$f" .json)"
    case "$id" in (*[!A-Za-z0-9_-]*|"") continue ;; esac   # ids are [A-Za-z0-9_-] only
    curl -fsS -m 8 -o /dev/null "$ENDPOINT?inputId=$id" \
      || logger -t hs-refresher "live-state curl failed for input $id"
  done
  sleep "$INTERVAL_SECONDS"
done
