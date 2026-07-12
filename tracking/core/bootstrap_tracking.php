<?php
declare(strict_types=1);

/**
 * Tracking Bootstrap
 * Loads core app bootstrap + tracking-specific classes
 *
 * The session is started through the shared session_boot helper so the
 * hardened ini settings (30-day gc_maxlifetime, strict mode, secure cookie)
 * apply to these high-frequency endpoints too. A bare session_start() here
 * ran with the php.ini default 24-minute gc_maxlifetime, and PHP's GC firing
 * from the app's 15-second location polling deleted everyone's live
 * 30-day sessions — users kept getting logged out.
 */
require_once __DIR__ . '/../../core/session_boot.php';

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
