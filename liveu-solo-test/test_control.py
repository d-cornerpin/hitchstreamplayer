#!/usr/bin/env python3
"""
Phase 2 — CONTROL endpoints.  *** DESTRUCTIVE — NEVER auto-run ***

These start/stop the real LiveU Solo unit or reboot it. Starting a stream pushes
to whatever destination is currently configured on the unit — that is real
production hardware for a wedding streaming business. Nothing here runs without
BOTH an explicit action flag AND the --yes-i-am-sure confirmation flag.

This script is never invoked by the automated test run. Run it by hand only.

Usage:
    python3 test_control.py --unit <boss_id> --start  --yes-i-am-sure
    python3 test_control.py --unit <boss_id> --stop   --yes-i-am-sure
    python3 test_control.py --unit <boss_id> --reboot --yes-i-am-sure

Find <boss_id> by running test_readonly.py first (it lists unit ids).
"""

import argparse
import json
import os
import sys

from env_loader import load_env, require
from auth import SoloPortalAuth, AuthError
from liveu_client import LiveUClient


def main():
    ap = argparse.ArgumentParser(
        description="LiveU Solo CONTROL endpoints (destructive).")
    ap.add_argument("--unit", required=True, metavar="BOSS_ID",
                    help="Target unit boss_id (from test_readonly.py).")
    grp = ap.add_mutually_exclusive_group(required=True)
    grp.add_argument("--start", action="store_true",
                     help="Start streaming (POST /stream, expect 201). "
                          "PUSHES TO THE CONFIGURED DESTINATION.")
    grp.add_argument("--stop", action="store_true",
                     help="Stop streaming (DELETE /stream, expect 204).")
    grp.add_argument("--reboot", action="store_true",
                     help="Reboot the unit (POST v2 /reboot, expect 204).")
    ap.add_argument("--yes-i-am-sure", action="store_true",
                    help="Required confirmation. Without it, this script only "
                         "prints what it WOULD do and exits.")
    args = ap.parse_args()

    if args.start:
        action, verb, expect = "start", "START STREAMING", 201
    elif args.stop:
        action, verb, expect = "stop", "STOP STREAMING", 204
    else:
        action, verb, expect = "reboot", "REBOOT UNIT", 204

    print()
    print(f"  Target unit : {args.unit}")
    print(f"  Action      : {verb}  (expect HTTP {expect})")

    if not args.yes_i_am_sure:
        print()
        print("  DRY RUN — no request sent. This action is destructive.")
        print("  Re-run with --yes-i-am-sure to actually perform it.")
        if action == "start":
            print("  NOTE: starting pushes to the destination configured on the "
                  "unit right now. Make sure the unit is safe to test with.")
        sys.exit(0)

    here = os.path.dirname(os.path.abspath(__file__))
    load_env(os.path.join(here, ".env"))
    email = require("LIVEU_EMAIL")
    password = require("LIVEU_PASSWORD")

    auth = SoloPortalAuth(email, password)
    try:
        auth.get_token()
    except AuthError as e:
        print(f"\n  AUTH FAILED (HTTP {e.status}): {e}")
        print("  " + (e.body or "")[:800])
        sys.exit(1)

    client = LiveUClient(auth)
    fn = {"start": client.start_stream,
          "stop": client.stop_stream,
          "reboot": client.reboot_unit}[action]

    print(f"\n  Sending {action} …")
    resp = fn(args.unit)
    ok = resp.status == expect
    print(f"  → HTTP {resp.status}  ({'OK' if ok else 'UNEXPECTED'})")
    if resp.json is not None:
        print(json.dumps(resp.json, indent=2, ensure_ascii=False))
    elif resp.raw:
        print("  raw: " + resp.raw[:800])
    sys.exit(0 if ok else 2)


if __name__ == "__main__":
    main()
