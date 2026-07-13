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

---

## UPDATE 2026-07-13 (later): customizations re-homed to the child theme

The 4.5.1 update (tested on the upgrade-rehearsal clone) wiped these edits
and renamed 3 of the 4 files (underscores → hyphens). The customizations were
**re-implemented update-proof in the child theme** instead of re-hacking:

- `celebration-child/css/wpep-overrides.css` — all styling (buttons, headings,
  opacity, amount-picker hiding)
- `celebration-child/js/wpep-overrides.js` — functional bits (hidden `acct`
  field from `?acct=`, heading/success wording, stray-USD trim)

The plugin should stay **checksum-pristine** from now on (`wp plugin
verify-checksums wp-easy-pay` = clean). This archive remains as the
historical record of the original 4.0.4 in-plugin edits.
