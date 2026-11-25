<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ScheduledJobs.php';

exit(ScheduledJobs::run($db, 'maintenance-cleanup', static function () use ($db, $cache): void {
    $deleted = [
        'sessions' => $db->exec('DELETE FROM sessions WHERE expires_at < NOW()'),
        'weather cache' => $db->exec('DELETE FROM weather_cache WHERE cached_at < NOW() - INTERVAL 6 HOUR'),
        'audit log' => $db->exec('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 90 DAY'),
        'push jobs' => $db->exec("DELETE FROM push_deliveries WHERE created_at < NOW() - INTERVAL 30 DAY"),
        'web subscriptions' => $db->exec("DELETE t FROM web_push_subscriptions t LEFT JOIN sessions s
            ON s.session_token = t.session_hash AND s.user_id = t.user_id WHERE s.user_id IS NULL"),
        'chat receipts' => $db->exec('DELETE FROM message_send_receipts WHERE created_at < NOW() - INTERVAL 30 DAY'),
        'chat changes' => $db->exec('DELETE FROM message_changes WHERE created_at < NOW() - INTERVAL 30 DAY'),
        'schedule records' => $db->exec('DELETE FROM scheduled_deliveries WHERE created_at < NOW() - INTERVAL 90 DAY'),
    ];
    $cache->clearExpired();
    foreach ($deleted as $type => $count) {
        printf("Removed %d expired %s records.\n", $count, $type);
    }
}));
