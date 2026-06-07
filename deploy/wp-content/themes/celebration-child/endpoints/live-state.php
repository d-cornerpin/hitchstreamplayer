<?php
/**
 * Redirect shim for the old live-state.php endpoint.
 *
 * B2: Replaced by WP REST route hitchstream/v1/live-state.
 * This file redirects legacy URLs to the REST endpoint.
 * Keep in place one release so any existing bookmarks/monitors don't 404.
 */

// This file is hit directly over HTTP, so WordPress is not otherwise loaded.
// Without this, rest_url()/wp_safe_redirect() are undefined → fatal 500 on
// every poll. (Matches the sibling cf-live-webhook.php bootstrap.)
require_once __DIR__ . '/../../../../wp-load.php';

$input_id = isset($_GET['inputId']) ? trim($_GET['inputId']) : '';
if ($input_id) {
    $redirect_url = rest_url('hitchstream/v1/live-state') . '?inputId=' . urlencode($input_id);
} else {
    $redirect_url = rest_url('hitchstream/v1/live-state');
}

wp_safe_redirect($redirect_url, 301);
exit;
