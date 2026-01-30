<?php
/**
 * Holiday Traveling - Internal Calendar Integration
 * Syncs trip itinerary events to the app's internal calendar
 */
declare(strict_types=1);

class HT_InternalCalendar {

    // Color for holiday/travel events
    private const TRAVEL_COLOR = '#f39c12'; // Orange - Holiday color
    private const SLEEPOVER_COLOR = '#9b59b6'; // Purple - Sleepover color

    // Trip event type constants for notes tagging
    public const TRIP_TYPE_MAIN = 'MAIN';
    public const TRIP_TYPE_ACTIVITY = 'ACTIVITY';
    public const TRIP_TYPE_SLEEPOVER = 'SLEEPOVER';
    public const TRIP_TYPE_CHECKIN = 'CHECKIN';
    public const TRIP_TYPE_CHECKOUT = 'CHECKOUT';

    /**
     * Build standardized trip identifier for notes field
     * Format: HT_TRIP_ID={tripId};HT_TRIP_TYPE={type}[;extra=value]
     */
    public static function buildTripNotes(int $tripId, string $type, array $extra = []): string {
        $notes = "HT_TRIP_ID={$tripId};HT_TRIP_TYPE={$type}";
        foreach ($extra as $key => $value) {
            $notes .= ";{$key}={$value}";
        }
        return $notes;
    }

    /**
     * Insert trip itinerary events into the internal calendar
     *
     * @param int $userId The user ID
     * @param int $familyId The family ID
     * @param array $trip Trip data
     * @param array $plan Plan data with itinerary
     * @return array Created events info
     */
    public static function insertTripEvents($userId, $familyId, array $trip, array $plan): array {
        // Cast to integers
        $userId = (int) $userId;
        $familyId = (int) $familyId;
        $tripId = (int) $trip['id'];

        // Delete existing non-main trip events (keeps the main "Holiday" event, removes activities + sleepovers)
        self::deleteNonMainTripEvents($familyId, $tripId);

        $createdEvents = [];
        $destination = $trip['destination'] ?? 'Unknown';
        $startDate = new DateTime($trip['start_date']);
        $endDate = new DateTime($trip['end_date']);

        // Calculate total number of days
        $totalDays = (int) $startDate->diff($endDate)->days + 1;

        // Note: Main "Holiday" event is created when trip is first created in trips_create.php
        // Here we only create the individual activity events from the AI plan

        // Create check-in event on first day (15:00)
        $checkInNotes = self::buildTripNotes($tripId, self::TRIP_TYPE_CHECKIN, ['DAY' => 1]);
        $checkInDate = $startDate->format('Y-m-d');
        $eventId = self::createEvent([
            'family_id' => $familyId,
            'user_id' => $userId,
            'title' => "🔑 Check-in - {$destination}",
            'description' => "Arrival and check-in at accommodation",
            'notes' => $checkInNotes,
            'location' => $destination,
            'starts_at' => "{$checkInDate} 15:00:00",
            'ends_at' => "{$checkInDate} 16:00:00",
            'all_day' => 0,
            'kind' => 'event',
            'color' => self::SLEEPOVER_COLOR
        ]);
        if ($eventId) {
            $createdEvents[] = ['id' => $eventId, 'type' => 'checkin', 'day' => 1, 'date' => $checkInDate];
        }

        // Create individual activity events from itinerary
        foreach ($plan['itinerary'] ?? [] as $index => $day) {
            // Get day number (1-based) - use from AI or calculate from index
            $dayNum = $day['day'] ?? ($index + 1);

            // Calculate the date for this day based on trip start date
            // Day 1 = start_date, Day 2 = start_date + 1, etc.
            $dayDate = clone $startDate;
            $dayDate->modify('+' . ($dayNum - 1) . ' days');
            $date = $dayDate->format('Y-m-d');

            // Morning activities (9:00 - 12:00)
            $morningStart = 9;
            foreach ($day['morning'] ?? [] as $actIndex => $activity) {
                $activityName = self::getActivityName($activity);
                $startHour = $morningStart + $actIndex;
                if ($startHour >= 12) break; // Don't overflow into afternoon

                $activityNotes = self::buildTripNotes($tripId, self::TRIP_TYPE_ACTIVITY, [
                    'DAY' => $dayNum,
                    'PART' => 'morning'
                ]);

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "🌅 " . $activityName,
                    'description' => self::getActivityDescription($activity),
                    'notes' => $activityNotes,
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'morning', 'day' => $dayNum, 'date' => $date];
                }
            }

            // Afternoon activities (14:00 - 17:00)
            $afternoonStart = 14;
            foreach ($day['afternoon'] ?? [] as $actIndex => $activity) {
                $activityName = self::getActivityName($activity);
                $startHour = $afternoonStart + $actIndex;
                if ($startHour >= 17) break;

                $activityNotes = self::buildTripNotes($tripId, self::TRIP_TYPE_ACTIVITY, [
                    'DAY' => $dayNum,
                    'PART' => 'afternoon'
                ]);

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "☀️ " . $activityName,
                    'description' => self::getActivityDescription($activity),
                    'notes' => $activityNotes,
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'afternoon', 'day' => $dayNum, 'date' => $date];
                }
            }

            // Evening activities (18:00 - 21:00)
            $eveningStart = 18;
            foreach ($day['evening'] ?? [] as $actIndex => $activity) {
                $activityName = self::getActivityName($activity);
                $startHour = $eveningStart + $actIndex;
                if ($startHour >= 21) break;

                $activityNotes = self::buildTripNotes($tripId, self::TRIP_TYPE_ACTIVITY, [
                    'DAY' => $dayNum,
                    'PART' => 'evening'
                ]);

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "🌙 " . $activityName,
                    'description' => self::getActivityDescription($activity),
                    'notes' => $activityNotes,
                    'location' => $destination,
                    'starts_at' => "{$date} " . sprintf('%02d', $startHour) . ":00:00",
                    'ends_at' => "{$date} " . sprintf('%02d', $startHour + 1) . ":00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::TRAVEL_COLOR
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'evening', 'day' => $dayNum, 'date' => $date];
                }
            }

            // Create sleepover event for each night (except last day)
            if ($dayNum < $totalDays) {
                $nextDate = clone $dayDate;
                $nextDate->modify('+1 day');
                $nextDateStr = $nextDate->format('Y-m-d');

                $sleepoverNotes = self::buildTripNotes($tripId, self::TRIP_TYPE_SLEEPOVER, [
                    'NIGHT' => $dayNum
                ]);

                $eventId = self::createEvent([
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'title' => "🛌 Sleepover - {$destination}",
                    'description' => "Overnight accommodation",
                    'notes' => $sleepoverNotes,
                    'location' => $destination,
                    'starts_at' => "{$date} 21:00:00",
                    'ends_at' => "{$nextDateStr} 08:00:00",
                    'all_day' => 0,
                    'kind' => 'event',
                    'color' => self::SLEEPOVER_COLOR
                ]);

                if ($eventId) {
                    $createdEvents[] = ['id' => $eventId, 'type' => 'sleepover', 'night' => $dayNum, 'date' => $date];
                }
            }
        }

        // Create check-out event on last day (11:00)
        $checkOutNotes = self::buildTripNotes($tripId, self::TRIP_TYPE_CHECKOUT, ['DAY' => $totalDays]);
        $checkOutDate = $endDate->format('Y-m-d');
        $eventId = self::createEvent([
            'family_id' => $familyId,
            'user_id' => $userId,
            'title' => "🚪 Check-out - {$destination}",
            'description' => "Check-out from accommodation and departure",
            'notes' => $checkOutNotes,
            'location' => $destination,
            'starts_at' => "{$checkOutDate} 10:00:00",
            'ends_at' => "{$checkOutDate} 11:00:00",
            'all_day' => 0,
            'kind' => 'event',
            'color' => self::SLEEPOVER_COLOR
        ]);
        if ($eventId) {
            $createdEvents[] = ['id' => $eventId, 'type' => 'checkout', 'day' => $totalDays, 'date' => $checkOutDate];
        }

        return $createdEvents;
    }

    /**
     * Delete non-main trip events (keeps the main "Holiday" event, removes activities + sleepovers)
     * Uses the standardized HT_TRIP_ID token for bulletproof matching
     */
    public static function deleteNonMainTripEvents(int $familyId, int $tripId): int {
        // Delete all trip events EXCEPT the main holiday event
        $stmt = HT_DB::execute(
            "DELETE FROM events WHERE family_id = ? AND notes LIKE ? AND notes NOT LIKE ?",
            [$familyId, "%HT_TRIP_ID={$tripId}%", "%HT_TRIP_TYPE=MAIN%"]
        );
        return $stmt->rowCount();
    }

    /**
     * Delete activity events for a trip (keeps the main "Holiday" event)
     * Supports both old format "Trip ID: X" and new format "HT_TRIP_ID=X"
     * @deprecated Use deleteNonMainTripEvents instead
     */
    public static function deleteActivityEvents($familyId, $tripId): int {
        $familyId = (int) $familyId;
        $tripId = (int) $tripId;

        // Delete old format activity events (those with "Day" in notes)
        $stmt1 = HT_DB::execute(
            "DELETE FROM events WHERE family_id = ? AND notes LIKE ? AND notes LIKE ?",
            [$familyId, "%Trip ID: {$tripId}%", "%Day %"]
        );
        $count1 = $stmt1->rowCount();

        // Also delete new format non-main events
        $stmt2 = HT_DB::execute(
            "DELETE FROM events WHERE family_id = ? AND notes LIKE ? AND notes NOT LIKE ?",
            [$familyId, "%HT_TRIP_ID={$tripId}%", "%HT_TRIP_TYPE=MAIN%"]
        );
        $count2 = $stmt2->rowCount();

        return $count1 + $count2;
    }

    /**
     * Delete all events for a trip including the main holiday event
     * Supports both old format "Trip ID: X" and new format "HT_TRIP_ID=X"
     */
    public static function deleteTripEvents(int $familyId, int $tripId): int {
        // Delete old format events
        $stmt1 = HT_DB::execute(
            "DELETE FROM events WHERE family_id = ? AND notes LIKE ?",
            [$familyId, "%Trip ID: {$tripId}%"]
        );
        $count1 = $stmt1->rowCount();

        // Delete new format events
        $stmt2 = HT_DB::execute(
            "DELETE FROM events WHERE family_id = ? AND notes LIKE ?",
            [$familyId, "%HT_TRIP_ID={$tripId}%"]
        );
        $count2 = $stmt2->rowCount();

        return $count1 + $count2;
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
