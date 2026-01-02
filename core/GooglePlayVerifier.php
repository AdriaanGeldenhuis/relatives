<?php
declare(strict_types=1);

/**
 * ============================================
 * GOOGLE PLAY BILLING VERIFIER
 * Verifies purchases with Google Play Developer API
 * ============================================
 */

class GooglePlayVerifier {
    private PDO $db;
    private string $packageName;
    private string $serviceAccountJson;
    
    public function __construct(PDO $db) {
        $this->db = $db;
        $this->packageName = $_ENV['GOOGLE_PACKAGE_NAME'] ?? 'za.co.relatives';
        $this->serviceAccountJson = $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '';
    }
    
    /**
     * Verify subscription with Google Play
     */
    public function verifySubscription(string $productId, string $purchaseToken): array {
        try {
            if (empty($this->serviceAccountJson)) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Google Play service account not configured'
                ];
            }
            
            $accessToken = $this->getAccessToken();
            
            if (!$accessToken) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Failed to obtain access token'
                ];
            }
            
            $url = sprintf(
                'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s',
                urlencode($this->packageName),
                urlencode($productId),
                urlencode($purchaseToken)
            );
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                error_log("Google Play API cURL error: $curlError");
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Network error communicating with Google Play'
                ];
            }
            
            if ($httpCode !== 200) {
                error_log("Google Play API HTTP $httpCode: $response");
                return [
                    'ok' => false,
                    'status' => 'invalid',
                    'error' => 'Subscription verification failed'
                ];
            }
            
            $data = json_decode($response, true);
            
            if (!$data) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Invalid response from Google Play'
                ];
            }
            
            // Parse Google's response
            // paymentState: 0 = pending, 1 = received, 2 = free trial, 3 = pending deferred
            $paymentState = (int)($data['paymentState'] ?? 0);
            
            // Calculate status
            $status = 'pending';
            $expiryMillis = (int)($data['expiryTimeMillis'] ?? 0);
            $now = time() * 1000;
            
            if ($expiryMillis > $now) {
                if ($paymentState === 1 || $paymentState === 2) {
                    $status = 'active';
                }
            } else {
                $status = 'expired';
            }
            
            // Check if cancelled
            if (isset($data['cancelReason']) || isset($data['userCancellationTimeMillis'])) {
                $status = 'cancelled';
            }
            
            return [
                'ok' => true,
                'status' => $status,
                'order_id' => $data['orderId'] ?? null,
                'expiry_time' => $expiryMillis > 0 ? date('Y-m-d H:i:s', intdiv($expiryMillis, 1000)) : null,
                'raw' => $data
            ];
            
        } catch (Exception $e) {
            error_log("Google Play verification exception: " . $e->getMessage());
            return [
                'ok' => false,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
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
            
            openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            openssl_free_key($privateKey);
            
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
                error_log("OAuth token request failed: HTTP $httpCode - $response");
                return null;
            }
            
            $tokenData = json_decode($response, true);
            return $tokenData['access_token'] ?? null;
            
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