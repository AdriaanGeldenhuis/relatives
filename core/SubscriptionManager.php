<?php
declare(strict_types=1);

/**
 * ============================================
 * SUBSCRIPTION MANAGER
 * Handles subscription logic, limits, trials
 * ============================================
 */

class SubscriptionManager {
    private PDO $db;
    
    // Free plan limits (3-day trial)
    const FREE_LIMITS = [
        'members' => 2,
        'messages' => 100,
        'notes' => 5,
        'shopping_items' => 20
    ];
    
    const TRIAL_DAYS = 3;
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    /**
     * Get family subscription status
     */
    public function getFamilySubscriptionStatus(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT f.*, s.status as sub_status, s.provider, s.store_product_id, 
                   s.expires_at, s.store_order_id
            FROM families f
            LEFT JOIN subscriptions s ON f.id = s.family_id 
                AND s.status IN ('active', 'trial')
                AND s.expires_at > NOW()
            WHERE f.id = ?
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$familyId]);
        $family = $stmt->fetch();
        
        if (!$family) {
            throw new Exception('Family not found');
        }
        
        $trialInfo = $this->getTrialInfo($familyId);
        $isLocked = $this->isFamilyLocked($familyId);
        
        // Determine status
        if ($family['sub_status'] === 'active' && $family['expires_at']) {
            $status = 'active';
            $provider = $family['provider'] ?? 'none';
            $planCode = $family['store_product_id'] ?? null;
        } elseif ($trialInfo['is_trial'] && !$trialInfo['expired']) {
            $status = 'trial';
            $provider = 'none';
            $planCode = null;
        } elseif ($isLocked) {
            $status = 'locked';
            $provider = 'none';
            $planCode = null;
        } else {
            $status = 'expired';
            $provider = 'none';
            $planCode = null;
        }
        
        return [
            'status' => $status,
            'trial_ends_at' => $trialInfo['ends_at'],
            'current_period_end' => $family['expires_at'],
            'provider' => $provider,
            'plan_code' => $planCode,
            'family_id' => $familyId
        ];
    }
    
    /**
     * Check if family is locked (trial expired + no active sub)
     */
    public function isFamilyLocked(int $familyId): bool {
        $stmt = $this->db->prepare("
            SELECT 
                f.created_at,
                COUNT(s.id) as active_subs
            FROM families f
            LEFT JOIN subscriptions s ON f.id = s.family_id 
                AND s.status = 'active'
                AND s.expires_at > NOW()
            WHERE f.id = ?
            GROUP BY f.id, f.created_at
        ");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch();
        
        if (!$row) {
            return true;
        }
        
        // Has active subscription = not locked
        if ((int)$row['active_subs'] > 0) {
            return false;
        }
        
        // Check if trial expired
        $createdAt = new DateTime($row['created_at']);
        $trialEnd = (clone $createdAt)->modify('+' . self::TRIAL_DAYS . ' days');
        $now = new DateTime();
        
        return $now > $trialEnd;
    }
    
    /**
     * Get trial information
     */
    public function getTrialInfo(int $familyId): array {
        $stmt = $this->db->prepare("SELECT created_at FROM families WHERE id = ?");
        $stmt->execute([$familyId]);
        $createdAt = $stmt->fetchColumn();
        
        if (!$createdAt) {
            return [
                'is_trial' => false,
                'ends_at' => null,
                'expired' => true
            ];
        }
        
        $created = new DateTime($createdAt);
        $trialEnd = (clone $created)->modify('+' . self::TRIAL_DAYS . ' days');
        $now = new DateTime();
        
        return [
            'is_trial' => true,
            'ends_at' => $trialEnd->format('Y-m-d H:i:s'),
            'expired' => $now > $trialEnd
        ];
    }
    
    /**
     * Activate subscription from native store
     */
    public function activateSubscription(
        int $familyId,
        string $provider,
        string $productId,
        string $purchaseToken,
        array $verificationResult
    ): array {
        if (!$verificationResult['ok'] || $verificationResult['status'] !== 'active') {
            throw new Exception('Subscription verification failed');
        }
        
        $this->db->beginTransaction();
        
        try {
            // Check if subscription already exists
            $stmt = $this->db->prepare("
                SELECT id FROM subscriptions 
                WHERE family_id = ? AND provider = ? AND store_purchase_token = ?
            ");
            $stmt->execute([$familyId, $provider, $purchaseToken]);
            $existingId = $stmt->fetchColumn();
            
            if ($existingId) {
                // Update existing
                $stmt = $this->db->prepare("
                    UPDATE subscriptions 
                    SET store_product_id = ?,
                        store_order_id = ?,
                        status = 'active',
                        expires_at = ?,
                        last_verified_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $productId,
                    $verificationResult['order_id'] ?? null,
                    $verificationResult['expiry_time'],
                    $existingId
                ]);
            } else {
                // Insert new
                $stmt = $this->db->prepare("
                    INSERT INTO subscriptions (
                        family_id, provider, store_product_id, store_purchase_token, 
                        store_order_id, plan_id, platform, platform_subscription_id,
                        platform_product_id, status, started_at, expires_at,
                        last_verified_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'active', NOW(), ?, NOW(), NOW())
                ");
                
                $platform = $provider === 'google_play' ? 'google' : 'apple';
                
                $stmt->execute([
                    $familyId,
                    $provider,
                    $productId,
                    $purchaseToken,
                    $verificationResult['order_id'] ?? null,
                    $platform,
                    $verificationResult['order_id'] ?? $purchaseToken,
                    $productId,
                    $verificationResult['expiry_time']
                ]);
            }
            
            // Log payment
            $stmt = $this->db->prepare("
                INSERT INTO payment_history (
                    family_id, provider, external_id, external_raw, 
                    amount_cents, currency, status, created_at
                ) VALUES (?, ?, ?, ?, 0, 'ZAR', 'successful', NOW())
            ");
            
            $stmt->execute([
                $familyId,
                $provider,
                $verificationResult['order_id'] ?? null,
                json_encode($verificationResult['raw'] ?? [])
            ]);
            
            $this->db->commit();
            
            return ['ok' => true, 'status' => 'active'];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}