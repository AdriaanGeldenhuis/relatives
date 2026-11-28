<?php
declare(strict_types=1);

// Run against an empty, disposable MariaDB/MySQL database named relatives_tracking_test.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$dsn = getenv('RELATIVES_TRACKING_TEST_DSN');
if (!$dsn || !preg_match('/(?:^|;)dbname=relatives_tracking_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Set RELATIVES_TRACKING_TEST_DSN for an empty relatives_tracking_test database.');
}
$db = new PDO($dsn, getenv('RELATIVES_TRACKING_TEST_USER') ?: 'root', getenv('RELATIVES_TRACKING_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec("SET time_zone = '+02:00'");
$root = __DIR__ . '/../../';
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
foreach (['tests/reliability-fixture.sql', 'tracking/migrations/001_tracking_tables.sql', 'tracking/migrations/002_place_state.sql',
    'tracking/migrations/003_tracking_reliability.sql', 'tracking/migrations/003_tracking_reliability.sql',
    'tracking/migrations/004_tracking_evidence.sql', 'tracking/migrations/004_tracking_evidence.sql',
    'tracking/migrations/005_family_live_sessions.sql', 'tracking/migrations/005_family_live_sessions.sql',
    'migrations/app-reliability-v1.sql'] as $migration) {
    // The shared migration's stored trigger section requires the mysql CLI.
    // The push tables used by this fixture precede the delimiter.
    $sql = explode('DELIMITER $$', file_get_contents($root . $migration), 2)[0];
    $result = $db->query($sql);
    while ($result->nextRowset()) {}
    $result->closeCursor();
}
$db->exec("INSERT INTO families (id, name) VALUES (1, 'Fixture family'), (2, 'Other family');
    INSERT INTO users (id, family_id, status, location_sharing, role, full_name, email, password_hash)
        VALUES (1,1,'active',1,'owner','Requester','requester@example.invalid',''),
               (2,1,'active',1,'member','Recipient','recipient@example.invalid',''),
               (3,2,'active',1,'owner','Other family','other@example.invalid','')");
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
foreach (['core/Cache.php', 'core/GeoUtils.php', 'tracking/core/SiteContext.php', 'tracking/core/Time.php',
    'tracking/core/Validator.php', 'tracking/core/services/TrackingCache.php', 'tracking/core/services/TrackingPolicy.php',
    'tracking/core/services/LocationQuality.php',
    'tracking/core/services/TrackingWake.php', 'tracking/core/services/FamilyLive.php', 'tracking/core/services/LocationIngest.php',
    'tracking/core/services/MotionGate.php', 'tracking/core/services/GeofenceEngine.php',
    'tracking/core/services/AlertsEngine.php', 'tracking/core/repos/AlertsRepo.php', 'tracking/core/repos/EventsRepo.php',
    'tracking/core/repos/GeofenceRepo.php', 'tracking/core/repos/PlacesRepo.php', 'tracking/core/repos/SettingsRepo.php',
    'tracking/core/repos/LocationRepo.php'] as $file) {
    require_once $root . $file;
}

function check(bool $passed, string $description): void
{
    if (!$passed) {
        throw new RuntimeException($description);
    }
}

function account(PDO $db, int $id, string $token): SiteContext
{
    $db->prepare("INSERT INTO sessions (user_id, session_token, csrf_token, expires_at, created_at) VALUES (?, ?, '', NOW() + INTERVAL 1 DAY, NOW())")
        ->execute([$id, hash('sha256', $token)]);
    $_SESSION = ['user_id' => $id, 'session_token' => $token];
    return SiteContext::fromSession($db);
}

$_ENV['TRACKING_ADAPTIVE_CADENCE'] = '1';
$_ENV['TRACKING_SILENT_WAKE'] = '1';
$sender = account($db, 1, 'fixture-sender');
$recipient = account($db, 2, 'fixture-recipient');
$other = account($db, 3, 'fixture-other');
$otherSession = account($db, 2, 'fixture-recipient-other-session');
$deviceId = TrackingWake::newId();
$secondDevice = TrackingWake::newId();
$token = $db->prepare("INSERT INTO fcm_tokens (user_id, token, device_type, installation_id, wake_capability, wake_session_hash)
    VALUES (2, ?, 'android', ?, 'silent_ready', ?)");
$token->execute(['token-first', $deviceId, $recipient->sessionHash()]);
$token->execute(['token-second', $secondDevice, $otherSession->sessionHash()]);

$cache = new TrackingCache(Cache::getInstance());
$ingest = new LocationIngest($db, $cache);
$settings = new SettingsRepo($db, $cache);
$defaults = $settings->get(1);
check(TrackingPolicy::native($defaults, true)['idle_interval'] === 300, 'Normal idle interval');
check(TrackingPolicy::native(['mode' => 2] + $defaults, true)['moving_interval'] === 15, 'Active moving interval');
check(Time::captureMs('2026-11-25T08:00:00.555+02:00') === Time::captureMs('2026-11-25 06:00:00.555'), 'UTC conversion');

$wake = new TrackingWake($db);
$devices = $wake->devices($sender, false, false, TrackingPolicy::native($defaults, true));
check(count($devices) === 2, 'Two installations for one member');
check($wake->devices($other, false, false, TrackingPolicy::native($defaults, true)) === [], 'Cross-family push isolation');
$key = TrackingWake::newId();
$request = $wake->admit($sender, $key, $devices);
$id = $request['request_id'];
check($wake->admit($sender, $key, $devices) === ['request_id' => $id], 'Idempotent request');
check($wake->admit($recipient, TrackingWake::newId(), $devices)['request_id'] === $id, 'Concurrent family viewers share a request');
check($wake->status($other, $id) === null, 'Wake status requester isolation');
check($wake->status($recipient, $id) === null && $wake->status($recipient, $id, true, true) !== null, 'Shared status remains family scoped');

$live = new FamilyLive($db);
$db->prepare('UPDATE fcm_tokens SET device_info = ? WHERE user_id = ?')->execute(['{"family_live":true}', 2]);
$viewerA = TrackingWake::newId();
$viewerB = TrackingWake::newId();
$started = $live->view($sender, $viewerA, true);
$liveId = $started['session']['id'];
check($started['session']['pulse_ms'] === 30000 && count($started['targets']) === 2,
    'Opening Tracking schedules one 30-second session for every eligible phone');
$joined = $live->view($recipient, $viewerB, true);
check($joined['session']['id'] === $liveId && $joined['targets'] === [], 'Second viewer joins without a second start push');
$pulse = ['session_id' => $liveId, 'device_id' => $deviceId];
check($ingest->process($other, [], $pulse)['live_session'] === null, 'A foreign family cannot pulse');
check($ingest->process($otherSession, [], $pulse)['live_session'] === null, 'An installation is bound to its sign-in');
$live->requireOpen($recipient, $liveId, $deviceId);
$db->prepare("DELETE FROM tracking_live_phones WHERE device_id = ?")->execute([$secondDevice]);
check($live->presence(1)[2]['state'] === 'open_required', 'The map can show an Android startup fallback');
$result = $ingest->process($recipient, [], $pulse);
check($result['live_session']['id'] === $liveId && $result['stored'] === 0, 'A stationary phone can check in without a location');
check($live->presence(1)[2]['state'] === 'live' && $live->presence(1)[2]['last_seen_ms'] > 0, 'Pulse timestamp is recorded separately');
check($live->view($sender, $viewerA, false)['session']['id'] === $liveId, 'Leaving preserves the other viewer');
check($live->view($recipient, $viewerB, false)['session'] === null, 'Last viewer ends the family session');
check($ingest->process($recipient, [], $pulse)['live_session'] === null && $live->presence(1) === [],
    'An ended session rejects old pulses and clears live presence');
$newSession = $live->view($sender, $viewerA, true);
check($newSession['session']['id'] !== $liveId, 'Reopening after expiry creates a new session');
$db->exec('UPDATE tracking_live_sessions SET expires_ms = 0 WHERE family_id = 1');
check($live->active(1) === null, 'Lease expires when all viewers disappear');

$db->beginTransaction();
check($wake->acknowledge($otherSession, $id, $deviceId, 'received') === null, 'Session is bound to installation');
check($wake->acknowledge($recipient, $id, $deviceId, 'received')['acquire'], 'Correct installation can acquire');
$wake->acknowledge($recipient, $id, $deviceId, 'open_required');
$db->commit();
$db->exec("INSERT INTO tracking_geofences (family_id, name, center_lat, center_lng, radius_m)
    VALUES (1, 'Fixture circle', -26.2, 28.0, 200)");
$db->exec("INSERT INTO tracking_alert_rules (family_id, enabled) VALUES (1, 0)");
$now = Time::nowMs();
$base = ['lat' => -26.2, 'lng' => 28.0, 'accuracy_m' => 15, 'device_id' => $deviceId];
$sample = ['client_event_id' => 'wake-fix', 'wake_request_id' => $id, 'recorded_at_ms' => $now] + $base;
$result = $ingest->process($recipient, [$sample, ['lat' => 'text', 'lng' => 20, 'client_event_id' => 'bad']]);
check($result['results'][0]['status'] === 'stored' && $result['results'][1]['status'] === 'error', 'Mixed batch acknowledgement');
check($ingest->process($recipient, [$sample])['results'][0]['status'] === 'duplicate', 'Lost response replay');
check(in_array('refreshed', array_column($wake->status($sender, $id)['targets'], 'state'), true), 'Commit confirms Wake');

$result = $ingest->process($recipient, [['client_event_id' => 'older', 'recorded_at_ms' => $now - 60000, 'lat' => -27] + $base]);
check(!$result['results'][0]['advanced_current'], 'Offline replay preserves the current pin');
check((int) $db->query('SELECT COUNT(*) FROM tracking_events')->fetchColumn() === 0,
    'The first location initializes state without a false arrival');
usleep(10000);
$result = $ingest->process($recipient, [['client_event_id' => 'stationary', 'recorded_at_ms' => Time::nowMs()] + $base]);
check($result['results'][0]['advanced_current'] && !$result['results'][0]['stored_history'], 'Stationary refresh advances capture time');
$repo = new LocationRepo($db, $cache);
$rows = array_column($repo->getFamilyCurrentLocations(1, true), null, 'user_id');
check((float) $rows[2]['lat'] === -26.2 && (int) $rows[2]['recorded_ms'] > $now, 'Current snapshot stays newest');

$db->exec('UPDATE users SET location_sharing = 0 WHERE id = 2');
$rows = array_column($repo->getFamilyCurrentLocations(1, true), null, 'user_id');
check($rows[2]['lat'] === null, 'Revoked members disappear from current locations');
check($repo->getHistory(1, 2, Time::format(time() - 3600), Time::now(), 1) === [], 'Revoked member history is private');
check($ingest->process($recipient, [$sample])['blocked'] === 'location_sharing_disabled', 'In-flight writer rechecks sharing');
TrackingPolicy::setCutoff($db, 'user', 2);
$db->exec('UPDATE users SET location_sharing = 1 WHERE id = 2');
$result = $ingest->process($recipient, [['client_event_id' => 'revoked-queue', 'recorded_at_ms' => $now - 30000] + $base]);
check($result['results'][0]['reason'] === 'expired_fix', 'Re-enable discards old queued locations');

$db->prepare('UPDATE tracking_wake_requests SET expires_ms = ? WHERE request_id = ?')->execute([Time::nowMs() - 1, $id]);
check($wake->acknowledge($otherSession, $id, $secondDevice, 'received') === null, 'Expired request cannot acquire');
check(in_array('no_reply', array_column($wake->status($sender, $id)['targets'], 'state'), true), 'Expired pending request reports no reply');
echo "Tracking reliability integration checks passed.\n";

$policy = TrackingPolicy::native($defaults, true);
check(TrackingPolicy::locationQuality($db) && TrackingPolicy::geofenceEvidence($db), 'Additive evidence migration is available');
$token->execute(['old-build', TrackingWake::newId(), $otherSession->sessionHash()]);
$db->exec("UPDATE fcm_tokens SET wake_capability = 'ready' WHERE token = 'old-build'");
check(count($wake->devices($sender, false, false, $policy)) === 2, 'Older notice-generating builds are never targeted');

$now = Time::nowMs();
$db->exec("DELETE FROM tracking_current WHERE user_id = 2");
$db->exec("DELETE FROM tracking_sharing_cutoffs WHERE subject_type = 'user' AND subject_id = 2");
$start = $now - 10000;
$fix = $base + ['platform' => 'android', 'speed_mps' => 15];
$first = ['client_event_id' => 'accurate', 'recorded_at_ms' => $start] + $fix;
$result = $ingest->process($recipient, [$first]);
check($result['results'][0]['advanced_current'], 'Current source accepts an accurate fix');
$coarse = ['client_event_id' => 'coarse-jump', 'recorded_at_ms' => $start + 1000, 'lat' => -26.205, 'accuracy_m' => 700] + $fix;
$result = $ingest->process($recipient, [$coarse]);
check($result['results'][0]['selection'] === 'hold_coarse', 'A 700 m fix cannot replace a fresh 15 m pin');
$selected = array_column($repo->getFamilyCurrentLocations(1, true, $policy), null, 'user_id')[2];
check((float) $selected['lat'] === (float) $first['lat'] && (int) $selected['recorded_ms'] === $start,
    'Map keeps the coordinate and capture time of the accurate fix');
$underLock = $repo->currentForWrite(2, 1, true, true);
$fallback = LocationQuality::visible($underLock, $start + 91000);
check((float) $fallback['accuracy_m'] === 700.0 && (int) $fallback['recorded_ms'] === $coarse['recorded_at_ms'],
    'When the reliable fix ages out, the next approximate area retains its real capture time');
$result = $ingest->process($otherSession, [['client_event_id' => 'spare-phone', 'recorded_at_ms' => $start + 2000,
    'device_id' => $secondDevice, 'lat' => -26.004] + $fix]);
check($result['results'][0]['selection'] === 'secondary_device', 'A spare phone cannot steal a current member pin');

$wave = $wake->admit($sender, TrackingWake::newId(), $wake->devices($sender, false, false, $policy))['request_id'];
check($wake->pending($other, $deviceId) === null && $wake->pending($otherSession, $deviceId) === null,
    'Active refresh lookup is installation, session and family scoped');
check($wake->pending($recipient, $deviceId)['request_id'] === $wave, 'Active trackers can retrieve a silent refresh on reconnect');
$db->beginTransaction();
$wake->acknowledge($recipient, $wave, $deviceId, 'received');
$db->commit();
usleep(10000);
$low = ['client_event_id' => 'coarse-wake', 'recorded_at_ms' => Time::nowMs(), 'accuracy_m' => 700, 'wake_request_id' => $wave] + $fix;
$ingest->process($recipient, [$low]);
check(in_array('received', array_column($wake->status($sender, $wave)['targets'], 'state'), true),
    'Wake does not report a fix held back from the map');
usleep(10000);
$ingest->process($recipient, [['client_event_id' => 'visible-ordinary', 'recorded_at_ms' => Time::nowMs()] + $fix]);
check(in_array('refreshed', array_column($wake->status($sender, $wave)['targets'], 'state'), true),
    'A qualifying committed observation also resolves a Wake whose signal was missed');

$ownDevice = TrackingWake::newId();
$db->prepare("INSERT INTO fcm_tokens (user_id, token, device_type, installation_id, wake_capability, wake_session_hash)
    VALUES (1, 'sender-token', 'android', ?, 'silent_ready', ?)")->execute([$ownDevice, $sender->sessionHash()]);
check(count($wake->devices($sender, false, false, $policy)) === 3
    && count($wake->devices($sender, false, true, $policy)) === 2, 'Manual Wake includes own phone unless already refreshing locally');
check(!in_array(2, array_column($wake->devices($sender, true, false, $policy), 'user_id'), true),
    'Map-open refresh does not request phones with good recent coverage');

$db->exec('UPDATE tracking_alert_rules SET enabled = 1 WHERE family_id = 1');
$geo = new GeofenceRepo($db, $cache);
$events = new EventsRepo($db);
$bounds = new GeofenceEngine($db, $geo, new PlacesRepo($db, $cache), $events,
    new AlertsEngine($db, new AlertsRepo($db, $cache)));
usleep(10000);
$geoId = (int) $db->query("SELECT id FROM tracking_geofences WHERE name = 'Fixture circle'")->fetchColumn();
$now = Time::nowMs();
$crossings = [
    [$now - 5, -26.197, 10],    // clearly outside
    [$now - 4, -26.2017, 30],   // near the boundary, uncertainty overlaps it
    [$now - 3, -26.2005, 8],    // clearly inside
    [$now - 2, -26.197, 12],    // clearly outside
    [$now - 1, -26.2005, 8],    // genuine re-entry within the old 15-minute cooldown
];
foreach ($crossings as [$capture, $latitude, $accuracy]) {
    $db->beginTransaction();
    $bounds->observe(1, 2, 'Recipient', ['lat' => $latitude, 'lng' => 28.0, 'accuracy_m' => $accuracy, 'recorded_ms' => $capture], 0);
    $db->commit();
}
$rows = $events->list(1, 50, 0, null, 1);
check(array_column(array_slice($rows, 0, 4), 'event_type') === ['enter_geofence', 'exit_geofence', 'enter_geofence', 'exit_geofence'],
    'Genuine rapid re-entries alert once and boundary jitter creates no crossing');
$notices = $db->query("SELECT * FROM notifications WHERE type = 'tracking' ORDER BY id DESC")->fetchAll();
check(count($notices) === 4 && str_contains($notices[0]['message'], 'Recipient entered Fixture circle at ')
    && $notices[0]['action_url'] === '/tracking/app/events.php?event=' . $rows[0]['id'],
    'Geofence inbox preserves the member, direction, place, time and specific event');
check((int) $db->query('SELECT COUNT(*) FROM push_deliveries')->fetchColumn() === 4, 'One durable push per actual crossing');
$data = json_decode($notices[0]['data_json'], true, 512, JSON_THROW_ON_ERROR);
check(LocationNotice::content($data, 'Recipient', 'Africa/Johannesburg')['body'] === $notices[0]['message'],
    'The queued push formatter preserves the geofence copy');
check(LocationNotice::content(['event_type' => 'wake_tracking'] + $data, 'Recipient', null) === null,
    'Routine refresh can never become a geofence push');
check(count($events->list(1, 1, 0, null, 1, (int) $rows[0]['id'])) === 1
    && $events->list(2, 1, 0, null, 3, (int) $rows[0]['id']) === [], 'Specific notification event is confined to its family');

$before = count($rows);
$db->beginTransaction();
$bounds->observe(1, 2, 'Recipient', ['lat' => -26.197, 'lng' => 28.0, 'accuracy_m' => 10, 'recorded_ms' => $now - 2], 0);
$bounds->observe(1, 2, 'Recipient', ['lat' => -26.197, 'lng' => 28.0, 'accuracy_m' => 10, 'recorded_ms' => $now], 0, baselineOnly: true);
$db->commit();
check(count($events->list(1, 50, 0, null, 1)) === $before
    && (int) $db->query("SELECT is_inside FROM tracking_geofence_state WHERE geofence_id = $geoId AND user_id = 2")->fetchColumn() === 1,
    'An old replay and the repair job cannot overwrite a confirmed state');

$circle = ['type' => 'circle', 'center_lat' => -26.2, 'center_lng' => 28.0, 'radius_m' => 50];
$distance = GeofenceEngine::signedDistance(-26.20009, 28.0, $circle);
check($distance > 10 && $distance < 80, 'The same near-centre point can confirm the small fence only with adequate accuracy');
$polygon = ['type' => 'polygon', 'polygon_json' => [[0, 179.999], [0.001, 179.999], [0.001, -179.999], [0, -179.999]]];
check(GeofenceEngine::signedDistance(0.0005, -180.0, $polygon) > 45
    && GeofenceEngine::signedDistance(0.0011, 180.0, $polygon) < -8, 'Polygon evidence measures distance on both sides across the date line');

$db->exec('UPDATE users SET location_sharing = 0 WHERE id = 2');
check(NotificationManager::getInstance($db)->getUnreadCount(1, 'tracking') === 0, 'A revoked subject disappears from the inbox badge');
(new PushOutbox($db))->dispatch(1500);
check((int) $db->query("SELECT COUNT(*) FROM push_deliveries WHERE state = 'skipped' AND reason = 'location_unavailable'")->fetchColumn() === 4,
    'The outbox suppresses queued crossing details after sharing is revoked');
echo "Location quality, silent Wake and geofence delivery checks passed.\n";
