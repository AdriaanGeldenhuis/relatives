<?php
/**
 * POST /tracking/api/keepalive.php
 *
 * Keeps the family tracking session alive (Mode 1).
 * Called periodically by the tracking UI.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Optional: check subscription
requireActiveSubscription($familyId);

// Initialize services
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$sessionGate = new SessionGate($sessionsRepo, $settingsRepo);

// Check if this is a new session start
$wasActive = $sessionsRepo->isActive($familyId);

// Keepalive
$session = $sessionGate->keepalive($familyId, $user['id']);

// Log session start event if new
if (!$wasActive) {
    $eventsRepo->logSessionOn($familyId, $user['id']);
}

// Get settings for interval info
$settings = $settingsRepo->get($familyId);

jsonSuccess([
    'session' => [
        'id' => $session['id'],
        'active' => true,
        'expires_at' => $session['expires_at'],
        'expires_in_seconds' => Time::secondsUntil($session['expires_at'])
    ],
    'config' => [
        'keepalive_interval_seconds' => $settings['keepalive_interval_seconds'],
        'mode' => $settings['mode']
    ]
]);
