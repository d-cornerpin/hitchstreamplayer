<?php
/**
 * RecordingsService — wraps CloudflareClient for recordings list/download/delete.
 */

namespace HS\Services;

use HS\CloudflareClient;
use HS\Config;

class RecordingsService {

    private CloudflareClient $client;

    public function __construct(?CloudflareClient $client = null) {
        $this->client = $client ?? new CloudflareClient(Config::cloudflareAccountId());
    }

    /**
     * Find videos matching a stream name, enriching with MP4 download info.
     * Paginates through all Cloudflare videos.
     */
    public function findByStreamName(string $stream_name): array {
        $all_videos = $this->listAll();

        $filtered = [];
        foreach ($all_videos as $video) {
            if (!isset($video['meta']['name']) || stripos($video['meta']['name'], $stream_name) === false) {
                continue;
            }

            $dl_result = $this->client->get("stream/{$video['uid']}/downloads");
            if ($dl_result['success']) {
                $dl_data = json_decode($dl_result['body'], true);
                if (($dl_data['success'] ?? false)) {
                    foreach ($dl_data['result'] ?? [] as $download) {
                        if (isset($download['url']) && strpos($download['url'], '.mp4') !== false) {
                            $video['mp4_download_url'] = $download['url'];
                            break;
                        }
                    }
                }
            }

            $filtered[] = $video;
        }

        return $filtered;
    }

    /** List all uploaded/recorded videos, as associative arrays. Pagination is
     *  defensive: Cloudflare's /stream endpoint ignores ?page, so we dedup by
     *  uid and stop as soon as a page adds nothing new (or returns a short
     *  page), with a hard cap to guarantee termination. */
    public function listAll(): array {
        $all = [];
        $seen = [];
        for ($page = 1; $page <= 50; $page++) {
            $result = $this->client->listVideos(null, $page, 100);
            $data = json_decode($result['body'], true);
            $batch = ($result['success'] && ($data['success'] ?? false)) ? ($data['result'] ?? []) : [];
            if (empty($batch)) {
                break;
            }
            $added = 0;
            foreach ($batch as $v) {
                $uid = $v['uid'] ?? null;
                if ($uid !== null && !isset($seen[$uid])) {
                    $seen[$uid] = true;
                    $all[] = $v;
                    $added++;
                }
            }
            if ($added === 0 || count($batch) < 100) {
                break;
            }
        }
        return $all;
    }

    /** Create a download for a recording by UID. */
    public function createDownload(string $video_id): array {
        return $this->client->post("stream/{$video_id}/downloads");
    }

    /** Check download status for a recording by UID. */
    public function checkDownload(string $video_id): array {
        $result = $this->client->get("stream/{$video_id}/downloads");
        $data = json_decode($result['body'], true);

        if ($result['success'] && !empty($data['result'])) {
            foreach ($data['result'] as $download) {
                if (!is_array($download) || empty($download['url'])) {
                    continue;
                }
                $status  = $download['status'] ?? '';
                $percent = isset($download['percentComplete']) && is_numeric($download['percentComplete'])
                    ? (float) $download['percentComplete'] : null;
                // Cloudflare keeps the .mp4 URL stable; only trust it once ready.
                if ($status === 'ready' && strpos($download['url'], '.mp4') !== false) {
                    return ['success' => true, 'download_url' => $download['url'], 'status' => 'ready', 'percent' => 100.0];
                }
                return ['success' => false, 'status' => $status ?: 'inprogress', 'percent' => $percent];
            }
        }
        return ['success' => false, 'status' => 'unknown', 'percent' => null];
    }

    /** Delete a recording by UID. */
    public function delete(string $video_id): array {
        return $this->client->delete("stream/{$video_id}");
    }
}
