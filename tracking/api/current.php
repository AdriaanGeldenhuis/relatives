<?php
/**
 * GET /tracking/api/current.php
 *
 * Get current locations for all family members.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Initialize services
$locationRepo = new LocationRepo($db, $trackingCache);
$settingsRepo = new SettingsRepo($db, $trackingCache);

// Get family locations
$locations = $locationRepo->getFamilyCurrent($familyId);

// Get settings for stale threshold
$settings = $settingsRepo->get($familyId);

// Add status indicators
$staleThreshold = 300; // 5 minutes
$offlineThreshold = 3600; // 1 hour

foreach ($locations as &$loc) {
    $secondsSinceUpdate = Time::secondsSince($loc['updated_at']);

    if ($secondsSinceUpdate > $offlineThreshold) {
        $loc['status'] = 'offline';
    } elseif ($secondsSinceUpdate > $staleThreshold) {
        $loc['status'] = 'stale';
    } else {
        $loc['status'] = 'active';
    }

    $loc['last_seen_ago'] = Time::ago($loc['updated_at']);
}

// Get session status
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$sessionStatus = $sessionsRepo->getStatus($familyId);

jsonSuccess([
    'members' => $locations,
    'session' => $sessionStatus,
    'settings' => [
        'mode' => $settings['mode'],
        'units' => $settings['units']
    ],
    'timestamp' => Time::now()
]);
