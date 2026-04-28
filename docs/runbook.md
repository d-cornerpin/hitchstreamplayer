# HitchStream — Operational Runbook

> **Audience:** Staff members who run weddings. No developer needed.

---

## 1. Trigger a Test Webhook

From the Cloudflare dashboard:

1. Go to **Notifications** → [dash.cloudflare.com/?to=/:account/notifications](https://dash.cloudflare.com/?to=/:account/notifications)
2. Find the webhook channel for **Stream Live** (the one with `notification_url` pointing to your HitchStream receiver)
3. Click **Save and Test** — Cloudflare sends a test ping
4. Check WordPress: **Tools → HitchStream Activity** — you should see a row with `event_type: notifications.test` and `signature_ok: yes`

---

## 2. Rotate the Webhook Secret (cf-webhook-auth)

Cloudflare generates its own `cf-webhook-auth` secret on webhook registration. If you need to rotate it:

1. **In WordPress**: Go to **Settings → HS CloudFlare** (under the main "HS CloudFlare" menu)
2. Scroll to **Webhook Settings**
3. Click **Delete Webhook** — this removes the existing webhook from Cloudflare
4. Click **Register Webhook** — this registers a new webhook; Cloudflare generates a fresh `cf-webhook-auth`
5. Copy the new secret from the **Webhook Secret** field (it updates automatically)
6. Test: use step 1 above to verify

---

## 3. Rotate the Cloudflare API Token

1. Go to [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens)
2. Create a new API token with **Stream Editor** + **Account Read** permissions
3. Go to **Settings → HS CloudFlare** in WordPress
4. In the **CloudFlare API Settings** section, paste the new token in the **CloudFlare API Token (Bearer)** field
5. Click **Save Changes**
6. Click **Test Cloudflare Connection** — should show "Connected successfully"

---

## 4. Read the Activity Log

**Path:** WordPress Dashboard → **Tools → HitchStream Activity**

- Shows the last **200** webhook log entries (auto-trimmed weekly, rows older than 30 days are removed)
- Use the **Filter by Input ID** field to narrow to a specific stream
- Click **Export CSV** for forensic copy/paste
- Columns: `Received At`, `Input ID`, `Event Type`, `Normalized State`, `Error Code`, `Signature OK`, `Correlation ID`

---

## 5. Error Code → Action Mapping

| Error Code | Meaning | Action |
|---|---|---|
| `ERR_STORAGE_QUOTA_EXHAUSTED` | Account storage is full | Log into Cloudflare Dash → Stream → Videos → delete old recordings or purchase additional storage |
| `ERR_MISSING_SUBSCRIPTION` | No active Stream subscription | Log into Cloudflare Dash → check subscription status; renew or upgrade |
| `ERR_GOP_OUT_OF_RANGE` | Input GOP (keyframe) interval is invalid | Check the broadcaster settings (OBS, vMix, LiveU). Keyframe interval should be 1-2 seconds |
| `ERR_UNSUPPORTED_VIDEO_CODEC` | Video codec not supported by Cloudflare | Set the broadcaster to H.264 (AVC) with AAC audio |
| `ERR_UNSUPPORTED_AUDIO_CODEC` | Audio codec not supported | Set the broadcaster to AAC audio at 44100 or 48000 Hz |

---

## 6. Day-of-Wedding Pre-Flight Checklist

**Run this 30 minutes before the stream goes live:**

1. **Stream Connection:** Confirm the live input shows **connected** in the HS CloudFlare admin panel
2. **Webhook Status:** Verify the webhook secret is configured (no red warning on the admin page)
3. **Player Test:** Open the customer page in a browser and confirm the stream loads
4. **Alert Email:** Ensure `HSCF_alert_email` is set (Settings → HS CloudFlare → Alerts) so critical errors page to the right person
5. **Streamer Service:** Verify the placeholder streamer API key is configured if you plan to use placeholder streams

---

## 7. Post-Event Questions

**"What happened during the stream?"**

- Go to **Tools → HitchStream Activity**
- Filter by the wedding's input ID
- The `event_type` column shows: `live_input.connected`, `live_input.disconnected`, `live_input.errored`
- `Normalized State` shows the mapped state: `live`, `idle`, `reconnecting`, `error`

**"Was there an interruption?"**

- Look for rows with `Normalized State: error` or `event_type: live_input.errored`
- Check `Error Code` column for the specific error
- Cross-reference with Section 5 (Error Code → Action Mapping)

**"Did the webhook receiver stay healthy?"**

- Look at the `Signature OK` column — if you see `✗`, webhooks were rejected (secret mismatch)
- The activity log shows all webhook events with timestamps
