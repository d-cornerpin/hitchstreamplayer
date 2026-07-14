"""
Minimal LiveU Solo API client (unofficial portal API).

Reference: NOALBS liveu_stats_bot, src/liveu.rs. This is a throwaway discovery
harness — parsing is intentionally loose. Every request method returns a small
Response wrapper (status + parsed json + raw text) so the test scripts can print
exact status codes and raw shapes rather than trusting a model.

Auth is injected (see auth.py) so the official LU-Central flow can be swapped in
later without touching this file.
"""

import json
from urllib import request as urlrequest
from urllib import error as urlerror

APPLICATION_ID = "SlZ3SHqiqtYJRkF0zO"
# Portal host base URLs. v0 is what the old NOALBS bot used; v2 is where the
# official example's destination/provider endpoints live (the official code
# proves the portal host serves the v2 routes — see get_destination_detail).
LIVEU_API_V0 = "https://lu-central.liveu.tv/luc/luc-core-web/rest/v0"
LIVEU_API_V2 = "https://lu-central.liveu.tv/luc/luc-core-web/rest/v2"
LIVEU_API = LIVEU_API_V0  # backwards-compat alias for the original v0 methods


class Response:
    """Thin, honest wrapper around an HTTP reply. `json` is None if the body
    wasn't JSON (e.g. an empty 204, or an HTML error page)."""

    def __init__(self, status, raw, url, method):
        self.status = status
        self.raw = raw
        self.url = url
        self.method = method
        try:
            self.json = json.loads(raw) if raw else None
        except json.JSONDecodeError:
            self.json = None

    @property
    def ok(self):
        return 200 <= self.status < 300


class LiveUClient:
    def __init__(self, auth, timeout=15):
        # `auth` is any object with .get_token(force_refresh=False) -> str
        self._auth = auth
        self._timeout = timeout

    # ── transport ────────────────────────────────────────────────────────────

    def _request(self, method, url, payload=None, _retried=False):
        """Send an authenticated request. On 401, re-auth once and retry."""
        token = self._auth.get_token()
        headers = {
            "Authorization": f"Bearer {token}",
            "application-id": APPLICATION_ID,
            "Accept": "application/json, text/plain, */*",
        }
        data = None
        if payload is not None:
            data = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json;charset=UTF-8"

        req = urlrequest.Request(url, data=data, headers=headers, method=method)

        try:
            with urlrequest.urlopen(req, timeout=self._timeout) as resp:
                return Response(resp.status, resp.read().decode("utf-8", "replace"),
                                url, method)
        except urlerror.HTTPError as e:
            status = e.code
            raw = e.read().decode("utf-8", "replace") if e.fp else ""
            # Token expired mid-session — refresh once and retry, per liveu.rs.
            if status == 401 and not _retried:
                self._auth.get_token(force_refresh=True)
                return self._request(method, url, payload, _retried=True)
            return Response(status, raw, url, method)
        except urlerror.URLError as e:
            # Network/DNS failure — surface as a synthetic 0 so the report can
            # distinguish "couldn't connect" from an HTTP error status.
            return Response(0, f"URLError: {e.reason}", url, method)

    # ── read-only endpoints ──────────────────────────────────────────────────

    def get_inventories(self):
        """GET /inventories. Units live at data.inventories[0].units[] per
        liveu.rs; each unit is {id, reg_code, status, name} and boss_id == id."""
        return self._request("GET", f"{LIVEU_API}/inventories")

    def get_interfaces(self, boss_id):
        """GET /units/{id}/status/interfaces. 200 = list, 204 = none."""
        return self._request(
            "GET", f"{LIVEU_API}/units/{boss_id}/status/interfaces"
        )

    def get_battery(self, boss_id):
        """GET /units/{id}/status/battery."""
        return self._request(
            "GET", f"{LIVEU_API}/units/{boss_id}/status/battery"
        )

    def get_video(self, boss_id):
        """GET /units/{id}/status/video. resolution set + no bitrate = idle;
        bitrate set = streaming."""
        return self._request(
            "GET", f"{LIVEU_API}/units/{boss_id}/status/video"
        )

    def get_delay(self, boss_id):
        """GET /units/{id}?fields=delay."""
        return self._request(
            "GET", f"{LIVEU_API}/units/{boss_id}?fields=delay"
        )

    # ── control endpoints (DESTRUCTIVE — gated by the caller, never auto-run) ──

    def start_stream(self, boss_id):
        """POST /units/{id}/stream  body {"unit_id": id}. Expect 201.
        Pushes to whatever destination is configured on the unit."""
        return self._request(
            "POST", f"{LIVEU_API}/units/{boss_id}/stream",
            payload={"unit_id": boss_id},
        )

    def stop_stream(self, boss_id):
        """DELETE /units/{id}/stream. Expect 204."""
        return self._request(
            "DELETE", f"{LIVEU_API}/units/{boss_id}/stream"
        )

    def reboot_unit(self, boss_id):
        """POST /units/{id}/reboot (v2 base). Expect 204."""
        return self._request(
            "POST", f"{LIVEU_API_V2}/units/{boss_id}/reboot"
        )

    # ── convenience: pull the units list into a flat, loose structure ─────────

    @staticmethod
    def parse_units(inventories_response):
        """Best-effort extraction of units from a get_inventories() Response.
        Returns a list of dicts (raw unit objects) or []. Kept loose on purpose:
        we walk data.inventories[] and collect every 'units' array we find."""
        data = inventories_response.json
        units = []
        if not isinstance(data, dict):
            return units
        inventories = data.get("data", {}).get("inventories")
        if isinstance(inventories, list):
            for inv in inventories:
                if isinstance(inv, dict) and isinstance(inv.get("units"), list):
                    units.extend(inv["units"])
        return units

    # ══════════════════════════════════════════════════════════════════════════
    #  Official-example endpoints (ported to portal auth).
    #
    #  These come from liveuinc/solo_api_examples. The official code hits a
    #  /rest-v2/v2 gateway; we point them at the portal host's v2 path and reuse
    #  our existing portal bearer token. Each call tries v2 first and falls back
    #  to v0 on a 404, reporting which base answered.
    # ══════════════════════════════════════════════════════════════════════════

    def _request_v2first(self, method, path, payload=None):
        """Send `path` to the v2 base; on a 404 retry the v0 base.
        Returns (Response, base_label) where base_label is 'v2' or 'v0'.
        A 403/401/5xx on v2 is NOT retried on v0 (only a 404 means 'wrong base');
        the report surfaces those statuses directly."""
        r2 = self._request(method, LIVEU_API_V2 + path, payload)
        if r2.status != 404:
            return r2, "v2"
        r0 = self._request(method, LIVEU_API_V0 + path, payload)
        return r0, "v0"

    # ── read-only ─────────────────────────────────────────────────────────────

    def list_inventories(self):
        """GET /inventories (v2-first). Bare list on the gateway shape, or the
        portal's {data:{inventories:[…]}} envelope — use parse_inventory_list()."""
        return self._request_v2first("GET", "/inventories")

    def list_units(self, inventory_db_id):
        """GET /inventories/{dbId}/units?offset=0&limit=1000 (v2-first)."""
        return self._request_v2first(
            "GET", f"/inventories/{inventory_db_id}/units?offset=0&limit=1000"
        )

    def get_unit_status(self, uid):
        """GET /units/{uid}/status (v2-first). Distinct from v0 /status/video etc."""
        return self._request_v2first("GET", f"/units/{uid}/status")

    def list_stream_providers(self):
        """GET /streamProviders?offset=0&limit=500&searchKey=domain&searchValue=solo."""
        return self._request_v2first(
            "GET",
            "/streamProviders?offset=0&limit=500&searchKey=domain&searchValue=solo",
        )

    def list_provider_profiles(self, provider_id):
        """GET /streamProviders/{providerId}/streamMediaProfiles (v2-first)."""
        return self._request_v2first(
            "GET", f"/streamProviders/{provider_id}/streamMediaProfiles"
        )

    def list_destinations(self, inventory_db_id):
        """GET /inventories/{dbId}/destinations (v2-first)."""
        return self._request_v2first(
            "GET", f"/inventories/{inventory_db_id}/destinations"
        )

    def get_destination_detail(self, inventory_db_id, destination_id):
        """GET /inventories/{dbId}/destinations/{id} (v2-first). The official
        code calls exactly this on the portal host — should just work."""
        return self._request_v2first(
            "GET", f"/inventories/{inventory_db_id}/destinations/{destination_id}"
        )

    def list_zones(self, uid):
        """GET /units/{uid}/selectableChannelsLight (v2-first)."""
        return self._request_v2first(
            "GET", f"/units/{uid}/selectableChannelsLight"
        )

    # ── writes (destinations + unit config) — callers gate these ──────────────

    def create_destination(self, name, inventory_db_id, provider_name,
                           stream_profile, stream_key, ingress_url,
                           dest_type="rtmp", srt_latency=200, srt_passphrase=""):
        """POST /destinations (v2-first). Builds the exact body from the official
        make_stream_destination(). dest_type 'srt' adds streamingSrt inside
        streamingIngest; 'rtmp' omits it."""
        streaming_ingest = {"primaryUrl": ingress_url}
        if dest_type == "srt":
            streaming_ingest["streamingSrt"] = {
                "mode": "caller", "latency": srt_latency, "passphrase": srt_passphrase,
            }
        body = {
            "name": name,
            "inventoryId": inventory_db_id,
            "type": "stream",
            "streamingProvider": provider_name,
            "externalId": name,
            "streamingDestinationOverrides": [
                {"streamingProfile": stream_profile, "streamId": stream_key}
            ],
            "streamingIngest": streaming_ingest,
        }
        return self._request_v2first("POST", "/destinations", payload=body)

    def set_unit_destination(self, uid, destination_id):
        """PUT /units/{uid}/destinations/selected (v2-first).
        Body {"solo": {"destination": <int>}} per the official example."""
        return self._request_v2first(
            "PUT", f"/units/{uid}/destinations/selected",
            payload={"solo": {"destination": int(destination_id)}},
        )

    def set_zone(self, uid, alias):
        """PUT /units/{uid}/channels/selected (v2-first). MANUAL ONLY — never
        auto-run. Body {"alias": "<zone alias>"}."""
        return self._request_v2first(
            "PUT", f"/units/{uid}/channels/selected", payload={"alias": alias}
        )

    def delete_destination(self, destination_id):
        """DELETE /destinations/{id} (v2-first). Inferred cleanup path — not in
        the official example; may 404/405."""
        return self._request_v2first("DELETE", f"/destinations/{destination_id}")

    def delete_inventory_destination(self, inventory_db_id, destination_id):
        """DELETE /inventories/{dbId}/destinations/{id} (v2-first). Second
        inferred cleanup path."""
        return self._request_v2first(
            "DELETE", f"/inventories/{inventory_db_id}/destinations/{destination_id}"
        )

    # ── loose shape normalizers (handle bare-gateway OR portal-envelope) ──────

    @staticmethod
    def _unwrap(value, *list_keys):
        """Return a list from a response body that might be:
          - a bare list                      → returned as-is
          - {"data": [...]}                  → data
          - {"data": {"<key>": [...]}}       → the first matching key's list
          - {"<key>": [...]}                 → that key's list
        Tries each name in list_keys (e.g. 'inventories', 'units')."""
        if isinstance(value, list):
            return value
        if isinstance(value, dict):
            data = value.get("data", value)
            if isinstance(data, list):
                return data
            if isinstance(data, dict):
                for k in list_keys:
                    if isinstance(data.get(k), list):
                        return data[k]
            for k in list_keys:
                if isinstance(value.get(k), list):
                    return value[k]
        return []

    @staticmethod
    def parse_inventory_list(response):
        return LiveUClient._unwrap(response.json, "inventories")

    @staticmethod
    def parse_unit_list(response):
        return LiveUClient._unwrap(response.json, "units")

    @staticmethod
    def parse_provider_list(response):
        return LiveUClient._unwrap(response.json, "streamProviders", "providers")

    @staticmethod
    def parse_destination_list(response):
        return LiveUClient._unwrap(response.json, "destinations")

    @staticmethod
    def parse_zone_list(response):
        return LiveUClient._unwrap(
            response.json, "selectableChannels", "channels", "zones"
        )

    @staticmethod
    def inv_db_id(inv):
        """Inventory numeric id, tolerant of gateway (dbId) vs portal (db_id)."""
        if isinstance(inv, dict):
            return inv.get("dbId") or inv.get("db_id") or inv.get("id")
        return None

    @staticmethod
    def unit_uid(unit):
        """Unit BOSS id, tolerant of uid / id / BOSSID."""
        if isinstance(unit, dict):
            return unit.get("uid") or unit.get("id") or unit.get("BOSSID")
        return None

    @staticmethod
    def unit_alias(unit):
        """Unit display name, tolerant of alias / name."""
        if isinstance(unit, dict):
            return unit.get("alias") or unit.get("name")
        return None

    @staticmethod
    def find_unit_by_alias(units, alias):
        """Client-side filter, matching alias OR name (case-insensitive)."""
        if not alias:
            return None
        want = str(alias).strip().lower()
        for u in units:
            if str(LiveUClient.unit_alias(u) or "").strip().lower() == want:
                return u
        return None

    @staticmethod
    def dest_id(dest):
        """Destination id, tolerant of id / dbId."""
        if isinstance(dest, dict):
            return dest.get("id") or dest.get("dbId")
        return None

    @staticmethod
    def parse_obj(response):
        """Return a single object from a response that might be bare {...} or
        wrapped {"data": {...}}. Used for create-destination + unit-status."""
        v = response.json
        if isinstance(v, dict):
            data = v.get("data")
            if isinstance(data, dict):
                return data
            return v
        return {}
