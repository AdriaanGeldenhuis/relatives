<?php
/**
 * GET /tracking/api/alerts_rules_get.php
 *
 * Get alert rules and status for the family.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Initialize services
$alertsRepo = new AlertsRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$alertsEngine = new AlertsEngine($alertsRepo, $eventsRepo);

// Get summary
$summary = $alertsEngine->getSummary($familyId);

// Add edit permission
$summary['can_edit'] = in_array($user['role'], ['owner', 'admin']);

jsonSuccess($summary);
