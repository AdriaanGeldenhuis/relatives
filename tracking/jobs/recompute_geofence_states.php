<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';
require_once __DIR__ . '/../../core/ScheduledJobs.php';

exit(ScheduledJobs::run($db, 'geofence-state-repair', static function () use ($db, $cache): void {
    $trackingCache = new TrackingCache($cache);
    $settings = new SettingsRepo($db, $trackingCache);
    $locations = new LocationRepo($db, $trackingCache);
    $engine = new GeofenceEngine($db, $trackingCache, new GeofenceRepo($db, $trackingCache),
        new PlacesRepo($db, $trackingCache), new EventsRepo($db),
        new AlertsEngine($db, $trackingCache, new AlertsRepo($db, $trackingCache)));
    $families = $db->query('SELECT family_id FROM tracking_geofences WHERE active = 1 UNION SELECT family_id FROM tracking_places')
        ->fetchAll(PDO::FETCH_COLUMN);
    $sharing = $db->prepare("SELECT 1 FROM users WHERE id = ? AND family_id = ?
        AND status = 'active' AND location_sharing = 1 FOR UPDATE");
    $evaluated = $stale = 0;
    foreach ($families as $id) {
        $family = (int) $id;
        $db->beginTransaction();
        try {
            if ((int) $settings->lockForTracking($family)['mode'] !== 0) {
                foreach ($locations->getFamilyCurrentLocations($family, true) as $member) {
                    if (!$member['location_sharing']) continue;
                    $capture = (int) ($member['recorded_ms'] ?? Time::captureMs($member['recorded_at'] ?? ''));
                    if ($member['lat'] === null || $capture < Time::nowMs() - 3600000) {
                        $stale++;
                        continue;
                    }
                    $sharing->execute([$member['user_id'], $family]);
                    if (!$sharing->fetchColumn()) continue;
                    $engine->recordTransitions($family, (int) $member['user_id'], (float) $member['lat'], (float) $member['lng'],
                        $member['name'], isset($member['accuracy_m']) ? (float) $member['accuracy_m'] : null, false);
                    $evaluated++;
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
    printf("Geofence repair examined %d recent fixes. %d sharing members have stale or missing fixes.\n", $evaluated, $stale);
}));
