<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Only owner can upgrade
    if ($user['role'] !== 'owner') {
        throw new Exception('Only family owner can upgrade subscription');
    }
    
    $yoco = new Yoco($db);
    
    // Check if already Pro
    if ($yoco->isPro($user['family_id'])) {
        throw new Exception('Already subscribed to Pro');
    }
    
    // Create checkout
    $checkout = $yoco->createCheckout($user['family_id'], [
        'user_id' => (string)$user['id'],
        'user_email' => $user['email'],
        'family_name' => $user['family_name']
    ]);
    
    echo json_encode([
        'success' => true,
        'checkout' => $checkout
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}