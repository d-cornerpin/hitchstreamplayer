#!/usr/bin/env python3
"""
One-off: create a Cloudflare "Test Stream" destination on the LiveU account and
select it on SeattleSolo. DOES NOT START STREAMING (no start_stream call exists
in this file). Run manually; it writes to the real LiveU account by design.

    python3 set_test_destination.py
"""

import json
import os
import sys

from env_loader import load_env, require
from auth import SoloPortalAuth, AuthError
from liveu_client import LiveUClient, LIVEU_API_V2, LIVEU_API_V0

# ── what we're creating / selecting ─────────────────────────────────────────
UNIT_ALIAS   = "SeattleSolo"
DEST_NAME    = "API Test - Test Stream 1080p30"
PROVIDER     = "Generic"
PROFILE      = "1920 x 1080 Widescreen (16:9) 30 fps"
INGEST_URL   = "rtmps://live.cloudflare.com:443/live/"
STREAM_KEY   = "bc176f46c46eac6578326daa2ebe9b3ckbd1f434d93902fb6c9f32316ae736ab8"

HERE = os.path.dirname(os.path.abspath(__file__))
SAMPLES = os.path.join(HERE, "samples")


def save(name, resp):
    os.makedirs(SAMPLES, exist_ok=True)
    payload = resp.json if resp.json is not None else {"_raw": resp.raw, "_status": resp.status}
    with open(os.path.join(SAMPLES, f"{name}.json"), "w", encoding="utf-8") as fh:
        json.dump(payload, fh, indent=2, ensure_ascii=False)


def read_selected(client, uid):
    """Best-effort read of the unit's currently selected destination. Not in the
    official example — we try the obvious GET on the 'selected' path (v2, then
    v0). Returns (value_or_None, status_str)."""
    r2 = client._request("GET", f"{LIVEU_API_V2}/units/{uid}/destinations/selected")
    if r2.status == 404:
        r0 = client._request("GET", f"{LIVEU_API_V0}/units/{uid}/destinations/selected")
        return r0, "v0"
    return r2, "v2"


def main():
    load_env(os.path.join(HERE, ".env"))
    email = require("LIVEU_EMAIL")
    password = require("LIVEU_PASSWORD")

    print("\n=== LiveU: create Test Stream destination + select on SeattleSolo ===")
    print("    (this DOES NOT start a stream)\n")

    auth = SoloPortalAuth(email, password)
    try:
        auth.get_token()
        print("1. Auth ................. OK")
    except AuthError as e:
        print(f"   AUTH FAILED HTTP {e.status}: {(e.body or '')[:400]}")
        sys.exit(1)
    client = LiveUClient(auth)

    # inventory dbId
    resp, _ = client.list_inventories()
    invs = LiveUClient.parse_inventory_list(resp)
    inv_id = LiveUClient.inv_db_id(invs[0]) if invs else None
    print(f"2. Inventory dbId ....... {inv_id}")

    # find SeattleSolo
    resp, _ = client.list_units(inv_id)
    units = LiveUClient.parse_unit_list(resp)
    unit = LiveUClient.find_unit_by_alias(units, UNIT_ALIAS)
    if not unit:
        print(f"   Unit {UNIT_ALIAS!r} not found. Aborting.")
        sys.exit(1)
    uid = LiveUClient.unit_uid(unit)
    print(f"3. Unit {UNIT_ALIAS} ...... uid={uid}")

    # record the BEFORE selection (so it's captured, in case you want to revert)
    r, base = read_selected(client, uid)
    save("select_before", r)
    print(f"4. Current selection .... [{base}] HTTP {r.status}  "
          f"{json.dumps(r.json) if r.json is not None else r.raw[:120]}")

    # create the destination
    print(f"\n5. Creating destination {DEST_NAME!r}")
    print(f"     provider={PROVIDER}  profile={PROFILE}")
    print(f"     url={INGEST_URL}")
    print(f"     key={STREAM_KEY[:12]}…{STREAM_KEY[-6:]}")
    resp, base = client.create_destination(
        DEST_NAME, inv_id, PROVIDER, PROFILE, STREAM_KEY, INGEST_URL, dest_type="rtmp")
    save("create_test_destination", resp)
    print(f"   → [{base}] HTTP {resp.status}")
    if resp.status not in (200, 201):
        print("   CREATE FAILED. Raw body:")
        print("   " + (json.dumps(resp.json, indent=2) if resp.json else resp.raw)[:1000])
        sys.exit(2)
    created = LiveUClient.parse_obj(resp)
    new_id = LiveUClient.dest_id(created)
    if not new_id:
        print("   No destination id returned. Body:")
        print("   " + json.dumps(created, indent=2)[:800])
        sys.exit(2)
    print(f"   ✓ created destination id = {new_id}")

    # verify it shows up
    resp, _ = client.list_destinations(inv_id)
    dests = LiveUClient.parse_destination_list(resp)
    appears = any(str(LiveUClient.dest_id(d)) == str(new_id) for d in dests)
    print(f"6. Appears in list ...... {'YES' if appears else 'NO'}")

    # select it on the unit
    print(f"\n7. Selecting destination {new_id} on {UNIT_ALIAS} …")
    resp, base = client.set_unit_destination(uid, new_id)
    save("set_unit_destination", resp)
    print(f"   → [{base}] HTTP {resp.status}  "
          f"({'OK' if resp.status in (200, 204) else 'UNEXPECTED'})")
    if resp.status not in (200, 204):
        print("   SELECT FAILED. Raw body:")
        print("   " + (json.dumps(resp.json, indent=2) if resp.json else resp.raw)[:1000])
        sys.exit(3)

    # verify the AFTER selection
    r, base = read_selected(client, uid)
    save("select_after", r)
    print(f"8. Selection now ........ [{base}] HTTP {r.status}  "
          f"{json.dumps(r.json) if r.json is not None else r.raw[:120]}")

    print(f"\n✓ DONE. {UNIT_ALIAS} is now pointed at destination {new_id} "
          f"({DEST_NAME}).")
    print("  No stream was started. Verify in the Solo portal if you like.\n")


if __name__ == "__main__":
    main()
