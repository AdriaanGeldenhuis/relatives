<?php
declare(strict_types=1);

require_once __DIR__ . '/FirebaseMessaging.php';
require_once __DIR__ . '/WebPushTransport.php';
require_once __DIR__ . '/LocationNotice.php';

final class PushOutbox
{
    private const DEVICE_SESSION = "(
        (d.channel = 'fcm' AND EXISTS (SELECT 1 FROM fcm_tokens t
            WHERE t.id = d.device_id AND t.user_id = d.user_id AND t.wake_session_hash = ?))
        OR (d.channel = 'web' AND EXISTS (SELECT 1 FROM web_push_subscriptions t
            WHERE t.id = d.device_id AND t.user_id = d.user_id AND t.session_hash = ?))
    )";

    private static ?WeakMap $availability = null;
    private array $immediate = [];

    public function __construct(private PDO $db) {}

    public static function available(PDO $db): bool
    {
        if (($_ENV['PUSH_OUTBOX_ENABLED'] ?? '1') === '0') {
            return false;
        }
        self::$availability ??= new WeakMap();
        return self::$availability[$db] ??= (bool) $db->query(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_deliveries'"
        )->fetchColumn();
    }

    public static function alert(string $type, ?string $sender): array
    {
        $category = match ($type) {
            'message' => 'Family chat', 'shopping' => 'Shopping',
            'calendar' => 'Calendar', 'schedule' => 'Schedule',
            'tracking' => 'Family tracking', 'weather' => 'Weather',
            'note' => 'Notes', default => 'Relatives update',
        };
        return ['title' => trim($sender ?? '') ?: 'Relatives', 'body' => $category];
    }

    public function enqueue(int $notification, int $user, ?array $target = null, ?int $expiresUnix = null): void
    {
        $insert = $this->db->prepare("INSERT INTO push_deliveries
            (notification_id, user_id, channel, device_id, receipt, expires_at)
            SELECT id, user_id, ?, ?, ?, LEAST(COALESCE(expires_at, NOW() + INTERVAL 1 DAY),
                COALESCE(FROM_UNIXTIME(?), NOW() + INTERVAL 1 DAY), NOW() + INTERVAL 1 DAY)
            FROM notifications WHERE id = ? AND user_id = ?");
        $channels = ['fcm' => "SELECT t.id FROM fcm_tokens t WHERE t.user_id = ?
            AND (t.installation_id IS NULL OR EXISTS (SELECT 1 FROM sessions s
                WHERE s.user_id = t.user_id AND s.session_token = t.wake_session_hash AND s.expires_at > NOW()))"];
        if (WebPushTransport::publicKey() !== null) {
            $channels['web'] = "SELECT t.id FROM web_push_subscriptions t JOIN sessions s
                ON s.user_id = t.user_id AND s.session_token = t.session_hash AND s.expires_at > NOW()
                WHERE t.user_id = ?";
        }
        foreach ($channels as $channel => $sql) {
            if ($target !== null && $target['channel'] !== $channel) {
                continue;
            }
            $stmt = $this->db->prepare($sql . ($target !== null ? ' AND t.id = ?' : ''));
            $stmt->execute($target === null ? [$user] : [$user, $target['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $insert->execute([$channel, $id, bin2hex(random_bytes(16)), $expiresUnix, $notification, $user]);
            }
        }
    }

    public function sendAfterResponse(int $notification): void
    {
        if (!$this->immediate) {
            register_shutdown_function(function (): void {
                if ($this->db->inTransaction()) {
                    return;
                }
                try {
                    $budget = 2000;
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                        $budget = 5000;
                    } elseif (function_exists('litespeed_finish_request')) {
                        litespeed_finish_request();
                        $budget = 5000;
                    }
                    ignore_user_abort(true);
                    $this->dispatch($budget, array_keys($this->immediate));
                } catch (Throwable) {
                    error_log('Push delivery could not run after the response');
                }
            });
        }
        $this->immediate[$notification] = true;
    }

    public function dispatch(int $timeoutMs, array $only = []): int
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        $lease = bin2hex(random_bytes(16));
        $scope = $only ? ' AND d.notification_id IN (' . implode(',', array_fill(0, count($only), '?')) . ')' : '';
        $locationVisible = LocationNotice::visible($this->db);
        $select = $this->db->prepare("SELECT d.*, n.type, n.category, n.action_url, n.priority, n.data_json, n.is_read,
                UNIX_TIMESTAMP(d.expires_at) AS expires_unix, $locationVisible AS location_visible,
                sender.full_name AS sender_name, u.status AS user_status, f.timezone,
                p.enabled, p.push_enabled, p.sound_enabled, p.vibrate_enabled,
                p.quiet_hours_enabled, p.quiet_hours_start, p.quiet_hours_end, privacy.allow_messages
            FROM push_deliveries d LEFT JOIN notifications n ON n.id = d.notification_id AND n.user_id = d.user_id
            LEFT JOIN users u ON u.id = d.user_id LEFT JOIN families f ON f.id = u.family_id
            LEFT JOIN users sender ON sender.id = n.from_user_id AND sender.family_id = u.family_id
            LEFT JOIN notification_preferences p ON p.user_id = u.id AND p.category = n.category
            LEFT JOIN user_privacy_settings privacy ON privacy.user_id = u.id
            WHERE d.state = 'pending' AND d.next_at <= NOW() AND (d.lease_until IS NULL OR d.lease_until <= NOW())
            $scope ORDER BY d.next_at, d.id LIMIT 30");
        $select->execute($only);
        $claim = $this->db->prepare("UPDATE push_deliveries SET lease_id = ?, lease_until = NOW() + INTERVAL 45 SECOND
            WHERE id = ? AND state = 'pending' AND next_at <= NOW() AND (lease_until IS NULL OR lease_until <= NOW())");

        $fcm = [];
        $web = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $claim->execute([$lease, $job['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $job['lease_id'] = $lease;
            $data = json_decode($job['data_json'] ?? '', true) ?: [];
            $ttl = (int) $job['expires_unix'] - time();
            if ($job['type'] === 'message' && (bool) $job['is_read']) {
                $this->finish($job, 'skipped', 'already_read');
                continue;
            }
            if ($ttl <= 0 || $job['type'] === null || $job['user_status'] !== 'active'
                || (empty($data['device_test']) && !self::allowed($job))) {
                $this->finish($job, 'skipped', $ttl <= 0 ? 'expired' : 'disabled');
                continue;
            }
            $device = $this->device($job);
            if (!$device) {
                $this->finish($job, 'skipped', 'device_removed');
                continue;
            }

            $location = $job['type'] === 'tracking';
            $content = $location
                ? LocationNotice::content($data, $job['sender_name'], $job['timezone'])
                : self::alert($job['type'], $job['sender_name']);
            if ($content === null || ($location && !(bool) $job['location_visible'])) {
                $this->finish($job, 'skipped', 'location_unavailable');
                continue;
            }
            $payload = $content + [
                'type' => $job['type'],
                'notification_id' => (string) $job['notification_id'],
                'recipient_user_id' => (string) $job['user_id'],
                'receipt' => $job['receipt'],
                'action_url' => self::safePath($job['action_url']),
                'silent' => $job['sound_enabled'] === '0' || $job['sound_enabled'] === 0 ? '1' : '0',
                'vibrate' => $job['vibrate_enabled'] === '0' || $job['vibrate_enabled'] === 0 ? '0' : '1',
            ];
            if ($location) {
                $payload['location_alert'] = '1';
            }
            if ($job['type'] === 'message' && (int) ($data['message_id'] ?? 0) > 0) {
                $payload['message_id'] = (string) $data['message_id'];
                if (isset($data['sender_id'])) {
                    $payload['new_message'] = '1';
                    $payload['body'] = 'New message';
                } elseif (isset($data['reactor_id'])) {
                    $payload['body'] = 'Reacted to your message';
                }
            }
            if ($job['channel'] === 'fcm') {
                $fcm[$job['id']] = ['job' => $job, 'device' => $device, 'payload' => $payload, 'ttl' => $ttl];
            } else {
                $web[] = ['job' => $job, 'device' => $device, 'payload' => $payload, 'ttl' => $ttl];
            }
        }

        $sent = 0;
        $jobs = [];
        foreach ($fcm as $id => $item) {
            $payload = $item['payload'];
            $jobs[$id] = [
                'token' => $item['device']['token'], 'data' => $payload,
                'notification' => ['title' => $payload['title'], 'body' => $payload['body'],
                    'sound' => $payload['silent'] === '1' ? '' : 'default'],
                'android' => ['ttl' => $item['ttl'] . 's'],
            ];
        }
        $remaining = (int) (($deadline - microtime(true)) * 1000);
        $fcmBudget = $web ? intdiv($remaining, 2) : $remaining;
        $results = $fcmBudget > 0 ? (new FirebaseMessaging())->sendBatch($jobs, $fcmBudget) : [];
        foreach ($fcm as $id => $item) {
            $sent += $this->handle($item, $results[$id] ?? null);
        }
        $transport = new WebPushTransport();
        foreach ($web as $item) {
            $remaining = (int) (($deadline - microtime(true)) * 1000);
            $result = $remaining > 100
                ? $transport->send($item['device'], $item['payload'], min(2000, $remaining), $item['ttl']) : null;
            $sent += $this->handle($item, $result);
        }
        return $sent;
    }

    public static function safePath(?string $path): string
    {
        if (!$path || !preg_match('~^/(?![/\\\\])[^\p{C}\\\\]*$~uD', $path)) {
            return '/notifications/';
        }
        return $path;
    }

    private static function allowed(array $row): bool
    {
        if (($row['type'] === 'message' && $row['allow_messages'] !== null && !(bool) $row['allow_messages'])
            || ($row['enabled'] !== null && !(bool) $row['enabled'])
            || ($row['push_enabled'] !== null && !(bool) $row['push_enabled'])) {
            return false;
        }
        if (!$row['quiet_hours_enabled'] || !($row['quiet_hours_start'] && $row['quiet_hours_end'])
            || in_array($row['priority'], ['urgent', 'high'], true)) {
            return true;
        }
        try {
            $zone = new DateTimeZone($row['timezone'] ?: 'Africa/Johannesburg');
        } catch (Exception) {
            $zone = new DateTimeZone('Africa/Johannesburg');
        }
        $time = (new DateTimeImmutable('now', $zone))->format('H:i:s');
        $start = $row['quiet_hours_start'];
        $end = $row['quiet_hours_end'];
        return $start > $end ? ($time < $start && $time >= $end) : ($time < $start || $time >= $end);
    }

    private function device(array $job): ?array
    {
        $sql = $job['channel'] === 'fcm'
            ? "SELECT t.token FROM fcm_tokens t WHERE t.id = ? AND t.user_id = ?
                AND (t.installation_id IS NULL OR EXISTS (SELECT 1 FROM sessions s
                    WHERE s.user_id = t.user_id AND s.session_token = t.wake_session_hash AND s.expires_at > NOW()))"
            : "SELECT t.* FROM web_push_subscriptions t JOIN sessions s
                ON s.user_id = t.user_id AND s.session_token = t.session_hash AND s.expires_at > NOW()
                WHERE t.id = ? AND t.user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$job['device_id'], $job['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function handle(array $item, ?array $result): int
    {
        $job = $item['job'];
        $state = $result['state'] ?? 'budget';
        if ($state === 'accepted') {
            $this->finish($job, 'accepted');
            return 1;
        }
        if ($state === 'invalid_token') {
            $device = $item['device'];
            $sql = $job['channel'] === 'fcm'
                ? 'DELETE FROM fcm_tokens WHERE id = ? AND user_id = ? AND BINARY token = BINARY ?'
                : 'DELETE FROM web_push_subscriptions WHERE id = ? AND user_id = ? AND session_hash = ?';
            $this->db->prepare($sql)->execute([$job['device_id'], $job['user_id'], $device['token'] ?? $device['session_hash']]);
        }
        if (in_array($state, ['temporary', 'configuration', 'budget'], true) && (int) $job['attempts'] < 8) {
            $delay = $state === 'budget' ? 0 : max((int) $result['retry_after'], min(3600, 30 * (2 ** (int) $job['attempts'])));
            if (time() + $delay < (int) $job['expires_unix']) {
                $stmt = $this->db->prepare("UPDATE push_deliveries SET next_at = NOW() + INTERVAL ? SECOND,
                    attempts = attempts + ?, reason = ?, lease_id = NULL, lease_until = NULL WHERE id = ? AND lease_id = ?");
                $stmt->execute([$delay, $state === 'budget' ? 0 : 1, $state, $job['id'], $job['lease_id']]);
                return 0;
            }
        }
        $this->finish($job, 'failed', $state);
        return 0;
    }

    private function finish(array $job, string $state, ?string $reason = null): void
    {
        $stmt = $this->db->prepare("UPDATE push_deliveries SET state = ?, reason = ?, lease_id = NULL, lease_until = NULL,
            attempts = attempts + 1, accepted_at = IF(? = 'accepted', NOW(), accepted_at)
            WHERE id = ? AND lease_id = ? AND state = 'pending'");
        $stmt->execute([$state, $reason, $state, $job['id'], $job['lease_id']]);
    }

    public function receipt(string $receipt, int $user, string $session): bool
    {
        $locationVisible = LocationNotice::visible($this->db);
        $deviceMatches = self::DEVICE_SESSION;
        $stmt = $this->db->prepare("UPDATE push_deliveries d SET state = 'delivered', delivered_at = COALESCE(delivered_at, NOW()),
                accepted_at = COALESCE(accepted_at, NOW()), lease_id = NULL, lease_until = NULL
            WHERE d.receipt = ? AND d.user_id = ? AND d.expires_at > NOW()
            AND EXISTS (SELECT 1 FROM notifications n
                WHERE n.id = d.notification_id AND n.user_id = d.user_id AND $locationVisible
                AND (n.type <> 'message' OR n.is_read = 0))
            AND d.state IN ('pending', 'accepted', 'delivered') AND $deviceMatches");
        $stmt->execute([$receipt, $user, $session, $session]);
        return $stmt->rowCount() > 0;
    }

    public function chatMessageForReceipt(string $receipt, int $user, string $session): ?int
    {
        $deviceMatches = self::DEVICE_SESSION;
        $stmt = $this->db->prepare("SELECT n.action_url FROM push_deliveries d
            JOIN notifications n ON n.id = d.notification_id AND n.user_id = d.user_id AND n.type = 'message'
            WHERE d.receipt = ? AND d.user_id = ? AND d.expires_at > NOW()
                AND d.state IN ('pending', 'accepted', 'delivered') AND $deviceMatches");
        $stmt->execute([$receipt, $user, $session, $session]);
        $url = $stmt->fetchColumn();
        return is_string($url) && preg_match('~^/messages/\?message=([1-9][0-9]*)$~D', $url, $match)
            ? (int) $match[1] : null;
    }
}
