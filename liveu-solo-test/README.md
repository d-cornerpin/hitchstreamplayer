# LiveU Solo — Unofficial API Test Harness

A throwaway discovery harness that authenticates against the **unofficial** LiveU
Solo web-portal API and checks which endpoints still respond today. The API was
reverse-engineered from the Solo web portal years ago (via the NOALBS
[`liveu_stats_bot`](https://github.com/NOALBS/liveu_stats_bot), `src/liveu.rs`)
and isn't maintained — so the goal here is **discovery, not a product**: does it
still authenticate, and which endpoints still work?

This informs a possible future HitchStream stream-control dashboard. The official
API (LU-Central, `lu-central-api.liveu.tv`) is coming; the auth layer is isolated
so it can be swapped for the official flow with a one-file change.

> ⚠️ **Nothing here touches the HitchStream production server.** It talks only to
> LiveU's cloud. The control endpoints (start/stop/reboot) act on the *real*
> encoder and are gated behind explicit flags — see Phase 2.

## Files

| File | Purpose |
|------|---------|
| `auth.py` | Isolated auth providers. `SoloPortalAuth` = the unofficial login flow. `LuCentralAuth` = stub for the official API (swap-in point). |
| `liveu_client.py` | Thin client: one method per endpoint, 401→re-auth-and-retry, loose parsing. |
| `test_readonly.py` | **Phase 1.** Runs the read-only sequence, prints a pass/fail table, saves raw JSON to `samples/`. Safe. |
| `test_control.py` | **Phase 2.** start/stop/reboot, gated behind `--yes-i-am-sure`. Destructive. Never auto-runs. |
| `env_loader.py` | Tiny `.env` parser (no third-party dependency needed). |
| `samples/` | Raw response bodies saved for later schema reference. |

## Setup

Requires only Python 3 (standard library — no `pip install` needed).

```bash
cd liveu-solo-test
cp .env.example .env
# edit .env — your solo.liveu.tv email + password
```

## Phase 1 — read-only (safe to run)

```bash
python3 test_readonly.py
```

This will:
1. Authenticate. On failure it dumps the raw response body (which usually reveals
   whether the endpoint moved or the app id was revoked).
2. `GET /inventories` and list every unit with its name, `boss_id`, and status.
3. For the first unit: `GET` interfaces, battery, video status, and delay.
4. Print a summary table (endpoint → HTTP status → works/broken) and save raw
   JSON to `samples/`.

## Phase 2 — control endpoints (manual, destructive)

**Do not run these casually.** Starting a stream pushes to whatever destination
is configured on the unit right now.

Without `--yes-i-am-sure` the script does a dry run (prints what it *would* do):

```bash
python3 test_control.py --unit <boss_id> --start          # dry run
python3 test_control.py --unit <boss_id> --start --yes-i-am-sure   # for real
python3 test_control.py --unit <boss_id> --stop  --yes-i-am-sure
python3 test_control.py --unit <boss_id> --reboot --yes-i-am-sure
```

Get `<boss_id>` from `test_readonly.py`.

## Swapping in the official API later

When LU-Central credentials/spec are available, implement `get_token()` in
`LuCentralAuth` (in `auth.py`) and pass an instance of it to `LiveUClient`
instead of `SoloPortalAuth`. The client and endpoint methods don't change —
the base URLs in `liveu_client.py` are the only other thing to revisit.

## Notes / expected failure modes

- **Auth moved / app id revoked** → auth returns 4xx; the harness prints the raw
  body and stops (a token is required for everything else).
- **Response shapes drifted** → parsing is deliberately loose; the harness prints
  raw JSON rather than trusting a fixed model.
- If `solo-api.liveu.tv` is gone entirely, the harness reports it — it does **not**
  go probing for replacement endpoints.
