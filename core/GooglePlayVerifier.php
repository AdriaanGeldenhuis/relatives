<?php
declare(strict_types=1);

/**
 * ============================================
 * GOOGLE PLAY BILLING VERIFIER
 * Verifies purchases with Google Play Developer API
 * ============================================
 */

class GooglePlayVerifier {
    private string $packageName;
    private string $serviceAccountJson;
    private ?string $accessToken = null;

    public function __construct() {
        $this->packageName = $_ENV['GOOGLE_PACKAGE_NAME'] ?? 'za.co.relatives.app';
        $this->serviceAccountJson = $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '';
    }

    public function isConfigured(): bool {
        $account = json_decode($this->serviceAccountJson, true);
        return $this->packageName === 'za.co.relatives.app'
            && is_array($account)
            && !empty($account['client_email'])
            && !empty($account['private_key']);
    }

    public static function familyAccountId(int $familyId): string {
        return hash('sha256', 'relatives:google-play:family:' . $familyId);
    }

    public function verifySubscription(string $productId, string $purchaseToken): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 'unavailable'];
        }

        $result = $this->publisherRequest('/purchases/subscriptionsv2/tokens/' . rawurlencode($purchaseToken));
        if ($result['http'] !== 200) {
            return ['ok' => false, 'status' => in_array($result['http'], [400, 404, 410], true) ? 'invalid' : 'unavailable'];
        }

        $subscription = $result['data'];
        $line = null;
        foreach (($subscription['lineItems'] ?? []) as $item) {
            if (($item['productId'] ?? null) === $productId
                && ($line === null || strcmp($item['expiryTime'] ?? '', $line['expiryTime'] ?? '') > 0)) {
                $line = $item;
            }
        }
        if ($line === null || empty($line['expiryTime'])) {
            return ['ok' => false, 'status' => 'invalid'];
        }

        try {
            $expiry = new DateTimeImmutable($line['expiryTime']);
            $start = isset($subscription['startTime']) ? new DateTimeImmutable($subscription['startTime']) : null;
        } catch (Exception $exception) {
            return ['ok' => false, 'status' => 'unavailable'];
        }

        // Cancellation retains the entitlement until the paid period ends.
        $state = $subscription['subscriptionState'] ?? '';
        $paidPeriod = in_array($state, [
            'SUBSCRIPTION_STATE_ACTIVE',
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
            'SUBSCRIPTION_STATE_CANCELED',
        ], true);
        $timezone = new DateTimeZone(date_default_timezone_get());
        return [
            'ok' => true,
            'status' => $paidPeriod && $expiry->getTimestamp() > time() ? 'active' : 'expired',
            'order_id' => $line['latestSuccessfulOrderId'] ?? $subscription['latestOrderId'] ?? null,
            'expiry_time' => $expiry->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'start_time' => $start?->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'account_id' => $subscription['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null,
            'acknowledged' => ($subscription['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
        ];
    }

    public function acknowledgeSubscription(string $productId, string $purchaseToken): bool {
        $path = '/purchases/subscriptions/' . rawurlencode($productId) . '/tokens/'
            . rawurlencode($purchaseToken) . ':acknowledge';
        $result = $this->publisherRequest($path, true);
        return $result['http'] >= 200 && $result['http'] < 300;
    }

    private function publisherRequest(string $path, bool $post = false): array {
        $this->accessToken ??= $this->getAccessToken();
        if ($this->accessToken === null) {
            return ['http' => 0, 'data' => []];
        }

        $url = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'
            . rawurlencode($this->packageName) . $path;
        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($post) {
            curl_setopt($request, CURLOPT_POSTFIELDS, '{}');
        }
        $response = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $data = is_string($response) ? json_decode($response, true) : null;
        return ['http' => $status, 'data' => is_array($data) ? $data : []];
    }

    /**
     * Get OAuth access token from service account
     */
    private function getAccessToken(): ?string {
        try {
            $serviceAccount = json_decode($this->serviceAccountJson, true);
            
            if (!$serviceAccount || !isset($serviceAccount['private_key'], $serviceAccount['client_email'])) {
                error_log("Invalid service account JSON");
                return null;
            }
            
            $now = time();
            $expiry = $now + 3600; // 1 hour
            
            // JWT header
            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT'
            ];
            
            // JWT claim set
            $claim = [
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $expiry,
                'iat' => $now
            ];
            
            // Encode
            $headerEncoded = $this->base64UrlEncode(json_encode($header));
            $claimEncoded = $this->base64UrlEncode(json_encode($claim));
            $signatureInput = "$headerEncoded.$claimEncoded";
            
            // Sign with private key
            $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
            if (!$privateKey) {
                error_log("Failed to load private key");
                return null;
            }
            
            if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                return null;
            }
            
            $signatureEncoded = $this->base64UrlEncode($signature);
            $jwt = "$signatureInput.$signatureEncoded";
            
            // Exchange JWT for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt
                ]),
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("Google Play OAuth token request failed: HTTP $httpCode");
                return null;
            }
            
            $tokenData = is_string($response) ? json_decode($response, true) : null;
            return is_string($tokenData['access_token'] ?? null) ? $tokenData['access_token'] : null;
            
        } catch (Exception $e) {
            error_log("Access token generation failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Base64 URL-safe encoding
     */
    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}