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
                f.trial_ends_at,
                f.subscription_status,
                f.subscription_expires_at,
                COUNT(s.id) as active_subs
            FROM families f
            LEFT JOIN subscriptions s ON f.id = s.family_id
                AND s.status = 'active'
                AND s.expires_at > NOW()
            WHERE f.id = ?
            GROUP BY f.id, f.created_at, f.trial_ends_at, f.subscription_status, f.subscription_expires_at
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

        // Check family-level subscription
        if ($row['subscription_status'] === 'active' && $row['subscription_expires_at']) {
            $expiresAt = new DateTime($row['subscription_expires_at']);
            if (new DateTime() < $expiresAt) {
                return false;
            }
        }

        // Check if trial expired - use trial_ends_at if set, fallback to created_at + 3 days
        $now = new DateTime();
        if ($row['trial_ends_at']) {
            $trialEnd = new DateTime($row['trial_ends_at']);
        } else {
            $createdAt = new DateTime($row['created_at']);
            $trialEnd = (clone $createdAt)->modify('+' . self::TRIAL_DAYS . ' days');
        }

        return $now > $trialEnd;
    }
    
    /**
     * Get trial information
     */
    public function getTrialInfo(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT created_at, trial_started_at, trial_ends_at, subscription_status
            FROM families WHERE id = ?
        ");
        $stmt->execute([$familyId]);
        $family = $stmt->fetch();

        if (!$family) {
            return [
                'is_trial' => false,
                'ends_at' => null,
                'expired' => true
            ];
        }

        $now = new DateTime();

        // Use explicit trial_ends_at if set, otherwise calculate from created_at
        if ($family['trial_ends_at']) {
            $trialEnd = new DateTime($family['trial_ends_at']);
        } else {
            $created = new DateTime($family['created_at']);
            $trialEnd = (clone $created)->modify('+' . self::TRIAL_DAYS . ' days');
        }

        $isTrial = $family['subscription_status'] === 'trial' || !$family['subscription_status'];

        return [
            'is_trial' => $isTrial,
            'ends_at' => $trialEnd->format('Y-m-d H:i:s'),
            'expired' => $now > $trialEnd
        ];
    }
    
    public const GOOGLE_PLAY_MONTHLY_PRODUCTS = [
        'relatives.starter.monthly', 'relatives.family.monthly', 'relatives.big.monthly',
    ];

    public function googlePlayProducts(): array {
        $stmt = $this->db->prepare('SELECT code FROM plans WHERE is_active = 1 AND code IN (?, ?, ?)');
        $stmt->execute(self::GOOGLE_PLAY_MONTHLY_PRODUCTS);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Commit an already verified entitlement. Serialize replays of the same
     * token so two families cannot claim an existing purchase concurrently.
     */
    public function activateSubscription(
        int $familyId,
        string $provider,
        string $productId,
        string $purchaseToken,
        array $verificationResult
    ): void {
        if (($verificationResult['ok'] ?? false) !== true || $verificationResult['status'] !== 'active') {
            throw new DomainException('Purchase has no active entitlement.');
        }
        $lockName = hash('sha256', 'relatives:purchase:' . $provider . ':' . $purchaseToken);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not lock purchase.');
        }

        try {
            $this->db->beginTransaction();
            $find = $this->db->prepare('
                SELECT id, family_id, plan_id FROM subscriptions
                WHERE provider = ? AND BINARY store_purchase_token = ?
                FOR UPDATE
            ');
            $find->execute([$provider, $purchaseToken]);
            $existing = $find->fetchAll();
            foreach ($existing as $record) {
                if ((int) $record['family_id'] !== $familyId) {
                    throw new DomainException('Purchase is linked to another family.');
                }
            }

            if ($provider === 'google_play') {
                $account = $verificationResult['account_id'] ?? null;
                // Old purchases can be restored against their existing server association.
                if ($account === null ? !$existing :
                    !is_string($account) || !hash_equals(GooglePlayVerifier::familyAccountId($familyId), $account)) {
                    throw new DomainException('Purchase was not made for this family.');
                }
            }

            $plan = $this->db->prepare('SELECT id FROM plans WHERE code = ? AND is_active = 1');
            $plan->execute([$productId]);
            $planId = $plan->fetchColumn();
            if ($planId === false) {
                $planId = $existing[0]['plan_id'] ?? null;
            }
            if ($planId === null) {
                throw new DomainException('Purchase plan is unavailable.');
            }

            $expiry = $verificationResult['expiry_time'];
            $start = $verificationResult['start_time'] ?? null;
            $order = $verificationResult['order_id'] ?? null;
            if ($existing) {
                $write = $this->db->prepare("
                    UPDATE subscriptions SET store_product_id = ?, store_order_id = ?, plan_id = ?,
                        status = 'active', expires_at = ?, last_verified_at = NOW()
                    WHERE provider = ? AND BINARY store_purchase_token = ? AND family_id = ?
                ");
                $write->execute([$productId, $order, $planId, $expiry, $provider, $purchaseToken, $familyId]);
            } else {
                $write = $this->db->prepare("
                    INSERT INTO subscriptions (
                        family_id, provider, store_product_id, store_purchase_token, store_order_id,
                        plan_id, status, current_period_start, expires_at, last_verified_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW())
                ");
                $write->execute([$familyId, $provider, $productId, $purchaseToken, $order, $planId, $start, $expiry]);
            }

            $family = $this->db->prepare("
                UPDATE families SET current_plan_id = ?, billing_platform = ?,
                    subscription_status = 'active', subscription_expires_at = ?
                WHERE id = ? AND (subscription_expires_at IS NULL OR subscription_expires_at <= ?)
            ");
            $family->execute([$planId, $provider, $expiry, $familyId, $expiry]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** Revoke a Google Play entitlement after a successful store-state check. */
    public function expireGooglePlaySubscription(int $subscriptionId, string $verifiedExpiry): void {
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare("
                SELECT id, family_id, plan_id, expires_at FROM subscriptions
                WHERE id = ? AND provider = 'google_play' FOR UPDATE
            ");
            $find->execute([$subscriptionId]);
            $previous = $find->fetch();
            if (!$previous) {
                $this->db->commit();
                return;
            }

            $update = $this->db->prepare("
                UPDATE subscriptions SET status = IF(? > NOW(), 'paused', 'expired'),
                    expires_at = ?, last_verified_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$verifiedExpiry, $verifiedExpiry, $subscriptionId]);

            $active = $this->db->prepare("
                SELECT plan_id, provider, expires_at FROM subscriptions
                WHERE family_id = ? AND status = 'active' AND expires_at > NOW()
                    AND provider IN ('google_play', 'apple_app_store')
                ORDER BY expires_at DESC LIMIT 1
            ");
            $active->execute([$previous['family_id']]);
            $replacement = $active->fetch();
            $family = $this->db->prepare("
                UPDATE families SET current_plan_id = ?, billing_platform = ?,
                    subscription_status = ?, subscription_expires_at = ?
                WHERE id = ? AND billing_platform = 'google_play'
                    AND current_plan_id <=> ? AND subscription_expires_at <=> ?
            ");
            $family->execute([
                $replacement['plan_id'] ?? null,
                $replacement['provider'] ?? 'none',
                $replacement ? 'active' : 'expired',
                $replacement['expires_at'] ?? null,
                $previous['family_id'], $previous['plan_id'], $previous['expires_at'],
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

}
