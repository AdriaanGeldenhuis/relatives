<?php
declare(strict_types=1);

final class LocationNotice
{
    public static function content(array $data, ?string $member, ?string $timezone): ?array
    {
        $type = $data['event_type'] ?? null;
        $place = $data['place_name'] ?? null;
        $capture = $data['captured_at_ms'] ?? null;
        $id = $data['event_id'] ?? null;
        $family = $data['tracking_family_id'] ?? null;
        if (!is_int($id) || $id <= 0 || !is_int($family) || $family <= 0 || !is_int($capture) || $capture <= 0
            || !is_string($place) || trim($place) === '' || !is_string($member) || trim($member) === '') {
            return null;
        }
        $verb = match ($type) {
            'enter_geofence' => 'entered',
            'arrive_place' => 'arrived at',
            'exit_geofence', 'leave_place' => 'left',
            default => null,
        };
        if ($verb === null) {
            return null;
        }
        try {
            $zone = new DateTimeZone($timezone ?: 'Africa/Johannesburg');
        } catch (Exception) {
            $zone = new DateTimeZone('Africa/Johannesburg');
        }
        $clock = (new DateTimeImmutable('@' . intdiv($capture, 1000)))->setTimezone($zone)->format('H:i');
        $place = trim(preg_replace('/[\p{C}\s]+/u', ' ', $place) ?? '');
        $member = trim(preg_replace('/[\p{C}\s]+/u', ' ', $member) ?? '');
        if ($place === '' || $member === '') {
            return null;
        }
        return [
            'title' => in_array($type, ['enter_geofence', 'exit_geofence'], true) ? 'Geofence alert' : 'Place alert',
            'body' => "$member $verb $place at $clock",
        ];
    }

    /**
     * New location notices carry the original family and subject. Use the
     * same predicate for delivery, the inbox and the header bell.
     */
    public static function visible(PDO $db): string
    {
        $tables = self::trackingTables($db);
        $meta = "IF(JSON_VALID(n.data_json), n.data_json, '{}')";
        $family = "CAST(JSON_UNQUOTE(JSON_EXTRACT($meta, '$.tracking_family_id')) AS UNSIGNED)";
        $captured = "CAST(JSON_UNQUOTE(JSON_EXTRACT($meta, '$.captured_at_ms')) AS UNSIGNED)";
        $cutoff = in_array('tracking_sharing_cutoffs', $tables, true) ? "
                    AND NOT EXISTS (SELECT 1 FROM tracking_sharing_cutoffs c
                        WHERE ((c.subject_type = 'user' AND c.subject_id = tracked.id)
                            OR (c.subject_type = 'family' AND c.subject_id = tracked.family_id))
                          AND c.allowed_since_ms > $captured)" : '';
        $policy = in_array('tracking_family_settings', $tables, true) ? "
                    AND NOT EXISTS (SELECT 1 FROM tracking_family_settings s
                        WHERE s.family_id = tracked.family_id AND s.mode = 0)" : '';
        return "(n.type <> 'tracking' OR $family IS NULL
            OR EXISTS (SELECT 1 FROM users tracked JOIN users recipient ON recipient.id = n.user_id
                WHERE tracked.id = n.from_user_id AND tracked.status = 'active' AND tracked.location_sharing = 1
                    AND tracked.family_id = recipient.family_id AND tracked.family_id = $family
                    $policy $cutoff))";
    }

    private static function trackingTables(PDO $db): array
    {
        static $known;
        $known ??= new WeakMap();
        return $known[$db] ??= $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tracking_sharing_cutoffs', 'tracking_family_settings')"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}
