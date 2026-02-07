<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('method_not_allowed', 405);

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) Response::error('invalid_json', 400);

$stmt = $db->prepare("
    INSERT INTO tracking_consent (user_id, family_id, location_consent, notification_consent, background_consent, ip_address, user_agent)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        location_consent = VALUES(location_consent),
        notification_consent = VALUES(notification_consent),
        background_consent = VALUES(background_consent),
        ip_address = VALUES(ip_address),
        user_agent = VALUES(user_agent),
        updated_at = NOW()
");
$stmt->execute([
    $ctx->userId,
    $ctx->familyId,
    (int)($input['location_consent'] ?? 0),
    (int)($input['notification_consent'] ?? 0),
    (int)($input['background_consent'] ?? 0),
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

Response::success(null, 'Consent saved');
