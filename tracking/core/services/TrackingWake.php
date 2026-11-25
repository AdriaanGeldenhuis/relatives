<?php
declare(strict_types=1);

final class TrackingWake
{
    public const WINDOW_MS = 60000;
    public const PENDING = ['queued', 'sent', 'received', 'open_required'];
    public const DEVICE_STATES = ['received', 'permission', 'notifications', 'paused', 'open_required', 'no_fix'];

    public function __construct(private PDO $db) {}

    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value);
    }

    public static function newId(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-' . dechex(8 | (hexdec($hex[16]) & 3)) . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }

    public function devices(SiteContext $ctx): array
    {
        $registry = TrackingPolicy::hasInstallationRegistry($this->db);
        $fields = $registry ? 'ft.installation_id, ft.wake_capability' : 'NULL AS installation_id, NULL AS wake_capability';
        $binding = $registry ? 'AND (ft.installation_id IS NULL OR EXISTS (
            SELECT 1 FROM sessions s WHERE s.user_id = u.id AND s.session_token = ft.wake_session_hash AND s.expires_at > NOW()
        ))' : '';
        $stmt = $this->db->prepare("
            SELECT ft.id AS token_id, ft.token, u.id AS user_id, u.full_name, $fields
            FROM users u JOIN fcm_tokens ft ON ft.user_id = u.id AND ft.device_type = 'android'
            WHERE u.family_id = ? AND u.status = 'active' AND u.location_sharing = 1 AND u.id <> ?
            $binding ORDER BY ft.updated_at DESC, ft.id DESC
        ");
        $stmt->execute([$ctx->familyId, $ctx->userId]);
        $devices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $device) {
            $key = hash('sha256', $device['token']);
            $devices[$key] ??= $device;
        }
        return array_values($devices);
    }

    public function findRequest(SiteContext $ctx, string $clientId): ?string
    {
        $stmt = $this->db->prepare('SELECT request_id FROM tracking_wake_requests
            WHERE family_id = ? AND requester_id = ? AND client_request_id = ?');
        $stmt->execute([$ctx->familyId, $ctx->userId, $clientId]);
        return $stmt->fetchColumn() ?: null;
    }

    /** Serialize admissions for this family. An HTTP retry sees the original request. */
    public function admit(SiteContext $ctx, string $clientId, array $devices): array
    {
        $reservation = 'tracking:wake:' . $ctx->familyId;
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 2)');
        $lock->execute([$reservation]);
        if ((int) $lock->fetchColumn() !== 1) {
            return ['retry_after' => 2];
        }
        try {
            $existing = $this->findRequest($ctx, $clientId);
            if ($existing !== null) {
                return ['request_id' => $existing, 'dispatch' => false];
            }
            $stmt = $this->db->prepare('SELECT expires_ms FROM tracking_wake_requests
                WHERE family_id = ? ORDER BY created_ms DESC LIMIT 1');
            $stmt->execute([$ctx->familyId]);
            $now = Time::nowMs();
            $latest = (int) $stmt->fetchColumn();
            if ($latest > $now) {
                return ['retry_after' => (int) ceil(($latest - $now) / 1000)];
            }
            $id = self::newId();
            $expires = $now + self::WINDOW_MS;
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('INSERT INTO tracking_wake_requests
                (request_id, family_id, requester_id, client_request_id, created_ms, expires_ms) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id, $ctx->familyId, $ctx->userId, $clientId, $now, $expires]);
            $stmt = $this->db->prepare('INSERT INTO tracking_wake_targets
                (request_id, token_id, user_id, installation_id, state) VALUES (?, ?, ?, ?, ?)');
            foreach ($devices as $device) {
                $stmt->execute([$id, $device['token_id'], $device['user_id'], $device['installation_id'], self::initialState($device)]);
            }
            $this->db->commit();
            return ['request_id' => $id, 'expires_at_ms' => $expires, 'dispatch' => true];
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$reservation]);
        }
    }

    public static function initialState(array $device): string
    {
        return match ($device['wake_capability']) {
            'paused' => 'paused',
            'permission' => 'permission',
            'notifications' => 'notifications',
            default => 'queued',
        };
    }

    public function markSent(string $id, int $tokenId, string $state): void
    {
        $stmt = $this->db->prepare("UPDATE tracking_wake_targets SET state = ?
            WHERE request_id = ? AND token_id = ? AND state = 'queued'");
        $stmt->execute([$state, $id, $tokenId]);
    }

    public function status(SiteContext $ctx, string $id, bool $familyAllows = true): ?array
    {
        $stmt = $this->db->prepare('SELECT expires_ms, created_ms FROM tracking_wake_requests
            WHERE request_id = ? AND family_id = ? AND requester_id = ?');
        $stmt->execute([$id, $ctx->familyId, $ctx->userId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return null;
        }
        $now = Time::nowMs();
        $expired = (int) $request['expires_ms'] <= $now;
        $capability = TrackingPolicy::hasInstallationRegistry($this->db) ? 'ft.wake_capability' : 'NULL';
        $stmt = $this->db->prepare("SELECT t.user_id, t.state, t.recorded_ms, u.full_name, u.location_sharing,
            $capability AS current_capability
            FROM tracking_wake_targets t JOIN users u ON u.id = t.user_id
            LEFT JOIN fcm_tokens ft ON ft.id = t.token_id AND ft.user_id = t.user_id
            WHERE t.request_id = ? AND u.family_id = ? AND u.status = ? ORDER BY t.user_id, t.token_id");
        $stmt->execute([$id, $ctx->familyId, 'active']);
        $targets = [];
        $complete = true;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allowed = $familyAllows && $row['location_sharing'];
            $state = $allowed ? $row['state'] : 'paused';
            if (in_array($state, self::PENDING, true)
                && in_array($row['current_capability'], ['paused', 'permission', 'notifications'], true)) {
                $state = $row['current_capability'];
            }
            if ($expired && in_array($state, ['queued', 'sent', 'received'], true)) {
                $state = $state === 'received' ? 'update_timeout' : 'no_reply';
            }
            if (!$expired && in_array($state, self::PENDING, true)) {
                $complete = false;
            }
            $targets[] = [
                'user_id' => (int) $row['user_id'],
                'name' => $row['full_name'],
                'state' => $state,
                'recorded_at_ms' => $allowed && $row['recorded_ms'] !== null ? (int) $row['recorded_ms'] : null,
            ];
        }
        return [
            'request_id' => $id,
            'expires_at_ms' => (int) $request['expires_ms'],
            'remaining_ms' => max(0, (int) $request['expires_ms'] - $now),
            'created_at_ms' => (int) $request['created_ms'],
            'complete' => $complete,
            'targets' => $targets,
        ];
    }

    /** Receipts are bound to the target installation and its authenticated account. */
    public function acknowledge(SiteContext $ctx, string $id, string $device, string $state): ?array
    {
        $row = $this->target($ctx, $id, $device);
        if (!$row || (int) $row['expires_ms'] <= Time::nowMs()) {
            return null;
        }
        $pending = in_array($row['state'], [...self::PENDING, 'send_failed'], true);
        if ($pending && !($row['state'] === 'received' && $state === 'open_required')) {
            $stmt = $this->db->prepare('UPDATE tracking_wake_targets SET state = ? WHERE request_id = ? AND token_id = ?');
            $stmt->execute([$state, $id, $row['token_id']]);
        }
        TrackingPolicy::metric('wake_device', ['state' => $state, 'latency_ms' => Time::nowMs() - (int) $row['created_ms']]);
        return [
            'acquire' => $state === 'received' && $pending,
            'issued_at_ms' => (int) $row['created_ms'],
            'server_time_ms' => Time::nowMs(),
            'remaining_ms' => max(0, (int) $row['expires_ms'] - Time::nowMs()),
        ];
    }

    public function acceptsFix(SiteContext $ctx, array $fix): bool
    {
        if (!self::isId($fix['wake_request_id']) || !$fix['device_id']) {
            return false;
        }
        $row = $this->target($ctx, $fix['wake_request_id'], $fix['device_id']);
        return $row && $row['state'] === 'received'
            && $fix['recorded_ms'] >= (int) $row['created_ms']
            && Time::nowMs() < (int) $row['expires_ms'];
    }

    public function confirmFix(SiteContext $ctx, array $fix): void
    {
        $stmt = $this->db->prepare("UPDATE tracking_wake_targets
            SET state = 'refreshed', recorded_ms = ?
            WHERE request_id = ? AND user_id = ? AND installation_id = ? AND state = 'received'");
        $stmt->execute([$fix['recorded_ms'], $fix['wake_request_id'], $ctx->userId, $fix['device_id']]);
    }

    private function target(SiteContext $ctx, string $id, string $device): ?array
    {
        $stmt = $this->db->prepare('SELECT t.token_id, t.state, r.created_ms, r.expires_ms
            FROM tracking_wake_targets t JOIN tracking_wake_requests r ON r.request_id = t.request_id
            WHERE t.request_id = ? AND t.user_id = ? AND t.installation_id = ? AND r.family_id = ?
                AND EXISTS (SELECT 1 FROM fcm_tokens f WHERE f.installation_id = t.installation_id
                    AND f.user_id = t.user_id AND f.device_type = ? AND f.wake_session_hash = ?)
            LIMIT 1 FOR UPDATE');
        $stmt->execute([$id, $ctx->userId, $device, $ctx->familyId, 'android', $ctx->sessionHash()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
