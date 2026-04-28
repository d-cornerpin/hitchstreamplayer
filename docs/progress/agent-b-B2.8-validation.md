# B2.8 Contract Validation — Agent A Fixture Round-Trip

**Purpose:** Validate that the B2 live-state REST endpoint produces responses that exactly match the shapes in Agent A's mock fixtures (`docs/progress/agent-a-fixtures/`).

**Validation method:** Each fixture has `response_headers` and `response_body`. The endpoint must produce matching field names, types, and constraints for all eight scenarios.

---

## Fixture validation checklist

### 1. `live.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"live"` | string enum | StateWriter writes `state: 'live'` from B1.3 |
| `videoUID` = populated string | string (not null) | B1.3 populates via /lifecycle |
| `hlsUrl` = populated string | string matching CF allowlist | `https://customer-{code}/{uid}/manifest/video.m3u8` |
| `errorCode` = null | null | Enforced in contract enforcement |
| `source` = `"webhook"` | string enum | Flat-file path defaults to `'webhook'` source |
| `ts` = unix timestamp | integer | Written by B1.3 at receive time |
| `Content-Type` header | `application/json; charset=utf-8` | WP REST response |
| `Cache-Control` | `no-store` | Set on every response |
| `ETag` | opaque string, changes on state change | `md5(json_encode($data))` |
| `X-HS-Correlation-Id` | UUID v4 | `wp_generate_uuid4()` per request |

### 2. `idle.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"idle"` | string enum | StateWriter writes `state: 'idle'` |
| `videoUID` = null | null enforced | Contract enforcement: idle → videoUID null, hlsUrl null |
| `hlsUrl` = null | null enforced | Contract enforcement |
| `errorCode` = null OR string | null or cloudflare code | Allowed by contract table |
| `source` = `"webhook"` | string enum | Flat-file path |
| All required headers | as above | Yes |

### 3. `reconnecting.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"reconnecting"` | string enum | StateWriter |
| `videoUID` = same as preceding live | string, non-null | Preserved from prior live event |
| `hlsUrl` = same as preceding live | string, non-null | Preserved |
| `errorCode` = null | null | Enforced for live/reconnecting states |
| `source` = `"webhook"` | string enum | Flat-file path |

### 4. `error-gop.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"error"` | string enum | B1.2 normalized to `'error'` for `live_input.errored` |
| `videoUID` = null | null | Contract enforcement: error → null |
| `hlsUrl` = null | null | Contract enforcement |
| `errorCode` = `"ERR_GOP_OUT_OF_RANGE"` | string pass-through | B1.5: stored from webhook payload |
| `source` = `"webhook"` | string enum | Flat-file path |

### 5. `error-quota.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"error"` | string enum | Same as error-gop |
| `errorCode` = `"ERR_STORAGE_QUOTA_EXHAUSTED"` | string pass-through | Stored from webhook or probe |
| `source` = `"probe"` | string enum | Probe path sets `source: 'probe'` |

### 6. `handover-new-uid.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"live"` | string enum | New live event |
| `videoUID` = new UID (different from prior) | string | /lifecycle returns new UID for new broadcast session |
| `hlsUrl` = new URL | string matching allowlist regex | Constructed from new UID |
| `errorCode` = null | null | Enforced for live state |
| `source` = `"webhook"` | string enum | Flat-file path |
| ETag must differ from preceding state | opaque, changes | ETag = md5 of full response → changes when any field changes |

### 7. `304-response.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| HTTP status = 304 | integer | If-None-Match matching ETag → 304 |
| No response body | empty | WP REST 304 has no body |
| `Cache-Control` = `no-store` | header | Set on all responses |
| `ETag` = same as prior 200 | opaque, matches | The ETag that was sent in If-None-Match |
| `X-HS-Correlation-Id` = present | UUID v4 | Generated per-request |

### 8. `coalesced.json`
| Requirement | Constraint | Endpoint compliance |
|-------------|-----------|-------------------|
| `state` = `"live"` | string enum | Probe result or prior state |
| `videoUID` = populated | string | Probe returns UID |
| `hlsUrl` = populated | string | Constructed from UID |
| `errorCode` = null | null | Enforced for live |
| `source` = `"coalesced"` | string enum | B2.4: single-flight lock returns coalesced |
| `ts` = original probe write time | integer (not current time) | B1.4a: ts carried through coalesced |
| ETag = same as probe result | opaque, matches | Same data → same md5 |
| X-HS-Correlation-Id = different per request | UUID v4 | New UUID per request |

---

## Validation notes

### Source field coverage
All three source values are produced:
- `webhook` — flat-file read path (most common during healthy events)
- `probe` — transient miss → /lifecycle probe (first poll after start, or TTL expiry)
- `coalesced` — cache miss with single-flight lock held (concurrent viewer spike)

### ETag stability
ETag = `md5(json_encode($state_data))`. Stable because:
- Same state fields → same JSON → same md5
- Any field change (videoUID, hlsUrl, state, errorCode, ts) → different JSON → different md5

### Contract enforcement
The `enforce_contract()` method ensures all responses match §4.1 before headers are set:
- `live`/`reconnecting`: errorCode always null
- `idle`/`error`: videoUID always null, hlsUrl always null
- source always one of {webhook, probe, coalesced}
- ts always a unix timestamp
- videoUID/hlsUrl always scalar or null

### 8/8 fixtures: round-trip compliant
All eight Agent A fixtures map to endpoint output with matching field names, types, constraints, and header requirements.
