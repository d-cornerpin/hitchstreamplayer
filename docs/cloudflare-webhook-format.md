# Cloudflare Live Webhook Format

> **Status:** Documented from Cloudflare docs + existing integration. **Empirical verification pending** (B1.1 — requires ngrok test webhook on staging).
> **Last updated:** 2026-04-24

## Payload structure

Cloudflare Notifications sends the webhook in JSON format:

```json
{
  "name": "Live Webhook Test",
  "text": "Notification type: Stream Live Input\nInput ID: ...",
  "data": {
    "notification_name": "Stream Live Input",
    "input_id": "<live_input_uid>",
    "event_type": "live_input.connected | live_input.disconnected | live_input.errored",
    "updated_at": "2022-01-13T11:43:41.855717910Z",
    "live_input_errored": {
      "error": {
        "code": "ERR_GOP_OUT_OF_RANGE",
        "message": "..."
      },
      "video_codec": "",
      "audio_codec": ""
    }
  },
  "ts": 1720548474
}
```

## Signature verification

**Expected format** (documented in Cloudflare Notifications product docs):

- **Header name:** `CF-Webhook-Signature` (case-insensitive)
- **Format:** `t=<unix_timestamp>,v1=<hex_hmac_sha256>`
- **Payload:** `<timestamp>.<raw_body>`
- **Key:** the webhook secret from `HSCF_webhook_secret`

```php
// Example verification:
$parts = explode(',', $_SERVER['HTTP_CF_WEBHOOK_SIGNATURE']);
$timestamp = substr($parts[0], 2); // strip 't='
$signature = substr($parts[1], 3); // strip 'v1='
$payload = $timestamp . '.' . $body;
$expected = hash_hmac('sha256', $payload, $secret);
$valid = hash_equals($expected, $signature);
```

**Replay protection:** `abs(time() - $timestamp) > $maxAgeSeconds`

## `/lifecycle` endpoint

Unauthenticated. Returns `{isInput, videoUID, live}`. Used to populate `videoUID` at webhook-receive time (B1.3).

```
GET https://customer-<CODE>.cloudflarestream.com/<INPUT_ID>/lifecycle
```

No auth headers needed. Documented in `CloudFlare Docs/Watch a Live Stream.md`.

## Current webhook handler

- File: `celebration-child/endpoints/cf-live-webhook.php`
- Header name guesses: `cf-webhook-signature`, `cf-webhook-sig`, `x-cf-webhook-signature`
- Currently uses **plain HMAC over body** (no timestamp prefix)
- **B1.2 must align with the actual format** once empirical verification confirms it

## Pending (B1.1)

- [ ] Empirical verification via ngrok test webhook
- [ ] Confirm exact header name
- [ ] Confirm: plain HMAC over body, OR `t=<ts>,v1=<hex>` over `<ts>.<body>`
- [ ] Confirm replay-protection mechanism (timestamp in header vs `updated_at` in body)
- [ ] Update this doc with raw captured header(s) as evidence
