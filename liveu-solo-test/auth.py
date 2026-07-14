"""
Auth providers for the LiveU Solo client.

This module is deliberately isolated from the rest of the client. The whole
point of the "swap later" requirement is that when the official LU-Central API
(lu-central-api.liveu.tv) ships, we drop in a new provider class that satisfies
the same tiny interface — get_token() -> str — and nothing in liveu_client.py
has to change.

Interface contract for any provider:
    - .get_token(force_refresh=False) -> str   # returns a bearer token
    - raises AuthError on failure

Do NOT log credentials anywhere in here.
"""

import json
import uuid
from urllib import request as urlrequest
from urllib import error as urlerror
import base64


class AuthError(Exception):
    """Raised when a token cannot be obtained. Carries the HTTP status and
    (truncated) response body so the caller can report what actually happened —
    e.g. whether the endpoint moved or the app id was revoked."""

    def __init__(self, message, status=None, body=None):
        super().__init__(message)
        self.status = status
        self.body = body


class SoloPortalAuth:
    """
    The unofficial Solo web-portal login flow, reverse-engineered from the
    NOALBS liveu_stats_bot (src/liveu.rs::get_access_token).

    POST https://solo-api.liveu.tv/v1_prod/zendesk/userlogin
      - HTTP Basic auth: portal email + password
      - x-user-name: "{email}{uuid4}"
      - body: {"return_to": "https://solo.liveu.tv/#/dashboard/units"}
      - token at: data.response.access_token
    """

    LOGIN_URL = "https://solo-api.liveu.tv/v1_prod/zendesk/userlogin"
    RETURN_TO = "https://solo.liveu.tv/#/dashboard/units"

    def __init__(self, email, password, timeout=15):
        self._email = email
        self._password = password
        self._timeout = timeout
        self._token = None
        # Exposed for the report so we can show what the login endpoint returned
        # without re-issuing the request. Never contains credentials.
        self.last_status = None
        self.last_raw_body = None

    def get_token(self, force_refresh=False):
        if self._token and not force_refresh:
            return self._token
        self._token = self._login()
        return self._token

    def _login(self):
        # HTTP Basic header, built by hand so we don't drag in a dependency.
        basic = base64.b64encode(
            f"{self._email}:{self._password}".encode("utf-8")
        ).decode("ascii")

        headers = {
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "en-US,en;q=0.9",
            "Content-Type": "application/json;charset=UTF-8",
            "Authorization": f"Basic {basic}",
            # email concatenated with a fresh uuid, per liveu.rs
            "x-user-name": f"{self._email}{uuid.uuid4()}",
        }
        body = json.dumps({"return_to": self.RETURN_TO}).encode("utf-8")

        req = urlrequest.Request(
            self.LOGIN_URL, data=body, headers=headers, method="POST"
        )

        try:
            with urlrequest.urlopen(req, timeout=self._timeout) as resp:
                status = resp.status
                raw = resp.read().decode("utf-8", "replace")
        except urlerror.HTTPError as e:
            status = e.code
            raw = e.read().decode("utf-8", "replace") if e.fp else ""
            self.last_status = status
            self.last_raw_body = raw
            raise AuthError(
                f"Login failed with HTTP {status}", status=status, body=raw
            )
        except urlerror.URLError as e:
            self.last_status = None
            self.last_raw_body = str(e.reason)
            raise AuthError(f"Login connection error: {e.reason}")

        self.last_status = status
        self.last_raw_body = raw

        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            raise AuthError(
                "Login returned non-JSON (endpoint may have moved)",
                status=status,
                body=raw,
            )

        # Token path per liveu.rs: data.response.access_token
        token = (
            data.get("data", {}).get("response", {}).get("access_token")
            if isinstance(data, dict)
            else None
        )
        if not token:
            raise AuthError(
                "Login succeeded but no access_token at data.response.access_token "
                "(response shape may have drifted)",
                status=status,
                body=raw,
            )
        return token


class LuCentralAuth:
    """
    Placeholder for the forthcoming OFFICIAL API auth
    (lu-central-api.liveu.tv, OAuth-style token_grant).

    When credentials/spec are available, implement get_token() here to satisfy
    the same interface and pass an instance of this class into LiveUClient
    instead of SoloPortalAuth. Nothing else needs to change.
    """

    def __init__(self, *args, **kwargs):
        pass

    def get_token(self, force_refresh=False):
        raise NotImplementedError(
            "Official LU-Central auth is not implemented yet. "
            "Use SoloPortalAuth for the unofficial portal API."
        )
