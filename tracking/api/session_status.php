<?php
/**
 * GET /tracking/api/session_status.php
 *
 * Returns the current session status.
 * Used by mobile apps to check if they should track.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Initialize services
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$sessionGate = new SessionGate($sessionsRepo, $settingsRepo);

// Get status
$status = $sessionGate->getStatus($familyId);

jsonSuccess($status);
