<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/NotificationTriggers.php';
require_once __DIR__ . '/../core/ScheduledJobs.php';
require_once __DIR__ . '/../core/Events/SyncEngine.php';

exit(ScheduledJobs::run($db, 'calendar-reminders', static function () use ($db): void {
    $triggers = new NotificationTriggers($db);
    $due = $db->query("SELECT e.id, e.user_id, e.family_id, e.title, e.starts_at, e.kind,
            e.reminder_minutes, e.reminder_sent_at, f.timezone
        FROM events e JOIN families f ON f.id = e.family_id
        JOIN users u ON u.id = e.user_id AND u.family_id = e.family_id AND u.status = 'active'
        WHERE e.status = 'pending' AND e.starts_at BETWEEN NOW() - INTERVAL 2 DAY AND NOW() + INTERVAL 3 DAY
            AND (e.reminder_minutes IS NOT NULL OR e.kind IN ('study', 'work', 'todo'))");
    $fresh = $db->prepare("SELECT starts_at, status, reminder_minutes, reminder_sent_at, user_id
        FROM events WHERE id = ? AND family_id = ? FOR UPDATE");
    $sent = $db->prepare('UPDATE events SET reminder_sent_at = NOW() WHERE id = ?');
    $count = 0;
    foreach ($due->fetchAll() as $event) {
        $zone = ScheduledJobs::zone($event['timezone']);
        $now = new DateTimeImmutable('now', $zone);
        $starts = new DateTimeImmutable($event['starts_at'], $zone);
        $remaining = $starts->getTimestamp() - $now->getTimestamp();
        $minutes = $event['reminder_minutes'];
        $lead = $minutes !== null && $event['reminder_sent_at'] === null && $remaining > 0 && $remaining <= 60 * (int) $minutes;
        $task = in_array($event['kind'], ['study', 'work', 'todo'], true) && $remaining <= 0
            && $starts->format('Y-m-d') === $now->format('Y-m-d');
        if (!$lead && !$task) {
            continue;
        }
        $type = $lead ? 'event' : 'task';
        $key = implode('|', [$type, $event['id'], $event['user_id'], $event['starts_at'], $lead ? $minutes : 'due']);
        $count += (int) ScheduledJobs::once($db, $key, static function () use ($event, $fresh, $sent, $db, $triggers, $lead, $remaining, $starts, $now): bool {
            $fresh->execute([$event['id'], $event['family_id']]);
            $state = $fresh->fetch();
            if (!$state || $state['status'] !== 'pending' || $state['starts_at'] !== $event['starts_at']
                || $state['reminder_minutes'] !== $event['reminder_minutes'] || $state['user_id'] !== $event['user_id']) {
                return false;
            }
            if ($lead) {
                if ($state['reminder_sent_at'] !== null) {
                    return false;
                }
                $legacy = $db->prepare("SELECT is_sent, snooze_until FROM event_reminders
                    WHERE event_id = ? AND trigger_offset = ? AND trigger_type IN ('push', 'sound')");
                $legacy->execute([$event['id'], $event['reminder_minutes']]);
                foreach ($legacy->fetchAll() as $old) {
                    if ($old['snooze_until'] !== null) {
                        // The legacy row owns a manually snoozed reminder.
                        return false;
                    }
                    if ($old['is_sent']) {
                        $sent->execute([$event['id']]);
                        return true;
                    }
                }
                $expires = $starts->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d H:i:s');
                $triggers->onEventReminder((int) $event['id'], (int) $event['user_id'], $event['title'],
                    (int) ceil($remaining / 60), $expires);
                $sent->execute([$event['id']]);
                $db->prepare("UPDATE event_reminders SET is_sent = 1, sent_at = NOW()
                    WHERE event_id = ? AND trigger_offset = ? AND trigger_type IN ('push', 'sound')
                    AND is_sent = 0 AND snooze_until IS NULL")->execute([$event['id'], $event['reminder_minutes']]);
            } else {
                $expires = $now->modify('tomorrow')->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d H:i:s');
                $triggers->onScheduleTaskDue((int) $event['id'], (int) $event['user_id'], $event['title'], $expires);
            }
            return true;
        });
    }

    $legacy = $db->query("SELECT r.id, r.next_trigger_at, r.trigger_type, r.trigger_offset, r.snooze_until,
            e.id AS event_id, e.user_id, e.title, e.starts_at, e.reminder_minutes, f.timezone
        FROM event_reminders r JOIN events e ON e.id = r.event_id
        JOIN users u ON u.id = e.user_id AND u.family_id = e.family_id AND u.status = 'active'
        JOIN families f ON f.id = e.family_id
        WHERE r.is_sent = 0 AND e.status = 'pending' AND r.trigger_type IN ('push','sound')
            AND (e.reminder_minutes IS NULL OR e.reminder_minutes <> r.trigger_offset OR r.snooze_until IS NOT NULL)
            AND r.next_trigger_at <= NOW() + INTERVAL 1 DAY ORDER BY r.next_trigger_at, r.id");
    foreach ($legacy->fetchAll() as $row) {
        $zone = ScheduledJobs::zone($row['timezone']);
        $now = new DateTimeImmutable('now', $zone);
        $starts = new DateTimeImmutable($row['starts_at'], $zone);
        $remaining = $starts->getTimestamp() - $now->getTimestamp();
        if ($remaining <= 0 || new DateTimeImmutable($row['next_trigger_at'], $zone) > $now) {
            continue;
        }
        $count += (int) ScheduledJobs::once($db, implode('|', ['legacy', $row['id'], $row['user_id'], $row['next_trigger_at']]),
            static function () use ($db, $row, $triggers, $remaining, $starts): bool {
                $locked = $db->prepare("UPDATE event_reminders r JOIN events e ON e.id = r.event_id
                    SET r.is_sent = 1, r.sent_at = NOW()
                    WHERE r.id = ? AND r.is_sent = 0 AND r.next_trigger_at = ? AND e.status = 'pending'
                    AND e.starts_at = ? AND e.user_id = ?");
                $locked->execute([$row['id'], $row['next_trigger_at'], $row['starts_at'], $row['user_id']]);
                if ($locked->rowCount() !== 1) {
                    return false;
                }
                $triggers->onEventReminder((int) $row['event_id'], (int) $row['user_id'], $row['title'],
                    (int) ceil($remaining / 60), $starts->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d H:i:s'));
                return true;
            });
    }

    $occasions = $db->query("SELECT e.id, COALESCE(e.recurrence_parent_id, e.id) AS series_id,
            e.title, e.kind, e.starts_at, e.family_id, f.timezone
        FROM events e JOIN families f ON f.id = e.family_id
        WHERE e.kind IN ('birthday', 'anniversary') AND e.status <> 'cancelled' ORDER BY e.id");
    $members = $db->prepare("SELECT id FROM users WHERE family_id = ? AND status = 'active'");
    $seen = [];
    foreach ($occasions->fetchAll() as $row) {
        $today = new DateTimeImmutable('now', ScheduledJobs::zone($row['timezone']));
        if ((int) $today->format('G') < 6) {
            continue;
        }
        foreach ([0, 1, 7] as $days) {
            $occurrence = $today->modify("+$days days");
            if ($occurrence->format('m-d') !== substr($row['starts_at'], 5, 5)) {
                continue;
            }
            $key = implode('|', [$row['family_id'], $row['series_id'], $occurrence->format('Y-m-d'), $days]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $members->execute([$row['family_id']]);
            foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $member) {
                $count += (int) ScheduledJobs::once($db, "$key|$member", static function () use ($triggers, $row, $member, $days): void {
                    if ($row['kind'] === 'birthday') {
                        $name = trim(preg_replace("/'s Birthday$/i", '', $row['title']));
                        $triggers->onBirthdayReminder((int) $row['id'], (int) $member, $name, $days);
                    } else {
                        $triggers->onAnniversaryReminder((int) $row['id'], (int) $member, $row['title'], $days);
                    }
                });
            }
        }
    }

    $sync = SyncEngine::getInstance($db)->processSyncQueue();
    printf("Calendar reminders queued %d. Sync succeeded %d, failed %d.\n", $count, $sync['synced'], $sync['failed']);
    if ($sync['failed'] > 0) {
        throw new RuntimeException('Calendar sync has pending failures');
    }
}));
