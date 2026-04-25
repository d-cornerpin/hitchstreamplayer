# B1.7 Staging Smoke-Test Plan

**Prerequisites:** PR #1 (B0 security hotfix) deployed to staging.
**Webhook URL:** `https://STAGING_URL/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php`
**Secret:** `STAGING_SECRET` (value of `HSCF_webhook_secret` WP option)
**Input ID:** `test-input-id` (any valid Cloudflare input ID)

## Signature helpers

```bash
# Set these before running tests
export SECRET='STAGING_SECRET'
export URL='https://STAGING_URL/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php'
export INPUT='test-input-id'

# Timestamped HMAC (t=<ts>,v1=<hmac>) over "ts.body"
ts_sig() {
    local ts=$(date +%s)
    local payload="${ts}.$1"
    local sig=$(printf '%s' "$payload" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
    echo "t=${ts},v1=${sig}"
}

# Plain HMAC (signature IS the raw HMAC over the body)
plain_sig() {
    printf '%s' "$1" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}'
}
```

---

## Test 1: Valid `live_input.connected` (timestamped format)

**Setup:** Nothing. This is the happy path.

**Run:**
```bash
BODY='{"data":{"event_type":"live_input.connected","input_id":"'$INPUT'"}}'
SIG=$(ts_sig "$BODY")
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG" \
  -d "$BODY"
```

**Expected:**
- HTTP 200
- Response: `{"status":"ok","normalized_state":"live"}`
- WP transient `hs_live_state_test-input-id` exists with:
  - `state` = `"live"`
  - `videoUID` = non-empty string (or empty if `/lifecycle` unreachable)
  - `hlsUrl` = `https://customer-XXX.cloudflarestream.com/VIDEOUID/manifest/video.m3u8` or null
  - `source` = `"webhook"`
  - `ts` = current unix timestamp
  - `ttl` = 300s
- `hs_webhook_log` table: row with `input_id=test-input-id`, `event_type=live_input.connected`, `normalized_state=live`, `signature_ok=1`, `processed=1`, `correlation_id` is valid UUID v4

---

## Test 2: Valid `live_input.disconnected` (client_disconnect)

**Run:**
```bash
BODY='{"data":{"event_type":"live_input.disconnected","input_id":"'$INPUT'"}}'
SIG=$(ts_sig "$BODY")
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG" \
  -d "$BODY"
```

**Expected:**
- HTTP 200
- Response: `{"status":"ok","normalized_state":"idle"}`
- WP transient `hs_live_state_test-input-id` exists with:
  - `state` = `"idle"`
  - `videoUID` = null (explicitly cleared on disconnect)
  - `hlsUrl` = null (explicitly cleared on disconnect)
- `hs_webhook_log`: row with `normalized_state=idle`, `signature_ok=1`

---

## Test 3: Tampered signature

**Run:**
```bash
BODY='{"data":{"event_type":"live_input.connected","input_id":"'$INPUT'"}}'
# Tamper with signature: append garbage to a valid signature
VALID_SIG=$(ts_sig "$BODY")
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: ${VALID_SIG}X" \
  -d "$BODY"
```

**Expected:**
- HTTP 403
- Response: `{"error":"Invalid webhook signature"}`
- No transient write (if a prior transient exists, its value is unchanged; if none, no transient created)
- `hs_webhook_log`: row with `signature_ok=0`, `processed=0`

---

## Test 4: Missing `CF-Webhook-Signature` header

**Run:**
```bash
BODY='{"data":{"event_type":"live_input.connected","input_id":"'$INPUT'"}}'
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

**Expected:**
- HTTP 403
- Response: `{"error":"Invalid webhook signature"}`
- No transient write
- `hs_webhook_log`: row with `signature_ok=0`, `processed=0`

---

## Test 5: Replay attack (timestamp >5 min old)

**Run:**
```bash
BODY='{"data":{"event_type":"live_input.connected","input_id":"'$INPUT'"}}'
OLD_TS=$(($(date +%s) - 601))
OLD_PAYLOAD="${OLD_TS}.${BODY}"
OLD_SIG=$(printf '%s' "$OLD_PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: t=${OLD_TS},v1=${OLD_SIG}" \
  -d "$BODY"
```

**Expected:**
- HTTP 403
- Response: `{"error":"Invalid webhook signature"}`
- No transient write
- `hs_webhook_log`: row with `signature_ok=0`, `processed=0`

---

## Test 6: Plain-HMAC fallback (no t=,v1= prefix)

**Run:**
```bash
BODY='{"data":{"event_type":"live_input.connected","input_id":"'$INPUT'"}}'
SIG=$(plain_sig "$BODY")
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG" \
  -d "$BODY"
```

**Expected:**
- HTTP 200 (signature verified via plain HMAC path)
- Response: `{"status":"ok","normalized_state":"live"}`
- Transient has `state=live`
- `hs_webhook_log`: row with `signature_ok=1`

---

## Test 7: `/lifecycle` failure path (B1.3a)

**Setup:** Clear the transient first, then simulate a live event where `/lifecycle` would fail.

```bash
# Set a known prior transient value to verify it is preserved
WP_CLI="wp option get HSCF_customer_id --allow-root"
CUSTOMER=$($WP_CLI 2>/dev/null)
if [ -z "$CUSTOMER" ]; then
    echo "SKIP: Need WordPress CLI (wp) configured on staging"
else
    # Set a prior state for a DIFFERENT input_id
    wp transient set hs_live_state_prior-input '{"state":"live","videoUID":"old-video-uid","hlsUrl":"https://customer-XXX.cloudflarestream.com/old-video-uid/manifest/video.m3u8","source":"webhook","ts":1000000}' 300 --allow-root

    # Now trigger live for test-input-id with a valid signature
    # This will call /lifecycle for test-input-id. If it returns 404/500,
    # B1.3a says transient is NOT updated — prior value for prior-input is preserved.
    BODY='{"data":{"event_type":"live_input.connected","input_id":"test-input-id"}}'
    SIG=$(ts_sig "$BODY")
    curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
      -H "Content-Type: application/json" \
      -H "CF-Webhook-Signature: $SIG" \
      -d "$BODY"

    # Verify prior-input transient is untouched
    echo "Prior input state (should still be old-video-uid):"
    wp transient get hs_live_state_prior-input --allow-root
fi
```

**Expected:**
- HTTP 200
- Response: `{"status":"ok","normalized_state":"live","lifecycle_failed":true}`
- `hs_live_state_test-input-id` is NOT written (no transient)
- `hs_live_state_prior-input` is UNCHANGED (still has `old-video-uid`)
- `hs_webhook_log`: row with `normalized_state=live`, `processed=0`, `lifecycle_failed` noted in error_log

---

## Test 8: Coalescing window (B1.4) — two events <3s apart

**Setup:** Seed the coalescing transient so Event 1 falls inside the 3s window.

```bash
# Seed the coalescing key with a value from ~2 seconds ago
# The code stores ['ts' => time()] as an associative array
OLD_TS=$(($(date +%s) - 2))
wp transient set hs_webhook_coalesce_cool-input '{"ts":'$OLD_TS'}' --type=array --allow-root

# Seed a prior state for Event 1 to reference on coalesced read
wp transient set hs_live_state_cool-input '{"state":"live","videoUID":"seeded-video","hlsUrl":"https://example.com/seeded.m3u8","source":"webhook","ts":'$OLD_TS'}' 300 --allow-root
```

**Run:**
```bash
# Event 1 — seed is 2s old < 3s threshold → Event 1 is DROPPED (coalesced)
BODY1='{"data":{"event_type":"live_input.connected","input_id":"cool-input"}}'
SIG1=$(ts_sig "$BODY1")
echo "=== Event 1 (coalesced — should return seeded state) ==="
EVENT1_RESP=$(curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG1" \
  -d "$BODY1")
echo "$EVENT1_RESP"

# Wait for the coalescing key TTL to expire (TTL=5s, seed was 2s old, so wait 4s → seed is 6s old → expired)
sleep 4

# Event 2 — coalescing key expired → processed normally
BODY2='{"data":{"event_type":"live_input.connected","input_id":"cool-input"}}'
SIG2=$(ts_sig "$BODY2")
echo "=== Event 2 (fresh — should update transient with current ts) ==="
EVENT2_RESP=$(curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG2" \
  -d "$BODY2")
echo "$EVENT2_RESP"

# Verify: Event 1 was coalesced (returned seeded state with ts=OLD_TS)
# Event 2 was processed fresh (ts = current time)
echo "=== Final live state ts (should be Event 2's time, ~NOW) ==="
wp transient get hs_live_state_cool-input --allow-root 2>/dev/null || echo "(no transient)"
echo "=== Log rows for cool-input (should be 2 total, both processed=1) ==="
wp db query "SELECT id, event_type, processed, correlation_id FROM wp_hs_webhook_log WHERE input_id='cool-input' ORDER BY id" --allow-root 2>/dev/null || echo "(no log)"
```

**Expected:**
- Event 1: HTTP 200, response `{"status":"coalesced","data":{"state":"live","videoUID":"seeded-video","ts":OLD_TS,...}}`
- Event 2: HTTP 200, response `{"status":"ok","normalized_state":"live"}`, transient updated with ts=NOW
- Final transient ts = current time (Event 2's time), NOT OLD_TS
- `hs_webhook_log`: 2 rows — both with `processed=1`

---

## Test 9: Idempotency dedup (B1.4) — exact duplicate within 60s

**Setup:** First send a real event.

```bash
BODY='{"data":{"event_type":"live_input.connected","input_id":"idem-input"}}'
SIG=$(ts_sig "$BODY")
echo "=== First send ==="
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG" \
  -d "$BODY"

sleep 2
```

**Run (same body, new timestamp — but the coalesced key checks body match):**

Actually, the idempotency key drops exact duplicates (same body + same event) within 60s. For a new timestamp, the body changes in the signature header but the raw body is identical. The function compares `$parsed['data']['event_type']` and `$body` from the stored transient.

```bash
# Send identical body 5 seconds later
echo "=== Duplicate send (5s later, same body) ==="
SIG2=$(ts_sig "$BODY")
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG2" \
  -d "$BODY"
```

**Expected:**
- First send: HTTP 200, `{"status":"ok","normalized_state":"live"}`, transient written
- Duplicate: HTTP 200, response `{"status":"debounced"}` (no transient update)
- Only one log row with `processed=1` for `idem-input`

---

## Test 10: Missing `HSCF_webhook_secret`

**Setup:** Temporarily clear the secret WP option.

```bash
# Save current secret
wp option get HSCF_webhook_secret --allow-root 2>/dev/null
SAVED_SECRET=$?

# Clear the secret
wp option delete HSCF_webhook_secret --allow-root 2>/dev/null

# Now send any webhook — should be rejected
BODY='{"data":{"event_type":"live_input.connected","input_id":"nosec-input"}}'
SIG=$(ts_sig "$BODY")
echo "=== Webhook with secret cleared ==="
curl -s -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "CF-Webhook-Signature: $SIG" \
  -d "$BODY"

# Restore secret
wp option update HSCF_webhook_secret "$SECRET" --allow-root 2>/dev/null

echo "=== Admin notice check ==="
echo "Visit WP Admin → check for RED error banner at top of screen"
```

**Expected:**
- HTTP 403
- Response: `{"error":"Invalid webhook signature"}`
- `error_log` contains: `[HitchStream] CRITICAL: Webhook secret not configured. Rejecting webhook to prevent unauthorized state manipulation.`
- No transient write
- `hs_webhook_log`: row with `signature_ok=0`
- WP Admin area shows a red error notice about missing `HSCF_webhook_secret`

---

## Cleanup

After all tests:
```bash
# Clear test data
wp transient delete hs_live_state_test-input-id --allow-root
wp transient delete hs_live_state_prior-input --allow-root
wp transient delete hs_live_state_cool-input --allow-root
wp transient delete hs_live_state_idem-input --allow-root
wp transient delete hs_webhook_coalesce_cool-input --allow-root
wp transient delete hs_webhook_update_ts_test-input-id --allow-root
wp transient delete hs_webhook_update_ts_cool-input --allow-root
wp transient delete hs_webhook_update_ts_idem-input --allow-root
wp transient delete hs_webhook_idem_test-input-id --allow-root
wp transient delete hs_webhook_idem_idem-input --allow-root
wp transient delete hs_webhook_coalesce_test-input-id --allow-root
```

Verify log table:
```sql
SELECT * FROM wp_hs_webhook_log ORDER BY id DESC LIMIT 20;
```
