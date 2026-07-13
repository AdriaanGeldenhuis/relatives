<?php
declare(strict_types=1);

/**
 * Tracking keep-alive wake push.
 *
 * Android kills the tracking foreground service after an hour or two
 * (Doze / OEM battery killers); the device then goes silent until the user
 * reopens the app. A high-priority data-only FCM message grants the app a
 * temporary background-start exemption, letting it revive the service.
 *
 * Every run: find Android devices of active, location-sharing users in
 * families where tracking is on (mode 2 always; mode 1 only with a live
 * session; mode 0 = Off is never woken) whose current location has gone
 * stale, and send each a silent {type: wake_tracking, keepalive: 1} push.
 * The app-side handler quietly restarts the service in IDLE mode — no GPS
 * burst, no visible notification, no notifications-table rows.
 *
 * Uses tracking_current.updated_at (MySQL CURRENT_TIMESTAMP) for staleness,
 * NOT recorded_at — recorded_at is written as a UTC wall-clock string into
 * a +02:00 connection and is therefore 2h off; updated_at compares cleanly
 * against NOW() on the same connection.
 *
 * Crontab (cPanel):
 *   *&#47;10 * * * * /usr/bin/php /home/relatykbvc/public_html/tracking/jobs/wake_keepalive.php >> /home/relatykbvc/logs/tracking-wake.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

$start = microtime(true);
$tag = '[wake_keepalive]';

if (!class_exists('FirebaseMessaging')) {
    error_log("$tag ERROR: FirebaseMessaging not available (core/FirebaseMessaging.php missing?)");
    exit(1);
}

// A device is "quiet" when its last stored fix is older than this. Keep it
// comfortably above the idle upload cadence (~10 min heartbeat) so healthy
// devices are never pushed — Android deprioritises apps that receive
// high-priority pushes without visible results.
$staleMinutes = 15;

try {
    $sql = "
        SELECT ft.id AS token_id, ft.token, u.id AS user_id, u.family_id
        FROM tracking_family_settings s
        JOIN users u
            ON u.family_id = s.family_id
           AND u.status = 'active'
           AND u.location_sharing = 1
        JOIN fcm_tokens ft
            ON ft.user_id = u.id
           AND ft.device_type = 'android'
        LEFT JOIN tracking_current c
            ON c.user_id = u.id
        WHERE (
                s.mode = 2
             OR (s.mode = 1 AND EXISTS (
                    SELECT 1 FROM tracking_family_sessions fs
                    WHERE fs.family_id = s.family_id
                      AND fs.active = 1
                      AND fs.expires_at > NOW()
                ))
              )
          AND (c.user_id IS NULL OR c.updated_at < DATE_SUB(NOW(), INTERVAL {$staleMinutes} MINUTE))
    ";
    $stmt = $db->query($sql);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("$tag ERROR: target query failed: " . $e->getMessage());
    exit(1);
}

if (empty($targets)) {
    echo "$tag nothing to wake (all devices fresh)\n";
    exit(0);
}

$fcm = new FirebaseMessaging();
$sent = 0;
$invalid = 0;
$failed = 0;

foreach ($targets as $t) {
    // Silent data-only wake; the sender already marks Android priority high.
    // TTL matches the 10-min cron cadence — an undelivered keepalive is
    // superseded by the next run, so FCM must not queue them for weeks, and
    // the collapse key keeps at most one pending per device.
    $result = $fcm->send($t['token'], [], [
        'type' => 'wake_tracking',
        'keepalive' => '1',
    ], [
        'ttl' => '600s',
        'collapse_key' => 'wake_keepalive',
    ]);

    if ($result === 'invalid_token') {
        // Same pruning NotificationManager does — dead tokens never recover.
        try {
            $db->prepare('DELETE FROM fcm_tokens WHERE id = ?')->execute([$t['token_id']]);
        } catch (Throwable $e) {
            error_log("$tag WARN: failed to prune token {$t['token_id']}: " . $e->getMessage());
        }
        $invalid++;
    } elseif ($result === true) {
        $sent++;
    } else {
        $failed++;
    }

    // Don't spam FCM (same pacing as FirebaseMessaging::sendMultiple).
    usleep(50000);
}

$elapsed = round(microtime(true) - $start, 2);
echo sprintf(
    "%s %s — targets=%d sent=%d invalid_pruned=%d failed=%d (%ss)\n",
    $tag,
    date('Y-m-d H:i:s'),
    count($targets),
    $sent,
    $invalid,
    $failed,
    $elapsed
);
