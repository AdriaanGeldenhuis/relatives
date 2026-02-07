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

$repo = new AlertsRepo($db);

$saved = $repo->save($ctx->familyId, $input);

if (!$saved) {
    Response::error('no_valid_fields', 422);
}

$trackingCache = new TrackingCache($cache);
$trackingCache->deleteAlertRules($ctx->familyId);

Response::success(null, 'Alert rules saved');
