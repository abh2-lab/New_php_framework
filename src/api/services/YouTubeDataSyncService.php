<?php

namespace App\Services;

/**
 * YouTubeDataSyncService
 *
 * Automatically syncs YouTube data for incomplete rows
 * Detects rows with missing data and fetches from YouTube API
 *
 * Flow:
 * 1. Find event_uploads with NULL YouTube data
 * 2. Find youtube_creators with NULL channel_name or channel_id
 * 3. Fetch from YouTube API and update
 * 4. Update creator_stats
 */
class YouTubeDataSyncService
{
    private $conn;
    private $youtubeApiService;
    private $creatorAnalyticsService;
    private $projectService;

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->youtubeApiService = new YouTubeApiService();
        $this->creatorAnalyticsService = new CreatorAnalyticsService($conn);
        $this->projectService = new ProjectService($conn);
    }

    /**
     * Main sync function - Auto-detects incomplete rows and syncs
     *
     * @param string $projectCode Project code (required)
     * @return array Response with sync results
     */
    public function syncYouTubeData(string $projectCode): array
    {
        try {
            if (empty(trim($projectCode))) {
                return [
                    'success' => false,
                    'message' => 'Project code is required',
                    'data' => null,
                    'error' => null
                ];
            }

            // Validate project
            $projectId = $this->projectService->resolveProjectId($projectCode);
            if (!$projectId) {
                return [
                    'success' => false,
                    'message' => 'Project not found',
                    'data' => null,
                    'error' => null
                ];
            }

            $results = [
                'project_code' => $projectCode,
                'project_id' => $projectId,
                'videos_updated' => 0,
                'creators_updated' => 0,
                'stats_updated' => 0,
                'errors' => []
            ];

            // Step 1: Update event_uploads with missing YouTube data
            $videoResults = $this->updateIncompleteVideos($projectId);
            $results['videos_updated'] = $videoResults['updated_count'];
            $results['video_details'] = $videoResults['details'];
            $results['errors'] = array_merge($results['errors'], $videoResults['errors']);

            // Step 2: Update youtube_creators with missing data
            $creatorResults = $this->updateIncompleteCreators($projectId);
            $results['creators_updated'] = $creatorResults['updated_count'];
            $results['creator_details'] = $creatorResults['details'];
            $results['errors'] = array_merge($results['errors'], $creatorResults['errors']);

            // Step 3: Update creator stats
            $statsResults = $this->updateAllProjectCreatorStats($projectId);
            $results['stats_updated'] = $statsResults['updated_count'];

            return [
                'success' => true,
                'message' => 'YouTube data sync completed',
                'data' => $results,
                'error' => null
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update event_uploads rows that have NULL/empty YouTube data
     *
     * @param int $projectId
     * @return array
     */
    private function updateIncompleteVideos(int $projectId): array
    {
        $updatedCount = 0;
        $details = [];
        $errors = [];

        try {
            // Find videos with missing YouTube metadata
            $query = "SELECT id, video_id 
                      FROM event_uploads 
                      WHERE project_id = ? 
                      AND video_id IS NOT NULL 
                      AND (
                          title IS NULL 
                          OR description IS NULL 
                          OR view_count IS NULL
                          OR published_at IS NULL
                      )";

            $videos = RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => [$projectId]
            ]);

            if (empty($videos)) {
                return [
                    'updated_count' => 0,
                    'details' => [],
                    'errors' => []
                ];
            }

            foreach ($videos as $video) {
                try {
                    $videoId = $video['video_id'];

                    if (empty($videoId)) {
                        $errors[] = "Skipping row {$video['id']}: video_id is empty";
                        continue;
                    }

                    // Fetch fresh data from YouTube using existing service method
                    $videoData = $this->youtubeApiService->getVideoDetailsForDb($videoId, 'Asia/Kolkata');

                    if (!$videoData['success'] || empty($videoData['data'])) {
                        $errors[] = "Failed to fetch video data for: {$videoId}";
                        continue;
                    }

                    // Update event_uploads table
                    $updateResult = $this->updateEventUploadRow($projectId, $videoId, $videoData['data']);

                    if ($updateResult['success']) {
                        $updatedCount++;
                        $details[] = [
                            'video_id' => $videoId,
                            'title' => $videoData['data']['title'] ?? 'Unknown',
                            'status' => 'updated'
                        ];
                    }

                } catch (\Throwable $e) {
                    $errors[] = "Error updating video {$videoId}: " . $e->getMessage();
                }
            }

        } catch (\Throwable $e) {
            $errors[] = "Error fetching incomplete videos: " . $e->getMessage();
        }

        return [
            'updated_count' => $updatedCount,
            'details' => $details,
            'errors' => $errors
        ];
    }

    /**
     * Update youtube_creators rows with NULL channel_name or channel_id
     *
     * @param int $projectId
     * @return array
     */
    private function updateIncompleteCreators(int $projectId): array
    {
        $updatedCount = 0;
        $details = [];
        $errors = [];

        try {
            // Find creators with missing data (NULL channel_name OR NULL channel_id)
            $query = "SELECT yc.id, yc.channel_id, yc.channel_handle 
                      FROM youtube_creators yc
                      INNER JOIN creator_project_map cpm ON yc.id = cpm.creator_id
                      WHERE cpm.project_id = ? 
                      AND cpm.is_active = 1
                      AND yc.channel_handle IS NOT NULL
                      AND (
                          yc.channel_name IS NULL 
                          OR yc.channel_id IS NULL
                          OR yc.subscriber_count IS NULL
                      )";

            $creators = RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => [$projectId]
            ]);

            if (empty($creators)) {
                return [
                    'updated_count' => 0,
                    'details' => [],
                    'errors' => []
                ];
            }

            foreach ($creators as $creator) {
                try {
                    $channelHandle = $creator['channel_handle'];

                    if (empty($channelHandle)) {
                        $errors[] = "Skipping creator ID {$creator['id']}: channel_handle is empty";
                        continue;
                    }

                    // Ensure handle starts with @
                    if (!str_starts_with($channelHandle, '@')) {
                        $channelHandle = '@' . $channelHandle;
                    }

                    // Fetch fresh data from YouTube using existing service method
                    $channelData = $this->youtubeApiService->getChannelInfo([
                        'handles' => $channelHandle,
                        'details' => true
                    ]);

                    if (!$channelData['success'] || empty($channelData['data'])) {
                        $errors[] = "Failed to fetch channel data for: {$channelHandle}";
                        continue;
                    }

                    $channelInfo = $channelData['data'][$channelHandle] ?? null;

                    if (!$channelInfo || empty($channelInfo['id'])) {
                        $errors[] = "No channel found for handle: {$channelHandle}";
                        continue;
                    }

                    // Update youtube_creators table
                    $updateResult = $this->updateYouTubeCreatorRow($channelInfo);

                    if ($updateResult['success']) {
                        $updatedCount++;
                        $details[] = [
                            'channel_id' => $channelInfo['id'],
                            'channel_name' => $channelInfo['snippet']['title'] ?? 'Unknown',
                            'status' => 'updated'
                        ];
                    }

                } catch (\Throwable $e) {
                    $errors[] = "Error updating creator {$creator['channel_handle']}: " . $e->getMessage();
                }
            }

        } catch (\Throwable $e) {
            $errors[] = "Error fetching incomplete creators: " . $e->getMessage();
        }

        return [
            'updated_count' => $updatedCount,
            'details' => $details,
            'errors' => $errors
        ];
    }

    /**
     * Update event_uploads row with fresh YouTube data
     * Uses helper functions: arrayToJson, convertDatetime, getFullLanguageName, convertYouTubeDuration, getVideoType
     *
     * @param int $projectId
     * @param string $videoId
     * @param array $videoData
     * @return array
     */
    private function updateEventUploadRow(int $projectId, string $videoId, array $videoData): array
    {
        try {
            // Use helper function to convert arrays to JSON
            $thumbnailJson = arrayToJson($videoData['thumbnails'] ?? null);
            $tagsJson = arrayToJson($videoData['tags'] ?? null);
            $hashtagsJson = arrayToJson($videoData['hashtags'] ?? null);
            $locationJson = arrayToJson($videoData['location'] ?? null);

            // Build UPDATE query with ALL columns from event_uploads schema
            $updateQuery = "UPDATE event_uploads
                SET
                    title = ?,
                    description = ?,
                    channel_id = ?,
                    channel_title = ?,
                    published_at = ?,
                    hashtags = ?,
                    tags = ?,
                    category_id = ?,
                    thumbnail = ?,
                    view_count = ?,
                    like_count = ?,
                    comment_count = ?,
                    favorite_count = ?,
                    location = ?,
                    location_desc = ?,
                    city = ?,
                    state = ?,
                    country = ?,
                    full_address = ?,
                    duration = ?,
                    definition = ?,
                    dimension = ?,
                    caption = ?,
                    licensed_content = ?,
                    default_language = ?,
                    default_audio_language = ?,
                    video_lang_short = ?,
                    video_language = ?,
                    video_duration = ?,
                    video_type = ?,
                    updated_at = NOW()
                WHERE video_id = ? AND project_id = ?";

            $params = [
                $videoData['title'] ?? null,
                $videoData['description'] ?? null,
                $videoData['channel_id'] ?? null,
                $videoData['channel_title'] ?? null,
                $videoData['published_at_converted'] ?? null,
                $hashtagsJson,
                $tagsJson,
                $videoData['category_id'] ?? null,
                $thumbnailJson,
                $videoData['view_count'] ?? 0,
                $videoData['like_count'] ?? 0,
                $videoData['comment_count'] ?? 0,
                $videoData['favorite_count'] ?? 0,
                $locationJson,
                $videoData['location_desc'] ?? null,
                $videoData['city'] ?? null,
                $videoData['state'] ?? null,
                $videoData['country'] ?? null,
                $videoData['full_address'] ?? null,
                $videoData['duration'] ?? null,
                $videoData['definition'] ?? 'hd',
                $videoData['dimension'] ?? '2d',
                ($videoData['caption'] === 'true' || $videoData['caption'] === true) ? 1 : 0,
                !empty($videoData['licensed_content']) ? 1 : 0,
                $videoData['default_language'] ?? null,
                $videoData['default_audio_language'] ?? null,
                $videoData['default_audio_language'] ?? null, // video_lang_short
                getFullLanguageName($videoData['default_audio_language'] ?? null), // Use helper
                convertYouTubeDuration($videoData['duration'] ?? null), // Use helper
                // getVideoType($videoData['duration'] ?? null), // Use helper
                getVideoType($videoId), // Use helper
                $videoId,
                $projectId
            ];

            RunQuery([
                'conn' => $this->conn,
                'query' => $updateQuery,
                'params' => $params
            ]);

            return [
                'success' => true,
                'message' => 'Video updated successfully'
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to update video',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update youtube_creators row with fresh YouTube data
     * Uses helper function: convertDatetime
     *
     * @param array $channelData
     * @return array
     */
    private function updateYouTubeCreatorRow(array $channelData): array
    {
        try {
            $snippet = $channelData['snippet'] ?? [];
            $statistics = $channelData['statistics'] ?? [];
            $branding = $channelData['brandingSettings'] ?? [];

            // Use helper function for thumbnails
            $thumbnailsJson = arrayToJson($snippet['thumbnails'] ?? null);

            // Update using channel_handle OR channel_id
            $updateQuery = "UPDATE youtube_creators
                SET
                    channel_id = ?,
                    channel_name = ?,
                    channel_handle = ?,
                    channel_description = ?,
                    channel_country_code = ?,
                    subscriber_count = ?,
                    hidden_subscriber_count = ?,
                    video_count = ?,
                    view_count = ?,
                    thumbnails = ?,
                    banner_url = ?,
                    channel_kind = ?,
                    channel_etag = ?,
                    published_at = ?,
                    updated_at = NOW()
                WHERE channel_handle = ? OR channel_id = ?";

            $params = [
                $channelData['id'],
                $snippet['title'] ?? null,
                $snippet['customUrl'] ?? null,
                $snippet['description'] ?? null,
                $snippet['country'] ?? null,
                (int) ($statistics['subscriberCount'] ?? 0),
                (int) ($statistics['hiddenSubscriberCount'] ?? 0),
                (int) ($statistics['videoCount'] ?? 0),
                (int) ($statistics['viewCount'] ?? 0),
                $thumbnailsJson,
                $branding['image']['bannerExternalUrl'] ?? null,
                $channelData['kind'] ?? null,
                $channelData['etag'] ?? null,
                convertDatetime($snippet['publishedAt'] ?? null), // Use helper
                $snippet['customUrl'] ?? null,  // WHERE channel_handle
                $channelData['id']  // WHERE channel_id
            ];

            RunQuery([
                'conn' => $this->conn,
                'query' => $updateQuery,
                'params' => $params
            ]);

            return [
                'success' => true,
                'message' => 'Creator updated successfully'
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to update creator',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update all creator stats for a project
     *
     * @param int $projectId
     * @return array
     */
    private function updateAllProjectCreatorStats(int $projectId): array
    {
        $updatedCount = 0;

        try {
            // Get all creators for this project
            $query = "SELECT DISTINCT creator_id
                      FROM event_uploads
                      WHERE project_id = ?";

            $creators = RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => [$projectId]
            ]);

            foreach ($creators as $creator) {
                try {
                    $creatorId = (int) $creator['creator_id'];

                    // Update all creator stats using existing service
                    $this->creatorAnalyticsService->updateAllCreatorStats($creatorId, $projectId);

                    $updatedCount++;

                } catch (\Throwable $e) {
                    error_log("Failed to update stats for creator {$creatorId}: " . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            error_log("Error updating creator stats: " . $e->getMessage());
        }

        return [
            'updated_count' => $updatedCount
        ];
    }
}
