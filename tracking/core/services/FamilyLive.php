<?php
declare(strict_types=1);

final class FamilyLive
{
    public const PULSE_MS = 30000;
    public const VIEWER_TTL_MS = 75000;

    public function __construct(private PDO $db) {}

    public static function available(PDO $db): bool
    {
        return TrackingPolicy::hasTable($db, 'tracking_live_phones');
    }

    private function row(int $familyId, string $lock = ''): ?array
    {
        $stmt = $this->db->prepare('SELECT session_id, expires_ms FROM tracking_live_sessions WHERE family_id = ?' . $lock);
        $stmt->execute([$familyId]);
        return $stmt->fetch() ?: null;
    }

    private function eligiblePhones(SiteContext $ctx): array
    {
        $stmt = $this->db->prepare("SELECT f.token, f.installation_id, f.user_id, f.wake_session_hash
            FROM fcm_tokens f JOIN users u ON u.id = f.user_id
            JOIN sessions s ON s.user_id = u.id AND s.session_token = f.wake_session_hash AND s.expires_at > NOW()
            WHERE u.family_id = ? AND u.status = 'active' AND u.location_sharing = 1
              AND f.device_type = 'android' AND f.installation_id IS NOT NULL AND f.wake_capability = 'silent_ready'
              AND CASE WHEN JSON_VALID(f.device_info) THEN JSON_UNQUOTE(JSON_EXTRACT(f.device_info, '$.family_live')) END = 'true'");
        $stmt->execute([$ctx->familyId]);
        return $stmt->fetchAll();
    }

    public function view(SiteContext $ctx, string $viewer, bool $visible): array
    {
        $db = $this->db;
        $family = $ctx->familyId;
        $db->beginTransaction();
        try {
            if ($visible) {
                $setting = $db->prepare('SELECT mode FROM tracking_family_settings WHERE family_id = ? LOCK IN SHARE MODE');
                $setting->execute([$family]);
                $mode = $setting->fetchColumn();
                if ($mode !== false && (int) $mode === 0) {
                    $db->commit();
                    return ['session' => null, 'targets' => []];
                }
            }
            // Create and lock a stable row to serialize starts, renewals and departures.
            $db->prepare('INSERT INTO tracking_live_sessions (family_id, session_id, expires_ms)
                VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE family_id = family_id')->execute([$family, TrackingWake::newId()]);
            $row = $this->row($family, ' FOR UPDATE');
            $now = Time::nowMs();
            $session = $row['session_id'];
            $targets = [];
            if ($visible && (int) $row['expires_ms'] <= $now) {
                $session = TrackingWake::newId();
                $db->prepare('DELETE FROM tracking_live_viewers WHERE family_id = ?')->execute([$family]);
                $db->prepare('DELETE FROM tracking_live_phones WHERE family_id = ?')->execute([$family]);
                $targets = $this->eligiblePhones($ctx);
                $insert = $db->prepare("INSERT INTO tracking_live_phones (family_id, user_id, device_id, auth_session, state)
                    VALUES (?, ?, ?, ?, 'starting')");
                foreach ($targets as $target) {
                    $insert->execute([$family, $target['user_id'], $target['installation_id'], $target['wake_session_hash']]);
                }
            }
            $viewerKey = [$family, $ctx->userId, $ctx->sessionHash(), $viewer];
            if ($visible) {
                $db->prepare('INSERT INTO tracking_live_viewers (family_id, user_id, auth_session, viewer_id, expires_ms)
                    VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE expires_ms = VALUES(expires_ms)')
                    ->execute([...$viewerKey, $now + self::VIEWER_TTL_MS]);
            } else {
                $db->prepare('DELETE FROM tracking_live_viewers
                    WHERE family_id = ? AND user_id = ? AND auth_session = ? AND viewer_id = ?')->execute($viewerKey);
            }
            $db->prepare('DELETE FROM tracking_live_viewers WHERE family_id = ? AND expires_ms <= ?')->execute([$family, $now]);
            $expiry = $db->prepare('SELECT MAX(expires_ms) FROM tracking_live_viewers WHERE family_id = ?');
            $expiry->execute([$family]);
            $expires = (int) $expiry->fetchColumn();
            $db->prepare('UPDATE tracking_live_sessions SET session_id = ?, expires_ms = ? WHERE family_id = ?')
                ->execute([$session, $expires ?: $now, $family]);
            $db->commit();
            return ['session' => $expires ? $this->lease($session, $expires) : null, 'targets' => $targets];
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    private function lease(string $session, int $expires): array
    {
        $now = Time::nowMs();
        return ['id' => $session, 'remaining_ms' => max(0, $expires - $now), 'pulse_ms' => self::PULSE_MS,
            'server_time_ms' => $now];
    }

    public function active(int $family): ?array
    {
        $row = $this->row($family);
        return $row && (int) $row['expires_ms'] > Time::nowMs()
            ? $this->lease($row['session_id'], (int) $row['expires_ms']) : null;
    }

    public function end(int $family): void
    {
        $this->db->prepare('DELETE FROM tracking_live_sessions WHERE family_id = ?')->execute([$family]);
    }

    // LocationIngest holds the account and privacy locks when calling this method.
    public function pulse(SiteContext $ctx, string $session, string $device): ?array
    {
        $row = $this->row($ctx->familyId, ' LOCK IN SHARE MODE');
        $now = Time::nowMs();
        if (!$row || $row['session_id'] !== $session || (int) $row['expires_ms'] <= $now) {
            return null;
        }
        $check = $this->db->prepare("SELECT auth_session FROM tracking_live_phones WHERE family_id = ? AND user_id = ? AND device_id = ?");
        $key = [$ctx->familyId, $ctx->userId, $device];
        $check->execute($key);
        $bound = $check->fetchColumn();
        if ($bound !== false && $bound !== $ctx->sessionHash()) {
            return null;
        }
        $this->db->prepare("INSERT INTO tracking_live_phones (family_id, user_id, device_id, auth_session, state, last_seen_ms)
            VALUES (?, ?, ?, ?, 'live', ?) ON DUPLICATE KEY UPDATE state = 'live', last_seen_ms = VALUES(last_seen_ms)")
            ->execute([...$key, $ctx->sessionHash(), $now]);
        return $this->lease($session, (int) $row['expires_ms']);
    }

    public function requireOpen(SiteContext $ctx, string $session, string $device): void
    {
        $this->db->prepare("UPDATE tracking_live_phones p JOIN tracking_live_sessions s USING (family_id)
            SET p.state = 'open_required' WHERE p.family_id = ? AND p.user_id = ? AND p.device_id = ?
                AND p.auth_session = ? AND p.state = 'starting' AND s.session_id = ? AND s.expires_ms > ?")
            ->execute([$ctx->familyId, $ctx->userId, $device, $ctx->sessionHash(), $session, Time::nowMs()]);
    }

    public function presence(int $family): array
    {
        $stmt = $this->db->prepare("SELECT p.user_id, p.state, p.last_seen_ms FROM tracking_live_phones p
            JOIN tracking_live_sessions l USING (family_id)
            JOIN users u ON u.id = p.user_id AND u.family_id = p.family_id AND u.status = 'active' AND u.location_sharing = 1
            JOIN sessions s ON s.user_id = p.user_id AND s.session_token = p.auth_session AND s.expires_at > NOW()
            WHERE p.family_id = ? AND l.expires_ms > ? ORDER BY p.last_seen_ms DESC, p.state = 'starting' DESC");
        $now = Time::nowMs();
        $stmt->execute([$family, $now]);
        $presence = [];
        foreach ($stmt->fetchAll() as $phone) {
            $last = (int) $phone['last_seen_ms'];
            $user = (int) $phone['user_id'];
            $presence[$user] ??= ['state' => $phone['state'], 'last_seen_ms' => $last ?: null, 'phones' => 0, 'live_phones' => 0];
            $presence[$user]['phones']++;
            $presence[$user]['live_phones'] += (int) ($last > $now - 2 * self::PULSE_MS);
        }
        return $presence;
    }
}
