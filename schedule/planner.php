<?php
declare(strict_types=1);

function scheduleDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value && $value >= '1000-01-01' && $value <= '9998-12-31'
        ? $date : null;
}

function scheduleAddUrl(string $date, string $view, bool $fromCalendar = false): string
{
    $parameters = ['date' => $date, 'view' => $view];
    if ($fromCalendar) $parameters['from'] = 'calendar';
    return '/schedule/add.php?' . http_build_query($parameters);
}

function scheduleTypes(): array
{
    return [
        'work' => ['name' => 'Work', 'color' => '#327451'],
        'study' => ['name' => 'Study', 'color' => '#65559c'],
        'church' => ['name' => 'Church', 'color' => '#80557a'],
        'event' => ['name' => 'Event', 'color' => '#346b8a'],
        'focus' => ['name' => 'Focus', 'color' => '#3c6489'],
        'break' => ['name' => 'Break', 'color' => '#8c622c'],
        'todo' => ['name' => 'To-do', 'color' => '#846051'],
        'birthday' => ['name' => 'Birthday', 'color' => '#ae5360'],
        'anniversary' => ['name' => 'Anniversary', 'color' => '#a55474'],
        'holiday' => ['name' => 'Holiday', 'color' => '#8c622c'],
        'family_event' => ['name' => 'Family event', 'color' => '#2f766f'],
        'date' => ['name' => 'Special date', 'color' => '#a55474'],
        'reminder' => ['name' => 'Reminder', 'color' => '#876338'],
        'other' => ['name' => 'Other', 'color' => '#52654c']
    ];
}

function scheduleFamilyMembers(PDO $db, int $familyId): array
{
    $stmt = $db->prepare("SELECT id, full_name FROM users WHERE family_id = ? AND status = 'active' ORDER BY full_name, id");
    $stmt->execute([$familyId]);
    return $stmt->fetchAll();
}

function scheduleActiveFocus(PDO $db, int $familyId, int $userId): ?array
{
    $stmt = $db->prepare("SELECT id, title, starts_at, ends_at, actual_start FROM events
        WHERE family_id = ? AND user_id = ? AND status = 'in_progress' AND focus_mode = 1
        ORDER BY actual_start DESC, id DESC LIMIT 1");
    $stmt->execute([$familyId, $userId]);
    return $stmt->fetch() ?: null;
}

function scheduleTemplates(): array
{
    return [
        'Productive morning' => [
            ['Morning study session', '08:00', '10:00', 'study'], ['Coffee break', '10:00', '10:15', 'break'],
            ['Work block', '10:15', '12:00', 'work'], ['Lunch break', '12:00', '13:00', 'break'],
        ],
        'Deep work day' => [
            ['Deep work session', '09:00', '11:00', 'focus'], ['Break', '11:00', '11:15', 'break'],
            ['Deep work session', '11:15', '13:00', 'focus'], ['Lunch', '13:00', '14:00', 'break'],
            ['Deep work session', '14:00', '16:00', 'focus'],
        ],
        'Study intensive' => [
            ['Study block', '08:00', '10:00', 'study'], ['Break', '10:00', '10:15', 'break'],
            ['Study block', '10:15', '12:15', 'study'], ['Lunch', '12:15', '13:00', 'break'],
            ['Study block', '13:00', '15:00', 'study'], ['Break', '15:00', '15:15', 'break'],
            ['Study block', '15:15', '17:00', 'study'],
        ],
        'Balanced day' => [
            ['Morning work', '09:00', '11:00', 'work'], ['Break', '11:00', '11:15', 'break'],
            ['Study session', '11:15', '13:00', 'study'], ['Lunch', '13:00', '14:00', 'break'],
            ['Afternoon work', '14:00', '16:00', 'work'], ['Break', '16:00', '16:15', 'break'],
            ['Personal time', '16:15', '17:00', 'todo'],
        ],
    ];
}

function scheduleEvents(PDO $db, int $familyId, DateTimeImmutable $from, DateTimeImmutable $until): array
{
    $stmt = $db->prepare("
        SELECT e.*, COALESCE(NULLIF(e.recurrence_rule, ''), e.repeat_rule) AS repeat_rule,
               u.full_name AS added_by_name, u.avatar_color,
               a.full_name AS assigned_to_name, a.avatar_color AS assigned_color
        FROM events e
        LEFT JOIN users u ON e.created_by = u.id AND u.family_id = e.family_id
        LEFT JOIN users a ON e.assigned_to = a.id AND a.family_id = e.family_id
        WHERE e.family_id = ? AND e.status != 'cancelled' AND e.starts_at < ?
          AND (e.starts_at >= ? OR e.ends_at > ?)
        ORDER BY e.all_day DESC, e.starts_at, e.id
    ");
    $start = $from->format('Y-m-d H:i:s');
    $stmt->execute([$familyId, $until->format('Y-m-d H:i:s'), $start, $start]);
    return $stmt->fetchAll();
}

function scheduleOnDay(array $events, DateTimeImmutable $day): array
{
    $start = $day->format('Y-m-d H:i:s');
    $end = $day->modify('+1 day')->format('Y-m-d H:i:s');
    return array_values(array_filter($events, static fn(array $event) =>
        $event['starts_at'] < $end && ($event['starts_at'] >= $start || ($event['ends_at'] ?? '') > $start)
    ));
}

function scheduleEventVersion(array $event): string
{
    $fields = [];
    foreach (['title', 'starts_at', 'ends_at', 'notes', 'location', 'kind', 'all_day', 'assigned_to',
        'priority', 'color', 'reminder_minutes', 'focus_mode', 'status'] as $key) {
        $fields[] = (string)($event[$key] ?? '');
    }
    $fields[] = $event['recurrence_rule'] ?: ($event['repeat_rule'] ?? '');
    return hash('sha256', serialize($fields));
}

function scheduleDuration(int $minutes): string
{
    $hours = intdiv(max(0, $minutes), 60);
    $rest = max(0, $minutes) % 60;
    return $hours ? $hours . 'h' . ($rest ? ' ' . $rest . 'm' : '') : $rest . 'm';
}

function scheduleDayTime(array $event, DateTimeImmutable $day): string
{
    if ($event['all_day']) {
        return 'All day';
    }
    $from = new DateTimeImmutable($event['starts_at']);
    $until = $event['ends_at'] ? new DateTimeImmutable($event['ends_at']) : $from;
    $tomorrow = $day->modify('+1 day');
    $start = $from < $day ? '00:00' : $from->format('H:i');
    if ($until <= $from) {
        return $start;
    }
    $end = $until >= $tomorrow ? '24:00' : $until->format('H:i');
    return $start . '–' . $end;
}

function scheduleMinutesOnDay(array $event, DateTimeImmutable $day): int
{
    if ($event['all_day'] || !$event['ends_at']) {
        return 0;
    }
    $start = max($day->getTimestamp(), strtotime($event['starts_at']));
    $end = min($day->modify('+1 day')->getTimestamp(), strtotime($event['ends_at']));
    return max(0, intdiv($end - $start, 60));
}

function scheduleEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function scheduleJson($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}
