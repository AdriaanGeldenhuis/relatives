<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

if (!$ctx->isAdmin()) {
    Response::error('admin_required', 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (empty($input)) {
    Response::error('no_data_provided', 422);
}

$trackingCache = new TrackingCache($cache);
$settingsRepo = new SettingsRepo($db, $trackingCache);

if (isset($input['mode']) && !in_array((string) $input['mode'], ['0', '1', '2'], true)) {
    Response::error('invalid_tracking_mode', 422);
}
$settingsRepo->get($ctx->familyId);
$db->beginTransaction();
try {
    $previous = $settingsRepo->lockForTracking($ctx->familyId, true);
    $saved = $settingsRepo->save($ctx->familyId, $input);
    if ($saved && isset($input['mode']) && ((int) $input['mode'] === 0) !== ((int) $previous['mode'] === 0)) {
        TrackingPolicy::setCutoff($db, 'family', $ctx->familyId);
        $db->prepare('DELETE FROM tracking_current WHERE family_id = ?')->execute([$ctx->familyId]);
        if ((int) $input['mode'] === 0 && FamilyLive::available($db)) {
            (new FamilyLive($db))->end($ctx->familyId);
        }
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
if (!$saved) {
    Response::error('no_valid_settings_provided', 422);
}
$trackingCache->deleteSettings($ctx->familyId);
Response::success($settingsRepo->get($ctx->familyId), 'Settings saved');
