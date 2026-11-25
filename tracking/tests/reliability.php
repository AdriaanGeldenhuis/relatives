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
$db->exec("CREATE TABLE families (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
    CREATE TABLE users (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, family_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL, location_sharing INT NOT NULL, role VARCHAR(16) NOT NULL,
        full_name VARCHAR(50) NOT NULL, avatar_color VARCHAR(20), has_avatar INT DEFAULT 0) ENGINE=InnoDB;
    CREATE TABLE sessions (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
        session_token CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL) ENGINE=InnoDB;
    CREATE TABLE fcm_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token VARCHAR(255) NOT NULL,
        device_type VARCHAR(20) NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY user_device (user_id, device_type)) ENGINE=InnoDB;
    INSERT INTO families VALUES (1), (2);
    INSERT INTO users VALUES (1,1,'active',1,'owner','Requester',NULL,0),
        (2,1,'active',1,'member','Recipient',NULL,0), (3,2,'active',1,'owner','Other family',NULL,0)");
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
foreach (['001_tracking_tables.sql', '002_place_state.sql', '003_tracking_reliability.sql', '003_tracking_reliability.sql'] as $migration) {
    $result = $db->query(file_get_contents(__DIR__ . '/../migrations/' . $migration));
    while ($result->nextRowset()) {
        // Consume each DDL result before the next migration.
    }
    $result->closeCursor();
}

$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$root = __DIR__ . '/../../';
foreach (['core/Cache.php', 'core/GeoUtils.php', 'tracking/core/SiteContext.php', 'tracking/core/Time.php',
    'tracking/core/Validator.php', 'tracking/core/services/TrackingCache.php', 'tracking/core/services/TrackingPolicy.php',
    'tracking/core/services/TrackingWake.php', 'tracking/core/services/LocationIngest.php',
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
    $db->prepare('INSERT INTO sessions (user_id, session_token, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 DAY)')
        ->execute([$id, hash('sha256', $token)]);
    $_SESSION = ['user_id' => $id, 'session_token' => $token];
    return SiteContext::fromSession($db);
}

$_ENV['TRACKING_ADAPTIVE_CADENCE'] = '1';
$_ENV['TRACKING_CONFIRMED_WAKE'] = '1';
$sender = account($db, 1, 'fixture-sender');
$recipient = account($db, 2, 'fixture-recipient');
$other = account($db, 3, 'fixture-other');
$otherSession = account($db, 2, 'fixture-recipient-other-session');
$deviceId = TrackingWake::newId();
$secondDevice = TrackingWake::newId();
$token = $db->prepare("INSERT INTO fcm_tokens (user_id, token, device_type, installation_id, wake_capability, wake_session_hash)
    VALUES (2, ?, 'android', ?, 'ready', ?)");
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
$devices = $wake->devices($sender);
check(count($devices) === 2, 'Two installations for one member');
check($wake->devices($other) === [], 'Cross-family push isolation');
$key = TrackingWake::newId();
$request = $wake->admit($sender, $key, $devices);
$id = $request['request_id'];
check($wake->admit($sender, $key, $devices) === ['request_id' => $id, 'dispatch' => false], 'Idempotent request');
check(isset($wake->admit($sender, TrackingWake::newId(), $devices)['retry_after']), 'Family cooldown without cache');
check($wake->status($other, $id) === null, 'Wake status requester isolation');
check($wake->status($recipient, $id) === null, 'Recipient cannot read sender results');

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
check((int) $db->query('SELECT COUNT(*) FROM tracking_events')->fetchColumn() === 1,
    'Wake retry and offline replay do not duplicate a live geofence transition');
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
