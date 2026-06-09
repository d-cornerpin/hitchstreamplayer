<?php
/**
 * OutputMeta — persists the bits Cloudflare doesn't store for a live-input
 * output: a friendly name and which provider it's for (youtube/facebook/rtmp).
 *
 * Keyed by the output's Cloudflare uid in a single wp_option (array map).
 */

namespace HS;

class OutputMeta {

    private const OPTION = 'HSCF_output_meta';

    /** @return array<string,array{name:string,provider:string}> */
    public static function all(): array {
        $v = get_option(self::OPTION, []);
        return is_array($v) ? $v : [];
    }

    /** @return array{name:string,provider:string}|array{} */
    public static function get(string $uid): array {
        $all = self::all();
        return (isset($all[$uid]) && is_array($all[$uid])) ? $all[$uid] : [];
    }

    public static function set(string $uid, string $name, string $provider): void {
        if ($uid === '') return;
        $all = self::all();
        $all[$uid] = ['name' => $name, 'provider' => $provider];
        update_option(self::OPTION, $all, false);
    }

    public static function remove(string $uid): void {
        $all = self::all();
        if (isset($all[$uid])) {
            unset($all[$uid]);
            update_option(self::OPTION, $all, false);
        }
    }

    /** Move stored meta from one uid to another (an output is recreated when its
     *  URL/key is edited, since Cloudflare outputs can't be updated in place). */
    public static function moveUid(string $old, string $new): void {
        if ($old === $new || $new === '') return;
        $all = self::all();
        if (isset($all[$old])) {
            $all[$new] = $all[$old];
            unset($all[$old]);
            update_option(self::OPTION, $all, false);
        }
    }

    /** Infer a provider key from an ingest URL (for legacy outputs with no meta). */
    public static function providerFromUrl(string $url): string {
        $u = strtolower($url);
        if (strpos($u, 'youtube') !== false)  return 'youtube';
        if (strpos($u, 'facebook') !== false || strpos($u, 'fbcdn') !== false) return 'facebook';
        return 'rtmp';
    }
}
