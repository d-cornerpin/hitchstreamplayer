# HitchStream Player — Configuration Reference

All configuration is stored as WordPress options. Managed via **Admin → HS CloudFlare** settings page.

## Cloudflare API Settings

| Option | Required | Default | Purpose |
|--------|----------|---------|---------|
| `HSCF_cloudflare_email` | Yes | — | Cloudflare account email (legacy auth). Used as fallback when Bearer token is not set. |
| `HSCF_cloudflare_api_key` | Yes | — | Cloudflare Global API Key (legacy auth). Fallback when Bearer token is not set. |
| `HSCF_cloudflare_account_id` | Yes | — | Cloudflare account ID for all API calls. |
| `HSCF_cloudflare_api_token` | No | — | **Preferred auth method.** Scoped Bearer token for Cloudflare API. When set, `HSCF_cloudflare_email`/`_api_key` are ignored (no deprecation notice fired). When unset, falls back to email+key with a `trigger_error(E_USER_DEPRECATED)` notice. |

### Auth precedence

1. `HSCF_cloudflare_api_token` (Bearer) — silent, preferred
2. `HSCF_cloudflare_email` + `HSCF_cloudflare_api_key` — fires deprecation notice
3. Neither — fires `E_USER_WARNING`, API calls fail

## Webhook Settings

| Option | Required | Default | Purpose |
|--------|----------|---------|---------|
| `HSCF_webhook_url` | Yes | Auto-generated to `cf-live-webhook.php` | URL Cloudflare sends webhook events to. |
| `HSCF_webhook_secret` | Yes | — | Shared-secret token Cloudflare sends in `cf-webhook-auth` header. Set automatically by Cloudflare on register. |

## Player Settings

| Option | Required | Default | Purpose |
|--------|----------|---------|---------|
| `HSCF_customer_id` | No | `juu1r5es4cbffqjf` | Cloudflare Customer ID used to construct customer-specific URLs (`customer-{id}.cloudflarestream.com`). No longer hardcoded. |
| `HSCF_poster_initial` | No | Built-in default | Poster image shown when player loads before stream state is known. |
| `HSCF_poster_idle` | No | Built-in default | Poster image shown when stream is idle (no active broadcast). |
| `HSCF_poster_fatal` | No | Built-in default | Poster image shown when stream encounters a fatal error. |

## Streamer Service Settings

| Option | Required | Default | Purpose |
|--------|----------|---------|---------|
| `HSCF_streamer_api_url` | No | `https://streamer1.hitchstream.com` | Base URL for the placeholder-stream service. |
| `HSCF_streamer_api_key` | No | — | API key for the placeholder-stream service. Required for all placeholder-stream operations. |

## Alerts Settings

| Option | Required | Default | Purpose |
|--------|----------|---------|---------|
| `HSCF_alert_email` | No | — | Email address for critical error alerts (sent when configured error_codes are received via webhook). |
| `HSCF_alert_codes` | No | `ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION` | Comma-separated list of error_codes that trigger email alerts. Must align with `HSPlayerConfig.errorMessages` keys. |

## CloudflareClient (B3.1)

All Cloudflare Stream API calls route through `HS\CloudflareClient`. Created via:

```php
$client = new \HS\CloudflareClient(\HS\Config::cloudflareAccountId());
```

Methods:

| Method | Cloudflare API | Description |
|--------|---------------|-------------|
| `lifecycle($inputId)` | GET `/accounts/{id}/stream/{inputId}/lifecycle` | Fetch live input lifecycle state. |
| `listVideos($inputId, $page, $perPage)` | GET `/accounts/{id}/stream` | List stream resources (live_inputs). |
| `getLiveInput($inputId)` | GET `/accounts/{id}/stream/live_inputs/{id}` | Get a single live input's details. |
| `createLiveInput($params)` | POST `/accounts/{id}/stream/live_inputs` | Create a new live input. |
| `deleteLiveInput($inputId)` | DELETE `/accounts/{id}/stream/live_inputs/{id}` | Delete a live input. |
| `registerWebhook($url, $secret)` | PUT `/accounts/{id}/stream/webhook` | Register account-level webhook. |
| `deleteWebhook()` | DELETE `/accounts/{id}/stream/webhook` | Remove account-level webhook. |
| `getWebhook()` | GET `/accounts/{id}/stream/webhook` | Retrieve webhook configuration. |

All methods return: `['success' => bool, 'body' => string, 'status' => int|null, 'error' => string|null, 'correlation_id' => string]`
