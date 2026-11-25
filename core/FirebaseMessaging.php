<?php
/**
 * ============================================
 * FIREBASE CLOUD MESSAGING (FCM) v1 API
 * Modern implementation with service account
 * ============================================
 */

class FirebaseMessaging {
    private $projectId;
    private ?array $serviceAccount = null;
    private $accessToken;
    private $tokenExpiry;
    private string $lastFailure = '';
    private string $configurationFailure = '';

    public function __construct() {
        // Prefer the account directory above public_html. Older installations
        // can continue using the filename protected by the root .htaccess.
        $privatePath = dirname(__DIR__, 2) . '/firebase-service-account.json';
        $legacyPath = __DIR__ . '/../config/firebase-service-account.json';
        $path = @is_file($privatePath) ? $privatePath : $legacyPath;

        if (!is_file($path)) {
            $this->configurationFailure = 'firebase_key_missing';
        } else {
            $json = @file_get_contents($path);
            $account = $json === false ? null : json_decode($json, true);
            if (is_array($account)) {
                $this->serviceAccount = $account;
            }
            $this->projectId = !empty($_ENV['FCM_PROJECT_ID'])
                ? $_ENV['FCM_PROJECT_ID']
                : ($this->serviceAccount['project_id'] ?? null);
            if (!$this->isConfigured()) {
                $this->configurationFailure = $json === false ? 'firebase_key_unreadable' : 'firebase_key_invalid';
            }
        }

        if ($this->configurationFailure !== '') {
            error_log('FCM: ' . $this->configurationFailure);
        }
    }

    public function isConfigured(): bool {
        foreach ([$this->serviceAccount['client_email'] ?? null, $this->serviceAccount['private_key'] ?? null, $this->projectId] as $field) {
            if (!is_string($field) || trim($field) === '') {
                return false;
            }
        }
        return true;
    }

    public function prepareSend(): bool {
        $this->lastFailure = $this->configurationFailure;
        return $this->isConfigured() && $this->getAccessToken() !== null;
    }

    public function lastFailure(): string {
        return $this->lastFailure;
    }

    /**
     * Get OAuth2 access token
     */
    private function getAccessToken(): ?string {
        // Check if cached token is still valid
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry - 300) {
            return $this->accessToken;
        }

        if (!$this->isConfigured()) {
            return null;
        }
        if (!function_exists('openssl_sign') || !function_exists('curl_init')) {
            return $this->tokenFailure('firebase_php_extension_missing');
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
            if (!@openssl_sign($signingInput, $signature, $this->serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) {
                return $this->tokenFailure('firebase_key_invalid');
            }
            $segments[] = $this->base64UrlEncode($signature);
            $jwt = implode('.', $segments);

            // Exchange JWT for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt
                ])
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $transportCode = curl_errno($ch);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $data = json_decode($response, true);
                if (!is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
                    return $this->tokenFailure('firebase_auth_unavailable', $httpCode);
                }
                $this->accessToken = $data['access_token'];
                $this->tokenExpiry = $now + ($data['expires_in'] ?? 3600);
                return $this->accessToken;
            }

            $reason = $httpCode >= 400 && $httpCode < 500 && $httpCode !== 429
                ? 'firebase_auth_rejected' : 'firebase_auth_unavailable';
            return $this->tokenFailure($reason, $httpCode, $transportCode);
        } catch (Throwable) {
            return $this->tokenFailure('firebase_auth_unavailable');
        }
    }

    private function tokenFailure(string $reason, int $httpCode = 0, int $transportCode = 0): ?string {
        $this->lastFailure = $reason;
        error_log("FCM: $reason (OAuth HTTP $httpCode, cURL $transportCode)");
        return null;
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
     *
     * $androidOverrides is merged into the message's android block. Wake
     * pushes pass ['ttl' => '60s', 'collapse_key' => 'wake_tracking']: without
     * a TTL, FCM stores an undeliverable message for its default 4 WEEKS and
     * replays every queued copy when the device comes back online — a phone
     * switched on in the morning burst-fired GPS for each overnight wake.
     * collapse_key keeps at most one pending copy per kind.
     */
    public function send(string $token, array $notification, array $data = [], array $androidOverrides = [], int $timeoutMs = 10000) {
        $this->lastFailure = '';
        // Get access token
        $accessToken = $this->getAccessToken();

        if (!$accessToken || !$this->projectId) {
            $this->lastFailure = 'configuration';
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
                    'android' => array_merge([
                        'priority' => 'high'
                    ], $androidOverrides),
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
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return true;
            }

            $responseData = json_decode(is_string($response) ? $response : '', true);
            $errorCode = '';
            foreach ($responseData['error']['details'] ?? [] as $detail) {
                if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') {
                    $errorCode = $detail['errorCode'] ?? '';
                    break;
                }
            }
            if ($errorCode === 'UNREGISTERED' || ($errorCode === 'INVALID_ARGUMENT'
                && str_contains((string) ($responseData['error']['message'] ?? ''), 'not a valid FCM registration token'))) {
                $this->lastFailure = 'invalid_token';
                return 'invalid_token';
            }

            $this->lastFailure = match (true) {
                in_array($httpCode, [401, 403, 404], true) => 'configuration',
                $httpCode === 0 || $httpCode === 429 || $httpCode >= 500 => 'temporary',
                default => 'rejected',
            };
            error_log("FCM v1 send failed: HTTP $httpCode ($this->lastFailure)");
            return false;

        } catch (Exception $e) {
            $this->lastFailure = 'temporary';
            error_log("FCM transport error");
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
