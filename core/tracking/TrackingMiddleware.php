<?php
declare(strict_types=1);

require_once __DIR__ . '/../SubscriptionManager.php';

/**
 * Check if the family's subscription is locked (trial ended).
 * If locked, sends a 402 JSON response and exits.
 *
 * @param PDO $db Database connection
 * @param int $familyId The family ID to check
 */
function tracking_requireActiveSubscription(PDO $db, int $familyId): void {
    $subscriptionManager = new SubscriptionManager($db);

    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue.'
        ]);
        exit;
    }
}
