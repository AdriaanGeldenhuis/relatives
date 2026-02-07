<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('method_not_allowed', 405);

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['fcm_token'])) {
    Response::error('fcm_token required', 400);
}

$token = trim($input['fcm_token']);
$deviceType = in_array($input['device_type'] ?? '', ['android', 'ios', 'web']) ? $input['device_type'] : 'android';
$deviceName = TrackingValidator::string($input['device_name'] ?? null, 100);

$stmt = $db->prepare("
    INSERT INTO tracking_devices (user_id, fcm_token, device_type, device_name, active)
    VALUES (?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), device_type = VALUES(device_type), device_name = VALUES(device_name), active = 1, updated_at = NOW()
");
$stmt->execute([$ctx->userId, $token, $deviceType, $deviceName]);

Response::success(null, 'Device registered');
