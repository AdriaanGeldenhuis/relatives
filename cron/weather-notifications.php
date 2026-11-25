<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ScheduledJobs.php';
require_once __DIR__ . '/../weather/digest.php';

exit(ScheduledJobs::run($db, 'weather-notifications', static function () use ($db): void {
    $rows = $db->query("SELECT w.user_id, w.notification_time, w.include_forecast, w.last_sent, u.family_id, f.timezone
        FROM weather_notification_schedule w JOIN users u ON u.id = w.user_id AND u.status = 'active'
        JOIN families f ON f.id = u.family_id WHERE w.enabled = 1 AND w.notification_time IS NOT NULL
        ORDER BY w.last_sent IS NOT NULL, w.last_sent, w.user_id")->fetchAll();
    $manager = NotificationManager::getInstance($db);
    $clock = new DateTimeZone('Africa/Johannesburg');
    $count = $failed = 0;
    $deadline = microtime(true) + 45;
    foreach ($rows as $row) {
        $zone = ScheduledJobs::zone($row['timezone']);
        $now = new DateTimeImmutable('now', $zone);
        $today = $now->format('Y-m-d');
        $last = $row['last_sent'] ? (new DateTimeImmutable($row['last_sent'], $clock))->setTimezone($zone) : null;
        if ($last?->format('Y-m-d') === $today || $now->format('H:i:s') < $row['notification_time']) {
            continue;
        }
        if (microtime(true) >= $deadline) {
            break;
        }
        try {
            $expires = $now->modify('tomorrow')->setTimezone($clock)->format('Y-m-d H:i:s');
            $data = weatherDigest($db, $row, (bool) $row['include_forecast'], $expires);
            $count += (int) ScheduledJobs::once($db, 'weather|' . $row['user_id'] . '|' . $today,
                static function () use ($db, $row, $data, $manager): bool {
                    $check = $db->prepare('SELECT enabled, notification_time FROM weather_notification_schedule
                        WHERE user_id = ? FOR UPDATE');
                    $check->execute([$row['user_id']]);
                    $latest = $check->fetch();
                    if (!$latest || !$latest['enabled'] || $latest['notification_time'] !== $row['notification_time']) {
                        return false;
                    }
                    $manager->create($data);
                    $db->prepare('UPDATE weather_notification_schedule SET last_sent = NOW() WHERE user_id = ?')
                        ->execute([$row['user_id']]);
                    return true;
                });
        } catch (Throwable) {
            $failed++;
        }
    }
    printf("Weather digests processed %d. Errors %d.\n", $count, $failed);
    if ($failed > 0) {
        throw new RuntimeException('Some weather digests are awaiting retry');
    }
}));
