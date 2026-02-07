<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);
$settingsRepo = new SettingsRepo($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) Response::error('invalid_json', 400);

    $settingsRepo->save($ctx->familyId, $input);
    Response::success($settingsRepo->getForFamily($ctx->familyId), 'Settings saved');
} else {
    Response::success($settingsRepo->getForFamily($ctx->familyId));
}
