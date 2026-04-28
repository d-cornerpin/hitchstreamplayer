<?php
/**
 * Cloudflare webhook shared-secret authenticator.
 *
 * Cloudflare Notifications sends a plain shared-secret token
 * in the cf-webhook-auth header — no HMAC, no timestamp, no replay protection.
 */

namespace HS\Webhook;

class Verifier
{
    /**
     * Verify the webhook shared-secret token.
     *
     * @param string $auth   The cf-webhook-auth header value.
     * @param string $secret The configured secret from WP option.
     * @return bool
     */
    public static function verify(string $auth, string $secret): bool
    {
        if (!$secret) {
            return false;
        }

        if (!$auth) {
            return false;
        }

        return hash_equals($secret, $auth);
    }
}
