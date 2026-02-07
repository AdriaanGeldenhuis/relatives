<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('method_not_allowed', 405);

$ctx = SiteContext::require($db);

// Get FCM tokens for family members
$stmt = $db->prepare("
    SELECT td.fcm_token, td.device_type, u.full_name
    FROM tracking_devices td
    JOIN users u ON td.user_id = u.id
    WHERE u.family_id = ? AND td.user_id != ? AND td.active = 1
");
$stmt->execute([$ctx->familyId, $ctx->userId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
if (!empty($devices) && isset($firebase)) {
    foreach ($devices as $device) {
        try {
            $result = $firebase->send(
                $device['fcm_token'],
                ['title' => 'Family Location Request', 'body' => $ctx->userName . ' is requesting everyone\'s location'],
                ['type' => 'wake_tracking', 'requester' => $ctx->userName]
            );
            if ($result === true) $sent++;
        } catch (\Exception $e) {
            error_log('Wake push error: ' . $e->getMessage());
        }
    }
}

Response::success(['devices_notified' => $sent, 'total_devices' => count($devices)]);
