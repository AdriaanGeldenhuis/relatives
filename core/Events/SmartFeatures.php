<?php
/**
 * ============================================
 * SMART FEATURES - FUTURE EXPANSION HOOKS
 * ============================================
 * Preparation for:
 * - Location-based reminders
 * - Natural language input
 * - "Running late" actions
 * - AI suggestions
 * - Insights & stats
 * ============================================
 */

class SmartFeatures {
    private $db;
    private $userId;
    private $familyId;
    private static $instance = null;

    private function __construct($db, $userId = null, $familyId = null) {
        $this->db = $db;
        $this->userId = $userId;
        $this->familyId = $familyId;
    }

    public static function getInstance($db, $userId = null, $familyId = null) {
        if (self::$instance === null) {
            self::$instance = new self($db, $userId, $familyId);
        }
        return self::$instance;
    }

    public function setUser($userId, $familyId) {
        $this->userId = $userId;
        $this->familyId = $familyId;
        return $this;
    }

    // ============================================
    // LOCATION-BASED REMINDERS (HOOK)
    // ============================================

    /**
     * Register location for event
     * Hook for future Google Places / GPS integration
     */
    public function setEventLocation(int $eventId, array $location): bool {
        // Location format:
        // [
        //   'name' => 'Office',
        //   'address' => '123 Main St',
        //   'lat' => -25.7479,
        //   'lng' => 28.2293,
        //   'radius' => 100, // meters for geofence
        //   'remind_on_arrival' => true,
        //   'remind_on_departure' => false
        // ]

        $stmt = $this->db->prepare("
            UPDATE events
            SET location = ?,
                location_data = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $location['address'] ?? $location['name'] ?? null,
            json_encode($location),
            $eventId
        ]);
    }

    /**
     * Check if user is near event location
     * Hook for geofence integration
     */
    public function checkProximity(int $eventId, float $userLat, float $userLng): array {
        $stmt = $this->db->prepare("
            SELECT location_data FROM events WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event || !$event['location_data']) {
            return ['nearby' => false, 'distance' => null];
        }

        $location = json_decode($event['location_data'], true);
        if (!isset($location['lat']) || !isset($location['lng'])) {
            return ['nearby' => false, 'distance' => null];
        }

        $distance = $this->calculateDistance(
            $userLat, $userLng,
            $location['lat'], $location['lng']
        );

        $radius = $location['radius'] ?? 100;

        return [
            'nearby' => $distance <= $radius,
            'distance' => round($distance),
            'radius' => $radius
        ];
    }

    private function calculateDistance($lat1, $lng1, $lat2, $lng2): float {
        // Haversine formula
        $r = 6371000; // Earth radius in meters
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi/2) ** 2 +
             cos($phi1) * cos($phi2) * sin($deltaLambda/2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $r * $c;
    }

    // ============================================
    // NATURAL LANGUAGE INPUT (HOOK)
    // ============================================

    /**
     * Parse natural language event description
     * Hook for future AI/NLP integration
     */
    public function parseNaturalLanguage(string $input): array {
        // This is a placeholder - in production, integrate with:
        // - OpenAI API
        // - Google Natural Language API
        // - Custom NLP model

        $result = [
            'title' => null,
            'date' => null,
            'time' => null,
            'duration' => null,
            'location' => null,
            'participants' => [],
            'raw_input' => $input,
            'confidence' => 0
        ];

        // Basic pattern matching as fallback

        // Extract time patterns
        if (preg_match('/at (\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $input, $matches)) {
            $result['time'] = $matches[1];
            $result['confidence'] += 0.2;
        }

        // Extract date patterns
        $datePatterns = [
            '/tomorrow/i' => date('Y-m-d', strtotime('+1 day')),
            '/today/i' => date('Y-m-d'),
            '/next week/i' => date('Y-m-d', strtotime('next monday')),
            '/on (\w+day)/i' => null, // Need to resolve day name
        ];

        foreach ($datePatterns as $pattern => $date) {
            if (preg_match($pattern, $input)) {
                $result['date'] = $date ?? $this->resolveDayName($input);
                $result['confidence'] += 0.2;
                break;
            }
        }

        // Extract duration
        if (preg_match('/for (\d+)\s*(hour|minute|hr|min)/i', $input, $matches)) {
            $duration = (int)$matches[1];
            $unit = strtolower($matches[2]);
            $result['duration'] = $duration * (strpos($unit, 'hour') !== false || $unit === 'hr' ? 60 : 1);
            $result['confidence'] += 0.2;
        }

        // The rest becomes the title
        $title = preg_replace('/at \d{1,2}(?::\d{2})?\s*(?:am|pm)?/i', '', $input);
        $title = preg_replace('/tomorrow|today|next week|on \w+day/i', '', $title);
        $title = preg_replace('/for \d+\s*(?:hour|minute|hr|min)s?/i', '', $title);
        $result['title'] = trim($title);

        if (!empty($result['title'])) {
            $result['confidence'] += 0.4;
        }

        return $result;
    }

    private function resolveDayName(string $input): ?string {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            if (stripos($input, $day) !== false) {
                return date('Y-m-d', strtotime("next $day"));
            }
        }
        return null;
    }

    // ============================================
    // RUNNING LATE ACTIONS (HOOK)
    // ============================================

    /**
     * Mark user as running late
     * Sends notifications to relevant parties
     */
    public function markRunningLate(int $eventId, int $delayMinutes = 15, string $reason = null): bool {
        $event = $this->getEvent($eventId);
        if (!$event) return false;

        // Log the delay
        $stmt = $this->db->prepare("
            INSERT INTO event_delays
            (event_id, user_id, delay_minutes, reason, notified_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$eventId, $this->userId, $delayMinutes, $reason]);

        // Notify assigned user or event owner
        $notifyUserId = null;
        if ($event['assigned_to'] && $event['assigned_to'] != $this->userId) {
            $notifyUserId = $event['assigned_to'];
        } elseif ($event['user_id'] != $this->userId) {
            $notifyUserId = $event['user_id'];
        }

        if ($notifyUserId && class_exists('NotificationManager')) {
            $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $notifManager = NotificationManager::getInstance($this->db);
            $notifManager->create([
                'user_id' => $notifyUserId,
                'from_user_id' => $this->userId,
                'type' => 'calendar',
                'title' => 'Running Late',
                'message' => ($user['full_name'] ?? 'Someone') .
                            " will be ~$delayMinutes minutes late for \"{$event['title']}\"" .
                            ($reason ? ": $reason" : ''),
                'priority' => 'high',
                'action_url' => '/calendar/',
                'data' => [
                    'event_id' => $eventId,
                    'delay_minutes' => $delayMinutes,
                    'type' => 'running_late'
                ]
            ]);
        }

        return true;
    }

    /**
     * Estimate travel time (Hook for Google Maps API)
     */
    public function estimateTravelTime(int $eventId, float $fromLat, float $fromLng): ?array {
        $stmt = $this->db->prepare("
            SELECT location_data FROM events WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event || !$event['location_data']) {
            return null;
        }

        $location = json_decode($event['location_data'], true);
        if (!isset($location['lat']) || !isset($location['lng'])) {
            return null;
        }

        // Placeholder - integrate with Google Directions API
        $distance = $this->calculateDistance(
            $fromLat, $fromLng,
            $location['lat'], $location['lng']
        );

        // Very rough estimate: 30 km/h average speed
        $estimatedMinutes = round(($distance / 1000) * 2);

        return [
            'distance_meters' => $distance,
            'estimated_minutes' => $estimatedMinutes,
            'source' => 'estimate', // Would be 'google_maps' when integrated
            'departure_time' => date('Y-m-d H:i:s'),
            'arrival_time' => date('Y-m-d H:i:s', strtotime("+$estimatedMinutes minutes"))
        ];
    }

    // ============================================
    // AI SUGGESTIONS (HOOK)
    // ============================================

    /**
     * Get scheduling suggestions based on patterns
     */
    public function getSuggestions(string $date): array {
        // Analyze past patterns
        $stmt = $this->db->prepare("
            SELECT
                kind,
                HOUR(starts_at) as typical_hour,
                COUNT(*) as frequency,
                AVG(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as avg_duration,
                AVG(productivity_rating) as avg_rating
            FROM events
            WHERE user_id = ?
            AND status = 'done'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY kind, HOUR(starts_at)
            HAVING frequency >= 3
            ORDER BY frequency DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId]);
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get free slots for the date
        $freeSlots = $this->getFreeSlots($date);

        // Generate suggestions
        $suggestions = [];
        foreach ($patterns as $pattern) {
            foreach ($freeSlots as $slot) {
                if ($slot['hour'] == $pattern['typical_hour'] &&
                    $slot['duration'] >= $pattern['avg_duration']) {
                    $suggestions[] = [
                        'type' => 'pattern_match',
                        'kind' => $pattern['kind'],
                        'start_time' => sprintf('%02d:00', $pattern['typical_hour']),
                        'duration' => round($pattern['avg_duration']),
                        'confidence' => min($pattern['frequency'] / 10, 1),
                        'reason' => "You usually do {$pattern['kind']} tasks around this time"
                    ];
                    break;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Get free time slots for a date
     */
    private function getFreeSlots(string $date): array {
        $stmt = $this->db->prepare("
            SELECT starts_at, ends_at
            FROM events
            WHERE family_id = ?
            AND user_id = ?
            AND DATE(starts_at) = ?
            AND status != 'cancelled'
            ORDER BY starts_at
        ");
        $stmt->execute([$this->familyId, $this->userId, $date]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $freeSlots = [];
        $dayStart = 8; // 8 AM
        $dayEnd = 22; // 10 PM

        $lastEnd = $dayStart;

        foreach ($events as $event) {
            $eventStart = (int)date('H', strtotime($event['starts_at']));
            if ($eventStart > $lastEnd) {
                $freeSlots[] = [
                    'hour' => $lastEnd,
                    'duration' => ($eventStart - $lastEnd) * 60
                ];
            }
            $lastEnd = max($lastEnd, (int)date('H', strtotime($event['ends_at'])));
        }

        if ($lastEnd < $dayEnd) {
            $freeSlots[] = [
                'hour' => $lastEnd,
                'duration' => ($dayEnd - $lastEnd) * 60
            ];
        }

        return $freeSlots;
    }

    // ============================================
    // INSIGHTS & STATS (HOOK)
    // ============================================

    /**
     * Get productivity insights
     */
    public function getInsights(int $days = 30): array {
        $startDate = date('Y-m-d', strtotime("-$days days"));

        // Overall stats
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_events,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed,
                SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_planned_minutes,
                SUM(CASE WHEN status = 'done'
                    THEN TIMESTAMPDIFF(MINUTE, actual_start, actual_end)
                    ELSE 0 END) as actual_minutes,
                AVG(productivity_rating) as avg_rating
            FROM events
            WHERE user_id = ?
            AND DATE(starts_at) >= ?
            AND status != 'cancelled'
        ");
        $stmt->execute([$this->userId, $startDate]);
        $overall = $stmt->fetch(PDO::FETCH_ASSOC);

        // Best performing days
        $stmt = $this->db->prepare("
            SELECT
                DAYNAME(starts_at) as day_name,
                DAYOFWEEK(starts_at) as day_number,
                COUNT(*) as events,
                AVG(productivity_rating) as avg_rating
            FROM events
            WHERE user_id = ?
            AND DATE(starts_at) >= ?
            AND status = 'done'
            AND productivity_rating IS NOT NULL
            GROUP BY DAYOFWEEK(starts_at), DAYNAME(starts_at)
            ORDER BY avg_rating DESC
        ");
        $stmt->execute([$this->userId, $startDate]);
        $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Best performing hours
        $stmt = $this->db->prepare("
            SELECT
                HOUR(starts_at) as hour,
                COUNT(*) as events,
                AVG(productivity_rating) as avg_rating
            FROM events
            WHERE user_id = ?
            AND DATE(starts_at) >= ?
            AND status = 'done'
            AND productivity_rating IS NOT NULL
            GROUP BY HOUR(starts_at)
            ORDER BY avg_rating DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId, $startDate]);
        $byHour = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Streaks
        $stmt = $this->db->prepare("
            SELECT DATE(starts_at) as date
            FROM events
            WHERE user_id = ?
            AND DATE(starts_at) >= ?
            AND status = 'done'
            GROUP BY DATE(starts_at)
            ORDER BY date DESC
        ");
        $stmt->execute([$this->userId, $startDate]);
        $completedDays = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $currentStreak = 0;
        $expectedDate = date('Y-m-d');
        foreach ($completedDays as $day) {
            if ($day == $expectedDate) {
                $currentStreak++;
                $expectedDate = date('Y-m-d', strtotime("$expectedDate -1 day"));
            } else {
                break;
            }
        }

        return [
            'period_days' => $days,
            'total_events' => (int)$overall['total_events'],
            'completed' => (int)$overall['completed'],
            'completion_rate' => $overall['total_events'] > 0
                ? round($overall['completed'] / $overall['total_events'] * 100, 1)
                : 0,
            'planned_hours' => round(($overall['total_planned_minutes'] ?? 0) / 60, 1),
            'actual_hours' => round(($overall['actual_minutes'] ?? 0) / 60, 1),
            'avg_rating' => round($overall['avg_rating'] ?? 0, 2),
            'best_days' => array_slice($byDay, 0, 3),
            'best_hours' => $byHour,
            'current_streak' => $currentStreak,
            'streak_unit' => 'days'
        ];
    }

    private function getEvent(int $eventId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM events WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
