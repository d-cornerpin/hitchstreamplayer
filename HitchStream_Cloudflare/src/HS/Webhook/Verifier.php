<?php
/**
 * Cloudflare webhook signature verifier.
 *
 * Supports both the Cloudflare Notifications format (t=<ts>,v1=<hmac>)
 * and plain HMAC over raw body. Auto-detects the format from the header content.
 */

namespace HS\Webhook;

class Verifier
{
    /**
     * Verify a webhook signature.
     *
     * @param string $header   The signature header value (CF-Webhook-Signature or equivalent).
     * @param string $body     The raw POST body.
     * @param string $secret   The webhook secret.
     * @param int    $maxAge   Maximum age in seconds for replay protection (default 300 = 5 min).
     * @return bool
     */
    public static function verify(string $header, string $body, string $secret, int $maxAge = 300): bool
    {
        if (!$secret) {
            return false;
        }

        if (!$header) {
            return false;
        }

        // Detect format: Cloudflare Notifications uses "t=<ts>,v1=<hmac>"
        // Cloudflare Stream may use plain HMAC directly.
        if (preg_match('/^t=(\d+),v1=([a-fA-F0-9]{64})$/', $header, $matches)) {
            $timestamp = (int) $matches[1];
            $signature = $matches[2];

            // Replay protection
            if (abs(time() - $timestamp) > $maxAge) {
                return false;
            }

            // HMAC over "<timestamp>.<body>"
            $payload = "{$timestamp}.{$body}";
            $expected = hash_hmac('sha256', $payload, $secret);
            return hash_equals($expected, $signature);
        }

        // Fallback: plain HMAC over raw body
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $header);
    }
}
