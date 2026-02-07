<?php
declare(strict_types=1);

/**
 * Alerts engine - triggers push notifications for geofence/place events.
 * Respects cooldown periods and quiet hours.
 */
class AlertsEngine
{
    private PDO $db;
    private TrackingCache $cache;
    private AlertsRepo $alertsRepo;

    public function __construct(PDO $db, TrackingCache $cache, AlertsRepo $alertsRepo)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->alertsRepo = $alertsRepo;
    }

    /**
     * Fire an alert for a geofence/place event.
     *
     * @param int    $familyId  Family ID
     * @param int    $userId    User who triggered the event
     * @param string $eventType e.g. 'enter_geofence', 'arrive_place'
     * @param int    $targetId  Geofence or place ID
     * @param array  $meta      Extra data (name, user_name, etc.)
     */
    public function fire(int $familyId, int $userId, string $eventType, int $targetId, array $meta): void
    {
        $rules = $this->getRules($familyId);
        if (!$rules || !$rules['enabled']) {
            return;
        }

        // Map event type to rule toggle
        $ruleMap = [
            'arrive_place' => 'arrive_place_enabled',
            'leave_place' => 'leave_place_enabled',
            'enter_geofence' => 'enter_geofence_enabled',
            'exit_geofence' => 'exit_geofence_enabled',
        ];

        $ruleKey = $ruleMap[$eventType] ?? null;
        if (!$ruleKey || !($rules[$ruleKey] ?? false)) {
            return;
        }

        // Quiet hours check
        if ($this->isQuietHours($rules)) {
            return;
        }

        // Cooldown check
        $cooldown = (int) ($rules['cooldown_seconds'] ?? 900);
        if ($this->cache->getAlertCooldown($familyId, $eventType, $userId, $targetId)) {
            return;
        }

        // Set cooldown
        $this->cache->setAlertCooldown($familyId, $eventType, $userId, $targetId, $cooldown);

        // Log delivery
        $this->alertsRepo->logDelivery($familyId, $eventType, $userId, $targetId);

        // Send push notification to all family members (except the triggering user)
        $this->sendFamilyAlert($familyId, $userId, $eventType, $meta);
    }

    private function getRules(int $familyId): ?array
    {
        $cached = $this->cache->getAlertRules($familyId);
        if ($cached !== null) {
            return $cached;
        }

        $rules = $this->alertsRepo->get($familyId);
        if ($rules) {
            $this->cache->setAlertRules($familyId, $rules);
        }
        return $rules;
    }

    private function isQuietHours(array $rules): bool
    {
        if (empty($rules['quiet_hours_start']) || empty($rules['quiet_hours_end'])) {
            return false;
        }

        $now = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
        $start = DateTime::createFromFormat('H:i:s', $rules['quiet_hours_start'], new DateTimeZone('Africa/Johannesburg'));
        $end = DateTime::createFromFormat('H:i:s', $rules['quiet_hours_end'], new DateTimeZone('Africa/Johannesburg'));

        if (!$start || !$end) {
            return false;
        }

        if ($start > $end) {
            return $now >= $start || $now < $end;
        }
        return $now >= $start && $now < $end;
    }

    private function sendFamilyAlert(int $familyId, int $triggerUserId, string $eventType, array $meta): void
    {
        if (!class_exists('NotificationManager')) {
            return;
        }

        $userName = $meta['user_name'] ?? 'Someone';
        $targetName = $meta['name'] ?? 'a location';

        $messages = [
            'enter_geofence' => "{$userName} entered {$targetName}",
            'exit_geofence' => "{$userName} left {$targetName}",
            'arrive_place' => "{$userName} arrived at {$targetName}",
            'leave_place' => "{$userName} left {$targetName}",
        ];

        $message = $messages[$eventType] ?? "{$userName} triggered {$eventType}";

        try {
            $nm = NotificationManager::getInstance($this->db);
            $nm->createForFamily($familyId, [
                'type' => 'tracking',
                'priority' => 'normal',
                'title' => 'Family Tracking',
                'message' => $message,
                'action_url' => '/tracking/app/',
                'data' => [
                    'event_type' => $eventType,
                    'type' => 'tracking',
                ],
            ], $triggerUserId);
        } catch (Exception $e) {
            error_log('AlertsEngine notification error: ' . $e->getMessage());
        }
    }
}
