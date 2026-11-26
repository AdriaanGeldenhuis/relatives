<?php
declare(strict_types=1);

/**
 * Location data access - current positions and history
 */
class LocationRepo
{
    private PDO $db;
    private TrackingCache $cache;

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /** The caller serializes writers for this user and has compared capture times. */
    public function upsertCurrent(int $userId, int $familyId, array $loc, string $motionState, bool $preciseTime, bool $quality = false): void
    {
        $captureColumn = $preciseTime ? ', recorded_ms' : '';
        $captureValue = $preciseTime ? ', ?' : '';
        $captureUpdate = $preciseTime ? ', recorded_ms = VALUES(recorded_ms)' : '';
        $scopeColumn = $quality ? ', consent_scope' : '';
        $scopeValue = $quality ? ', ?' : '';
        $clearCandidate = $quality ? ', pending_fix_json = NULL, consent_scope = VALUES(consent_scope)' : '';
        $stmt = $this->db->prepare("
            INSERT INTO tracking_current
                (user_id, family_id, lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                 motion_state, recorded_at, device_id, platform, app_version$captureColumn$scopeColumn)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?$captureValue$scopeValue)
            ON DUPLICATE KEY UPDATE
                family_id = VALUES(family_id), lat = VALUES(lat), lng = VALUES(lng),
                accuracy_m = VALUES(accuracy_m), speed_mps = VALUES(speed_mps),
                bearing_deg = VALUES(bearing_deg), altitude_m = VALUES(altitude_m),
                motion_state = VALUES(motion_state), recorded_at = VALUES(recorded_at),
                device_id = VALUES(device_id), platform = VALUES(platform), app_version = VALUES(app_version),
                updated_at = CURRENT_TIMESTAMP$captureUpdate$clearCandidate
        ");
        $params = [
            $userId, $familyId, $loc['lat'], $loc['lng'], $loc['accuracy_m'], $loc['speed_mps'],
            $loc['bearing_deg'], $loc['altitude_m'], $motionState, $loc['recorded_at'],
            $loc['device_id'], $loc['platform'], $loc['app_version'],
        ];
        if ($preciseTime) {
            $params[] = $loc['recorded_ms'];
        }
        if ($quality) {
            $params[] = $loc['consent_scope'];
        }
        $stmt->execute($params);
    }

    public function currentForWrite(int $userId, int $familyId, bool $preciseTime, bool $quality = false): ?array
    {
        $captureColumn = $preciseTime ? ', recorded_ms' : '';
        $pendingColumn = $quality ? ', pending_fix_json, consent_scope' : '';
        $stmt = $this->db->prepare("SELECT lat, lng, accuracy_m, speed_mps, motion_state, device_id, platform,
            recorded_at$captureColumn$pendingColumn
            FROM tracking_current WHERE user_id = ? AND family_id = ? FOR UPDATE");
        $stmt->execute([$userId, $familyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function holdCandidate(int $userId, int $familyId, array $fix): string
    {
        $candidate = array_intersect_key($fix, array_flip([
            'lat', 'lng', 'accuracy_m', 'speed_mps', 'bearing_deg', 'altitude_m', 'motion_state',
            'recorded_ms', 'recorded_at', 'received_at_unix', 'updated_at', 'visible_after_ms',
        ]));
        $json = json_encode($candidate, JSON_THROW_ON_ERROR);
        $this->db->prepare('UPDATE tracking_current SET pending_fix_json = ?, updated_at = updated_at
            WHERE user_id = ? AND family_id = ?')->execute([$json, $userId, $familyId]);
        return $json;
    }

    /**
     * Insert a point into location history
     */
    public function insertHistory(int $familyId, int $userId, array $loc, string $motionState): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_locations
                (family_id, user_id, lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                 motion_state, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $familyId, $userId,
            $loc['lat'], $loc['lng'],
            $loc['accuracy_m'], $loc['speed_mps'],
            $loc['bearing_deg'], $loc['altitude_m'],
            $motionState, $loc['recorded_at'],
        ]);
    }

    /**
     * Get current locations for all family members
     */
    public function getFamilyCurrentLocations(int $familyId, bool $preciseTime = false, ?array $policy = null): array
    {
        $captureColumn = $preciseTime ? 'tc.recorded_ms' : 'NULL';
        $quality = $policy !== null && TrackingPolicy::locationQuality($this->db);
        $pendingColumn = $quality ? ', tc.pending_fix_json' : '';
        $stmt = $this->db->prepare("
            SELECT u.id AS user_id, u.full_name AS name, u.avatar_color, u.has_avatar,
                   tc.lat, tc.lng, tc.accuracy_m, tc.speed_mps,
                   tc.bearing_deg, tc.altitude_m, tc.motion_state,
                   tc.recorded_at, tc.updated_at, tc.device_id, tc.platform,
                   $captureColumn AS recorded_ms, UNIX_TIMESTAMP(tc.updated_at) AS received_at_unix$pendingColumn,
                   u.location_sharing
            FROM users u
            LEFT JOIN tracking_current tc ON tc.user_id = u.id AND tc.family_id = u.family_id AND u.location_sharing = 1
            WHERE u.family_id = ? AND u.status = 'active'
            ORDER BY tc.updated_at DESC
        ");
        $stmt->execute([$familyId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($quality) {
            $now = Time::nowMs();
            foreach ($members as &$member) {
                $member = LocationQuality::visible($member, $now);
                unset($member['pending_fix_json']);
            }
            unset($member);
        }
        return $members;
    }

    /**
     * Get location history for a user within a time range
     */
    public function getHistory(int $familyId, int $userId, string $from, string $to, int $viewerId, int $limit = 500): array
    {
        $stmt = $this->db->prepare("
            SELECT t.lat, t.lng, t.accuracy_m, t.speed_mps, t.bearing_deg, t.altitude_m,
                   t.motion_state, t.recorded_at
            FROM tracking_locations t
            JOIN users u ON u.id = t.user_id AND u.family_id = t.family_id AND u.status = 'active'
            WHERE t.family_id = ? AND t.user_id = ?
              AND u.family_id = t.family_id AND (u.location_sharing = 1 OR u.id = ?)
              AND t.recorded_at BETWEEN ? AND ?
            ORDER BY t.recorded_at ASC
            LIMIT ?
        ");
        $stmt->execute([$familyId, $userId, $viewerId, $from, $to, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get current location for a single user (from cache or DB)
     */
    public function getCurrent(int $userId): ?array
    {
        $cached = $this->cache->getCurrent($userId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                   motion_state, recorded_at
            FROM tracking_current
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Prune old location history
     */
    public function pruneHistory(int $retentionDays): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_locations
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }
}
