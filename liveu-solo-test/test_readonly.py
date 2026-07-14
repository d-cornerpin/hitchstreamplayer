#!/usr/bin/env python3
"""
Phase 1 — READ-ONLY discovery run.

Authenticates against the unofficial LiveU Solo portal API and probes every
read-only endpoint, printing HTTP status + pretty raw JSON for each. Saves raw
responses to samples/ for later schema reference. Prints a final pass/fail table.

Runs NOTHING destructive. Control endpoints (start/stop/reboot) live in
test_control.py and are gated behind explicit flags.

Usage:
    python3 test_readonly.py
"""

import json
import os
import sys

from env_loader import load_env, require
from auth import SoloPortalAuth, AuthError
from liveu_client import LiveUClient

SAMPLES_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "samples")

# ── tiny terminal helpers ──────────────────────────────────────────────────
_C = sys.stdout.isatty()
def _c(code, s): return f"\033[{code}m{s}\033[0m" if _C else s
def green(s): return _c("32", s)
def red(s): return _c("31", s)
def yellow(s): return _c("33", s)
def bold(s): return _c("1", s)
def dim(s): return _c("2", s)


def hr(char="─", n=72):
    print(dim(char * n))


def save_sample(name, response):
    """Persist a response body to samples/ as JSON (or raw text if it wasn't
    JSON) so we have real shapes to design against later."""
    os.makedirs(SAMPLES_DIR, exist_ok=True)
    path = os.path.join(SAMPLES_DIR, f"{name}.json")
    try:
        if response.json is not None:
            payload = response.json
        else:
            payload = {"_non_json_raw": response.raw, "_status": response.status}
        with open(path, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, indent=2, ensure_ascii=False)
    except Exception as e:  # noqa: BLE001 — best-effort, never fail the run
        print(dim(f"    (could not save sample {name}: {e})"))


def show(label, response, expect=None):
    """Print one endpoint result and return a (label, status, verdict) row."""
    ok = response.ok if expect is None else (response.status in expect)
    tag = green("WORKS") if ok else red("BROKEN")
    extra = ""
    if response.status == 204:
        tag = green("WORKS")
        extra = dim("  (204 No Content)")
    if response.status == 0:
        tag = red("NO CONNECT")
    print(f"  {bold(label):<34} HTTP {response.status}  [{tag}]{extra}")
    if response.json is not None:
        body = json.dumps(response.json, indent=2, ensure_ascii=False)
        # indent the pretty JSON under the row
        print("\n".join("      " + ln for ln in body.splitlines()))
    elif response.raw:
        print(dim("      raw: " + response.raw[:800]))
    print()
    verdict = "works" if ok else ("no-connect" if response.status == 0 else "broken")
    return (label, response.status, verdict)


def main():
    load_env(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env"))
    email = require("LIVEU_EMAIL")
    password = require("LIVEU_PASSWORD")

    rows = []

    print()
    print(bold("LiveU Solo — unofficial API read-only discovery"))
    print(dim("reference: NOALBS liveu_stats_bot / src/liveu.rs"))
    hr()

    # ── Step 1: authenticate ────────────────────────────────────────────────
    print(bold("1. Authenticate"))
    auth = SoloPortalAuth(email, password)
    try:
        token = auth.get_token()
        print(f"  {green('AUTH OK')}  HTTP {auth.last_status}  "
              f"token: {token[:12]}… ({len(token)} chars)")
        rows.append(("POST zendesk/userlogin", auth.last_status, "works"))
    except AuthError as e:
        print(f"  {red('AUTH FAILED')}  HTTP {e.status}")
        print(dim("  This usually means the endpoint moved or the app id was "
                  "revoked. Raw response body below:"))
        print(dim("  " + (e.body or "(empty)")[:1200]))
        rows.append(("POST zendesk/userlogin", e.status, "broken"))
        print()
        print_summary(rows)
        # Save whatever the login returned for reference, then stop — nothing
        # else can be probed without a token.
        os.makedirs(SAMPLES_DIR, exist_ok=True)
        with open(os.path.join(SAMPLES_DIR, "auth_error.json"), "w",
                  encoding="utf-8") as fh:
            json.dump({"status": e.status, "body": e.body}, fh, indent=2)
        sys.exit(1)
    print()

    client = LiveUClient(auth)

    # ── Step 2: inventories ─────────────────────────────────────────────────
    print(bold("2. GET /inventories"))
    inv = client.get_inventories()
    rows.append(show("GET /inventories", inv))
    save_sample("inventories", inv)

    units = LiveUClient.parse_units(inv)
    if not units:
        print(yellow("  No units parsed from inventories — cannot probe "
                     "per-unit endpoints. Stopping after inventories."))
        print_summary(rows)
        return

    print(bold(f"  Found {len(units)} unit(s):"))
    for u in units:
        print("   • name={name!r}  id={id}  status={status}  reg={reg}".format(
            name=u.get("name"), id=u.get("id"),
            status=u.get("status"), reg=u.get("reg_code")))
    print()

    # ── Step 3: per-unit read-only endpoints (first unit) ───────────────────
    unit = units[0]
    boss_id = unit.get("id")
    print(bold(f"3. Per-unit status for first unit "
               f"({unit.get('name')!r}, id={boss_id})"))
    hr()

    probes = [
        ("GET interfaces", client.get_interfaces, "interfaces", {200, 204}),
        ("GET battery",    client.get_battery,    "battery",    None),
        ("GET video",      client.get_video,      "video",      None),
        ("GET delay",      client.get_delay,      "delay",      None),
    ]
    for label, fn, sample_name, expect in probes:
        resp = fn(boss_id)
        rows.append(show(label, resp, expect=expect))
        save_sample(sample_name, resp)

    # ── Step 4: summary table ───────────────────────────────────────────────
    print_summary(rows)
    print(dim(f"Raw response samples written to: {SAMPLES_DIR}"))


def print_summary(rows):
    hr("═")
    print(bold("SUMMARY  (endpoint → status → verdict)"))
    hr()
    for label, status, verdict in rows:
        mark = {"works": green("✓ works"),
                "broken": red("✗ broken"),
                "no-connect": red("✗ no connect")}.get(verdict, verdict)
        status_s = str(status) if status is not None else "—"
        print(f"  {label:<32} {status_s:>5}   {mark}")
    hr("═")
    works = sum(1 for _, _, v in rows if v == "works")
    print(f"  {works}/{len(rows)} endpoints responding as of this run.")
    print()


if __name__ == "__main__":
    main()
