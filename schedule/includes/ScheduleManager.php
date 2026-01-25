<?php
/**
 * Schedule Manager Class
 * Handles schedule operations and business logic
 */

class ScheduleManager {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Get events for a specific date
     */
    public function getEventsByDate($familyId, $date) {
        $stmt = $this->db->prepare("
            SELECT e.*, u.full_name, u.avatar_color
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.family_id = ?
              AND DATE(e.starts_at) = ?
              AND e.kind IN ('work', 'study', 'todo')
              AND e.status != 'cancelled'
            ORDER BY e.starts_at ASC
        ");
        $stmt->execute([$familyId, $date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get events for a date range
     */
    public function getEventsByDateRange($familyId, $startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT e.*, u.full_name, u.avatar_color
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.family_id = ?
              AND DATE(e.starts_at) >= ?
              AND DATE(e.starts_at) <= ?
              AND e.kind IN ('work', 'study', 'todo')
              AND e.status != 'cancelled'
            ORDER BY e.starts_at ASC
        ");
        $stmt->execute([$familyId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check for conflicting events
     */
    public function checkConflicts($familyId, $startsAt, $endsAt, $excludeEventId = null) {
        $sql = "
            SELECT id, title, starts_at, ends_at, kind, color
            FROM events
            WHERE family_id = ?
              AND status != 'cancelled'
              AND status != 'done'
              AND (
                  (starts_at < ? AND ends_at > ?) OR
                  (starts_at >= ? AND starts_at < ?) OR
                  (ends_at > ? AND ends_at <= ?)
              )
        ";
        
        $params = [$familyId, $endsAt, $startsAt, $startsAt, $endsAt, $startsAt, $endsAt];
        
        if ($excludeEventId) {
            $sql .= " AND id != ?";
            $params[] = $excludeEventId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get upcoming alarms
     */
    public function getUpcomingAlarms($familyId, $hoursAhead = 24) {
        $stmt = $this->db->prepare("
            SELECT e.*, u.full_name, u.avatar_color,
                   TIMESTAMPDIFF(MINUTE, NOW(), e.starts_at) as minutes_until,
                   TIMESTAMPDIFF(MINUTE, NOW(), 
                       DATE_SUB(e.starts_at, INTERVAL e.reminder_minutes MINUTE)
                   ) as minutes_until_alarm
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.family_id = ?
              AND e.status = 'pending'
              AND e.reminder_minutes IS NOT NULL
              AND e.reminder_minutes > 0
              AND e.starts_at >= NOW()
              AND e.starts_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
            ORDER BY e.starts_at ASC
        ");
        $stmt->execute([$familyId, $hoursAhead]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get statistics for a date range
     */
    public function getStatistics($familyId, $startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                kind,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed,
                SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_minutes
            FROM events
            WHERE family_id = ?
              AND DATE(starts_at) >= ?
              AND DATE(starts_at) <= ?
              AND kind IN ('work', 'study')
              AND status != 'cancelled'
            GROUP BY kind
        ");
        $stmt->execute([$familyId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
    }
    
    /**
     * Create recurring events
     */
    public function createRecurringEvents($eventData, $occurrences = 10) {
        if (empty($eventData['repeat_rule'])) {
            return [];
        }
        
        $createdEvents = [];
        $baseDate = new DateTime($eventData['starts_at']);
        $duration = (new DateTime($eventData['ends_at']))->getTimestamp() - $baseDate->getTimestamp();
        
        for ($i = 1; $i <= $occurrences; $i++) {
            $nextDate = clone $baseDate;
            
            switch ($eventData['repeat_rule']) {
                case 'daily':
                    $nextDate->modify("+{$i} day");
                    break;
                case 'weekdays':
                    $daysAdded = 0;
                    $tempDate = clone $baseDate;
                    while ($daysAdded < $i) {
                        $tempDate->modify('+1 day');
                        if ($tempDate->format('N') < 6) { // Monday = 1, Sunday = 7
                            $daysAdded++;
                        }
                    }
                    $nextDate = $tempDate;
                    break;
                case 'weekly':
                    $nextDate->modify("+{$i} week");
                    break;
                case 'biweekly':
                    $nextDate->modify("+" . ($i * 2) . " weeks");
                    break;
                case 'monthly':
                    $nextDate->modify("+{$i} month");
                    break;
            }
            
            $newStartsAt = $nextDate->format('Y-m-d H:i:s');
            $newEndsAt = (clone $nextDate)->modify("+{$duration} seconds")->format('Y-m-d H:i:s');
            
            // Create the event
            $stmt = $this->db->prepare("
                INSERT INTO events 
                (family_id, user_id, kind, title, notes, starts_at, ends_at, color, 
                 status, reminder_minutes, repeat_rule, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            
            $stmt->execute([
                $eventData['family_id'],
                $eventData['user_id'],
                $eventData['kind'],
                $eventData['title'],
                $eventData['notes'],
                $newStartsAt,
                $newEndsAt,
                $eventData['color'],
                $eventData['reminder_minutes'],
                $eventData['repeat_rule']
            ]);
            
            $createdEvents[] = $this->db->lastInsertId();
        }
        
        return $createdEvents;
    }
    
    /**
     * Get productivity score
     */
    public function getProductivityScore($familyId, $userId, $days = 7) {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_events,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed_events,
                SUM(CASE WHEN status = 'done' THEN TIMESTAMPDIFF(MINUTE, starts_at, ends_at) ELSE 0 END) as productive_minutes
            FROM events
            WHERE family_id = ?
              AND user_id = ?
              AND DATE(starts_at) >= ?
              AND DATE(starts_at) <= ?
              AND kind IN ('work', 'study')
              AND status != 'cancelled'
        ");
        $stmt->execute([$familyId, $userId, $startDate, $endDate]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $completionRate = $stats['total_events'] > 0 
            ? ($stats['completed_events'] / $stats['total_events']) * 100 
            : 0;
        
        return [
            'total_events' => $stats['total_events'],
            'completed_events' => $stats['completed_events'],
            'productive_minutes' => $stats['productive_minutes'],
            'productive_hours' => round($stats['productive_minutes'] / 60, 1),
            'completion_rate' => round($completionRate, 1),
            'score' => round($completionRate * 0.7 + min(($stats['productive_minutes'] / 60) * 2, 30), 1)
        ];
    }
}