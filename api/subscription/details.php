<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    // Get family subscription details
    $stmt = $db->prepare("
        SELECT 
            s.*,
            f.name as family_name,
            f.created_at as family_created_at
        FROM subscriptions s
        JOIN families f ON s.family_id = f.id
        WHERE s.family_id = ?
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['family_id']]);
    $subscription = $stmt->fetch();
    
    // Get subscription status
    $subscriptionManager = new SubscriptionManager($db);
    $status = $subscriptionManager->getFamilySubscriptionStatus($user['family_id']);
    $trialInfo = $subscriptionManager->getTrialInfo($user['family_id']);
    
    // Get payment history
    $stmt = $db->prepare("
        SELECT 
            id,
            provider,
            external_id,
            amount_cents,
            currency,
            status,
            created_at
        FROM payment_history
        WHERE family_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user['family_id']]);
    $payments = $stmt->fetchAll();
    
    echo json_encode([
        'ok' => true,
        'subscription' => $subscription ?: null,
        'status' => $status,
        'trial' => $trialInfo,
        'payment_history' => $payments,
        'is_locked' => $subscriptionManager->isFamilyLocked($user['family_id'])
    ]);
    
} catch (Exception $e) {
    error_log('Subscription details error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}