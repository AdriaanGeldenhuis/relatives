<?php
/**
 * Holiday Traveling - Internal Calendar Integration
 * Syncs trip itinerary events to the app's internal calendar
 */
declare(strict_types=1);

class HT_InternalCalendar {

    // Color for holiday/travel events
    private const TRAVEL_COLOR = '#f39c12'; // Orange - Holiday color

    /**
     * Insert trip itinerary events into the internal calendar
     *
     * @param int $userId The user ID
     * @param int $familyId The family ID
     * @param array $trip Trip data
     * @param array $plan Plan data with itinerary
     * @return array Created events info
     */
    public static function insertTripEvents(int $userId, int $familyId, array $trip, array $plan): array {
        // First, delete any existing events for this trip to avoid duplicates
        self::deleteTripEvents($familyId, $trip['id']);

        $createdEvents = [];
        $destination = $trip['destination'];
        $tripId = $trip['id'];

        // Create main trip event (all-day spanning event)
        $mainEventId = self::createEvent([
            'family_id' => $familyId,
            'user_id' => $userId,
            'title' => '✈️ ' . $destination,
            'description' => $trip['title'] ?? 'Holiday Trip',
            'notes' => "Trip ID: {$tripId}",
            'location' => $destination,
            'starts_at' => $trip['start_date'] . ' 00:00:00',
            'ends_at' => $trip['end_date'] . ' 23:59:59',
            'all_day' => 1,
            'kind' => 'event',
            'color' => self::TRAVEL_COLOR,
            'trip_reference' => $tripId
        ]);

        if ($mainEventId) {
            $createdEvents[] = ['id' => $mainEventId, 'type' => 'main_trip'];
        }

        // Create individual activity events from itinerary
        foreach ($plan['itinerary'] ?? [] as $day) {
            $date = $day['date'] ?? null;
            if (!$date) continue;

            $dayNum = $day['day'] ?? '';

            // Morning activities (9:00 - 12:00)
            $morningStart = 9;
            foreach ($day['morning'] ?? [] as $index => $activity) {
                $activityName = self::getActivityName($activity);
                $startHour = $morningStart + $index;
                if ($startHour >= 12) break; // Don't overflow into afternoon

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "🌅 " . $activityName,
                    'description' => self::getActivityDescription($activity),
                    'notes' => "Day {$dayNum} Morning - Trip: {$destination}",
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR,
                    'trip_reference' => $tripId
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'morning', 'day' => $dayNum];
                }
            }

            // Afternoon activities (14:00 - 17:00)
            $afternoonStart = 14;
            foreach ($day['afternoon'] ?? [] as $index => $activity) {
                $activityName = self::getActivityName($activity);
                $startHour = $afternoonStart + $index;
                if ($startHour >= 17) break;

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "☀️ " . $activityName,
                    'description' => self::getActivityDescription($activity),
                    'notes' => "Day {$dayNum} Afternoon - Trip: {$destination}",
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR,
                    'trip_reference' => $tripId
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'afternoon', 'day' => $dayNum];
                }
            }

            // Evening activities (18:00 - 21:00)
            $eveningStart = 18;
            foreach ($day['evening'] ?? [] as $index => $activity) {
                $activityName = self::getActivityName($activity);
                $startHour = $eveningStart + $index;
                if ($startHour >= 21) break;

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "🌙 " . $activityName,
                    'description' => self::getActivityDescription($activity),
                    'notes' => "Day {$dayNum} Evening - Trip: {$destination}",
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR,
                    'trip_reference' => $tripId
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'evening', 'day' => $dayNum];
                }
            }
        }

        return $createdEvents;
    }

    /**
     * Delete all events for a trip (used before re-syncing)
     */
    public static function deleteTripEvents(int $familyId, int $tripId): int {
        $sql = "DELETE FROM events WHERE family_id = ? AND notes LIKE ?";
        $stmt = HT_DB::prepare($sql);
        $stmt->execute([$familyId, "%Trip ID: {$tripId}%"]);
        return $stmt->rowCount();
    }

    /**
     * Create a single event in the internal calendar
     */
    private static function createEvent(array $data): ?int {
        try {
            $sql = "INSERT INTO events (
                family_id, user_id, created_by,
                title, description, notes, location,
                starts_at, ends_at, timezone, all_day,
                kind, color, status,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 'Africa/Johannesburg', ?,
                ?, ?, 'pending',
                NOW(), NOW()
            )";

            $stmt = HT_DB::prepare($sql);
            $stmt->execute([
                $data['family_id'],
                $data['user_id'],
                $data['user_id'],
                $data['title'],
                $data['description'] ?? null,
                $data['notes'] ?? null,
                $data['location'] ?? null,
                $data['starts_at'],
                $data['ends_at'],
                $data['all_day'] ?? 0,
                $data['kind'] ?? 'event',
                $data['color'] ?? '#3498db'
            ]);

            return (int) HT_DB::lastInsertId();
        } catch (Exception $e) {
            error_log('HT_InternalCalendar::createEvent error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get activity name from activity data (can be string or array)
     */
    private static function getActivityName($activity): string {
        if (is_string($activity)) {
            return $activity;
        }
        return $activity['name'] ?? $activity['title'] ?? 'Activity';
    }

    /**
     * Get activity description from activity data
     */
    private static function getActivityDescription($activity): string {
        if (is_string($activity)) {
            return '';
        }
        return $activity['description'] ?? $activity['notes'] ?? '';
    }
}
