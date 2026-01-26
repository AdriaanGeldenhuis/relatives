<?php
/**
 * Tracking Bootstrap
 *
 * Initializes tracking context by loading site bootstrap
 * and setting up tracking-specific services.
 *
 * Usage:
 *   require_once __DIR__ . '/bootstrap_tracking.php';
 *   // Now $db, $cache, $trackingCache, $siteContext are available
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load site bootstrap (provides $db, $cache)
require_once __DIR__ . '/../../core/bootstrap.php';

// Load tracking core classes
require_once __DIR__ . '/SiteContext.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Time.php';

// Load tracking services
require_once __DIR__ . '/services/Cache.php';

// Load tracking repos
require_once __DIR__ . '/repos/SettingsRepo.php';
require_once __DIR__ . '/repos/LocationRepo.php';
require_once __DIR__ . '/repos/PlacesRepo.php';
require_once __DIR__ . '/repos/SessionsRepo.php';
require_once __DIR__ . '/repos/GeofenceRepo.php';
require_once __DIR__ . '/repos/EventsRepo.php';
require_once __DIR__ . '/repos/AlertsRepo.php';

// Load tracking services
require_once __DIR__ . '/services/SessionGate.php';
require_once __DIR__ . '/services/MotionGate.php';
require_once __DIR__ . '/services/RateLimiter.php';
require_once __DIR__ . '/services/Dedupe.php';
require_once __DIR__ . '/services/GeofenceEngine.php';
require_once __DIR__ . '/services/AlertsEngine.php';
require_once __DIR__ . '/services/MapboxDirections.php';

// Initialize tracking cache wrapper
$trackingCache = new TrackingCache($cache);

// Initialize site context (auth/session)
$siteContext = new SiteContext($db);

/**
 * Quick auth check for APIs.
 * Returns user array or sends 401 and exits.
 */
function requireAuth(): array
{
    global $siteContext;

    $user = $siteContext->getUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'not_authenticated',
            'message' => 'Authentication required'
        ]);
        exit;
    }

    return $user;
}

/**
 * Check subscription status.
 * Sends 402 and exits if locked.
 */
function requireActiveSubscription(int $familyId): void
{
    global $db;

    require_once __DIR__ . '/../../core/SubscriptionManager.php';
    $subscriptionManager = new SubscriptionManager($db);

    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your subscription has expired. Please renew to continue.'
        ]);
        exit;
    }
}

/**
 * Send JSON response and exit.
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send success response.
 */
function jsonSuccess($data = null, string $message = null): void
{
    $response = ['success' => true];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($message !== null) {
        $response['message'] = $message;
    }
    jsonResponse($response);
}

/**
 * Send error response.
 */
function jsonError(string $error, string $message, int $statusCode = 400): void
{
    jsonResponse([
        'success' => false,
        'error' => $error,
        'message' => $message
    ], $statusCode);
}
