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
        $all_videos = [];
        $page = 1;
        do {
            $result = $this->client->listVideos(null, $page, 100);
            $data = json_decode($result['body'], true);
            if (!$result['success'] || !($data['success'] ?? false) || empty($data['result'])) {
                break;
            }
            $all_videos = array_merge($all_videos, $data['result']);
            $page++;
        } while (!empty($data['result']));

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
                if (isset($download['url']) && strpos($download['url'], '.mp4') !== false) {
                    return ['success' => true, 'download_url' => $download['url']];
                }
            }
        }
        return ['success' => false];
    }

    /** Delete a recording by UID. */
    public function delete(string $video_id): array {
        return $this->client->delete("stream/{$video_id}");
    }
}
