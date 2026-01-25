<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    
    $subscriptionManager = new SubscriptionManager($db);
    $status = $subscriptionManager->getFamilySubscriptionStatus($user['family_id']);
    
    echo json_encode([
        'ok' => true,
        'status' => $status['status'],
        'trial_ends_at' => $status['trial_ends_at'],
        'current_period_end' => $status['current_period_end'],
        'provider' => $status['provider'],
        'plan_code' => $status['plan_code'],
        'family_id' => $status['family_id']
    ]);
    
} catch (Exception $e) {
    error_log('Subscription status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}