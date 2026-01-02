<?php
/**
 * NOTIFICATION CLEANUP CRON
 * Runs daily to clean old read notifications
 */

require_once __DIR__ . '/../core/bootstrap.php';

echo "Notification Cleanup - " . date('Y-m-d H:i:s') . "\n";

try {
    $notifManager = NotificationManager::getInstance($db);
    
    // Clean up notifications older than 30 days
    $deleted = $notifManager->cleanup(30);
    
    echo "Deleted $deleted old notifications\n";
    
    // Clean up expired notifications
    $stmt = $db->prepare("
        DELETE FROM notifications 
        WHERE expires_at IS NOT NULL 
        AND expires_at < NOW()
    ");
    $stmt->execute();
    $expired = $stmt->rowCount();
    
    echo "Deleted $expired expired notifications\n";
    
    // Clean up old delivery logs (keep 90 days)
    $stmt = $db->prepare("
        DELETE FROM notification_delivery_log 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $stmt->execute();
    $logDeleted = $stmt->rowCount();
    
    echo "Deleted $logDeleted old delivery logs\n";
    
    echo "Cleanup completed successfully\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Notification cleanup error: " . $e->getMessage());
}