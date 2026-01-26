<?php
/**
 * GET /tracking/api/settings_get.php
 *
 * Get family tracking settings.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Initialize services
$settingsRepo = new SettingsRepo($db, $trackingCache);

// Get settings
$settings = $settingsRepo->get($familyId);

// Add some context
$settings['can_edit'] = in_array($user['role'], ['owner', 'admin']);

jsonSuccess($settings);
