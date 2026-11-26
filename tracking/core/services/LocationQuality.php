<?php
declare(strict_types=1);

final class LocationQuality
{
    public static function capture(?array $fix): int
    {
        return (int) ($fix['recorded_ms'] ?? Time::captureMs($fix['recorded_at'] ?? ''));
    }

    public static function reliable(array $fix, array $policy): bool
    {
        return isset($fix['accuracy_m']) && $fix['accuracy_m'] > 0
            && $fix['accuracy_m'] <= min(60, $policy['min_accuracy_m']);
    }

    public static function pending(array $current): ?array
    {
        $json = $current['pending_fix_json'] ?? null;
        return is_string($json) ? json_decode($json, true, 16) : null;
    }

    private static function moving(array $fix): bool
    {
        return ($fix['motion_state'] ?? null) === 'moving'
            || (float) ($fix['speed_mps'] ?? 0) >= 3.0;
    }

    private static function holdUntil(array $current): int
    {
        return self::capture($current) + (self::moving($current) ? 20000 : 90000);
    }

    public static function visible(array $current, int $now): array
    {
        $pending = self::pending($current);
        if (isset($pending['visible_after_ms']) && $now >= $pending['visible_after_ms']) {
            return $pending + $current;
        }
        return $current;
    }

    public static function sameSource(array $current, array $fix): bool
    {
        return $current['device_id'] === $fix['device_id'] && $current['platform'] === $fix['platform'];
    }

    public static function sameConsent(array $current, array $fix): bool
    {
        return $fix['consent_scope'] === null || ($current['consent_scope'] ?? null) === $fix['consent_scope'];
    }

    /**
     * Keep the current installation while it is reporting. A fresh precise
     * Android reading can also take over from a web or legacy browser tab.
     */
    public static function selection(array $fix, ?array $current, array $policy, int $now): string
    {
        if (!$current) {
            return 'advance';
        }
        $pending = self::pending($current);
        $captured = self::capture($fix);
        $lastObservation = max(self::capture($current), self::capture($pending));
        if ($captured <= $lastObservation) {
            return 'out_of_order';
        }
        $reliable = self::reliable($fix, $policy);
        $sameSource = self::sameSource($current, $fix);
        if ($sameSource && !self::sameConsent($current, $fix)) {
            return 'advance';
        }
        if (!$sameSource) {
            $lease = self::moving($current) ? 75000 : min(900, max(180, 2 * $policy['idle_interval'])) * 1000;
            $upgrade = $fix['platform'] === 'android' && $current['platform'] !== 'android' && $reliable;
            if (!$upgrade && $captured - $lastObservation < $lease) {
                return 'secondary_device';
            }
        }
        if (!$reliable && self::reliable($current, $policy) && $now < self::holdUntil($current)) {
            return $sameSource ? 'hold_coarse' : 'secondary_device';
        }
        if ($sameSource && $reliable && self::reliable($current, $policy) && self::implausibleJump($current, $fix)
            && (!$pending || !empty($pending['visible_after_ms']) || self::implausibleJump($pending, $fix))) {
            return 'hold_jump';
        }
        return 'advance';
    }

    public static function held(array $fix, array $current, string $reason, int $now): array
    {
        // The lower-confidence pin has its own timestamp and accuracy.
        $fix['received_at_unix'] = intdiv($now, 1000);
        $fix['updated_at'] = Time::format($fix['received_at_unix']);
        $fix['visible_after_ms'] = $reason === 'hold_coarse' ? self::holdUntil($current) : null;
        return $fix;
    }

    private static function implausibleJump(array $from, array $to): bool
    {
        $seconds = (self::capture($to) - self::capture($from)) / 1000;
        if ($seconds <= 0 || $seconds > 120) {
            return false;
        }
        $distance = geo_haversineDistance((float) $from['lat'], (float) $from['lng'], (float) $to['lat'], (float) $to['lng']);
        return $distance > max(120, $seconds * 60 + 2 * ((float) $from['accuracy_m'] + (float) $to['accuracy_m']));
    }
}
