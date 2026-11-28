<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$fail = static function (string $message): void {
    file_put_contents('php://stderr', $message . "\n");
    exit(1);
};

$root = $argv[1] ?? getenv('RELATIVES_RELEASE_ROOT') ?: dirname(__DIR__);
if (PHP_VERSION_ID < 80200) {
    $fail("Release requires PHP 8.2 or newer. Detected " . PHP_VERSION . ".");
}
foreach (['pdo_mysql', 'openssl', 'curl', 'mbstring', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        $fail("Release requires PHP extension $extension.");
    }
}
$lines = @file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    $fail("Release configuration is missing.");
}
$env = [];
foreach ($lines as $line) {
    if (!str_starts_with(ltrim($line), '#') && str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_URL', 'OPENWEATHER_API_KEY'] as $key) {
    if (empty($env[$key])) {
        $fail("Release configuration requires $key.");
    }
}
if (($env['WEB_PUSH_ENABLED'] ?? '0') === '1' && (
    !preg_match('/^[A-Za-z0-9_-]{87}$/D', $env['WEB_PUSH_PUBLIC_KEY'] ?? '')
    || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $env['WEB_PUSH_PRIVATE_KEY'] ?? ''))) {
    $fail("Web Push is enabled and requires valid VAPID keys.");
}
try {
    $db = new PDO('mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4',
        $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $required = [
        'tracking_current' => ['lat', 'lng', 'recorded_ms'],
        'tracking_locations' => ['lat', 'lng', 'recorded_at'],
        'tracking_family_settings' => ['mode', 'idle_interval_seconds'],
        'tracking_wake_targets' => ['installation_id'],
        'tracking_place_state' => ['user_id'],
        'fcm_tokens' => ['installation_id', 'wake_session_hash'],
        'notifications' => ['expires_at', 'data_json'],
        'notification_preferences' => ['quiet_hours_enabled', 'vibrate_enabled'],
        'events' => ['reminder_minutes', 'reminder_sent_at'],
        'event_reminders' => ['next_trigger_at'],
        'event_sync_queue' => ['id'],
        'user_privacy_settings' => ['allow_messages'],
        'account_deletion_requests' => ['user_id'],
        'account_deletion_outcomes' => ['operator_id'],
        'ht_trips' => ['budget_currency'],
        'ht_trip_expenses' => ['currency'],
        'web_push_subscriptions' => ['session_hash'],
        'push_deliveries' => ['receipt', 'lease_id', 'expires_at'],
        'message_send_receipts' => ['client_id'],
        'message_revisions' => ['revision'],
        'message_changes' => ['revision'],
        'scheduled_deliveries' => ['event_key'],
        'scheduled_job_status' => ['last_success_at'],
        'weather_saved_cities' => ['user_id'],
    ];
    $lookup = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($required as $table => $columns) {
        $lookup->execute([$table]);
        if (array_diff($columns, $lookup->fetchAll(PDO::FETCH_COLUMN))) {
            $fail("Database schema migration is required for $table.");
        }
    }
    $triggers = (int) $db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
        AND TRIGGER_NAME IN ('relatives_chat_insert', 'relatives_chat_update', 'relatives_chat_delete')")->fetchColumn();
    if ($triggers !== 3) {
        $fail("Install the chat revision triggers before deploying.");
    }
    $lookup->execute(['notes']);
    $noteColumns = $lookup->fetchAll(PDO::FETCH_COLUMN);
    if (!$noteColumns) {
        $fail("The Notes table is missing.");
    }
    if (!in_array('image_path', $noteColumns, true)) {
        try {
            $db->exec('ALTER TABLE notes ADD COLUMN image_path TEXT NULL');
        } catch (PDOException) {
            $fail("Could not add the Notes photo column. Apply the Notes image path migration.");
        }
    }
    echo "Notes photo storage column is ready.\n";
    foreach (['004' => 'RELATIVES_TRACKING_EVIDENCE_SQL', '005' => 'RELATIVES_TRACKING_LIVE_SQL'] as $number => $variable) {
        $trackingSql = getenv($variable);
        if ($trackingSql === false) {
            continue;
        }
        try {
            $migration = base64_decode($trackingSql, true);
            if ($migration === false) {
                $fail("Could not read tracking migration $number.");
            }
            $db->exec('SET SESSION lock_wait_timeout = 10');
            $statement = $db->query($migration);
            while ($statement->nextRowset()) {
                // Drain the idempotent DDL results before checking columns.
            }
            $statement->closeCursor();
        } catch (Throwable) {
            $fail("Could not apply tracking migration $number. Check database permissions and schema locks.");
        }
    }
    foreach ([
        'tracking_current' => ['pending_fix_json', 'consent_scope'],
        'tracking_geofence_state' => ['observed_ms', 'definition_hash'],
        'tracking_place_state' => ['observed_ms', 'definition_hash'],
        'tracking_geofences' => ['revision_ms'],
        'tracking_live_sessions' => ['session_id', 'expires_ms'],
        'tracking_live_viewers' => ['viewer_id', 'expires_ms'],
        'tracking_live_phones' => ['device_id', 'last_seen_ms'],
    ] as $table => $columns) {
        $lookup->execute([$table]);
        if (array_diff($columns, $lookup->fetchAll(PDO::FETCH_COLUMN))) {
            $fail("Apply the tracking schema migrations for $table.");
        }
    }
    echo "Tracking accuracy, geofence and live session schema is ready.\n";
} catch (Throwable) {
    $fail("Could not verify the release database.");
}
echo "Release database and PHP readiness checks passed.\n";
