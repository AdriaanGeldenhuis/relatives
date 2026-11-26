<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/NotificationManager.php';

final class AlertsEngine
{
    private const PUSH_WINDOW_SECONDS = 180;

    public function __construct(
        private PDO $db,
        private AlertsRepo $rules,
    ) {}

    /** Persist the recipient's notice and push job with the original crossing. */
    public function record(
        int $family, int $user, string $member, string $type, string $place, int $eventId, int $capture,
    ): void {
        if (($_ENV['TRACKING_GEOFENCE_DELIVERY'] ?? '1') !== '1' || $capture < Time::nowMs() - 120000) {
            return;
        }
        $rule = match ($type) {
            'arrive_place' => 'arrive_place_enabled',
            'leave_place' => 'leave_place_enabled',
            'enter_geofence' => 'enter_geofence_enabled',
            'exit_geofence' => 'exit_geofence_enabled',
        };
        $rules = $this->rules->get($family);
        if (!$rules || !$rules['enabled'] || !$rules[$rule]) {
            return;
        }
        $stmt = $this->db->prepare('SELECT timezone FROM families WHERE id = ?');
        $stmt->execute([$family]);
        $timezone = $stmt->fetchColumn() ?: 'Africa/Johannesburg';
        $data = [
            'event_id' => $eventId, 'event_type' => $type, 'place_name' => $place,
            'captured_at_ms' => $capture, 'tracking_family_id' => $family,
        ];
        $content = LocationNotice::content($data, $member, $timezone);
        if ($content === null) {
            return;
        }
        NotificationManager::getInstance($this->db)->createForFamily($family, [
            'type' => NotificationManager::TYPE_TRACKING,
            'from_user_id' => $user,
            'title' => $content['title'],
            'message' => $content['body'],
            'action_url' => '/tracking/app/events.php?event=' . $eventId,
            'data' => $data,
            'send_push' => !$this->isQuiet($rules, $timezone),
            'push_expires_unix' => intdiv($capture, 1000) + self::PUSH_WINDOW_SECONDS,
        ], $user);
    }

    private function isQuiet(array $rules, string $timezone): bool
    {
        $start = $rules['quiet_hours_start'] ?? null;
        $end = $rules['quiet_hours_end'] ?? null;
        if (!$start || !$end || $start === $end) {
            return false;
        }
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            $zone = new DateTimeZone('Africa/Johannesburg');
        }
        $time = (new DateTimeImmutable('now', $zone))->format('H:i:s');
        return $start < $end ? ($start <= $time && $time < $end) : ($start <= $time || $time < $end);
    }
}
