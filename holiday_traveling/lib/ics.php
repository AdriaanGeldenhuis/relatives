<?php
/**
 * Holiday Traveling - ICS (iCalendar) Export
 * Generates .ics files for calendar import
 */
declare(strict_types=1);

class HT_ICS {
    private array $events = [];
    private string $calendarName;
    private string $timezone;

    public function __construct(string $calendarName = 'Trip Calendar', string $timezone = 'Africa/Johannesburg') {
        $this->calendarName = $calendarName;
        $this->timezone = $timezone;
    }

    /**
     * Add an event to the calendar
     */
    public function addEvent(array $event): self {
        $this->events[] = $event;
        return $this;
    }

    /**
     * Add all-day event
     */
    public function addAllDayEvent(string $summary, string $startDate, string $endDate, string $description = '', string $location = ''): self {
        return $this->addEvent([
            'type' => 'allday',
            'summary' => $summary,
            'start' => $startDate,
            'end' => $endDate,
            'description' => $description,
            'location' => $location
        ]);
    }

    /**
     * Add timed event
     */
    public function addTimedEvent(string $summary, string $startDateTime, string $endDateTime, string $description = '', string $location = ''): self {
        return $this->addEvent([
            'type' => 'timed',
            'summary' => $summary,
            'start' => $startDateTime,
            'end' => $endDateTime,
            'description' => $description,
            'location' => $location
        ]);
    }

    /**
     * Build ICS content from trip plan
     */
    public static function fromTripPlan(array $trip, array $plan): self {
        $ics = new self('Trip: ' . $trip['destination']);

        // Main trip event (all-day spanning)
        $ics->addAllDayEvent(
            'Trip: ' . $trip['destination'],
            $trip['start_date'],
            date('Y-m-d', strtotime($trip['end_date'] . ' +1 day')),
            $trip['title'],
            $trip['destination']
        );

        // Itinerary events
        foreach ($plan['itinerary'] ?? [] as $day) {
            $date = $day['date'] ?? null;
            if (!$date) continue;

            $dayNum = $day['day'] ?? '?';

            // Morning activities (9am - 12pm default)
            $morningStart = 9;
            foreach ($day['morning'] ?? [] as $activity) {
                $activityName = is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? 'Morning Activity') : $activity;
                $ics->addTimedEvent(
                    "Day {$dayNum}: {$activityName}",
                    "{$date} " . sprintf('%02d:00:00', $morningStart),
                    "{$date} " . sprintf('%02d:00:00', $morningStart + 1),
                    is_array($activity) ? ($activity['description'] ?? '') : '',
                    $trip['destination']
                );
                $morningStart++;
            }

            // Afternoon activities (2pm - 5pm default)
            $afternoonStart = 14;
            foreach ($day['afternoon'] ?? [] as $activity) {
                $activityName = is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? 'Afternoon Activity') : $activity;
                $ics->addTimedEvent(
                    "Day {$dayNum}: {$activityName}",
                    "{$date} " . sprintf('%02d:00:00', $afternoonStart),
                    "{$date} " . sprintf('%02d:00:00', $afternoonStart + 1),
                    is_array($activity) ? ($activity['description'] ?? '') : '',
                    $trip['destination']
                );
                $afternoonStart++;
            }

            // Evening activities (6pm - 9pm default)
            $eveningStart = 18;
            foreach ($day['evening'] ?? [] as $activity) {
                $activityName = is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? 'Evening Activity') : $activity;
                $ics->addTimedEvent(
                    "Day {$dayNum}: {$activityName}",
                    "{$date} " . sprintf('%02d:00:00', $eveningStart),
                    "{$date} " . sprintf('%02d:00:00', $eveningStart + 1),
                    is_array($activity) ? ($activity['description'] ?? '') : '',
                    $trip['destination']
                );
                $eveningStart++;
            }
        }

        return $ics;
    }

    /**
     * Generate ICS file content
     */
    public function generate(): string {
        $lines = [];

        // Calendar header
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Relatives App//Holiday Traveling//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . $this->escapeText($this->calendarName);
        $lines[] = 'X-WR-TIMEZONE:' . $this->timezone;

        // Timezone definition
        $lines[] = $this->getTimezoneDefinition();

        // Events
        foreach ($this->events as $event) {
            $lines[] = $this->generateEvent($event);
        }

        // Calendar footer
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Generate a single event
     */
    private function generateEvent(array $event): string {
        $lines = [];
        $lines[] = 'BEGIN:VEVENT';

        // Generate unique ID
        $uid = uniqid('ht-', true) . '@relatives.app';
        $lines[] = 'UID:' . $uid;

        // Timestamp
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');

        // Summary (title)
        $lines[] = 'SUMMARY:' . $this->escapeText($event['summary']);

        // Start/End times
        if ($event['type'] === 'allday') {
            $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $event['start']);
            $lines[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $event['end']);
        } else {
            $startDT = new DateTime($event['start'], new DateTimeZone($this->timezone));
            $endDT = new DateTime($event['end'], new DateTimeZone($this->timezone));

            $lines[] = 'DTSTART;TZID=' . $this->timezone . ':' . $startDT->format('Ymd\THis');
            $lines[] = 'DTEND;TZID=' . $this->timezone . ':' . $endDT->format('Ymd\THis');
        }

        // Description
        if (!empty($event['description'])) {
            $lines[] = 'DESCRIPTION:' . $this->escapeText($event['description']);
        }

        // Location
        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . $this->escapeText($event['location']);
        }

        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    /**
     * Escape text for ICS format
     */
    private function escapeText(string $text): string {
        $text = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
        return $text;
    }

    /**
     * Get timezone definition block
     */
    private function getTimezoneDefinition(): string {
        // Simplified timezone for Africa/Johannesburg (SAST - no DST)
        if ($this->timezone === 'Africa/Johannesburg') {
            return implode("\r\n", [
                'BEGIN:VTIMEZONE',
                'TZID:Africa/Johannesburg',
                'BEGIN:STANDARD',
                'DTSTART:19700101T000000',
                'TZOFFSETFROM:+0200',
                'TZOFFSETTO:+0200',
                'TZNAME:SAST',
                'END:STANDARD',
                'END:VTIMEZONE'
            ]);
        }

        // Generic UTC fallback
        return implode("\r\n", [
            'BEGIN:VTIMEZONE',
            'TZID:' . $this->timezone,
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0000',
            'TZOFFSETTO:+0000',
            'END:STANDARD',
            'END:VTIMEZONE'
        ]);
    }

    /**
     * Output ICS file for download
     */
    public function download(string $filename = 'trip-calendar.ics'): never {
        $content = $this->generate();

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, must-revalidate');

        echo $content;
        exit;
    }

    /**
     * Return ICS content as string
     */
    public function toString(): string {
        return $this->generate();
    }
}
