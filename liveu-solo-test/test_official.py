#!/usr/bin/env python3
"""
Official-example endpoints, tested against the PORTAL auth we already have.

The premise (from liveuinc/solo_api_examples): the official /rest-v2/v2 gateway
and the portal host lu-central.liveu.tv/luc/luc-core-web/rest/... are the same
backend. This script reuses our unofficial portal bearer token and probes the
official destination/provider/zone endpoints on the portal host's v2 path
(falling back to v0 on a 404), to find out — before official API creds arrive —
whether our token can drive the destination workflow that a HitchStream
"stream to Cloudflare" button would need.

Phases:
  Phase 1  read-only ...... runs automatically. Safe.
  Phase 2  --test-create ... creates a scratch RTMP destination, then tries to
                             delete it. Writes to your LiveU account.
  Phase 3  --test-select --yes-i-am-sure ... records the unit's current
                             destination, switches it to the scratch one, then
                             switches it BACK. Mutates unit config. Never starts
                             a stream. Requires --test-create in the same run.

Usage:
  python3 test_official.py                         # Phase 1 only
  python3 test_official.py --test-create           # Phases 1 + 2
  python3 test_official.py --test-create --test-select --yes-i-am-sure
  # optional overrides for Phase 2 provider/profile selection:
  #   --provider-name "<name>"  --profile "<profile>"
"""

import argparse
import json
import os
import sys

from env_loader import load_env, require
from auth import SoloPortalAuth, AuthError
from liveu_client import LiveUClient

HERE = os.path.dirname(os.path.abspath(__file__))
SAMPLES_DIR = os.path.join(HERE, "samples")

# scratch destination — deliberately obvious + a fake key (never a real one)
SCRATCH_NAME = "API-TEST-DELETE-ME"
SCRATCH_URL = "rtmp://a.rtmp.youtube.com/live2"
SCRATCH_KEY = "test-key-ignore"

# ── terminal helpers ────────────────────────────────────────────────────────
_C = sys.stdout.isatty()
def _c(code, s): return f"\033[{code}m{s}\033[0m" if _C else s
def green(s): return _c("32", s)
def red(s): return _c("31", s)
def yellow(s): return _c("33", s)
def bold(s): return _c("1", s)
def dim(s): return _c("2", s)
def hr(ch="─", n=74): print(dim(ch * n))

# summary rows: (label, base_used, status, verdict)
ROWS = []


def record(label, resp, base, expect=None):
    """Print one endpoint result, save its raw body, append a summary row."""
    if expect is None:
        ok = resp.ok
    else:
        ok = resp.status in expect
    if resp.status == 0:
        verdict, tag = "no-connect", red("✗ no connect")
    elif resp.status == 403:
        verdict, tag = "forbidden", red("✗ 403 forbidden")
    elif ok:
        verdict, tag = "works", green("✓ works")
    else:
        verdict, tag = "broken", red("✗ broken")
    print(f"  {bold(label):<40} [{base}] HTTP {resp.status}  {tag}")
    if resp.json is not None:
        body = json.dumps(resp.json, indent=2, ensure_ascii=False)
        preview = body if len(body) <= 1600 else body[:1600] + "\n      …(truncated; full body saved to samples/)"
        print("\n".join("      " + ln for ln in preview.splitlines()))
    elif resp.raw:
        print(dim("      raw: " + resp.raw[:600]))
    print()
    ROWS.append((label, base, resp.status, verdict))
    return ok


def save(name, resp):
    os.makedirs(SAMPLES_DIR, exist_ok=True)
    payload = resp.json if resp.json is not None else {
        "_non_json_raw": resp.raw, "_status": resp.status}
    with open(os.path.join(SAMPLES_DIR, f"{name}.json"), "w", encoding="utf-8") as fh:
        json.dump(payload, fh, indent=2, ensure_ascii=False)


def summary():
    hr("═")
    print(bold("SUMMARY  (endpoint → base → status → verdict)"))
    hr()
    for label, base, status, verdict in ROWS:
        mark = {"works": green("✓ works"), "broken": red("✗ broken"),
                "forbidden": red("✗ 403"), "no-connect": red("✗ no connect"),
                }.get(verdict, verdict)
        print(f"  {label:<40} {base:<3} {str(status):>4}   {mark}")
    hr("═")
    works = sum(1 for *_, v in ROWS if v == "works")
    print(f"  {works}/{len(ROWS)} calls responding as of this run.")
    if any(v == "forbidden" for *_, v in ROWS):
        print(yellow("  NOTE: 403(s) seen — the portal application-id likely lacks "
                     "gateway permission for that path. Fallback: capture the "
                     "portal's own request in Chrome DevTools."))
    print(dim(f"  Raw samples: {SAMPLES_DIR}"))
    print()


# ════════════════════════════════════════════════════════════════════════════

def main():
    ap = argparse.ArgumentParser(description="LiveU official-endpoint probe (portal auth).")
    ap.add_argument("--test-create", action="store_true",
                    help="Phase 2: create + delete a scratch RTMP destination.")
    ap.add_argument("--test-select", action="store_true",
                    help="Phase 3: switch the unit to the scratch destination and back.")
    ap.add_argument("--yes-i-am-sure", action="store_true",
                    help="Required with --test-select (mutates unit config).")
    ap.add_argument("--provider-name", help="Override Phase 2 streaming provider name.")
    ap.add_argument("--profile", help="Override Phase 2 streaming profile.")
    ap.add_argument("--unit", help="Alias of the unit to probe for per-unit reads "
                                   "(status/zones). Defaults to the first unit.")
    args = ap.parse_args()

    load_env(os.path.join(HERE, ".env"))
    email = require("LIVEU_EMAIL")
    password = require("LIVEU_PASSWORD")

    print()
    print(bold("LiveU official endpoints — probed with unofficial portal auth"))
    print(dim("reference: liveuinc/solo_api_examples · host: lu-central.liveu.tv (v2, v0 fallback)"))
    hr()

    # ── auth ────────────────────────────────────────────────────────────────
    print(bold("Authenticate (portal flow)"))
    auth = SoloPortalAuth(email, password)
    try:
        token = auth.get_token()
        print(f"  {green('AUTH OK')}  token {token[:12]}… ({len(token)} chars)\n")
    except AuthError as e:
        print(f"  {red('AUTH FAILED')}  HTTP {e.status}\n  {dim((e.body or '')[:800])}")
        sys.exit(1)
    client = LiveUClient(auth)

    ctx = phase1_readonly(client, prefer_alias=args.unit)

    if args.test_create:
        phase2_create(client, ctx, args)
        if args.test_select:
            phase3_select(client, ctx, args)
    elif args.test_select:
        print(yellow("  --test-select needs --test-create in the same run "
                     "(it uses the scratch destination). Skipping Phase 3.\n"))

    summary()


def phase1_readonly(client, prefer_alias=None):
    """Runs the safe read-only sequence and returns a context dict with the
    ids later phases need (inventory_db_id, chosen unit, providers …).
    prefer_alias selects which unit the per-unit reads target (else first)."""
    ctx = {"inv_db_id": None, "unit": None, "units": [], "providers": [],
           "profiles": [], "destinations": []}

    print(bold("PHASE 1 — read-only"))
    hr()

    # 1. inventories → dbId
    resp, base = client.list_inventories()
    record("GET /inventories", resp, base); save("v2_inventories", resp)
    invs = LiveUClient.parse_inventory_list(resp)
    if invs:
        ctx["inv_db_id"] = LiveUClient.inv_db_id(invs[0])
        print(dim(f"  → inventory dbId = {ctx['inv_db_id']}\n"))
    if not ctx["inv_db_id"]:
        print(yellow("  No inventory dbId — cannot probe per-inventory endpoints. "
                     "Stopping Phase 1 early.\n"))
        return ctx
    inv_id = ctx["inv_db_id"]

    # 2. units + find_unit_by_alias
    resp, base = client.list_units(inv_id)
    record("GET /inventories/{id}/units", resp, base); save("v2_units", resp)
    units = LiveUClient.parse_unit_list(resp)
    ctx["units"] = units
    if units:
        print(bold(f"  {len(units)} unit(s):"))
        for u in units:
            print(f"   • alias={LiveUClient.unit_alias(u)!r}  uid={LiveUClient.unit_uid(u)}")
        # Choose the unit for per-unit reads: prefer_alias if given & found.
        chosen = LiveUClient.find_unit_by_alias(units, prefer_alias) if prefer_alias else None
        if prefer_alias and chosen is None:
            print(yellow(f"  (--unit {prefer_alias!r} not found; using first unit)"))
        ctx["unit"] = chosen or units[0]
        # Verify find_unit_by_alias round-trips on the chosen unit's own alias.
        alias0 = LiveUClient.unit_alias(ctx["unit"])
        found = LiveUClient.find_unit_by_alias(units, alias0)
        ok = found is not None and LiveUClient.unit_uid(found) == LiveUClient.unit_uid(ctx["unit"])
        print(f"  probing unit: {alias0!r}   find_unit_by_alias({alias0!r}) → "
              f"{green('OK') if ok else red('MISMATCH')}\n")

    # 3. unit status (v2 /units/{uid}/status)
    if ctx["unit"]:
        uid = LiveUClient.unit_uid(ctx["unit"])
        resp, base = client.get_unit_status(uid)
        record("GET /units/{uid}/status", resp, base); save("v2_unit_status", resp)

    # 4. stream providers
    resp, base = client.list_stream_providers()
    record("GET /streamProviders", resp, base); save("v2_stream_providers", resp)
    providers = LiveUClient.parse_provider_list(resp)
    ctx["providers"] = providers
    if providers:
        print(bold(f"  {len(providers)} provider(s):"))
        for p in providers[:20]:
            pid = p.get("dbId") or p.get("id")
            print(f"   • name={p.get('name')!r}  id={pid}")
        print()

    # 5. provider profiles (first provider)
    if providers:
        pid = providers[0].get("dbId") or providers[0].get("id")
        resp, base = client.list_provider_profiles(pid)
        record(f"GET /streamProviders/{pid}/…/profiles", resp, base)
        save("v2_provider_profiles", resp)

    # 6. destinations (+ detail on first)
    resp, base = client.list_destinations(inv_id)
    record("GET /inventories/{id}/destinations", resp, base)
    save("v2_destinations", resp)
    dests = LiveUClient.parse_destination_list(resp)
    ctx["destinations"] = dests
    if dests:
        did = LiveUClient.dest_id(dests[0])
        resp, base = client.get_destination_detail(inv_id, did)
        record(f"GET destinations/{did} (detail)", resp, base)
        save("v2_destination_detail", resp)
    else:
        print(dim("  (no existing destinations to detail)\n"))

    # 7. zones
    if ctx["unit"]:
        uid = LiveUClient.unit_uid(ctx["unit"])
        resp, base = client.list_zones(uid)
        record("GET /units/{uid}/selectableChannelsLight", resp, base)
        save("v2_zones", resp)

    return ctx


def _pick_provider_profile(client, ctx, args):
    """Resolve (provider_name, profile) for Phase 2 — from CLI flags, else
    auto-pick an RTMP-ish provider and its first profile."""
    provider_name = args.provider_name
    profile = args.profile
    providers = ctx["providers"]
    if not provider_name:
        # prefer a provider whose name mentions rtmp/custom/other; else first.
        pick = None
        for p in providers:
            nm = str(p.get("name") or "").lower()
            if any(w in nm for w in ("rtmp", "custom", "other", "generic")):
                pick = p; break
        pick = pick or (providers[0] if providers else None)
        if not pick:
            return None, None, None
        provider_name = pick.get("name")
        pid = pick.get("dbId") or pick.get("id")
    else:
        pid = None
        for p in providers:
            if p.get("name") == provider_name:
                pid = p.get("dbId") or p.get("id"); break
    if not profile:
        resp, _ = client.list_provider_profiles(pid)
        profs = resp.json
        # profiles may be a bare list of strings/objs or wrapped
        names = []
        if isinstance(profs, list):
            names = [p if isinstance(p, str) else (p.get("name") or p.get("profile")) for p in profs]
        elif isinstance(profs, dict):
            inner = profs.get("data", profs)
            if isinstance(inner, list):
                names = [p if isinstance(p, str) else (p.get("name") or p.get("profile")) for p in inner]
        profile = next((n for n in names if n), None)
    return provider_name, profile, pid


def phase2_create(client, ctx, args):
    print(bold("PHASE 2 — create + delete a scratch RTMP destination"))
    hr()
    if not ctx["inv_db_id"]:
        print(red("  No inventory dbId from Phase 1 — cannot create. Skipping.\n"))
        return
    provider_name, profile, _ = _pick_provider_profile(client, ctx, args)
    print(dim(f"  provider={provider_name!r}  profile={profile!r}  "
              f"url={SCRATCH_URL}  key={SCRATCH_KEY!r}"))
    if not provider_name or not profile:
        print(red("  Could not resolve a provider/profile (Phase 1 returned none). "
                  "Re-run with --provider-name and --profile. Skipping create.\n"))
        return

    resp, base = client.create_destination(
        SCRATCH_NAME, ctx["inv_db_id"], provider_name, profile,
        SCRATCH_KEY, SCRATCH_URL, dest_type="rtmp")
    record("POST /destinations (create)", resp, base, expect={200, 201})
    save("v2_create_destination", resp)
    created = LiveUClient.parse_obj(resp)
    new_id = LiveUClient.dest_id(created)
    if not new_id:
        print(red("  No destination id returned — cannot verify or clean up. "
                  "Check the raw body above.\n"))
        return
    print(green(f"  created destination id = {new_id}\n"))
    ctx["scratch_dest_id"] = new_id

    # verify it appears + detail
    resp, base = client.list_destinations(ctx["inv_db_id"])
    dests = LiveUClient.parse_destination_list(resp)
    appears = any(str(LiveUClient.dest_id(d)) == str(new_id) for d in dests)
    print(f"  appears in list_destinations: "
          f"{green('YES') if appears else red('NO')}")
    resp, base = client.get_destination_detail(ctx["inv_db_id"], new_id)
    record(f"GET destinations/{new_id} (verify)", resp, base)
    save("v2_scratch_detail", resp)

    # Phase 3 will restore, so only delete now if we're NOT selecting it.
    if args.test_select:
        print(dim("  (leaving scratch destination in place for Phase 3; "
                  "cleanup happens after)\n"))
        return
    _cleanup_scratch(client, ctx, new_id)


def _cleanup_scratch(client, ctx, new_id):
    print(bold("  cleanup — try to delete the scratch destination"))
    resp, base = client.delete_destination(new_id)
    ok = record(f"DELETE /destinations/{new_id}", resp, base, expect={200, 204})
    if not ok:
        resp, base = client.delete_inventory_destination(ctx["inv_db_id"], new_id)
        ok = record(f"DELETE /inventories/{{id}}/destinations/{new_id}",
                    resp, base, expect={200, 204})
    if ok:
        print(green("  scratch destination deleted.\n"))
    else:
        print(yellow(f"  Could not delete via either inferred path. "
                     f"Please delete destination {new_id} ('{SCRATCH_NAME}') "
                     f"in the Solo portal manually.\n"))


def phase3_select(client, ctx, args):
    print(bold("PHASE 3 — switch unit destination to scratch, then restore"))
    hr()
    if not args.yes_i_am_sure:
        print(yellow("  --test-select requires --yes-i-am-sure (this mutates unit "
                     "config). Dry run — no change made.\n"))
        _cleanup_scratch(client, ctx, ctx.get("scratch_dest_id"))
        return
    unit = ctx.get("unit")
    scratch = ctx.get("scratch_dest_id")
    if not unit or not scratch:
        print(red("  Missing unit or scratch destination — skipping.\n"))
        return
    uid = LiveUClient.unit_uid(unit)

    # Record the current selection FIRST — if we can't identify it, we must not
    # change anything (we'd have nothing to restore to).
    resp, base = client.get_unit_status(uid)
    save("v2_unit_status_before_select", resp)
    original = _find_selected_destination(resp.json)
    print(dim(f"  current selected destination (best-effort) = {original}"))
    if original is None:
        print(red("  Could not identify the unit's current destination from its "
                  "status. Refusing to switch (nothing to restore to). "
                  "Skipping Phase 3.\n"))
        _cleanup_scratch(client, ctx, scratch)
        return

    # switch → scratch
    resp, base = client.set_unit_destination(uid, scratch)
    record(f"PUT /units/{uid}/destinations/selected → {scratch}",
           resp, base, expect={200, 204})
    # restore → original
    resp, base = client.set_unit_destination(uid, original)
    record(f"PUT …/destinations/selected → {original} (restore)",
           resp, base, expect={200, 204})
    print(green(f"  round trip done — unit restored to destination {original}.\n"))
    _cleanup_scratch(client, ctx, scratch)


def _find_selected_destination(status_json):
    """Best-effort: walk the unit-status JSON for a currently-selected
    destination id. Returns an int or None. Kept loose — shape is unknown."""
    found = []

    def walk(node, parent_key=""):
        if isinstance(node, dict):
            for k, v in node.items():
                kl = k.lower()
                if "destination" in kl and isinstance(v, int):
                    found.append(v)
                elif "destination" in kl and isinstance(v, dict):
                    inner = v.get("id") or v.get("destination") or v.get("selected")
                    if isinstance(inner, int):
                        found.append(inner)
                walk(v, kl)
        elif isinstance(node, list):
            for item in node:
                walk(item, parent_key)

    walk(status_json)
    return found[0] if found else None


if __name__ == "__main__":
    main()
