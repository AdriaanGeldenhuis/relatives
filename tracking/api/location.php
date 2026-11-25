<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
$ctx->requireLocationSharing();
session_write_close();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$isBatch = is_array($input['locations'] ?? null);
if ($isBatch && $input['locations'] === []) {
    Response::error('locations array is required', 422);
}
$trackingCache = new TrackingCache($cache);
if (!$isBatch) {
    $settings = (new SettingsRepo($db, $trackingCache))->get($ctx->familyId);
    $rate = (new RateLimiter($trackingCache, (int) $settings['rate_limit_seconds']))->check($ctx->userId);
    if (!$rate['allowed']) {
        header('Retry-After: ' . $rate['retry_after']);
        Response::json(['success' => false, 'error' => 'rate_limited', 'retry_after' => $rate['retry_after']], 429);
    }
}
$points = $isBatch ? array_values(array_slice($input['locations'], 0, 100)) : [$input];
LocationIngest::respond((new LocationIngest($db, $trackingCache))->process($ctx, $points), !$isBatch);
