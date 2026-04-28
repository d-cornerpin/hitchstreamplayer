# Cloudflare Live Webhook Format

> **Status:** Validated against a real Cloudflare Notifications test webhook on 2026-04-25.
> **Last updated:** 2026-04-25

## Captured evidence (real Cloudflare Notifications test ping)

**Headers:**
```
cf-webhook-auth: <REDACTED-SECRET>
cf-ray: 9f1b7110aa7e08ff-SEA
cf-worker: cf-application-services.workers.dev
cf-visitor: {"scheme":"https"}
cdn-loop: cloudflare; loops=1
content-type: application/json
content-length: 140
```

**Body:**
```json
{"text":"Hello World! This is a test message sent from https://cloudflare.com. If you can see this, your webhook is configured correctly."}
```

## Authentication

- **Header name:** `cf-webhook-auth` (case-insensitive via `$_SERVER['HTTP_CF_WEBHOOK_AUTH']`)
- **Value:** The configured secret, sent verbatim as a plain shared-secret token.
- **NO HMAC.** **NO timestamp.** **NO signature.** **NO replay protection.**

```php
// Verification in PHP:
$auth = $_SERVER['HTTP_CF_WEBHOOK_AUTH'] ?? '';
$secret = get_option('HSCF_webhook_secret', '');
$valid = $auth !== '' && $secret !== '' && hash_equals($secret, $auth);
```

**Security implications:** Shared-secret auth is weaker than HMAC. Anyone who learns the secret can forge webhooks. Mitigations:
- Production secret must be 32+ chars, cryptographically random.
- Webhook URL must be HTTPS (Cloudflare requires this).
- Rotate the secret quarterly (and immediately after any suspected leak).
- The webhook handler must not log the secret value anywhere.

## Body shapes

### Real Stream Live event (production)

```json
{
  "name": "Live Webhook",
  "text": "Notification type: Stream Live Input\nInput ID: ...",
  "data": {
    "notification_name": "Stream Live Input",
    "input_id": "<live_input_uid>",
    "event_type": "live_input.connected | live_input.disconnected | live_input.errored",
    "updated_at": "2022-01-13T11:43:41.855717910Z",
    "live_input_errored": {
      "error": {
        "code": "ERR_GOP_OUT_OF_RANGE",
        "message": ".."
      },
      "video_codec": "",
      "audio_codec": ""
    }
  },
  "ts": 1720548474
}
```

Distinguished by presence of `data.event_type`.

### Cloudflare Notifications test ping (dashboard "Save and Test")

```json
{"text":"Hello World! This is a test message sent from https://cloudflare.com..."}
```

Distinguished by absence of `data.event_type` and `data.input_id`. The handler accepts (200) or rejects (403) based on `cf-webhook-auth`, writes a log row with `event_type='notifications.test'`, but does NOT update any transient or trigger `/lifecycle`.

## `/lifecycle` endpoint

Unauthenticated. Returns `{isInput, videoUID, live}`. Used to populate `videoUID` at webhook-receive time (B1.3).

```
GET https://customer-<CODE>.cloudflarestream.com/<INPUT_ID>/lifecycle
```

No auth headers needed.

## Current webhook handler

- File: `celebration-child/endpoints/cf-live-webhook.php`
- Header: `cf-webhook-auth` only (single source of truth)
- Auth: Plain shared-secret via `hash_equals()` (timing-safe)
- Test pings: Detected by missing `data.event_type`; handled with 200 + `{"test":true}`, logged with `event_type=notifications.test`
