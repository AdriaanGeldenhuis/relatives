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
            'color' => self::TRAVEL_COLOR
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
                    'notes' => "Day {$dayNum} Morning - Trip ID: {$tripId}",
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR
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
                    'notes' => "Day {$dayNum} Afternoon - Trip ID: {$tripId}",
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR
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
                    'notes' => "Day {$dayNum} Evening - Trip ID: {$tripId}",
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR
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
        // Use HT_DB::execute for raw queries
        $stmt = HT_DB::execute(
            "DELETE FROM events WHERE family_id = ? AND notes LIKE ?",
            [$familyId, "%Trip ID: {$tripId}%"]
        );
        return $stmt->rowCount();
    }

    /**
     * Create a single event in the internal calendar
     */
    private static function createEvent(array $data): ?int {
        try {
            // Use HT_DB::insert which handles the insert and returns the ID
            $eventId = HT_DB::insert('events', [
                'family_id' => $data['family_id'],
                'user_id' => $data['user_id'],
                'created_by' => $data['user_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'location' => $data['location'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'timezone' => 'Africa/Johannesburg',
                'all_day' => $data['all_day'] ?? 0,
                'kind' => $data['kind'] ?? 'event',
                'color' => $data['color'] ?? '#3498db',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return $eventId > 0 ? $eventId : null;
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
