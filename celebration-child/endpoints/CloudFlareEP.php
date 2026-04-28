<?php
/**
 * CloudFlareEP.php — DEPRECATED.
 *
 * This endpoint previously proxied authenticated requests to the Cloudflare
 * Stream API using legacy X-Auth-Email + X-Auth-Key credentials. Per plan
 * Appendix B and §B0.2, the dangerous default pass-through was removed and
 * the live-state action was superseded by:
 *
 *   - The lightweight polling endpoint at /endpoints/live-state.php (theme)
 *     which now redirects to the WP REST route hitchstream/v1/live-state
 *     (plugin: HS\LiveState\Endpoint).
 *   - The unauthenticated Cloudflare /lifecycle endpoint, used by the live-
 *     state handler on cache miss.
 *
 * Nothing inside the codebase calls this file. It is kept only to return a
 * stable 410 to any stale external caller (e.g. a forgotten dashboard).
 * The legacy X-Auth-Key credential surface has been removed entirely.
 */

require_once __DIR__ . '/../../../../wp-load.php';

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'error'        => 'Endpoint deprecated.',
    'replacement'  => '/wp-json/hitchstream/v1/live-state?inputId=…',
    'documentation' => 'See docs/configuration.md for the migration path.',
]);
exit;
