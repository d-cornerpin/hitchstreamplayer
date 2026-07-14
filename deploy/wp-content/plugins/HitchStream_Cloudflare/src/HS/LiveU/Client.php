<?php
/**
 * LiveU Solo API client (unofficial portal API).
 *
 * PHP port of the proven liveu-solo-test harness. Talks to the Solo web-portal
 * backend (lu-central.liveu.tv) with the portal login flow: HTTP Basic → bearer
 * token, cached in a transient, re-auth on 401. The auth is isolated in
 * login()/token() so the official LU-Central token_grant flow can replace just
 * those two methods when official credentials arrive.
 *
 * Every request returns ['status'=>int, 'json'=>mixed, 'raw'=>string, 'base'=>str].
 * Parsing is intentionally loose: the portal host may answer either the bare
 * gateway shape or a {data:{...}} envelope, so the parse* helpers tolerate both.
 */

namespace HS\LiveU;

use HS\Config;

class Client {

    const APP_ID    = 'SlZ3SHqiqtYJRkF0zO';
    const LOGIN_URL = 'https://solo-api.liveu.tv/v1_prod/zendesk/userlogin';
    const RETURN_TO = 'https://solo.liveu.tv/#/dashboard/units';
    const API_V0    = 'https://lu-central.liveu.tv/luc/luc-core-web/rest/v0';
    const API_V2    = 'https://lu-central.liveu.tv/luc/luc-core-web/rest/v2';
    const TOKEN_TRANSIENT = 'hs_liveu_token';

    private string $email;
    private string $password;

    public function __construct(?string $email = null, ?string $password = null) {
        $this->email    = $email    ?? Config::liveuEmail();
        $this->password = $password ?? Config::liveuPassword();
    }

    // ── auth (the swappable layer) ─────────────────────────────────────────

    /** Cached bearer token, refreshed on demand. */
    private function token(bool $force = false): string {
        if (!$force) {
            $cached = get_transient(self::TOKEN_TRANSIENT);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        return $this->login();
    }

    /** Portal login: Basic auth + x-user-name → bearer token. */
    private function login(): string {
        if ($this->email === '' || $this->password === '') {
            throw new \RuntimeException('LiveU credentials are not configured.');
        }
        $resp = wp_remote_post(self::LOGIN_URL, [
            'headers' => [
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Content-Type'    => 'application/json;charset=UTF-8',
                'Authorization'   => 'Basic ' . base64_encode($this->email . ':' . $this->password),
                // email concatenated with a fresh uuid, per the portal flow.
                'x-user-name'     => $this->email . wp_generate_uuid4(),
            ],
            'body'    => wp_json_encode(['return_to' => self::RETURN_TO]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) {
            throw new \RuntimeException('LiveU login failed: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $token   = $data['data']['response']['access_token'] ?? '';
        $expires = (int) ($data['data']['response']['expires_in'] ?? 3600);
        if ($token === '') {
            $msg = $data['errors'][0]['message'] ?? ('HTTP ' . $code);
            throw new \RuntimeException('LiveU login rejected (' . $msg . ').');
        }
        // Cache slightly short of expiry so we never present a stale token.
        set_transient(self::TOKEN_TRANSIENT, $token, max(60, $expires - 120));
        return $token;
    }

    /** Drop the cached token (e.g. after a credential change). */
    public static function forgetToken(): void {
        delete_transient(self::TOKEN_TRANSIENT);
    }

    // ── transport ──────────────────────────────────────────────────────────

    /** One authenticated request. Re-auths once on a 401. */
    private function req(string $method, string $url, $payload = null, bool $retried = false): array {
        $headers = [
            'Authorization'  => 'Bearer ' . $this->token(),
            'application-id' => self::APP_ID,
            'Accept'         => 'application/json, text/plain, */*',
        ];
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 15];
        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json;charset=UTF-8';
            $args['body'] = wp_json_encode($payload);
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return ['status' => 0, 'json' => null, 'raw' => $resp->get_error_message(), 'base' => ''];
        }
        $status = (int) wp_remote_retrieve_response_code($resp);
        if ($status === 401 && !$retried) {
            $this->token(true); // force re-auth, then retry once
            return $this->req($method, $url, $payload, true);
        }
        $raw = wp_remote_retrieve_body($resp);
        return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw, 'base' => ''];
    }

    /** Try the v2 base, fall back to v0 only on a 404. */
    private function v2first(string $method, string $path, $payload = null): array {
        $r = $this->req($method, self::API_V2 . $path, $payload);
        if ($r['status'] !== 404) { $r['base'] = 'v2'; return $r; }
        $r = $this->req($method, self::API_V0 . $path, $payload);
        $r['base'] = 'v0';
        return $r;
    }

    // ── endpoints: read ──────────────────────────────────────────────────────

    public function listInventories(): array { return $this->v2first('GET', '/inventories'); }

    public function listUnits(int $invDbId): array {
        return $this->v2first('GET', "/inventories/{$invDbId}/units?offset=0&limit=1000");
    }

    public function getUnitStatus(string $uid): array {
        return $this->v2first('GET', '/units/' . rawurlencode($uid) . '/status');
    }

    public function listStreamProviders(): array {
        return $this->v2first('GET', '/streamProviders?offset=0&limit=500&searchKey=domain&searchValue=solo');
    }

    public function listProviderProfiles($providerId): array {
        return $this->v2first('GET', "/streamProviders/{$providerId}/streamMediaProfiles");
    }

    public function listDestinations(int $invDbId): array {
        return $this->v2first('GET', "/inventories/{$invDbId}/destinations");
    }

    public function getDestinationDetail(int $invDbId, $destId): array {
        return $this->v2first('GET', "/inventories/{$invDbId}/destinations/{$destId}");
    }

    public function selectedDestination(string $uid): array {
        return $this->v2first('GET', '/units/' . rawurlencode($uid) . '/destinations/selected');
    }

    /** The unit's currently selected bonding zone — body carries {alias:"..."}. */
    public function selectedChannel(string $uid): array {
        return $this->v2first('GET', '/units/' . rawurlencode($uid) . '/channels/selected');
    }

    public function listZones(string $uid): array {
        return $this->v2first('GET', '/units/' . rawurlencode($uid) . '/selectableChannelsLight');
    }

    // v0 telemetry (only meaningful while the unit is online/streaming)
    public function getInterfaces(string $uid): array {
        return $this->req('GET', self::API_V0 . '/units/' . rawurlencode($uid) . '/status/interfaces');
    }
    public function getBattery(string $uid): array {
        return $this->req('GET', self::API_V0 . '/units/' . rawurlencode($uid) . '/status/battery');
    }
    public function getVideo(string $uid): array {
        return $this->req('GET', self::API_V0 . '/units/' . rawurlencode($uid) . '/status/video');
    }

    // ── endpoints: write ─────────────────────────────────────────────────────

    public function createDestination(array $body): array {
        return $this->v2first('POST', '/destinations', $body);
    }

    public function updateDestination($destId, array $body): array {
        return $this->v2first('PUT', "/destinations/{$destId}", $body);
    }

    public function setUnitDestination(string $uid, int $destId): array {
        return $this->v2first('PUT', '/units/' . rawurlencode($uid) . '/destinations/selected',
            ['solo' => ['destination' => $destId]]);
    }

    public function setZone(string $uid, string $alias): array {
        return $this->v2first('PUT', '/units/' . rawurlencode($uid) . '/channels/selected',
            ['alias' => $alias]);
    }

    /**
     * LRT on/off, via the portal's own unit endpoint: selected_channel is a
     * region alias to bond (LRT on) or "" to go direct (LRT off). The body MUST
     * be wrapped in "unit" (same shape as set_delay). This is byte-for-byte what
     * the Solo portal's LRT toggle sends: PUT /v0/units/{uid} {unit:{selected_channel}}.
     */
    public function setSelectedChannel(string $uid, string $channel): array {
        return $this->req('PUT', self::API_V0 . '/units/' . rawurlencode($uid),
            ['unit' => ['selected_channel' => $channel]]);
    }

    public function startStream(string $uid): array {
        return $this->req('POST', self::API_V0 . '/units/' . rawurlencode($uid) . '/stream',
            ['unit_id' => $uid]);
    }

    public function stopStream(string $uid): array {
        return $this->req('DELETE', self::API_V0 . '/units/' . rawurlencode($uid) . '/stream');
    }

    // ── loose parsers (bare list OR {data:{<key>:[...]}} envelope) ────────────

    public static function unwrapList($value, string ...$keys): array {
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        if (is_array($value)) {
            $data = $value['data'] ?? $value;
            if (is_array($data) && array_is_list($data)) {
                return $data;
            }
            if (is_array($data)) {
                foreach ($keys as $k) {
                    if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
                }
            }
            foreach ($keys as $k) {
                if (isset($value[$k]) && is_array($value[$k])) return $value[$k];
            }
        }
        return [];
    }

    /** Unwrap a single object from a bare {...} or {data:{...}} response body. */
    public static function unwrapObj($value): array {
        if (is_array($value)) {
            if (isset($value['data']) && is_array($value['data']) && !array_is_list($value['data'])) {
                return $value['data'];
            }
            return $value;
        }
        return [];
    }

    public static function parseInventories(array $r): array { return self::unwrapList($r['json'] ?? null, 'inventories'); }
    public static function parseUnits(array $r): array       { return self::unwrapList($r['json'] ?? null, 'units'); }
    public static function parseProviders(array $r): array   { return self::unwrapList($r['json'] ?? null, 'streamProviders', 'providers'); }
    public static function parseDestinations(array $r): array{ return self::unwrapList($r['json'] ?? null, 'destinations'); }
    public static function parseZones(array $r): array       { return self::unwrapList($r['json'] ?? null, 'selectableChannels', 'channels', 'zones'); }

    public static function invDbId(array $inv) { return $inv['dbId'] ?? $inv['db_id'] ?? $inv['id'] ?? null; }
    public static function unitUid(array $u)   { return $u['uid'] ?? $u['id'] ?? $u['BOSSID'] ?? null; }
    public static function unitAlias(array $u) { return $u['alias'] ?? $u['name'] ?? null; }
    public static function destId(array $d)    { return $d['id'] ?? $d['dbId'] ?? null; }
}
