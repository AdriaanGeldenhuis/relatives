<?php
declare(strict_types=1);

class TrackingTime {
    /**
     * Format a datetime string as relative time ("2 min ago", "3 hours ago", etc.)
     */
    public static function relative(string $datetime): string {
        $ts = strtotime($datetime);
        if ($ts === false) return $datetime;

        $diff = time() - $ts;

        if ($diff < 0) return 'just now';
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';

        return date('M j, Y', $ts);
    }

    /**
     * Format datetime for display
     */
    public static function format(string $datetime, string $format = 'M j, Y g:i A'): string {
        $ts = strtotime($datetime);
        return $ts !== false ? date($format, $ts) : $datetime;
    }

    /**
     * Check if a datetime is within the last N seconds
     */
    public static function isRecent(string $datetime, int $seconds = 300): bool {
        $ts = strtotime($datetime);
        return $ts !== false && (time() - $ts) < $seconds;
    }

    /**
     * Get duration string from seconds
     */
    public static function duration(int $seconds): string {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return $h . 'h ' . $m . 'm';
    }
}
