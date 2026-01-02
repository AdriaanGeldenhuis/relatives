<?php
/**
 * Check if user is logged in
 */
session_start();
header('Content-Type: application/json');

$response = [
    'loggedIn' => false,
    'user' => null
];

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/bootstrap.php';
    
    $stmt = $db->prepare("
        SELECT id, full_name as name, email, role, avatar_color, family_id
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        $response['loggedIn'] = true;
        $response['user'] = $user;
    }
}

echo json_encode($response);