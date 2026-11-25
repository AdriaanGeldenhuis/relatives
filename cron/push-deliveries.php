<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/PushOutbox.php';
require_once __DIR__ . '/../core/ScheduledJobs.php';

exit(ScheduledJobs::run($db, 'push-deliveries', static function () use ($db): void {
    if (!PushOutbox::available($db)) {
        return;
    }
    $db->exec("UPDATE push_deliveries SET state = 'skipped', reason = 'expired', lease_id = NULL, lease_until = NULL
        WHERE state = 'pending' AND expires_at <= NOW() AND (lease_until IS NULL OR lease_until <= NOW()) LIMIT 2000");
    $queue = new PushOutbox($db);
    $deadline = microtime(true) + 40;
    $accepted = 0;
    do {
        $last = $queue->dispatch(min(4500, max(1, (int) (($deadline - microtime(true)) * 1000))));
        $accepted += $last;
    } while ($last > 0 && microtime(true) < $deadline);
    $age = (int) $db->query("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(next_at), NOW()), 0)
        FROM push_deliveries WHERE state = 'pending' AND next_at < NOW()")->fetchColumn();
    printf("Push accepted %d. Oldest due delivery %ds.\n", $accepted, $age);
}));
