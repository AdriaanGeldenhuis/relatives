<?php
declare(strict_types=1);

/**
 * ============================================
 * APPLE APP STORE VERIFIER
 * Verifies purchases with Apple App Store
 * ============================================
 */

class AppleAppStoreVerifier {
    private PDO $db;
    private string $sharedSecret;
    private bool $useSandbox;
    
    public function __construct(PDO $db) {
        $this->db = $db;
        $this->sharedSecret = $_ENV['APPLE_SHARED_SECRET'] ?? '';
        $this->useSandbox = ($_ENV['APP_ENV'] ?? 'production') !== 'production';
    }
    
    /**
     * Verify subscription with Apple
     */
    public function verifySubscription(string $productId, string $receiptData): array {
        try {
            if (empty($this->sharedSecret)) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Apple shared secret not configured'
                ];
            }
            
            // Try production first, fallback to sandbox if 21007
            $url = $this->useSandbox 
                ? 'https://sandbox.itunes.apple.com/verifyReceipt'
                : 'https://buy.itunes.apple.com/verifyReceipt';
            
            $payload = [
                'receipt-data' => $receiptData,
                'password' => $this->sharedSecret,
                'exclude-old-transactions' => true
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                error_log("Apple receipt verification cURL error: $curlError");
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Network error communicating with Apple'
                ];
            }
            
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['status'])) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'error' => 'Invalid response from Apple'
                ];
            }
            
            // Status codes: 0 = valid, 21007 = sandbox receipt on prod (retry sandbox)
            if ($data['status'] === 21007 && !$this->useSandbox) {
                // Retry with sandbox
                $this->useSandbox = true;
                return $this->verifySubscription($productId, $receiptData);
            }
            
            if ($data['status'] !== 0) {
                return [
                    'ok' => false,
                    'status' => 'invalid',
                    'error' => 'Receipt verification failed: status ' . $data['status']
                ];
            }
            
            // Find the subscription in latest_receipt_info
            $latestReceipts = $data['latest_receipt_info'] ?? [];
            $matchingReceipt = null;
            
            foreach ($latestReceipts as $receipt) {
                if ($receipt['product_id'] === $productId) {
                    $matchingReceipt = $receipt;
                    break;
                }
            }
            
            if (!$matchingReceipt) {
                return [
                    'ok' => false,
                    'status' => 'invalid',
                    'error' => 'Product not found in receipt'
                ];
            }
            
            // Parse expiry
            $expiryMs = (int)($matchingReceipt['expires_date_ms'] ?? 0);
            $now = time() * 1000;
            
            $status = $expiryMs > $now ? 'active' : 'expired';
            
            // Check cancellation
            if (isset($matchingReceipt['cancellation_date_ms'])) {
                $status = 'cancelled';
            }
            
            return [
                'ok' => true,
                'status' => $status,
                'order_id' => $matchingReceipt['transaction_id'] ?? null,
                'expiry_time' => $expiryMs > 0 ? date('Y-m-d H:i:s', intdiv($expiryMs, 1000)) : null,
                'raw' => $data
            ];
            
        } catch (Exception $e) {
            error_log("Apple receipt verification exception: " . $e->getMessage());
            return [
                'ok' => false,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
}