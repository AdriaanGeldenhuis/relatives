<?php
/**
 * Clean up seed test data from tracking_current.
 * Admin-only. Removes entries with device_id='seed-test'.
 * Delete this file after running.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);
if (!$ctx->isAdmin()) {
    Response::error('admin_required', 403);
}

$stmt = $db->prepare("DELETE FROM tracking_current WHERE device_id = 'seed-test' AND family_id = ?");
$stmt->execute([$ctx->familyId]);
$deleted = $stmt->rowCount();

$trackingCache = new TrackingCache($cache);
$trackingCache->deleteFamilySnapshot($ctx->familyId);

Response::success(['deleted' => $deleted], 'Seed data cleaned up. Real tracking will now take over.');
