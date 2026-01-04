<?php
/**
 * ============================================
 * CRON JOB: BIRTHDAY REMINDERS
 * Run daily at 8 AM: 0 8 * * * php /path/to/birthday-reminders.php
 * ============================================
 * Sends notifications for:
 * - Today's birthdays (high priority)
 * - Tomorrow's birthdays
 * - Birthdays in 7 days
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

echo "[" . date('Y-m-d H:i:s') . "] Starting birthday reminder cron...\n";

try {
    $triggers = new NotificationTriggers($db);
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $inSevenDays = date('Y-m-d', strtotime('+7 days'));

    // Get all birthday events for today, tomorrow, and in 7 days
    $stmt = $db->prepare("
        SELECT
            e.id,
            e.title,
            e.starts_at,
            e.family_id,
            e.user_id,
            e.created_by,
            DATE(e.starts_at) as birthday_date,
            DATEDIFF(DATE(e.starts_at), CURDATE()) as days_until
        FROM events e
        WHERE e.kind = 'birthday'
          AND e.status != 'cancelled'
          AND DATE(e.starts_at) IN (?, ?, ?)
        ORDER BY e.starts_at ASC
    ");
    $stmt->execute([$today, $tomorrow, $inSevenDays]);
    $birthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($birthdays)) {
        echo "No birthdays today, tomorrow, or in 7 days\n";
        exit(0);
    }

    echo "Found " . count($birthdays) . " birthday reminders to send\n";

    $sent = 0;
    $failed = 0;

    foreach ($birthdays as $birthday) {
        try {
            $daysUntil = (int)$birthday['days_until'];

            // Extract person's name from title (e.g., "John's Birthday" -> "John")
            $personName = preg_replace("/'s Birthday$/i", '', $birthday['title']);
            $personName = trim($personName);

            echo "Processing: {$birthday['title']} (in $daysUntil days)\n";

            // Get all family members to notify
            $stmt = $db->prepare("
                SELECT id FROM users
                WHERE family_id = ? AND status = 'active'
            ");
            $stmt->execute([$birthday['family_id']]);
            $familyMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($familyMembers as $userId) {
                // Check if we already sent a reminder for this birthday today
                $stmt = $db->prepare("
                    SELECT id FROM notifications
                    WHERE user_id = ?
                      AND JSON_EXTRACT(data_json, '$.event_id') = ?
                      AND JSON_EXTRACT(data_json, '$.days_until') = ?
                      AND DATE(created_at) = CURDATE()
                    LIMIT 1
                ");
                $stmt->execute([$userId, $birthday['id'], $daysUntil]);

                if ($stmt->fetch()) {
                    echo "  Already sent to user $userId for $daysUntil days, skipping\n";
                    continue;
                }

                // Send the birthday reminder
                $triggers->onBirthdayReminder(
                    $birthday['id'],
                    $userId,
                    $personName,
                    $daysUntil
                );

                echo "  Sent to user $userId\n";
                $sent++;
            }

        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
            error_log("Birthday reminder error for event {$birthday['id']}: " . $e->getMessage());
            $failed++;
        }
    }

    echo "\nBirthday reminder summary: $sent sent, $failed failed\n";
    echo "Cron job completed successfully\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Birthday reminder cron fatal error: " . $e->getMessage());
    exit(1);
}
