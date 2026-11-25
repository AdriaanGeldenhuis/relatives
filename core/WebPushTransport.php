<?php
declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class WebPushTransport
{
    public static function publicKey(): ?string
    {
        $public = $_ENV['WEB_PUSH_PUBLIC_KEY'] ?? '';
        $private = $_ENV['WEB_PUSH_PRIVATE_KEY'] ?? '';
        return ($_ENV['WEB_PUSH_ENABLED'] ?? '0') === '1'
            && preg_match('/^[A-Za-z0-9_-]{87}$/D', $public)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $private)
            && is_file(__DIR__ . '/../vendor/autoload.php') ? $public : null;
    }

    public static function validEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > 2048 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $url = parse_url($endpoint);
        if (($url['scheme'] ?? '') !== 'https' || isset($url['pass']) || isset($url['fragment'])
            || isset($url['user']) || (isset($url['port']) && $url['port'] !== 443)) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');
        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
    }

    public function send(array $device, array $payload, int $timeoutMs, int $ttl): array
    {
        $key = self::publicKey();
        if ($key === null || !self::validEndpoint($device['endpoint'])) {
            return ['state' => 'configuration', 'retry_after' => 0];
        }
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            $transport = new WebPush(['VAPID' => [
                'subject' => $_ENV['WEB_PUSH_SUBJECT'] ?? 'mailto:support@relatives.co.za',
                'publicKey' => $key,
                'privateKey' => $_ENV['WEB_PUSH_PRIVATE_KEY'],
            ]], [], max(1, (int) ceil($timeoutMs / 1000)), [
                'allow_redirects' => false, 'timeout' => $timeoutMs / 1000, 'connect_timeout' => $timeoutMs / 1000,
            ]);
            $report = $transport->sendOneNotification(Subscription::create([
                'endpoint' => $device['endpoint'],
                'keys' => ['p256dh' => $device['public_key'], 'auth' => $device['auth_secret']],
                'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), [
                'TTL' => $ttl, 'urgency' => 'high',
            ]);
            $retry = $report->getResponse()?->getHeaderLine('Retry-After') ?? '';
            $http = $report->getResponse()?->getStatusCode() ?? 0;
            $state = match (true) {
                $report->isSuccess() => 'accepted',
                $report->isSubscriptionExpired() => 'invalid_token',
                $http === 0 || $http === 429 || $http >= 500 => 'temporary',
                in_array($http, [401, 403], true) => 'configuration',
                default => 'rejected',
            };
            return ['state' => $state, 'retry_after' => FirebaseMessaging::retryAfter($retry)];
        } catch (Throwable) {
            error_log('Web Push transport could not send a notification');
            return ['state' => 'temporary', 'retry_after' => 0];
        }
    }
}
