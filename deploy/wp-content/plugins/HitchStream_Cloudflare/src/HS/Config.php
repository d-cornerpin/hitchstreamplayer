<?php
/**
 * Config — typed accessor for every HitchStream WP option.
 *
 * Throws HS\ConfigError when a required option is missing.
 * NO silent fallback to hardcoded values (e.g. juu1r5es4cbffqjf).
 */

namespace HS;

// ConfigError is declared in its own file at HS/ConfigError.php so the
// autoloader can find it. We require it here so this file can be loaded
// independently (e.g. via direct require_once during the bootstrap, before
// the autoloader is registered).
require_once __DIR__ . '/ConfigError.php';

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

    // ── LiveU Solo (unofficial portal API) ─────────────────────────
    // The Solo web-portal login (same email/password as solo.liveu.tv). Kept
    // optional so a site without a LiveU unit isn't forced to configure it —
    // the LiveU panel simply reports "not configured" instead of throwing.

    public static function liveuEmail(): string {
        return self::optional('HSCF_liveu_email', '');
    }

    public static function liveuPassword(): string {
        return self::optional('HSCF_liveu_password', '');
    }

    public static function liveuConfigured(): bool {
        return self::liveuEmail() !== '' && self::liveuPassword() !== '';
    }

    /** The Solo bonding-server region every HitchStream destination uses.
     *  optional()'s default doesn't apply to a missing option (it caches ''),
     *  so fall back explicitly. */
    public static function liveuZone(): string {
        $z = self::optional('HSCF_liveu_zone', '');
        return $z !== '' ? $z : 'USA_San_Jose';
    }

    public static function streamerApiKey(): string {
        return self::optional('HSCF_streamer_api_key', '');
    }

    public static function alertEmail(): string {
        return self::optional('HSCF_alert_email', '');
    }

    /**
     * The configured alert recipients as a de-duplicated list of valid
     * addresses. The HSCF_alert_email option may hold several, separated by
     * commas/semicolons/whitespace.
     *
     * @return string[]
     */
    public static function alertEmails(): array {
        return self::parseEmailList(self::alertEmail());
    }

    /** Split a free-form string into a list of valid, unique email addresses. */
    public static function parseEmailList(string $raw): array {
        $out = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && is_email($candidate)) {
                $out[] = $candidate;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Which alert events are enabled, as an array of event keys (see
     * SettingsPage::ALERT_EVENTS). Falls back to the two critical defaults, and
     * migrates the legacy comma-separated HSCF_alert_codes option if present.
     *
     * @return string[]
     */
    public static function alertEvents(): array {
        $val = get_option('HSCF_alert_events', null);
        if (is_array($val)) {
            return array_values(array_filter(array_map('strval', $val)));
        }
        // Legacy migration: old installs stored raw error codes as a CSV string.
        $legacy = get_option('HSCF_alert_codes', null);
        if (is_string($legacy) && $legacy !== '') {
            $events = [];
            if (strpos($legacy, 'ERR_STORAGE_QUOTA_EXHAUSTED') !== false) $events[] = 'storage_full';
            if (strpos($legacy, 'ERR_MISSING_SUBSCRIPTION') !== false)    $events[] = 'no_subscription';
            return $events ?: ['storage_full', 'no_subscription'];
        }
        return ['storage_full', 'no_subscription'];
    }

    // ── Internal ───────────────────────────────────────────────────

    private static function load_option(string $key): void {
        if (isset(self::$cache[$key])) {
            return;
        }
        self::$cache[$key] = get_option($key, '');
    }
}
