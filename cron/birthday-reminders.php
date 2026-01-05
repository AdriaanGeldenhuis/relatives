<?php
/**
 * ============================================
 * CRON JOB: BIRTHDAY & ANNIVERSARY REMINDERS
 * Run daily at 6 AM: 0 6 * * * php /path/to/birthday-reminders.php
 * ============================================
 * Sends notifications for:
 * - Today's birthdays/anniversaries (high priority)
 * - Tomorrow's birthdays/anniversaries
 * - Birthdays/anniversaries in 7 days
 * ============================================
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Load core
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/../core/NotificationTriggers.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting birthday & anniversary reminder cron...\n";

try {
    $triggers = new NotificationTriggers($db);
    $today = date('m-d');
    $tomorrow = date('m-d', strtotime('+1 day'));
    $inSevenDays = date('m-d', strtotime('+7 days'));

    // Get all birthday and anniversary events matching today, tomorrow, or 7 days from now
    // Use month-day comparison for yearly recurring events
    $stmt = $db->prepare("
        SELECT
            e.id,
            e.title,
            e.starts_at,
            e.family_id,
            e.user_id,
            e.created_by,
            e.kind,
            DATE_FORMAT(e.starts_at, '%m-%d') as event_month_day,
            CASE
                WHEN DATE_FORMAT(e.starts_at, '%m-%d') = ? THEN 0
                WHEN DATE_FORMAT(e.starts_at, '%m-%d') = ? THEN 1
                WHEN DATE_FORMAT(e.starts_at, '%m-%d') = ? THEN 7
                ELSE -1
            END as days_until
        FROM events e
        WHERE e.kind IN ('birthday', 'anniversary')
          AND e.status != 'cancelled'
          AND DATE_FORMAT(e.starts_at, '%m-%d') IN (?, ?, ?)
        ORDER BY e.starts_at ASC
    ");
    $stmt->execute([$today, $tomorrow, $inSevenDays, $today, $tomorrow, $inSevenDays]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($events)) {
        echo "No birthdays or anniversaries today, tomorrow, or in 7 days\n";
        exit(0);
    }

    echo "Found " . count($events) . " birthday/anniversary reminders to send\n";

    $sent = 0;
    $failed = 0;

    foreach ($events as $event) {
        try {
            $daysUntil = (int)$event['days_until'];
            $kind = $event['kind'];

            echo "Processing: {$event['title']} ($kind, in $daysUntil days)\n";

            // Get all family members to notify
            $stmt = $db->prepare("
                SELECT id FROM users
                WHERE family_id = ? AND status = 'active'
            ");
            $stmt->execute([$event['family_id']]);
            $familyMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($familyMembers as $userId) {
                // Check if we already sent a reminder for this event today
                $stmt = $db->prepare("
                    SELECT id FROM notifications
                    WHERE user_id = ?
                      AND JSON_EXTRACT(data_json, '$.event_id') = ?
                      AND JSON_EXTRACT(data_json, '$.days_until') = ?
                      AND DATE(created_at) = CURDATE()
                    LIMIT 1
                ");
                $stmt->execute([$userId, $event['id'], $daysUntil]);

                if ($stmt->fetch()) {
                    echo "  Already sent to user $userId for $daysUntil days, skipping\n";
                    continue;
                }

                // Send the appropriate reminder
                if ($kind === 'birthday') {
                    // Extract person's name from title (e.g., "John's Birthday" -> "John")
                    $personName = preg_replace("/'s Birthday$/i", '', $event['title']);
                    $personName = trim($personName);

                    $triggers->onBirthdayReminder(
                        $event['id'],
                        $userId,
                        $personName,
                        $daysUntil
                    );
                } else {
                    // Anniversary
                    $triggers->onAnniversaryReminder(
                        $event['id'],
                        $userId,
                        $event['title'],
                        $daysUntil
                    );
                }

                echo "  Sent to user $userId\n";
                $sent++;
            }

        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
            error_log("Reminder error for event {$event['id']}: " . $e->getMessage());
            $failed++;
        }
    }

    echo "\nReminder summary: $sent sent, $failed failed\n";
    echo "Cron job completed successfully\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Birthday/Anniversary reminder cron fatal error: " . $e->getMessage());
    exit(1);
}
