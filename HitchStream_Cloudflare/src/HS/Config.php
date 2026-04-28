<?php
/**
 * Config — typed accessor for every HitchStream WP option.
 *
 * Throws HS\ConfigError when a required option is missing.
 * NO silent fallback to hardcoded values (e.g. juu1r5es4cbffqjf).
 */

namespace HS;

class ConfigError extends \RuntimeException {}

class Config {

    /** @var array<string, string> Cache for get_option calls. */
    private static array $cache = [];

    /**
     * Get a required option. Throws ConfigError if missing or empty.
     */
    public static function required(string $key): string {
        self::load_option($key);
        $val = self::$cache[$key] ?? '';
        if ($val === '' || $val === null) {
            throw new ConfigError("Required option '{$key}' is not configured.");
        }
        return $val;
    }

    /**
     * Get an optional option. Returns default if missing.
     */
    public static function optional(string $key, string $default = ''): string {
        self::load_option($key);
        return self::$cache[$key] ?? $default;
    }

    // ── Required accessors ─────────────────────────────────────────

    public static function cloudflareEmail(): string {
        return self::required('HSCF_cloudflare_email');
    }

    public static function cloudflareApiKey(): string {
        return self::required('HSCF_cloudflare_api_key');
    }

    public static function cloudflareAccountId(): string {
        return self::required('HSCF_cloudflare_account_id');
    }

    public static function webhookSecret(): string {
        return self::required('HSCF_webhook_secret');
    }

    // ── Optional accessors ─────────────────────────────────────────

    public static function cloudflareApiToken(): string {
        return self::optional('HSCF_cloudflare_api_token', '');
    }

    public static function customerId(): string {
        return self::optional('HSCF_customer_id', '');
    }

    public static function posterInitial(): string {
        return self::optional('HSCF_poster_initial', '');
    }

    public static function posterIdle(): string {
        return self::optional('HSCF_poster_idle', '');
    }

    public static function posterFatal(): string {
        return self::optional('HSCF_poster_fatal', '');
    }

    public static function streamerApiUrl(): string {
        return self::optional('HSCF_streamer_api_url', 'https://streamer1.hitchstream.com');
    }

    public static function streamerApiKey(): string {
        return self::optional('HSCF_streamer_api_key', '');
    }

    public static function alertEmail(): string {
        return self::optional('HSCF_alert_email', '');
    }

    public static function alertCodes(): string {
        return self::optional('HSCF_alert_codes', 'ERR_STORAGE_QUOTA_EXHAUSTED,ERR_MISSING_SUBSCRIPTION');
    }

    // ── Internal ───────────────────────────────────────────────────

    private static function load_option(string $key): void {
        if (isset(self::$cache[$key])) {
            return;
        }
        self::$cache[$key] = get_option($key, '');
    }
}
