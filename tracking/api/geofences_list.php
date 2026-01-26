<?php
/**
 * GET /tracking/api/geofences_list.php
 *
 * Get all geofences for the family.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Initialize services
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

// Get geofences
$activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
$geofences = $geofenceRepo->getAll($familyId, $activeOnly);

jsonSuccess([
    'geofences' => $geofences,
    'count' => count($geofences)
]);
