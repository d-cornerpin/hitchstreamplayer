# WP Easy Pay — our customizations (captured 2026-07-13)

Live site runs wp-easy-pay 4.0.4 with exactly these local modifications
(verified via `wp plugin verify-checksums`; everything else is stock):

| File | Changed lines vs stock 4.0.4 |
|---|---|
| assets/frontend/css/single_page.css | ~69 |
| views/frontend/donation_payment_form.php | ~41 |
| views/frontend/amount_layouts/amount_custom.php | ~18 |
| views/frontend/simple_payment_form.php | ~50 |
| views/frontend/amount_layouts/BUamount_custom.php | added (backup copy of amount_custom) |

`files/` holds the full customized files as they exist on prod.
`*.diff` are unified diffs against pristine 4.0.4 (what to re-apply).

**Upgrading the plugin (4.0.4 → newer):** update, then re-apply the diffs to
the new versions of these 4 files (they are frontend templates/CSS, so expect
minor conflicts, not logic rewrites). A WordPress CORE update never touches
these files — only a wp-easy-pay plugin update does.
