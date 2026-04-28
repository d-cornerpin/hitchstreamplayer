# B4: Plugin Refactor — Smoke Test Plan

## Goal
Verify that the monolith → class-based refactor produces identical behavior.

## 1. Plugin Loads Cleanly
- [ ] Activate/deactivate the plugin in WP admin (no PHP fatal)
- [ ] `PHP Notice: Uninitialized string offset` — none in error log
- [ ] `PHP Warning: Class "HS\ConfigError" not found` — none (verify ConfigError.php exists)

## 2. Admin Menu
- [ ] "HS CloudFlare" menu item appears in WP sidebar
- [ ] Clicking it renders the admin page (Cloudflare API, Webhook, Player, Streamer, Alerts sections)
- [ ] Live inputs list renders (may show error if credentials unconfigured — that's expected)

## 3. Settings Registration
- [ ] Cloudflare API settings section: email, API key, account ID, API token fields render
- [ ] Webhook settings section: URL, Secret fields render
- [ ] Player settings section: Customer ID, poster URLs render
- [ ] Streamer settings section: API URL, API Key fields render
- [ ] Alerts section: email, codes fields render
- [ ] Saving each section persists values

## 4. Test Buttons
- [ ] "Test Cloudflare Connection" button: fires `hscf_test_connection` AJAX, returns result
- [ ] "Test Streamer Service" button: fires `hscf_test_streamer` AJAX, returns result
- [ ] "Rotate Secret" button: fires `hscf_rotate_webhook` AJAX, returns result

## 5. AJAX Handlers (via AjaxController)
- [ ] `hscf_upload_video` — creates TUS session
- [ ] `hscf_register_webhook` — registers webhook with Cloudflare
- [ ] `hscf_delete_webhook` — deletes webhook
- [ ] `hscf_fetch_webhooks` — lists webhooks
- [ ] `hscf_rotate_webhook` — rotates secret
- [ ] `hscf_test_connection` — tests Cloudflare credentials
- [ ] `hscf_test_streamer` — tests streamer service
- [ ] `get_live_inputs` — lists live inputs
- [ ] `hscf_check_live_input_status` — checks live input statuses
- [ ] `hscf_delete_output` — deletes output
- [ ] `hscf_create_output` — creates output
- [ ] `hscf_toggle_output` — toggles output
- [ ] `hscf_create_download` — creates MP4 download
- [ ] `hscf_check_download_status` — checks download
- [ ] `hscf_delete_recording` — deletes recording
- [ ] `get_video_files` — lists streamer videos
- [ ] `start_placeholderstream` — starts placeholder stream
- [ ] `stop_placeholderstream` — stops placeholder stream
- [ ] `check_stream_state` — checks stream state
- [ ] All AJAX calls return 403 on missing nonce
- [ ] All AJAX calls return 403 on missing manage_options capability

## 6. Backward Compatibility (child theme calls)
- [ ] `hs_register_cf_webhook()` — returns ['secret' => ...] or ['error' => ...]
- [ ] `hs_delete_cf_webhook()` — returns ['status' => int, 'body' => ...]
- [ ] `hs_list_cf_webhooks()` — returns array of webhook configs
- [ ] `hs_compute_server_live_state($input_id)` — returns bool (from transient)
- [ ] Player page renders with `?live=true&inputId=...` — no PHP errors

## 7. Admin Notices
- [ ] Webhook secret notice appears when HSCF_webhook_secret is empty
- [ ] Notice disappears after webhook is registered

## 8. Script Enqueuing
- [ ] `hscf-admin.js` loaded on admin pages
- [ ] `hscf-admin.js` loaded on frontend for admin users
- [ ] `hscf_ajax` global available (ajax_url, nonce)

## 9. Dead Code Removal
- [ ] `fetch_current_video_uid()` not defined (deleted)
- [ ] `ajax_fetch_current_video_uid()` not defined (deleted)
- [ ] `hs_unregister_cf_webhook()` not defined (deleted)
- [ ] `tus-js-client` reference removed
- [ ] `celebration-child/js/old/cloudflare_player.js` deleted
- [ ] `cloudflare_debug.log` excluded from git (via *.log)

## 10. RTMPS Allowlist (B4.7)
- [ ] `rtmps://live.hitchstream.com:443/live/xxx` — allowed
- [ ] `rtmps://liveu.liveu.tv:443/xxx` — allowed
- [ ] `rtmps://evil.com/xxx` — rejected (error log + message)
- [ ] `http://live.hitchstream.com/xxx` — rejected (wrong scheme)
- [ ] `wss://live.hitchstream.com/xxx` — allowed
