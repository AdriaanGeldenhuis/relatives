<?php
/**
 * Holiday Traveling - Google Calendar Integration
 * OAuth flow and event insertion
 * Full implementation in Phase 5
 */
declare(strict_types=1);

class HT_GoogleCalendar {
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.events';

    /**
     * Get OAuth authorization URL
     */
    public static function getAuthUrl(string $redirectUri, string $state = ''): string {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';

        if (empty($clientId)) {
            throw new Exception('Google OAuth not configured');
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];

        if ($state) {
            $params['state'] = $state;
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens
     */
    public static function exchangeCode(string $code, string $redirectUri): array {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

        $response = self::httpPost(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ]);

        if (!isset($response['access_token'])) {
            throw new Exception('Failed to exchange code for tokens');
        }

        return $response;
    }

    /**
     * Refresh access token
     */
    public static function refreshToken(string $refreshToken): array {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

        $response = self::httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token'
        ]);

        if (!isset($response['access_token'])) {
            throw new Exception('Failed to refresh token');
        }

        return $response;
    }

    /**
     * Save tokens for user
     */
    public static function saveTokens(int $userId, array $tokens): void {
        $expiresAt = isset($tokens['expires_in'])
            ? date('Y-m-d H:i:s', time() + $tokens['expires_in'])
            : null;

        $existing = HT_DB::fetchOne(
            "SELECT id FROM ht_user_calendar_tokens WHERE user_id = ? AND provider = 'google'",
            [$userId]
        );

        $data = [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'token_type' => $tokens['token_type'] ?? 'Bearer',
            'scope' => $tokens['scope'] ?? null,
            'expires_at' => $expiresAt
        ];

        if ($existing) {
            HT_DB::update('ht_user_calendar_tokens', $data, "user_id = ? AND provider = 'google'", [$userId]);
        } else {
            $data['user_id'] = $userId;
            $data['provider'] = 'google';
            HT_DB::insert('ht_user_calendar_tokens', $data);
        }
    }

    /**
     * Get valid access token for user (refreshes if needed)
     */
    public static function getAccessToken(int $userId): ?string {
        $token = HT_DB::fetchOne(
            "SELECT * FROM ht_user_calendar_tokens WHERE user_id = ? AND provider = 'google'",
            [$userId]
        );

        if (!$token) {
            return null;
        }

        // Check if token is expired or will expire in next 5 minutes
        if ($token['expires_at'] && strtotime($token['expires_at']) < time() + 300) {
            if (!$token['refresh_token']) {
                return null;
            }

            try {
                $newTokens = self::refreshToken($token['refresh_token']);
                self::saveTokens($userId, array_merge($newTokens, ['refresh_token' => $token['refresh_token']]));
                return $newTokens['access_token'];
            } catch (Exception $e) {
                error_log('Google token refresh failed: ' . $e->getMessage());
                return null;
            }
        }

        return $token['access_token'];
    }

    /**
     * Check if user has connected Google Calendar
     */
    public static function isConnected(int $userId): bool {
        return self::getAccessToken($userId) !== null;
    }

    /**
     * Disconnect Google Calendar
     */
    public static function disconnect(int $userId): void {
        HT_DB::delete('ht_user_calendar_tokens', "user_id = ? AND provider = 'google'", [$userId]);
    }

    /**
     * Create calendar event
     */
    public static function createEvent(int $userId, array $event): array {
        $accessToken = self::getAccessToken($userId);
        if (!$accessToken) {
            throw new Exception('Google Calendar not connected');
        }

        $response = self::httpPost(
            self::CALENDAR_API . '/calendars/primary/events',
            $event,
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            true
        );

        return $response;
    }

    /**
     * Create events for a trip itinerary
     */
    public static function insertTripEvents(int $userId, array $trip, array $plan): array {
        $accessToken = self::getAccessToken($userId);
        if (!$accessToken) {
            throw new Exception('Google Calendar not connected');
        }

        $createdEvents = [];

        // Create main trip event (all-day spanning event)
        $mainEvent = [
            'summary' => 'Trip: ' . $trip['destination'],
            'description' => $trip['title'],
            'start' => ['date' => $trip['start_date']],
            'end' => ['date' => date('Y-m-d', strtotime($trip['end_date'] . ' +1 day'))],
            'transparency' => 'transparent'
        ];

        $createdEvents[] = self::createEvent($userId, $mainEvent);

        // Create individual activity events from itinerary
        foreach ($plan['itinerary'] ?? [] as $day) {
            $date = $day['date'] ?? null;
            if (!$date) continue;

            // Morning activities
            foreach ($day['morning'] ?? [] as $activity) {
                $event = self::buildActivityEvent($date, '09:00', '12:00', $activity, $trip['destination']);
                $createdEvents[] = self::createEvent($userId, $event);
            }

            // Afternoon activities
            foreach ($day['afternoon'] ?? [] as $activity) {
                $event = self::buildActivityEvent($date, '14:00', '17:00', $activity, $trip['destination']);
                $createdEvents[] = self::createEvent($userId, $event);
            }

            // Evening activities
            foreach ($day['evening'] ?? [] as $activity) {
                $event = self::buildActivityEvent($date, '18:00', '21:00', $activity, $trip['destination']);
                $createdEvents[] = self::createEvent($userId, $event);
            }
        }

        return $createdEvents;
    }

    /**
     * Build activity event object
     */
    private static function buildActivityEvent(string $date, string $startTime, string $endTime, $activity, string $location): array {
        $summary = is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? 'Activity') : $activity;
        $description = is_array($activity) ? ($activity['description'] ?? '') : '';

        return [
            'summary' => $summary,
            'description' => $description,
            'location' => $location,
            'start' => [
                'dateTime' => $date . 'T' . $startTime . ':00',
                'timeZone' => 'Africa/Johannesburg'
            ],
            'end' => [
                'dateTime' => $date . 'T' . $endTime . ':00',
                'timeZone' => 'Africa/Johannesburg'
            ]
        ];
    }

    /**
     * HTTP POST helper
     */
    private static function httpPost(string $url, array $data, array $headers = [], bool $json = false): array {
        $ch = curl_init($url);

        $postData = $json ? json_encode($data) : http_build_query($data);
        if (!$json) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }
}
