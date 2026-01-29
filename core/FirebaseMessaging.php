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
        // Get project ID from environment
        $this->projectId = $_ENV['FCM_PROJECT_ID'] ?? null;
        
        // Load service account from file
        $serviceAccountPath = __DIR__ . '/../config/firebase-service-account.json';
        
        if (file_exists($serviceAccountPath)) {
            $this->serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
        } else {
            error_log('FCM: Service account file not found at ' . $serviceAccountPath);
        }
        
        // Fallback to legacy server key if service account not available
        if (!$this->serviceAccount) {
            error_log('FCM: Using legacy HTTP v1 API (service account recommended)');
        }
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
     * Send notification using FCM v1 API
     * Returns: true = success, false = failed but token valid, 'invalid_token' = token should be removed
     */
    public function send(string $token, array $notification, array $data = []) {
        // Get access token
        $accessToken = $this->getAccessToken();

        if (!$accessToken || !$this->projectId) {
            error_log('FCM: Cannot send - missing credentials');
            return $this->sendLegacy($token, $notification, $data);
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
                    'data' => array_map('strval', $dataPayload), // FCM requires string values
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

            // Check for invalid token errors - these tokens should be removed
            $responseData = json_decode($response, true);
            $errorCode = $responseData['error']['details'][0]['errorCode'] ?? '';
            $errorStatus = $responseData['error']['status'] ?? '';

            // Token is invalid/unregistered - should be deleted
            if ($errorCode === 'UNREGISTERED' ||
                $errorCode === 'INVALID_ARGUMENT' ||
                $errorStatus === 'NOT_FOUND' ||
                strpos($response, 'not a valid FCM') !== false ||
                strpos($response, 'UNREGISTERED') !== false) {
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
     * Legacy HTTP v1 fallback
     * Returns: true = success, false = failed, 'invalid_token' = token should be removed
     */
    private function sendLegacy(string $token, array $notification, array $data = []) {
        $serverKey = $_ENV['FCM_SERVER_KEY'] ?? null;

        if (!$serverKey) {
            error_log('FCM: No server key available');
            return false;
        }

        // Use data-only message for Android to ensure onMessageReceived() is called
        $dataPayload = array_merge($data, [
            'title' => $notification['title'] ?? 'New Notification',
            'body' => $notification['body'] ?? '',
            'message' => $notification['body'] ?? '',
            'icon' => $notification['icon'] ?? 'notification_icon',
            'sound' => $notification['sound'] ?? 'default'
        ]);

        $payload = [
            'to' => $token,
            'data' => $dataPayload,
            'priority' => 'high',
            'content_available' => true
        ];

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: key=' . $serverKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);

            // Check for invalid token in legacy response
            if (isset($result['results'][0]['error'])) {
                $error = $result['results'][0]['error'];
                if (in_array($error, ['NotRegistered', 'InvalidRegistration', 'MismatchSenderId'])) {
                    error_log("FCM Legacy: Invalid token detected: $error");
                    return 'invalid_token';
                }
            }

            return !isset($result['failure']) || $result['failure'] === 0;
        }

        return false;
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
            return false;
        }
        
        $message = [
            'message' => [
                'topic' => $topic,
                'notification' => [
                    'title' => $notification['title'] ?? 'New Notification',
                    'body' => $notification['body'] ?? ''
                ],
                'data' => array_map('strval', $data)
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