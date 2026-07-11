<?php
declare(strict_types=1);

/**
 * Returns the site's public Mapbox access token for the native app's map.
 * Auth-gated: only logged-in family members can read it. This is the same
 * public (pk.) token the web tracking page embeds in its HTML.
 */
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

SiteContext::require($db);

$token = $_ENV['MAPBOX_TOKEN'] ?? '';
if ($token === '') {
    Response::error('mapbox_token_not_configured', 500);
}

Response::success(['token' => $token]);
