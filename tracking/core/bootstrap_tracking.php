<?php
declare(strict_types=1);

/**
 * Tracking Bootstrap
 * Loads core app bootstrap + tracking-specific classes
 *
 * IMPORTANT: Start session BEFORE core bootstrap.
 * The login flow stores session data under the default PHPSESSID cookie.
 * If bootstrap.php starts the session first, it uses RELATIVES_SESSION
 * cookie name, which reads from a different (empty) session.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
    session_start();
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/GeoUtils.php';

// Load tracking core
require_once __DIR__ . '/SiteContext.php';
require_once __DIR__ . '/Time.php';
require_once __DIR__ . '/Validator.php';

// Load services
$servicesDir = __DIR__ . '/services';
require_once $servicesDir . '/TrackingCache.php';
require_once $servicesDir . '/RateLimiter.php';
require_once $servicesDir . '/Dedupe.php';
require_once $servicesDir . '/SessionGate.php';
require_once $servicesDir . '/MotionGate.php';
require_once $servicesDir . '/GeofenceEngine.php';
require_once $servicesDir . '/AlertsEngine.php';
require_once $servicesDir . '/MapboxDirections.php';

// Load repos
$reposDir = __DIR__ . '/repos';
require_once $reposDir . '/SettingsRepo.php';
require_once $reposDir . '/SessionsRepo.php';
require_once $reposDir . '/LocationRepo.php';
require_once $reposDir . '/EventsRepo.php';
require_once $reposDir . '/GeofenceRepo.php';
require_once $reposDir . '/PlacesRepo.php';
require_once $reposDir . '/AlertsRepo.php';
