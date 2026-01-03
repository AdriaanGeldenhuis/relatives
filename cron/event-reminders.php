<?php
/**
 * ============================================
 * CRON JOB: EVENT REMINDERS (NEW ENGINE)
 * ============================================
 * Uses ReminderEngine for proper reminder handling
 * Run every minute: * * * * * php /path/to/event-reminders.php
 * ============================================
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/../core/NotificationTriggers.php';
require_once __DIR__ . '/../core/Events/ReminderEngine.php';
require_once __DIR__ . '/../core/Events/SyncEngine.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting event reminder cron...\n";

try {
    $reminderEngine = ReminderEngine::getInstance($db);
    $syncEngine = SyncEngine::getInstance($db);
    $triggers = new NotificationTriggers($db);

    // Get due reminders (within next 5 minutes)
    $dueReminders = $reminderEngine->getDueReminders(5);

    if (empty($dueReminders)) {
        echo "No reminders due\n";

        // Still process sync queue
        $syncResult = $syncEngine->processSyncQueue();
        if ($syncResult['synced'] > 0 || $syncResult['failed'] > 0) {
            echo "Sync queue: {$syncResult['synced']} synced, {$syncResult['failed']} failed\n";
        }

        exit(0);
    }

    echo "Found " . count($dueReminders) . " reminders due\n";

    $sent = 0;
    $failed = 0;
    $snoozed = 0;

    foreach ($dueReminders as $reminder) {
        try {
            echo "Processing reminder {$reminder['id']} for event {$reminder['event_id']}: {$reminder['title']}\n";

            // Check if event is still pending
            if ($reminder['status'] !== 'pending') {
                echo "  ⏭️ Event no longer pending, skipping\n";
                $reminderEngine->markAsSent($reminder['id']);
                continue;
            }

            // Calculate minutes until event
            $startsAt = new DateTime($reminder['starts_at']);
            $now = new DateTime();
            $diff = $now->diff($startsAt);
            $minutesUntil = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

            // Send notification based on type
            switch ($reminder['trigger_type']) {
                case 'push':
                case 'sound':
                    $triggers->onEventReminder(
                        $reminder['event_id'],
                        $reminder['user_id'],
                        $reminder['title'],
                        $minutesUntil
                    );
                    break;

                case 'email':
                    // TODO: Implement email reminders
                    echo "  📧 Email reminders not yet implemented\n";
                    break;

                case 'silent':
                    // Silent reminder - just mark as sent
                    break;
            }

            // Mark as sent
            $reminderEngine->markAsSent($reminder['id']);
            echo "  ✅ Sent reminder\n";
            $sent++;

        } catch (Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";

            // Mark as failed for retry
            $reminderEngine->markAsFailed($reminder['id'], $e->getMessage());
            $failed++;

            // If this is the last retry, log it
            if ($reminder['retry_count'] >= $reminder['max_retries'] - 1) {
                error_log("Reminder {$reminder['id']} failed after max retries: " . $e->getMessage());
            }
        }
    }

    echo "\nReminder summary: $sent sent, $failed failed, $snoozed snoozed\n";

    // Process sync queue
    echo "\nProcessing sync queue...\n";
    $syncResult = $syncEngine->processSyncQueue();
    echo "Sync queue: {$syncResult['synced']} synced, {$syncResult['failed']} failed, {$syncResult['conflicts']} conflicts\n";

    // Cleanup old reminders (once per day at midnight)
    if (date('H:i') === '00:00') {
        echo "\nRunning daily cleanup...\n";
        $cleaned = $reminderEngine->cleanup(30);
        echo "Cleaned $cleaned old reminders\n";

        $syncCleaned = $syncEngine->cleanup(30);
        echo "Cleaned $syncCleaned old sync entries\n";
    }

    echo "\nCron job completed successfully\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Event reminder cron fatal error: " . $e->getMessage());
    exit(1);
}
