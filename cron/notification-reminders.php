<?php
/**
 * ============================================
 * CRON JOB: EVENT & SCHEDULE REMINDERS
 * Run every minute, sends reminders for upcoming events
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

echo "[" . date('Y-m-d H:i:s') . "] Starting reminder cron...\n";

try {
    $triggers = new NotificationTriggers($db);
    
    // Get events with reminders due in the next 5 minutes
    $stmt = $db->prepare("
        SELECT 
            e.id,
            e.user_id,
            e.family_id,
            e.title,
            e.kind,
            e.starts_at,
            e.reminder_minutes,
            u.full_name
        FROM events e
        JOIN users u ON e.user_id = u.id
        WHERE e.reminder_minutes IS NOT NULL
          AND e.status = 'pending'
          AND e.starts_at > NOW()
          AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.type = 'calendar'
                AND JSON_EXTRACT(n.data_json, '$.event_id') = e.id
                AND n.title LIKE 'Event Reminder%'
                AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
          )
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($events)) {
        echo "No reminders due\n";
        exit(0);
    }
    
    echo "Found " . count($events) . " events needing reminders\n";
    
    foreach ($events as $event) {
        try {
            $startsAt = new DateTime($event['starts_at']);
            $now = new DateTime();
            $diff = $now->diff($startsAt);
            $minutesUntil = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
            
            echo "Processing event {$event['id']}: {$event['title']} (in {$minutesUntil}m)\n";
            
            // Send reminder notification
            $triggers->onEventReminder(
                (int)$event['id'],
                (int)$event['user_id'],
                $event['title'],
                (int)$event['reminder_minutes']
            );
            
            echo "  ✅ Sent reminder for event {$event['id']}\n";
            
        } catch (Exception $e) {
            echo "  ❌ Error for event {$event['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // Check for schedule tasks (from events table with kind='study'|'work'|'todo')
    $stmt = $db->prepare("
        SELECT 
            e.id,
            e.user_id,
            e.family_id,
            e.title,
            e.kind,
            e.starts_at
        FROM events e
        WHERE e.kind IN ('study', 'work', 'todo')
          AND e.status = 'pending'
          AND e.starts_at <= NOW()
          AND e.starts_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.type = 'schedule'
                AND JSON_EXTRACT(n.data_json, '$.task_id') = e.id
                AND n.title LIKE 'Task Due Now%'
                AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
          )
    ");
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($tasks)) {
        echo "\nFound " . count($tasks) . " tasks due now\n";
        
        foreach ($tasks as $task) {
            try {
                echo "Processing task {$task['id']}: {$task['title']}\n";
                
                $triggers->onScheduleTaskDue(
                    (int)$task['id'],
                    (int)$task['user_id'],
                    $task['title']
                );
                
                echo "  ✅ Sent due notification for task {$task['id']}\n";
                
            } catch (Exception $e) {
                echo "  ❌ Error for task {$task['id']}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\nCron job completed successfully\n";
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Reminder cron fatal error: " . $e->getMessage());
    exit(1);
}