<?php
declare(strict_types=1);

final class TrackingPolicy
{
    /** @var WeakMap<PDO, array<string, bool>>|null */
    private static ?WeakMap $tables = null;

    public static function hasTable(PDO $db, string $table): bool
    {
        self::$tables ??= new WeakMap();
        $known = self::$tables[$db] ?? [];
        if (!isset($known[$table])) {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            $known[$table] = (bool) $stmt->fetchColumn();
            self::$tables[$db] = $known;
        }
        return $known[$table];
    }

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        self::$tables ??= new WeakMap();
        $known = self::$tables[$db] ?? [];
        $key = "$table.$column";
        if (isset($known[$key])) {
            return $known[$key];
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $known[$key] = (bool) $stmt->fetchColumn();
        self::$tables[$db] = $known;
        return $known[$key];
    }

    public static function hasInstallationRegistry(PDO $db): bool
    {
        return self::hasColumn($db, 'fcm_tokens', 'installation_id');
    }

    public static function confirmedWake(PDO $db): bool
    {
        return ($_ENV['TRACKING_SILENT_WAKE'] ?? '1') === '1'
            && self::hasTable($db, 'tracking_wake_requests')
            && self::hasTable($db, 'tracking_wake_targets')
            && self::hasInstallationRegistry($db);
    }

    public static function locationQuality(PDO $db): bool
    {
        return ($_ENV['TRACKING_LOCATION_QUALITY'] ?? '1') === '1'
            && self::hasColumn($db, 'tracking_current', 'pending_fix_json')
            && self::hasColumn($db, 'tracking_current', 'consent_scope');
    }

    public static function geofenceEvidence(PDO $db): bool
    {
        return self::hasColumn($db, 'tracking_geofence_state', 'observed_ms')
            && self::hasColumn($db, 'tracking_place_state', 'observed_ms');
    }

    public static function native(array $settings, bool $sharing): array
    {
        $mode = (int) $settings['mode'];
        $adaptive = ($_ENV['TRACKING_ADAPTIVE_CADENCE'] ?? '0') === '1';
        $moving = min(600, max(5, (int) $settings['moving_interval_seconds']));
        $idle = min(3600, max(300, (int) $settings['idle_interval_seconds']));
        return [
            'mode' => $mode,
            'allowed' => $sharing && $mode !== 0,
            'location_sharing' => $sharing,
            'moving_interval' => $adaptive ? ($mode === 2 ? max(15, intdiv($moving + 1, 2)) : max(30, $moving)) : 30,
            'idle_interval' => $adaptive ? $idle : 600,
            'min_accuracy_m' => min(500, max(10, (int) $settings['min_accuracy_m'])),
            'driving_accuracy' => ($_ENV['TRACKING_DRIVING_ACCURACY'] ?? '1') === '1',
            'server_time_ms' => Time::nowMs(),
        ];
    }

    public static function metric(string $event, array $fields): void
    {
        if (($_ENV['TRACKING_DIAGNOSTICS'] ?? '0') === '1') {
            error_log('Tracking ' . $event . ' ' . json_encode($fields));
        }
    }

    public static function setCutoff(PDO $db, string $type, int $id): void
    {
        if (!self::hasTable($db, 'tracking_sharing_cutoffs')) {
            return;
        }
        $stmt = $db->prepare('INSERT INTO tracking_sharing_cutoffs (subject_type, subject_id, allowed_since_ms)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allowed_since_ms = VALUES(allowed_since_ms)');
        $stmt->execute([$type, $id, Time::nowMs()]);
    }

    public static function cutoff(PDO $db, int $familyId, int $userId): int
    {
        if (!self::hasTable($db, 'tracking_sharing_cutoffs')) {
            return 0;
        }
        $stmt = $db->prepare("SELECT MAX(allowed_since_ms) FROM tracking_sharing_cutoffs
            WHERE (subject_type = 'family' AND subject_id = ?) OR (subject_type = 'user' AND subject_id = ?)");
        $stmt->execute([$familyId, $userId]);
        return (int) $stmt->fetchColumn();
    }
}
