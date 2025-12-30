<?php

namespace App\Services;

use Exception;

/**
 * YouTubeApiService
 * 
 * Service for fetching and transforming YouTube API data.
 * Does NOT handle database operations - purely API interaction and data transformation.
 * 
 * Dependencies:
 * - utilityHelpers.php: getNominatimLocation(), convertDatetime(), normalizeInput(), arrayToJson()
 * - extractHashtags() function must be available globally
 * 
 * Environment Variables:
 * - YOUTUBE_API_KEY: JSON array of API keys for rotation
 *   Example: YOUTUBE_API_KEY=["key1","key2"]
 */
class YouTubeApiService
{
    private $apiKey;           // Current active API key
    private $apiKeys = [];     // Array of API keys for rotation
    private $currentKeyIndex = 0;

    // /**
    //  * Constructor - Loads API keys from environment or accepts parameter
    //  * 
    //  * @param string|array|null $apiKey API key(s) or null to load from $_ENV['YOUTUBE_API_KEY']
    //  * @throws Exception If no valid API key found
    //  */
    // public function __construct($apiKey = null)
    // {
    //     // If no key passed, try to load from environment
    //     if ($apiKey === null) {
    //         $apiKey = $this->loadApiKeysFromEnv();
    //     }

    //     if (empty($apiKey)) {
    //         throw new Exception('YouTube API key is required');
    //     }

    //     if (is_array($apiKey)) {
    //         // Remove empty values and reindex
    //         $this->apiKeys = array_values(array_filter($apiKey, function ($key) {
    //             return !empty(trim($key));
    //         }));
    //         $this->apiKey = $this->apiKeys[0] ?? null;
    //     } else {
    //         $this->apiKey = trim($apiKey);
    //         $this->apiKeys = [$this->apiKey];
    //     }

    //     if (empty($this->apiKey)) {
    //         throw new Exception('Valid YouTube API key is required');
    //     }
    // }






    /**
     * Constructor - Loads API keys from environment or accepts parameter
     * 
     * @param string|array|null $apiKey API key(s) or null to load from $_ENV['YOUTUBE_API_KEY']
     * @throws Exception If no valid API key found
     */
    public function __construct($apiKey = null)
    {
        // DEBUG: Add stack trace to find where constructor is being called
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $callers = array_map(function ($t) {
            $file = basename($t['file'] ?? 'unknown');
            $line = $t['line'] ?? '?';
            $func = $t['function'] ?? 'unknown';
            $class = isset($t['class']) ? $t['class'] . '::' : '';
            return "$file:$line -> {$class}{$func}()";
        }, array_slice($trace, 1, 5)); // Skip current constructor, show next 5 calls

        error_log('=== YouTubeApiService Constructor Called ===');
        error_log('Call stack: ' . print_r($callers, true));
        error_log('==========================================');

        // If no key passed, try to load from environment
        if ($apiKey === null) {
            $apiKey = $this->loadApiKeysFromEnv();
        }

        if (empty($apiKey)) {
            throw new Exception('YouTube API key is required');
        }

        if (is_array($apiKey)) {
            // Remove empty values and reindex
            $this->apiKeys = array_values(array_filter($apiKey, function ($key) {
                return !empty(trim($key));
            }));
            $this->apiKey = $this->apiKeys[0] ?? null;
        } else {
            $this->apiKey = trim($apiKey);
            $this->apiKeys = [$this->apiKey];
        }

        if (empty($this->apiKey)) {
            throw new Exception('Valid YouTube API key is required');
        }

        // Optional: Log successful initialization (remove after debugging)
        error_log('YouTubeApiService initialized with ' . count($this->apiKeys) . ' API key(s)');
    }





    /**
     * Load API keys from environment variable
     * Supports JSON array: YOUTUBE_API_KEY=["key1","key2"]
     * 
     * @return array Array of API keys
     * @throws Exception If environment variable not found or invalid
     */
    private function loadApiKeysFromEnv(): array
    {
        // Try $_ENV first (recommended)
        $envValue = $_ENV['YOUTUBE_API_KEY'] ?? null;

        // Fallback to getenv() if $_ENV not available
        if ($envValue === null) {
            $envValue = getenv('YOUTUBE_API_KEY');
        }

        if (!$envValue || empty(trim($envValue))) {
            throw new Exception('YOUTUBE_API_KEY not found in environment variables');
        }

        $envValue = trim($envValue);

        error_log('YOUTUBE_API_KEY length: ' . strlen($envValue));

        // Try to decode as JSON array
        $decoded = json_decode($envValue, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Successfully decoded JSON array
            $keys = array_filter($decoded, function ($key) {
                return !empty(trim($key));
            });

            if (empty($keys)) {
                throw new Exception('YOUTUBE_API_KEY contains no valid keys');
            }

            return array_values($keys);
        }

        // If not JSON, treat as single key (fallback)
        if (!empty($envValue)) {
            return [$envValue];
        }

        throw new Exception('YOUTUBE_API_KEY is invalid or empty');
    }

    /**
     * Rotate to next API key if multiple keys provided
     * Useful when quota is exhausted on current key
     * 
     * @return bool True if rotated, false if no more keys
     */
    private function rotateApiKey(): bool
    {
        if (count($this->apiKeys) <= 1) {
            return false;
        }

        $this->currentKeyIndex = ($this->currentKeyIndex + 1) % count($this->apiKeys);
        $this->apiKey = $this->apiKeys[$this->currentKeyIndex];

        return true;
    }

    /**
     * Get current API key (for debugging/logging)
     * 
     * @return string Current API key (masked for security)
     */
    public function getCurrentApiKeyMasked(): string
    {
        if (empty($this->apiKey)) {
            return 'No key loaded';
        }

        $key = $this->apiKey;
        $visibleChars = 6;

        if (strlen($key) <= $visibleChars * 2) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, $visibleChars) . '...' . substr($key, -$visibleChars);
    }

    /**
     * Get total number of API keys loaded
     * 
     * @return int Number of API keys available
     */
    public function getApiKeyCount(): int
    {
        return count($this->apiKeys);
    }

    /**
     * Extract video ID from various YouTube URL formats
     * 
     * @param string $input YouTube video URL or video ID
     * @return string|null Video ID or null if invalid
     */
    public function extractVideoId(string $input): ?string
    {
        $input = trim($input);

        // Patterns for various YouTube URL formats
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',     // Standard
            '/youtu\.be\/([a-zA-Z0-9_-]{11})/',                 // Short URL
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',       // Embed
            '/youtube\.com\/v\/([a-zA-Z0-9_-]{11})/',           // Old embed
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/'       // Shorts
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                return $matches[1];
            }
        }

        // Check if it's already a video ID (11 characters)
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
            return $input;
        }

        return null;
    }

    /**
     * Extract channel ID from various YouTube channel URL formats
     * 
     * @param string $input YouTube channel URL or channel ID
     * @return string|null Channel ID or null if invalid
     */
    public function extractChannelId(string $input): ?string
    {
        $input = trim($input);

        // Patterns for various YouTube channel URL formats
        $patterns = [
            '/youtube\.com\/channel\/([a-zA-Z0-9_-]+)/',        // Channel ID URL
            '/youtube\.com\/c\/([a-zA-Z0-9_-]+)/',              // Custom URL
            '/youtube\.com\/user\/([a-zA-Z0-9_-]+)/',           // Legacy user URL
            '/youtube\.com\/@([a-zA-Z0-9_-]+)/'                 // Handle format
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                return $matches[1];
            }
        }

        // Check if it's already a channel ID (starts with UC, 24 chars)
        if (preg_match('/^UC[a-zA-Z0-9_-]{22}$/', $input)) {
            return $input;
        }

        return null;
    }

    /**
     * Extract channel handle from URL or input
     * 
     * @param string $input YouTube handle or URL
     * @return string|null Handle (without @) or null if invalid
     */
    public function extractHandle(string $input): ?string
    {
        $input = trim($input);

        // Extract from URL format: youtube.com/@handle
        if (preg_match('/youtube\.com\/@([a-zA-Z0-9_-]+)/', $input, $matches)) {
            return $matches[1];
        }

        // Remove @ prefix if present
        if (strpos($input, '@') === 0) {
            return ltrim($input, '@');
        }

        // If it looks like a plain handle (alphanumeric, underscore, hyphen)
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $input)) {
            return $input;
        }

        return null;
    }

    /**
     * Make HTTP GET request with cURL
     * 
     * @param string $url API URL
     * @return array|null Decoded JSON response or null on error
     */
    public function httpGetJson(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        // Check for API errors
        if (!is_array($data)) {
            return null;
        }

        // Return data with HTTP code for error handling
        $data['_http_code'] = $httpCode;

        return $data;
    }

    /**
     * Check if YouTube API quota is available
     * Makes a minimal quota request to verify key validity
     * 
     * @return array Response with quota status
     */
    public function checkApiQuota(): array
    {
        $attemptedRotation = false;

        do {
            try {
                // Stable test video (always exists)
                $testUrl = "https://www.googleapis.com/youtube/v3/videos"
                    . "?part=id"
                    . "&id=dQw4w9WgXcQ"
                    . "&key=" . urlencode($this->apiKey);

                $data = $this->httpGetJson($testUrl);

                // Handle connection failure
                if ($data === null) {
                    if (!$attemptedRotation && $this->rotateApiKey()) {
                        $attemptedRotation = true;
                        continue;
                    }

                    return [
                        'success' => false,
                        'message' => 'Failed to connect to YouTube API',
                        'data' => [
                            'quota_available' => null,
                            'current_api_key' => $this->apiKey
                        ],
                        'error' => 'Connection error'
                    ];
                }

                // --- Handle API error responses ---
                if (isset($data['error'])) {
                    $errorCode = $data['error']['code'] ?? null;
                    $errorMessage = $data['error']['message'] ?? '';
                    $errorReason = '';

                    // Extract reason from nested errors
                    if (!empty($data['error']['errors']) && is_array($data['error']['errors'])) {
                        foreach ($data['error']['errors'] as $err) {
                            if (!empty($err['reason'])) {
                                $errorReason = $err['reason'];
                                break;
                            }
                        }
                    }

                    // --- Detect quota exceeded (flexible match) ---
                    $isQuotaExceeded =
                        ($errorCode == 403 && stripos($errorReason, 'quota') !== false) ||
                        stripos($errorMessage, 'quota') !== false;

                    if ($isQuotaExceeded) {
                        // Rotate if possible and retry
                        if (!$attemptedRotation && $this->rotateApiKey()) {
                            $attemptedRotation = true;
                            continue;
                        }

                        return [
                            'success' => false,
                            'message' => 'YouTube API quota exceeded on all keys',
                            'data' => [
                                'quota_available' => false,
                                'error_code' => $errorCode,
                                'error_reason' => $errorReason ?: 'quotaExceeded',
                                'current_api_key' => $this->apiKey
                            ],
                            'error' => $errorMessage
                        ];
                    }

                    // --- Other API errors ---
                    return [
                        'success' => false,
                        'message' => 'YouTube API error: ' . $errorMessage,
                        'data' => [
                            'error_code' => $errorCode,
                            'error_reason' => $errorReason,
                            'error_message' => $errorMessage,
                            'current_api_key' => $this->apiKey
                        ],
                        'error' => $errorMessage
                    ];
                }

                // --- Handle empty or invalid response ---
                if (empty($data['items'])) {
                    return [
                        'success' => false,
                        'message' => 'YouTube API test failed - no data returned',
                        'data' => [
                            'quota_available' => null,
                            'current_api_key' => $this->apiKey
                        ],
                        'error' => 'Test video not found - possible API issue'
                    ];
                }

                // --- Success: API key valid and quota available ---
                return [
                    'success' => true,
                    'message' => 'YouTube API quota is available',
                    'data' => [
                        'quota_available' => true,
                        'api_key_valid' => true,
                        'current_api_key' => $this->apiKey,
                        'total_keys_available' => count($this->apiKeys)
                    ],
                    'error' => null
                ];

            } catch (\Throwable $e) {
                if (!$attemptedRotation && $this->rotateApiKey()) {
                    $attemptedRotation = true;
                    continue;
                }

                return [
                    'success' => false,
                    'message' => 'Server error: ' . $e->getMessage(),
                    'data' => [
                        'current_api_key' => $this->apiKey
                    ],
                    'error' => $e->getMessage()
                ];
            }

            // break; // Exit loop if no retry required
        } while (true);
    }







    /**
     * Get channel information by handle or channel ID
     * 
     * @param array $input {
     *     @type string|array $handles Single handle/ID or array of handles/IDs
     *     @type bool $details Whether to fetch full details (default: false)
     * }
     * @return array Response with channel data
     */
    public function getChannelInfo(array $input): array
    {
        try {
            // Validate input
            if (empty($input['handles'])) {
                return [
                    'success' => false,
                    'message' => 'Handle or channel ID is required',
                    'data' => null,
                    'error' => 'Missing handles parameter'
                ];
            }

            $handles = $input['handles'];
            $fetchDetails = $input['details'] ?? false;

            // Normalize handles input
            if (!is_array($handles)) {
                $handles = [$handles];
            }

            $handles = array_filter(array_map('trim', $handles));

            if (empty($handles)) {
                return [
                    'success' => false,
                    'message' => 'No valid handles provided',
                    'data' => null,
                    'error' => 'Empty handles after normalization'
                ];
            }

            $result = [];
            $isChannelId = function ($s) {
                return (bool) preg_match('/^UC[0-9A-Za-z_-]{22}$/', $s);
            };

            foreach ($handles as $originalInput) {
                $key = (string) $originalInput;
                $value = trim((string) $originalInput);

                if ($value === '') {
                    $result[$key] = null;
                    continue;
                }

                // Check if input is a channel ID
                if ($isChannelId($value)) {
                    if ($fetchDetails) {
                        $parts = 'snippet,statistics,brandingSettings';
                        $url = 'https://www.googleapis.com/youtube/v3/channels'
                            . '?part=' . urlencode($parts)
                            . '&id=' . urlencode($value)
                            . '&key=' . urlencode($this->apiKey);

                        $data = $this->httpGetJson($url);

                        if ($data === null || isset($data['error'])) {
                            $result[$key] = null;
                            continue;
                        }

                        $item = $data['items'][0] ?? null;
                        $result[$key] = $item ?: null;
                    } else {
                        $result[$key] = $value;
                    }
                    continue;
                }

                // Treat as handle
                $normalizedHandle = ltrim($value, '@');
                $parts = $fetchDetails ? 'snippet,statistics,brandingSettings' : 'snippet';

                $url = 'https://www.googleapis.com/youtube/v3/channels'
                    . '?part=' . urlencode($parts)
                    . '&forHandle=' . urlencode($normalizedHandle)
                    . '&key=' . urlencode($this->apiKey);

                $data = $this->httpGetJson($url);

                if ($data === null || isset($data['error'])) {
                    $result[$key] = null;
                    continue;
                }

                $item = $data['items'][0] ?? null;

                if (!$item) {
                    $result[$key] = null;
                    continue;
                }

                if ($fetchDetails) {
                    $result[$key] = $item;
                } else {
                    $result[$key] = $item['id'] ?? null;
                }
            }

            return [
                'success' => true,
                'message' => 'Channel information retrieved successfully',
                'data' => $result,
                'count' => count($result),
                'error' => null
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get video details from YouTube API
     * Includes metadata, statistics, hashtags, and location data
     * 
     * @param string $videoId Video ID or YouTube URL (required)
     * @param string $timezone Target timezone for datetime conversion (default: 'Asia/Kolkata')
     * @return array Response with video details
     */
    public function getVideoDetails(string $videoId, string $timezone = 'Asia/Kolkata'): array
    {
        try {
            // Validate input
            if (empty($videoId)) {
                return [
                    'success' => false,
                    'message' => 'Video ID or URL is required',
                    'data' => null,
                    'error' => 'Missing video_id parameter'
                ];
            }

            $videoInput = trim($videoId);

            // Extract video ID from URL or validate ID
            $videoId = $this->extractVideoId($videoInput);

            if (!$videoId) {
                return [
                    'success' => false,
                    'message' => 'Invalid YouTube video URL or ID',
                    'data' => null,
                    'error' => 'Could not extract valid video ID from input'
                ];
            }

            // Build API request
            $apiUrl = "https://www.googleapis.com/youtube/v3/videos"
                . "?part=snippet,contentDetails,statistics,recordingDetails"
                . "&id=" . urlencode($videoId)
                . "&key=" . urlencode($this->apiKey);

            $data = $this->httpGetJson($apiUrl);

            if ($data === null) {
                return [
                    'success' => false,
                    'message' => 'Failed to fetch video data from YouTube API',
                    'data' => null,
                    'error' => 'API request failed'
                ];
            }

            // Check HTTP status code
            $httpCode = $data['_http_code'] ?? 0;
            unset($data['_http_code']);

            if ($httpCode !== 200) {
                $errorMsg = $data['error']['message'] ?? 'Unknown error';
                return [
                    'success' => false,
                    'message' => 'YouTube API returned HTTP code ' . $httpCode,
                    'data' => null,
                    'error' => $errorMsg
                ];
            }

            // Check for API errors
            if (isset($data['error'])) {
                return [
                    'success' => false,
                    'message' => 'YouTube API error: ' . ($data['error']['message'] ?? 'Unknown'),
                    'data' => null,
                    'error' => $data['error']['message'] ?? 'Unknown error'
                ];
            }

            if (empty($data['items'])) {
                return [
                    'success' => false,
                    'message' => 'Video not found or unavailable',
                    'data' => null,
                    'error' => 'No video data returned from API'
                ];
            }

            $video = $data['items'][0];

            // Extract hashtags from title and description
            $title = $video['snippet']['title'] ?? '';
            $description = $video['snippet']['description'] ?? '';

            $hashtagsFromTitle = function_exists('extractHashtags') ? extractHashtags($title) : [];
            $hashtagsFromDescription = function_exists('extractHashtags') ? extractHashtags($description) : [];
            $allHashtags = array_values(array_unique(array_merge($hashtagsFromTitle, $hashtagsFromDescription)));

            // Reverse geocode location if available
            $cityInfo = [];
            if (isset($video['recordingDetails']['location'])) {
                $lat = (float) $video['recordingDetails']['location']['latitude'];
                $lng = (float) $video['recordingDetails']['location']['longitude'];

                if (function_exists('getNominatimLocation')) {
                    $cityInfo = getNominatimLocation($lat, $lng) ?? [];
                }
            }

            // Get thumbnails
            $thumbnails = $video['snippet']['thumbnails'] ?? [];
            $thumbnailsData = [
                'default' => $thumbnails['default']['url'] ?? null,
                'medium' => $thumbnails['medium']['url'] ?? null,
                'high' => $thumbnails['high']['url'] ?? null,
                'standard' => $thumbnails['standard']['url'] ?? null,
                'maxres' => $thumbnails['maxres']['url'] ?? null,
            ];

            // Convert published date to target timezone
            $publishedAt = $video['snippet']['publishedAt'] ?? null;
            $convertedDateTime = null;
            if ($publishedAt && function_exists('convertDatetime')) {
                $convertedDateTime = convertDatetime($publishedAt, $timezone);
            }

            // Prepare response data
            $responseData = [
                'video_id' => $videoId,
                'title' => $title,
                'description' => $description,
                'channel_id' => $video['snippet']['channelId'] ?? null,
                'channel_title' => $video['snippet']['channelTitle'] ?? null,
                'published_at' => $publishedAt,
                'published_at_converted' => $convertedDateTime,
                'tags' => $video['snippet']['tags'] ?? [],
                'hashtags' => $allHashtags,
                'category_id' => $video['snippet']['categoryId'] ?? null,
                'default_language' => $video['snippet']['defaultLanguage'] ?? null,
                'default_audio_language' => $video['snippet']['defaultAudioLanguage'] ?? null,
                'thumbnails' => $thumbnailsData,

                // Statistics
                'view_count' => (int) ($video['statistics']['viewCount'] ?? 0),
                'like_count' => (int) ($video['statistics']['likeCount'] ?? 0),
                'comment_count' => (int) ($video['statistics']['commentCount'] ?? 0),
                'favorite_count' => (int) ($video['statistics']['favoriteCount'] ?? 0),

                // Content details
                'duration' => $video['contentDetails']['duration'] ?? null,
                'dimension' => $video['contentDetails']['dimension'] ?? null,
                'definition' => $video['contentDetails']['definition'] ?? null,
                'caption' => $video['contentDetails']['caption'] ?? null,

                // Location data
                'location' => $video['recordingDetails']['location'] ?? null,
                'location_description' => $video['recordingDetails']['locationDescription'] ?? null,
                'city' => $cityInfo['city'] ?? null,
                'state' => $cityInfo['state'] ?? null,
                'country' => $cityInfo['country'] ?? null,
                'full_address' => $cityInfo['fulladdress'] ?? null,
            ];

            return [
                'success' => true,
                'message' => 'Video details retrieved successfully',
                'data' => $responseData,
                'error' => null
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }




    /**
     * Get video details with JSON-encoded fields for database storage
     * Same as getVideoDetails but with arrays converted to JSON strings
     * 
     * @param string $videoId Video ID or YouTube URL (required)
     * @param string|null $timezone Target timezone (default: 'Asia/Kolkata')
     * @return array Response with JSON-encoded arrays
     */
    public function getVideoDetailsForDb(string $videoId, ?string $timezone = 'Asia/Kolkata'): array
    {

        $result = $this->getVideoDetails($videoId, $timezone);

        pp($result);

        if (!$result['success'] || !$result['data']) {
            return $result;
        }

        try {
            $data = $result['data'];

            // Convert arrays to JSON strings using arrayToJson helper
            if (function_exists('arrayToJson')) {
                $data['tags'] = arrayToJson($data['tags'] ?? []);
                $data['hashtags'] = arrayToJson($data['hashtags'] ?? []);
                $data['thumbnails'] = arrayToJson($data['thumbnails'] ?? []);
                $data['location'] = arrayToJson($data['location'] ?? null);
            } else {
                // Fallback to json_encode
                $data['tags'] = json_encode($data['tags'] ?? [], JSON_UNESCAPED_UNICODE);
                $data['hashtags'] = json_encode($data['hashtags'] ?? [], JSON_UNESCAPED_UNICODE);
                $data['thumbnails'] = json_encode($data['thumbnails'] ?? [], JSON_UNESCAPED_UNICODE);
                $data['location'] = json_encode($data['location'] ?? null, JSON_UNESCAPED_UNICODE);
            }

            $result['data'] = $data;
            $result['message'] = 'Video details retrieved and formatted for database storage';

            return $result;

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error formatting data for database: ' . $e->getMessage(),
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }




    /**
     * Get multiple video details in batch
     * YouTube API allows up to 50 video IDs per request
     * 
     * @param array $input {
     *     @type array $video_ids Array of video IDs or URLs (max 50)
     *     @type string $timezone Target timezone (default: 'Asia/Kolkata')
     * }
     * @return array Response with array of video details
     */
    public function getBatchVideoDetails(array $input): array
    {
        try {
            // Validate input
            if (empty($input['video_ids']) || !is_array($input['video_ids'])) {
                return [
                    'success' => false,
                    'message' => 'video_ids array is required',
                    'data' => null,
                    'error' => 'Missing or invalid video_ids parameter'
                ];
            }

            $videoInputs = $input['video_ids'];
            $timezone = $input['timezone'] ?? 'Asia/Kolkata';

            // Extract and validate video IDs
            $videoIds = [];
            foreach ($videoInputs as $videoInput) {
                $videoId = $this->extractVideoId($videoInput);
                if ($videoId) {
                    $videoIds[] = $videoId;
                }
            }

            if (empty($videoIds)) {
                return [
                    'success' => false,
                    'message' => 'No valid video IDs found',
                    'data' => null,
                    'error' => 'Could not extract valid video IDs from inputs'
                ];
            }

            // Limit to 50 videos per API request
            $videoIds = array_slice($videoIds, 0, 50);
            $videoIdString = implode(',', $videoIds);

            // Build API request
            $apiUrl = "https://www.googleapis.com/youtube/v3/videos"
                . "?part=snippet,contentDetails,statistics,recordingDetails"
                . "&id=" . urlencode($videoIdString)
                . "&key=" . urlencode($this->apiKey);

            $data = $this->httpGetJson($apiUrl);

            if ($data === null || isset($data['error'])) {
                return [
                    'success' => false,
                    'message' => 'Failed to fetch video data from YouTube API',
                    'data' => null,
                    'error' => $data['error']['message'] ?? 'API request failed'
                ];
            }

            if (empty($data['items'])) {
                return [
                    'success' => false,
                    'message' => 'No videos found',
                    'data' => [],
                    'count' => 0,
                    'error' => null
                ];
            }

            // Process each video
            $results = [];
            foreach ($data['items'] as $video) {
                $videoId = $video['id'];

                // Reuse the processing logic
                $processedVideo = $this->getVideoDetails($videoId, $timezone);

                if ($processedVideo['success']) {
                    $results[$videoId] = $processedVideo['data'];
                } else {
                    $results[$videoId] = null;
                }
            }

            return [
                'success' => true,
                'message' => count($results) . ' videos retrieved successfully',
                'data' => $results,
                'count' => count($results),
                'error' => null
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Search for videos by keyword
     * 
     * @param array $input {
     *     @type string $query Search query (required)
     *     @type int $max_results Maximum results (default: 10, max: 50)
     *     @type string $order Sort order: date, rating, relevance, title, viewCount (default: relevance)
     *     @type string $type Result type: video, channel, playlist (default: video)
     * }
     * @return array Response with search results
     */
    public function searchVideos(array $input): array
    {
        try {
            // Validate input
            if (empty($input['query'])) {
                return [
                    'success' => false,
                    'message' => 'Search query is required',
                    'data' => null,
                    'error' => 'Missing query parameter'
                ];
            }

            $query = trim($input['query']);
            $maxResults = min((int) ($input['max_results'] ?? 10), 50);
            $order = $input['order'] ?? 'relevance';
            $type = $input['type'] ?? 'video';

            // Build API request
            $apiUrl = "https://www.googleapis.com/youtube/v3/search"
                . "?part=snippet"
                . "&q=" . urlencode($query)
                . "&type=" . urlencode($type)
                . "&order=" . urlencode($order)
                . "&maxResults=" . $maxResults
                . "&key=" . urlencode($this->apiKey);

            $data = $this->httpGetJson($apiUrl);

            if ($data === null || isset($data['error'])) {
                return [
                    'success' => false,
                    'message' => 'Failed to search videos',
                    'data' => null,
                    'error' => $data['error']['message'] ?? 'API request failed'
                ];
            }

            $items = $data['items'] ?? [];
            $results = [];

            foreach ($items as $item) {
                $results[] = [
                    'video_id' => $item['id']['videoId'] ?? null,
                    'channel_id' => $item['snippet']['channelId'] ?? null,
                    'title' => $item['snippet']['title'] ?? null,
                    'description' => $item['snippet']['description'] ?? null,
                    'channel_title' => $item['snippet']['channelTitle'] ?? null,
                    'published_at' => $item['snippet']['publishedAt'] ?? null,
                    'thumbnails' => $item['snippet']['thumbnails'] ?? [],
                ];
            }

            return [
                'success' => true,
                'message' => count($results) . ' videos found',
                'data' => $results,
                'count' => count($results),
                'error' => null
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }




    /**
     * Get uploaded videos for a YouTube channel within a date range (no channel resolution).
     * Requires channel_id and optionally accepts channel_handle for downstream use.
     *
     * @param string $channelId YouTube channel ID
     * @param string $fromDate Start date (YYYY-MM-DD)
     * @param string $toDate End date (YYYY-MM-DD)
     * @param string|null $channelHandle Optional channel handle
     * @param int|null $maxResults Maximum results per page (max 50)
     * @param string|null $timezone Timezone for date conversion
     * @return array {
     *   success: bool,
     *   message: string,
     *   data: array<array{
     *     video_id: string,
     *     title: string,
     *     description: string,
     *     published_at: string,
     *     published_at_converted?: string|null,
     *     thumbnails: array,
     *     hashtags: array<string>,
     *     channel_id: string,
     *     channel_title: string,
     *     channel_handle?: string|null
     *   }>,
     *   count: int,
     *   error: ?string
     * }
     */
    public function getChannelVideosByDate(
        string $channelId,
        string $fromDate,
        string $toDate,
        ?string $channelHandle = null,
        ?int $maxResults = 50,
        ?string $timezone = 'Asia/Kolkata'
    ): array {
        try {
            // Trim and validate inputs
            $channelId = trim($channelId);
            $from = trim($fromDate);
            $to = trim($toDate);
            $handle = $channelHandle !== null ? trim($channelHandle) : null;

            // Validate required parameters
            if ($channelId === '' || $from === '' || $to === '') {
                return [
                    'success' => false,
                    'message' => 'channel_id, from_date, and to_date are required',
                    'data' => null,
                    'count' => 0,
                    'error' => 'Missing required inputs'
                ];
            }

            // Set parameters
            $max = min((int) ($maxResults ?? 50), 50);
            $tz = $timezone ?? 'Asia/Kolkata';

            // Convert dates to ISO 8601 UTC format
            $publishedAfter = (new \DateTime($from, new \DateTimeZone('UTC')))->format(\DateTime::ATOM);
            $publishedBefore = (new \DateTime($to, new \DateTimeZone('UTC')))
                ->setTime(23, 59, 59)
                ->format(\DateTime::ATOM);

            // Initialize variables
            $videos = [];
            $pageToken = '';
            $maxRotationAttempts = count($this->apiKeys);
            $rotationAttempts = 0;

            // Pagination loop
            do {
                // Build API URL
                $apiUrl = 'https://www.googleapis.com/youtube/v3/search'
                    . '?part=snippet'
                    . '&channelId=' . urlencode($channelId)
                    . '&order=date'
                    . '&publishedAfter=' . urlencode($publishedAfter)
                    . '&publishedBefore=' . urlencode($publishedBefore)
                    . '&maxResults=' . $max
                    . '&type=video'
                    . (!empty($pageToken) ? ('&pageToken=' . urlencode($pageToken)) : '')
                    . '&key=' . urlencode($this->apiKey);

                // Make API request
                $data = $this->httpGetJson($apiUrl);

                // --- Handle API error responses FIRST ---
                if ($data !== null && isset($data['error'])) {
                    $errorCode = $data['error']['code'] ?? 0;
                    $errorMessage = $data['error']['message'] ?? 'Unknown error';
                    $errorReason = '';

                    // Extract reason from nested errors array
                    if (!empty($data['error']['errors']) && is_array($data['error']['errors'])) {
                        foreach ($data['error']['errors'] as $err) {
                            if (!empty($err['reason'])) {
                                $errorReason = $err['reason'];
                                break;
                            }
                        }
                    }

                    // Detect quota exceeded with flexible matching
                    $isQuotaExceeded =
                        ($errorCode == 403 && $errorReason === 'quotaExceeded') ||
                        ($errorCode == 403 && stripos($errorReason, 'quota') !== false) ||
                        stripos($errorMessage, 'quota') !== false;

                    if ($isQuotaExceeded) {
                        // Try rotating to next key if available
                        if ($rotationAttempts < $maxRotationAttempts && $this->rotateApiKey()) {
                            $rotationAttempts++;
                            $pageToken = ''; // CRITICAL: Reset page token for new key
                            continue; // Retry with new key
                        }

                        // All keys exhausted
                        return [
                            'success' => false,
                            'message' => 'YouTube API quota exceeded on all available keys',
                            'data' => null,
                            'count' => 0,
                            'error' => $errorMessage,
                            'error_details' => [
                                'code' => $errorCode,
                                'reason' => $errorReason,
                                'message' => $errorMessage,
                                'attempted_rotations' => $rotationAttempts
                            ]
                        ];
                    }

                    // Other API errors (not quota related)
                    return [
                        'success' => false,
                        'message' => 'YouTube API error: ' . $errorMessage,
                        'data' => null,
                        'count' => 0,
                        'error' => $errorMessage,
                        'error_details' => [
                            'code' => $errorCode,
                            'reason' => $errorReason,
                            'message' => $errorMessage
                        ]
                    ];
                }

                // --- Handle null response (connection failure) ---
                if ($data === null) {
                    // Try rotating key on connection failure
                    if ($rotationAttempts < $maxRotationAttempts && $this->rotateApiKey()) {
                        $rotationAttempts++;
                        $pageToken = ''; // Reset page token for new key
                        continue;
                    }

                    return [
                        'success' => false,
                        'message' => 'Failed to fetch search results from YouTube API',
                        'data' => null,
                        'count' => 0,
                        'error' => 'API request failed - no response received'
                    ];
                }

                // --- Success: Reset rotation attempts ---
                $rotationAttempts = 0;

                // --- Process video items ---
                foreach ($data['items'] ?? [] as $item) {
                    $vid = $item['id']['videoId'] ?? null;
                    if (!$vid) {
                        continue;
                    }

                    $snippet = $item['snippet'] ?? [];
                    $title = $snippet['title'] ?? '';
                    $desc = $snippet['description'] ?? '';
                    $pubAt = $snippet['publishedAt'] ?? null;

                    // Extract hashtags from title and description
                    $tagsTitle = function_exists('extractHashtags') ? extractHashtags($title) : [];
                    $tagsDesc = function_exists('extractHashtags') ? extractHashtags($desc) : [];
                    $hashtags = array_values(array_unique(array_merge($tagsTitle, $tagsDesc)));

                    // Convert datetime to specified timezone
                    $converted = null;
                    if ($pubAt && function_exists('convertDatetime')) {
                        $converted = convertDatetime($pubAt, $tz);
                    }

                    // Add video to results
                    $videos[] = [
                        'video_id' => $vid,
                        'title' => $title,
                        'description' => $desc,
                        'published_at' => $pubAt,
                        'published_at_converted' => $converted,
                        'thumbnails' => $snippet['thumbnails'] ?? [],
                        'hashtags' => $hashtags,
                        'channel_id' => $snippet['channelId'] ?? $channelId,
                        'channel_title' => $snippet['channelTitle'] ?? '',
                        'channel_handle' => $handle ?: null,
                    ];
                }

                // Get next page token for pagination
                $pageToken = $data['nextPageToken'] ?? '';

            } while (!empty($pageToken));

            // --- Return success response ---
            return [
                'success' => true,
                'message' => count($videos) . ' videos retrieved successfully',
                'data' => $videos,
                'count' => count($videos),
                'error' => null
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'data' => null,
                'count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }






    public function buildVideoUrl(string $videoId): ?string
    {
        $videoId = trim($videoId);

        // Validate video ID format (11 characters, alphanumeric with - and _)
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
            return "https://www.youtube.com/watch?v=" . $videoId;
        }

        return null;
    }




}
