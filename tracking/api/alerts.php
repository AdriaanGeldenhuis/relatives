<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);
$alertsRepo = new AlertsRepo($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) Response::error('invalid_json', 400);

    if (!empty($input['id'])) {
        $id = TrackingValidator::positiveInt($input['id']);
        if (!$id) Response::error('invalid id', 400);
        $alertsRepo->update($id, $ctx->familyId, $input);
        Response::success(null, 'Alert updated');
    } else {
        if (empty($input['name']) || empty($input['rule_type'])) Response::error('name and rule_type required', 400);
        $id = $alertsRepo->create($ctx->familyId, $ctx->userId, $input);
        Response::success(['id' => $id], 'Alert created');
    }
} else {
    Response::success($alertsRepo->getForFamily($ctx->familyId));
}
