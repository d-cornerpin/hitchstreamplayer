<?php
/**
 * Atomic state writer — writes to WordPress transient AND flat-file.
 *
 * B2.2a: Flat-file writes use POSIX atomic rename (write to .tmp, rename).
 * The webhook handler should write to BOTH so the lightweight read path
 * can choose whichever is faster.
 */

namespace HS\LiveState;

class StateWriter
{
    /**
     * Write state to transient (TTL) and flat-file (atomic).
     *
     * @param string $input_id   Cloudflare input ID.
     * @param array  $data       State data (must include 'state' key).
     * @param int    $ttl        Transient TTL in seconds.
     */
    public static function write($input_id, array $data, $ttl = 300)
    {
        // Write transient.
        set_transient("hs_live_state_{$input_id}", $data, $ttl);
        set_transient("hs_webhook_update_ts_{$input_id}", time(), 5);

        // Write flat file atomically (B2.2a).
        self::write_flat_file($input_id, $data);
    }

    /**
     * Write a flat-file atomically: write to .tmp, then rename().
     * POSIX rename() on the same filesystem is atomic — no reader can
     * hit a partially-written file.
     */
    private static function write_flat_file($input_id, $data)
    {
        $dir = WP_CONTENT_DIR . '/hs-state';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $tmp = "{$dir}/{$input_id}.json.tmp";
        $final = "{$dir}/{$input_id}.json";

        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES));
        rename($tmp, $final); // atomic on same filesystem
    }
}
