<?php
/**
 * ============================================
 * FIREBASE CLOUD MESSAGING (FCM) v1 API
 * Modern implementation with service account
 * ============================================
 */

class FirebaseMessaging {
    private $projectId;
    private $serviceAccount;
    private $accessToken;
    private $tokenExpiry;

    public function __construct() {
        // Load service account from file
        $serviceAccountPath = __DIR__ . '/../config/firebase-service-account.json';

        if (file_exists($serviceAccountPath)) {
            $this->serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
            if (!$this->serviceAccount) {
                error_log('FCM: config/firebase-service-account.json exists but is not valid JSON — push notifications DISABLED');
            }
        } else {
            error_log('FCM: Service account file not found at ' . $serviceAccountPath . ' — push notifications DISABLED until it is provisioned (see config/firebase-setup-instructions.md)');
        }

        // Project ID: the service-account JSON already contains it; the env
        // var is only an override. Requiring the env var separately meant a
        // fully valid credential on disk was ignored when the optional-looking
        // FCM_PROJECT_ID line was missing from .env, and every push was
        // silently dropped. empty() (not ??) so a scaffolded blank
        // "FCM_PROJECT_ID=" line in .env doesn't defeat the fallback either.
        $this->projectId = !empty($_ENV['FCM_PROJECT_ID'])
            ? $_ENV['FCM_PROJECT_ID']
            : ($this->serviceAccount['project_id'] ?? null);
    }

    /**
     * True when this server can actually reach FCM (credentials present).
     */
    public function isConfigured(): bool {
        return !empty($this->serviceAccount) && !empty($this->projectId);
    }

    /**
     * Get OAuth2 access token
     */
    private function getAccessToken(): ?string {
        // Check if cached token is still valid
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry - 300) {
            return $this->accessToken;
        }

        if (!$this->serviceAccount) {
            return null;
        }

        try {
            // Create JWT
            $now = time();
            $payload = [
                'iss' => $this->serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600
            ];

            // Create JWT signature
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $segments = [
                $this->base64UrlEncode(json_encode($header)),
                $this->base64UrlEncode(json_encode($payload))
            ];
            $signingInput = implode('.', $segments);

            $signature = '';
            openssl_sign($signingInput, $signature, $this->serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
            $segments[] = $this->base64UrlEncode($signature);
            $jwt = implode('.', $segments);

            // Exchange JWT for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt
                ])
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $this->accessToken = $data['access_token'];
                $this->tokenExpiry = $now + ($data['expires_in'] ?? 3600);
                return $this->accessToken;
            }

            error_log("FCM: Failed to get access token. HTTP $httpCode - $response");
            return null;

        } catch (Exception $e) {
            error_log("FCM: Access token error - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * FCM v1 requires data to be a flat map of string => string.
     * Nested arrays/objects are JSON-encoded (strval() turned them into the
     * literal string "Array"), booleans become "1"/"0", nulls are dropped.
     */
    private function stringifyData(array $data): array {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $out[(string)$key] = $value ? '1' : '0';
            } elseif (is_array($value) || is_object($value)) {
                $out[(string)$key] = json_encode($value);
            } else {
                $out[(string)$key] = (string)$value;
            }
        }
        return $out;
    }

    /**
     * Send notification using FCM v1 API
     * Returns: true = success, false = failed but token valid, 'invalid_token' = token should be removed
     */
    public function send(string $token, array $notification, array $data = []) {
        // Get access token
        $accessToken = $this->getAccessToken();

        if (!$accessToken || !$this->projectId) {
            // No fallback: Google removed the legacy fcm/send endpoint in
            // June 2024, so the old "legacy server key" path can never
            // deliver. Missing v1 credentials is a configuration error that
            // must be fixed on the server, not silently retried.
            error_log('FCM: Cannot send — FCM v1 credentials missing or invalid '
                . '(service account: ' . ($this->serviceAccount ? 'present' : 'MISSING') . ', '
                . 'project id: ' . ($this->projectId ?: 'MISSING') . '). '
                . 'See config/firebase-setup-instructions.md');
            return false;
        }

        try {
            // Include notification info in data payload for app to handle
            // This ensures onMessageReceived() is always called
            $dataPayload = array_merge($data, [
                'title' => $notification['title'] ?? 'New Notification',
                'body' => $notification['body'] ?? '',
                'message' => $notification['body'] ?? '',
                'icon' => $notification['icon'] ?? 'notification_icon',
                'sound' => $notification['sound'] ?? 'default'
            ]);

            // Build FCM v1 message - DATA ONLY for Android to ensure onMessageReceived() is called
            // This allows the app to handle navigation properly
            $message = [
                'message' => [
                    'token' => $token,
                    'data' => $this->stringifyData($dataPayload),
                    'android' => [
                        'priority' => 'high'
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $notification['title'] ?? 'New Notification',
                                    'body' => $notification['body'] ?? ''
                                ],
                                'sound' => $notification['sound'] ?? 'default',
                                'badge' => 1
                            ]
                        ]
                    ]
                ]
            ];

            // Send to FCM v1 API
            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($message),
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return true;
            }

            $responseData = json_decode($response, true);
            $errorCode = $responseData['error']['details'][0]['errorCode'] ?? '';
            $errorStatus = $responseData['error']['status'] ?? '';

            // Only UNREGISTERED (device uninstalled / token rotated away)
            // means the token itself is dead and must be deleted.
            // INVALID_ARGUMENT is deliberately NOT treated as a dead token:
            // FCM returns it for any malformed payload too, and classifying
            // it as invalid_token made a payload bug permanently delete every
            // valid device token it touched. The one INVALID_ARGUMENT case
            // that does mean a bad token has a distinctive message, matched
            // explicitly below.
            if ($errorCode === 'UNREGISTERED' ||
                $errorStatus === 'NOT_FOUND' ||
                strpos((string)$response, 'not a valid FCM registration token') !== false) {
                error_log("FCM: Invalid token detected, marking for removal: $token");
                return 'invalid_token';
            }

            error_log("FCM v1 send failed: HTTP $httpCode - $response");
            return false;

        } catch (Exception $e) {
            error_log("FCM send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send to multiple tokens
     */
    public function sendMultiple(array $tokens, array $notification, array $data = []): array {
        $results = [];

        foreach ($tokens as $token) {
            $results[$token] = $this->send($token, $notification, $data);

            // Rate limiting - don't spam FCM
            usleep(50000); // 50ms delay between sends
        }

        return $results;
    }

    /**
     * Send to topic
     */
    public function sendToTopic(string $topic, array $notification, array $data = []): bool {
        $accessToken = $this->getAccessToken();

        if (!$accessToken || !$this->projectId) {
            error_log('FCM: Cannot send to topic — FCM v1 credentials missing (see config/firebase-setup-instructions.md)');
            return false;
        }

        $message = [
            'message' => [
                'topic' => $topic,
                'notification' => [
                    'title' => $notification['title'] ?? 'New Notification',
                    'body' => $notification['body'] ?? ''
                ],
                'data' => $this->stringifyData($data)
            ]
        ];

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($message),
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}
