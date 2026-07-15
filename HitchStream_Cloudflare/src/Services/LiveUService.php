<?php
/**
 * LiveUService — high-level LiveU Solo operations for the admin control panel.
 *
 * Wraps HS\LiveU\Client with the workflow the "LiveU" section needs: point a
 * Cloudflare live input at the Solo (find-or-create destination → select →
 * force the USA_San_Jose zone), verify with 100% certainty which stream the
 * unit is armed for, aggregate battery/network/stream telemetry, and start/stop.
 */

namespace HS\Services;

use HS\LiveU\Client;
use HS\Config;

class LiveUService {

    /** Every HitchStream destination is Generic-provider, 1080p30. */
    const PROVIDER = 'Generic';
    const PROFILE  = '1920 x 1080 Widescreen (16:9) 30 fps';
    const INV_TRANSIENT = 'hs_liveu_inv_dbid';

    private Client $client;

    public function __construct(?Client $client = null) {
        $this->client = $client ?? new Client();
    }

    // ── inventory / units ────────────────────────────────────────────────────

    /** The account inventory dbId (cached — it never changes). 0 on failure. */
    public function inventoryDbId(): int {
        $cached = get_transient(self::INV_TRANSIENT);
        if (is_numeric($cached) && (int) $cached > 0) return (int) $cached;
        $invs = Client::parseInventories($this->client->listInventories());
        $id = $invs ? (int) Client::invDbId($invs[0]) : 0;
        if ($id > 0) set_transient(self::INV_TRANSIENT, $id, 12 * HOUR_IN_SECONDS);
        return $id;
    }

    /**
     * Throw a descriptive error when a portal response is a transport failure
     * (status 0 = never reached the host) or an HTTP error. The Client parsers
     * are deliberately loose and turn any of those into empty lists — fine for
     * telemetry, but for the units/inventory calls "empty" must mean genuinely
     * empty, not "the unofficial API is down", or the UI lies ("No units found")
     * exactly when the operator most needs to know the connection is broken.
     */
    private function ensureOk(array $r, string $what): array {
        $status = (int) ($r['status'] ?? 0);
        if ($status === 0) {
            $why = trim((string) ($r['raw'] ?? '')) ?: 'network error';
            throw new \RuntimeException("could not reach the LiveU portal while {$what} — " . mb_substr($why, 0, 160));
        }
        if ($status >= 400) {
            throw new \RuntimeException("the LiveU portal answered HTTP {$status} while {$what} — the credentials may be wrong, or the (unofficial) API may have changed.");
        }
        return $r;
    }

    /**
     * Units as a flat list for the picker: [{uid, alias, availability}].
     * Throws (via ensureOk / Client::login) when the portal is unreachable or
     * rejects us, so callers can tell "no units" apart from "no connection".
     */
    public function units(): array {
        $inv = $this->inventoryDbId();
        if (!$inv) {
            // Distinguish "portal unreachable / API error" from "empty account":
            // re-probe the inventory call and classify the failure.
            $this->ensureOk($this->client->listInventories(), 'listing inventories');
            return []; // portal answered fine — the account really has no inventory
        }
        $out = [];
        $r = $this->ensureOk($this->client->listUnits($inv), 'listing units');
        foreach (Client::parseUnits($r) as $u) {
            $uid = Client::unitUid($u);
            if (!$uid) continue;
            $out[] = [
                'uid'          => $uid,
                'alias'        => Client::unitAlias($u) ?: $uid,
                'availability' => $u['availability'] ?? $u['status'] ?? 'unknown',
            ];
        }
        return $out;
    }

    // ── set a Cloudflare input as the Solo destination ───────────────────────

    /**
     * Ensure a destination exists for this Cloudflare input (find by stream key,
     * else create; update its URL if drifted), select it on the unit, and force
     * the configured bonding zone. Returns a structured result including the
     * verification so the UI can show its green check.
     */
    public function setInputAsDestination(string $unitUid, string $inputName, string $rtmpUrl, string $streamKey): array {
        if ($streamKey === '' || $rtmpUrl === '') {
            return ['success' => false, 'message' => 'This live input has no RTMP URL/key to send.'];
        }
        $inv = $this->inventoryDbId();
        if (!$inv) {
            return ['success' => false, 'message' => 'Could not read the LiveU inventory (check credentials).'];
        }

        $name = $inputName !== '' ? $inputName : ('HitchStream ' . substr($unitUid, -6));

        // 1) Find-or-create a destination keyed by this input's stream key.
        $existing = $this->findDestinationByKey($inv, $streamKey);
        $created = false;
        if ($existing) {
            $destId = (int) Client::destId($existing);
            // Repair drift (URL/name) in place — edit is supported for these.
            $needsFix = ($existing['streamingIngest']['primaryUrl'] ?? '') !== $rtmpUrl
                     || ($existing['name'] ?? '') !== $name;
            if ($needsFix) {
                $body = $existing;
                $body['name'] = $name;
                $body['streamingIngest']['primaryUrl'] = $rtmpUrl;
                $r = $this->client->updateDestination($destId, $body);
                if (!$this->ok($r)) {
                    return ['success' => false, 'message' => 'Found the destination but could not update it (HTTP ' . $r['status'] . ').'];
                }
            }
        } else {
            $r = $this->client->createDestination($this->destinationBody($inv, $name, $rtmpUrl, $streamKey));
            if (!$this->ok($r)) {
                return ['success' => false, 'message' => 'Could not create the destination (HTTP ' . $r['status'] . ').'];
            }
            $destId = (int) Client::destId(Client::unwrapObj($r['json']));
            $created = true;
            if (!$destId) {
                return ['success' => false, 'message' => 'Destination created but no id was returned.'];
            }
        }

        // 2) Select it on the unit. (LRT/zone is deliberately NOT touched here —
        //    it's an independent control, since some streams shouldn't be bonded.)
        $sel = $this->client->setUnitDestination($unitUid, $destId);
        if (!$this->ok($sel)) {
            return ['success' => false, 'message' => 'Could not select the destination on the unit (HTTP ' . $sel['status'] . ').'];
        }

        // 3) Verify (selection propagates asynchronously — poll briefly).
        $verify = $this->verifySelected($unitUid, $streamKey, $destId);

        return [
            'success'        => true,
            'created'        => $created,
            'destination_id' => $destId,
            'verified'       => $verify['verified'],
            'verify'         => $verify,
            'message'        => $created ? 'Created and armed the destination.' : 'Armed the existing destination.',
        ];
    }

    /**
     * Turn LRT (LiveU Reliable Transport) on or off for a unit — independent of
     * arming a destination. On pins the configured bonding zone (USA_San_Jose);
     * off clears the channel (direct RTMP, no bonding). This is the portal's own
     * toggle: PUT /units/{uid} {unit:{selected_channel: region|""}}.
     */
    public function setLrt(string $unitUid, bool $on): array {
        $zone = Config::liveuZone();
        $r = $this->client->setSelectedChannel($unitUid, $on ? $zone : '');
        if (!$this->ok($r)) {
            return ['success' => false, 'message' => 'LiveU rejected the LRT change (HTTP ' . $r['status'] . ').'];
        }
        return [
            'success' => true,
            'lrt_on'  => $on,
            'zone'    => $on ? $zone : null,
            'message' => $on ? ('LRT on — bonding via ' . $zone . '.') : 'LRT off — direct RTMP (no bonding).',
        ];
    }

    /** Find an existing destination whose override stream key matches. */
    private function findDestinationByKey(int $inv, string $streamKey): ?array {
        foreach (Client::parseDestinations($this->client->listDestinations($inv)) as $d) {
            $k = $d['streamingDestinationOverrides'][0]['streamId'] ?? '';
            if ($k !== '' && hash_equals((string) $k, $streamKey)) return $d;
        }
        return null;
    }

    /** The exact create body a Generic/1080p30 Cloudflare destination needs. */
    private function destinationBody(int $inv, string $name, string $rtmpUrl, string $streamKey): array {
        return [
            'name'              => $name,
            'inventoryId'       => $inv,
            'type'              => 'stream',
            'streamingProvider' => self::PROVIDER,
            'externalId'        => wp_generate_uuid4(), // UUID, like the portal
            'streamingDestinationOverrides' => [[
                'streamingProfile'    => self::PROFILE,
                'streamId'            => $streamKey,
                'minResolutionOverride' => '', 'maxResolutionOverride' => '',
                'minFpsOverride' => null, 'maxFpsOverride' => null,
                'minBitrateOverride' => null, 'maxBitrateOverride' => null,
                'audioBitrateOverride' => null,
            ]],
            'streamingIngest' => [
                'primaryUsername' => '', 'primaryPassword' => '',
                'primaryUrl'      => $rtmpUrl,
                'secondaryUrl'    => '',
                'streamingSrt'    => null,
            ],
        ];
    }

    // ── the safety check ─────────────────────────────────────────────────────

    /**
     * Confirm — with 100% certainty — that the unit is armed for exactly this
     * Cloudflare input, by matching the SELECTED destination's stream key to the
     * input's key. Polls a few times because selection propagates asynchronously.
     */
    public function verifySelected(string $unitUid, string $streamKey, ?int $expectId = null): array {
        $inv = $this->inventoryDbId();
        $selId = null; $detail = null;
        for ($i = 0; $i < 4; $i++) {
            $sel = $this->client->selectedDestination($unitUid);
            $selId = $sel['json']['solo']['destination'] ?? null;
            if ($selId !== null && ($expectId === null || (int) $selId === $expectId)) break;
            usleep(700000); // 0.7s
        }
        if ($selId === null) {
            return ['verified' => false, 'reason' => 'Could not read the unit’s selected destination.'];
        }
        $detail = Client::unwrapObj($this->client->getDestinationDetail($inv, $selId)['json']);
        $selKey  = $detail['streamingDestinationOverrides'][0]['streamId'] ?? '';
        $selUrl  = $detail['streamingIngest']['primaryUrl'] ?? '';
        $matches = $selKey !== '' && hash_equals((string) $selKey, $streamKey);
        return [
            'verified'      => $matches,
            'selected_id'   => (int) $selId,
            'selected_name' => $detail['name'] ?? '',
            'selected_url'  => $selUrl,
            'key_tail'      => $selKey !== '' ? substr((string) $selKey, -6) : '',
            'reason'        => $matches ? 'Armed for this stream.' : 'Selected destination does NOT match this stream.',
        ];
    }

    // ── telemetry ────────────────────────────────────────────────────────────

    /** Aggregate battery + networks + stream state for the status panel. */
    public function statusFor(string $unitUid): array {
        $statusResp = $this->client->getUnitStatus($unitUid);
        // Transport failure (status 0) = the portal itself is unreachable — throw
        // so the UI can alert, instead of rendering it as a powered-off unit.
        // HTTP error codes stay tolerated: telemetry endpoints legitimately
        // error for offline units, and the loose parsers render that as "off".
        if ((int) ($statusResp['status'] ?? 0) === 0) {
            $why = trim((string) ($statusResp['raw'] ?? '')) ?: 'network error';
            throw new \RuntimeException('could not reach the LiveU portal for unit status — ' . mb_substr($why, 0, 160));
        }
        $status = Client::unwrapObj($statusResp['json']);
        $battery = $status['battery'] ?? Client::unwrapObj($this->client->getBattery($unitUid)['json']);
        $video   = Client::unwrapObj($this->client->getVideo($unitUid)['json']);
        $ifaces  = Client::unwrapList($this->client->getInterfaces($unitUid)['json'] ?? null, 'interfaces');
        $chan    = Client::unwrapObj($this->client->selectedChannel($unitUid)['json']);

        $availability = $status['availability'] ?? 'unknown';
        $upKbps = (int) ($status['totalUplinkKbps'] ?? 0);
        $bitrate = $video['bitrate'] ?? null;
        $streaming = ($bitrate !== null && (int) $bitrate > 0) || $upKbps > 0;

        $networks = [];
        foreach ($ifaces as $i) {
            if (!is_array($i)) continue;
            $nm = $i['name'] ?? '';
            if ($nm === 'No Device') continue; // empty modem slots — skip noise
            $networks[] = [
                'name'      => $nm,
                'connected' => (bool) ($i['connected'] ?? false),
                'kbps'      => (int) ($i['kbps'] ?? 0),
                'quality'   => (int) ($i['signalQuality'] ?? 0),
                'tech'      => $i['technology'] ?? '',
            ];
        }

        return [
            'availability' => $availability,
            'zone'         => $chan['alias'] ?? null,
            'online'       => $availability === 'online' || $availability === 'idle',
            'streaming'    => $streaming,
            'stream_state' => $streaming ? 'streaming' : ($availability === 'online' ? 'ready' : $availability),
            'up_kbps'      => $upKbps,
            'resolution'   => $video['resolution'] ?? null,
            'bitrate'      => $bitrate,
            'battery'      => [
                'connected'   => (bool) ($battery['connected'] ?? false),
                'percentage'  => isset($battery['percentage']) ? (int) $battery['percentage'] : null,
                'runtime_min' => isset($battery['runTimeToEmpty']) ? (int) $battery['runTimeToEmpty'] : null,
                'charging'    => (bool) ($battery['charging'] ?? false),
            ],
            'networks'     => $networks,
        ];
    }

    public function start(string $unitUid): array {
        $r = $this->client->startStream($unitUid);
        return ['success' => $r['status'] === 201 || $this->ok($r), 'status' => $r['status'],
                'message' => ($r['status'] === 201 || $this->ok($r)) ? 'Stream start requested.' : 'Start failed (HTTP ' . $r['status'] . ').'];
    }

    public function stop(string $unitUid): array {
        $r = $this->client->stopStream($unitUid);
        return ['success' => $r['status'] === 204 || $this->ok($r), 'status' => $r['status'],
                'message' => ($r['status'] === 204 || $this->ok($r)) ? 'Stream stop requested.' : 'Stop failed (HTTP ' . $r['status'] . ').'];
    }

    private function ok(array $r): bool {
        return $r['status'] >= 200 && $r['status'] < 300;
    }
}
