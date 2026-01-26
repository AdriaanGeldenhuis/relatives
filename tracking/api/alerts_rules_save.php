<?php
/**
 * POST /tracking/api/alerts_rules_save.php
 *
 * Save alert rules.
 * Requires admin or owner role.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('method_not_allowed', 'POST required', 405);
}

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Require admin role
if (!in_array($user['role'], ['owner', 'admin'])) {
    jsonError('forbidden', 'Admin access required', 403);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonError('invalid_json', 'Invalid JSON body', 400);
}

// Validate and clean input
$data = [];

if (isset($input['enabled'])) {
    $data['enabled'] = $input['enabled'] ? 1 : 0;
}

if (isset($input['arrive_place_enabled'])) {
    $data['arrive_place_enabled'] = $input['arrive_place_enabled'] ? 1 : 0;
}

if (isset($input['leave_place_enabled'])) {
    $data['leave_place_enabled'] = $input['leave_place_enabled'] ? 1 : 0;
}

if (isset($input['enter_geofence_enabled'])) {
    $data['enter_geofence_enabled'] = $input['enter_geofence_enabled'] ? 1 : 0;
}

if (isset($input['exit_geofence_enabled'])) {
    $data['exit_geofence_enabled'] = $input['exit_geofence_enabled'] ? 1 : 0;
}

if (isset($input['cooldown_seconds'])) {
    $cooldown = (int)$input['cooldown_seconds'];
    if ($cooldown >= 60 && $cooldown <= 86400) { // 1 min to 24 hours
        $data['cooldown_seconds'] = $cooldown;
    }
}

if (isset($input['quiet_hours_start'])) {
    $time = $input['quiet_hours_start'];
    if ($time === null || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        $data['quiet_hours_start'] = $time;
    }
}

if (isset($input['quiet_hours_end'])) {
    $time = $input['quiet_hours_end'];
    if ($time === null || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        $data['quiet_hours_end'] = $time;
    }
}

if (empty($data)) {
    jsonError('no_changes', 'No valid rules to update', 400);
}

// Initialize services
$alertsRepo = new AlertsRepo($db, $trackingCache);

// Save
$rules = $alertsRepo->saveRules($familyId, $data);

jsonSuccess([
    'rules' => $rules,
    'message' => 'Alert rules updated successfully'
]);
