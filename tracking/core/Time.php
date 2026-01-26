<?php
/**
 * Time Utilities
 *
 * Helper functions for time/date operations in tracking.
 */

class Time
{
    /**
     * Get current timestamp in MySQL format.
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get current Unix timestamp.
     */
    public static function unix(): int
    {
        return time();
    }

    /**
     * Format Unix timestamp to MySQL datetime.
     */
    public static function fromUnix(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Convert MySQL datetime to Unix timestamp.
     */
    public static function toUnix(string $datetime): int
    {
        return strtotime($datetime);
    }

    /**
     * Get timestamp N seconds in the future.
     */
    public static function addSeconds(int $seconds, ?string $from = null): string
    {
        $base = $from ? strtotime($from) : time();
        return date('Y-m-d H:i:s', $base + $seconds);
    }

    /**
     * Get timestamp N seconds in the past.
     */
    public static function subSeconds(int $seconds, ?string $from = null): string
    {
        $base = $from ? strtotime($from) : time();
        return date('Y-m-d H:i:s', $base - $seconds);
    }

    /**
     * Check if timestamp is expired (in the past).
     */
    public static function isExpired(string $datetime): bool
    {
        return strtotime($datetime) < time();
    }

    /**
     * Get seconds until a future timestamp.
     * Returns negative if already expired.
     */
    public static function secondsUntil(string $datetime): int
    {
        return strtotime($datetime) - time();
    }

    /**
     * Get seconds since a past timestamp.
     */
    public static function secondsSince(string $datetime): int
    {
        return time() - strtotime($datetime);
    }

    /**
     * Calculate age of a timestamp as human-readable string.
     */
    public static function ago(string $datetime): string
    {
        $seconds = time() - strtotime($datetime);

        if ($seconds < 0) {
            return 'in the future';
        }

        if ($seconds < 60) {
            return 'just now';
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return $minutes . ' min ago';
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        }

        $days = floor($seconds / 86400);
        if ($days < 7) {
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        }

        return date('M j', strtotime($datetime));
    }

    /**
     * Check if timestamp is stale (older than threshold).
     */
    public static function isStale(string $datetime, int $thresholdSeconds): bool
    {
        return self::secondsSince($datetime) > $thresholdSeconds;
    }

    /**
     * Format timestamp to ISO 8601.
     */
    public static function toISO(string $datetime): string
    {
        $dt = new DateTime($datetime);
        return $dt->format(DateTime::ATOM);
    }

    /**
     * Get start of today (midnight).
     */
    public static function startOfDay(?string $date = null): string
    {
        $base = $date ? strtotime($date) : time();
        return date('Y-m-d 00:00:00', $base);
    }

    /**
     * Get end of today (23:59:59).
     */
    public static function endOfDay(?string $date = null): string
    {
        $base = $date ? strtotime($date) : time();
        return date('Y-m-d 23:59:59', $base);
    }

    /**
     * Check if current time is within quiet hours.
     */
    public static function isInQuietHours(?string $startTime, ?string $endTime): bool
    {
        if (!$startTime || !$endTime) {
            return false;
        }

        $now = date('H:i:s');
        $start = $startTime;
        $end = $endTime;

        // Handle overnight quiet hours (e.g., 22:00 to 07:00)
        if ($start > $end) {
            // Quiet hours span midnight
            return $now >= $start || $now <= $end;
        }

        // Same-day quiet hours
        return $now >= $start && $now <= $end;
    }
}
