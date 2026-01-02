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
    
    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request body');
    }
    
    $platform = $input['platform'] ?? ''; // 'google_play' or 'apple_app_store'
    $familyId = (int)($input['family_id'] ?? $user['family_id']);
    $planCode = $input['plan_code'] ?? '';
    $productId = $input['product_id'] ?? $planCode;
    $purchaseToken = $input['purchase_token'] ?? '';
    
    // Validate platform
    if (!in_array($platform, ['google_play', 'apple_app_store'])) {
        throw new Exception('Invalid platform. Must be google_play or apple_app_store');
    }
    
    // Validate required fields
    if (empty($productId) || empty($purchaseToken)) {
        throw new Exception('Missing required fields: product_id, purchase_token');
    }
    
    // Verify family access
    if ($familyId !== $user['family_id']) {
        throw new Exception('You can only manage subscriptions for your own family');
    }
    
    // Verify purchase with store
    $verificationResult = null;
    
    if ($platform === 'google_play') {
        $verifier = new GooglePlayVerifier($db);
        $verificationResult = $verifier->verifySubscription($productId, $purchaseToken);
    } elseif ($platform === 'apple_app_store') {
        if (class_exists('AppleAppStoreVerifier')) {
            $verifier = new AppleAppStoreVerifier($db);
            $verificationResult = $verifier->verifySubscription($productId, $purchaseToken);
        } else {
            throw new Exception('Apple App Store verification not available yet');
        }
    }
    
    // Check verification result
    if (!$verificationResult || !$verificationResult['ok']) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $verificationResult['error'] ?? 'Verification failed',
            'details' => 'The purchase could not be verified with ' . $platform
        ]);
        exit;
    }
    
    if ($verificationResult['status'] !== 'active') {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Subscription is not active',
            'status' => $verificationResult['status']
        ]);
        exit;
    }
    
    // Activate subscription
    $subscriptionManager = new SubscriptionManager($db);
    $result = $subscriptionManager->activateSubscription(
        $familyId,
        $platform,
        $productId,
        $purchaseToken,
        $verificationResult
    );
    
    // Log audit
    $stmt = $db->prepare("
        INSERT INTO audit_log (family_id, user_id, action, entity_type, meta, created_at)
        VALUES (?, ?, 'subscription_activated', 'subscription', ?, NOW())
    ");
    $stmt->execute([
        $familyId,
        $user['id'],
        json_encode([
            'platform' => $platform,
            'product_id' => $productId,
            'order_id' => $verificationResult['order_id'] ?? null
        ])
    ]);
    
    echo json_encode([
        'ok' => true,
        'status' => 'active',
        'message' => 'Subscription activated successfully',
        'expiry' => $verificationResult['expiry_time'] ?? null
    ]);
    
} catch (Exception $e) {
    error_log('Subscription activation error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}