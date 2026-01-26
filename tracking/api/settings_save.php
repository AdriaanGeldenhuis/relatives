<?php
/**
 * POST /tracking/api/settings_save.php
 *
 * Save family tracking settings.
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

// Validate
$validation = TrackingValidator::validateSettings($input);
$data = $validation['cleaned'];

if (empty($data)) {
    jsonError('no_changes', 'No valid settings to update', 400);
}

// Initialize services
$settingsRepo = new SettingsRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);

// Get current for comparison
$current = $settingsRepo->get($familyId);

// Save
$updated = $settingsRepo->save($familyId, $data);

// Log change event
$changedFields = array_keys(array_filter($data, function($v, $k) use ($current) {
    return $current[$k] != $v;
}, ARRAY_FILTER_USE_BOTH));

if (!empty($changedFields)) {
    $eventsRepo->logSettingsChange($familyId, $user['id'], array_flip($changedFields));
}

jsonSuccess([
    'settings' => $updated,
    'changed_fields' => $changedFields
]);
