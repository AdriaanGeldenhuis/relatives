<?php
declare(strict_types=1);

const SCHEDULE_REPEAT_RULES = ['daily', 'weekdays', 'weekly', 'monthly', 'yearly'];

function schedulePost(string $key, string $fallback = ''): string
{
    $value = $_POST[$key] ?? $fallback;
    if (!is_string($value)) {
        throw new DomainException('Invalid schedule details.');
    }
    return $value;
}

function scheduleNumber(string $key, int $min, int $max): int
{
    $value = schedulePost($key);
    if ($value === '' && $min === 0) {
        return 0;
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($number === false) {
        throw new DomainException('Enter a valid ' . str_replace('_', ' ', $key) . '.');
    }
    return $number;
}

function scheduleEventId(): int
{
    return scheduleNumber('event_id', 1, PHP_INT_MAX);
}

function scheduleEventIds(): array
{
    $ids = json_decode(schedulePost('event_ids', '[]'), true);
    if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 500) {
        throw new DomainException('Select between 1 and 500 events.');
    }
    foreach ($ids as $id) {
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw new DomainException('Invalid event selection.');
        }
    }
    return array_values(array_unique($ids));
}

function scheduleRange(string $defaultStart, string $defaultEnd, int $maxDays): array
{
    $from = scheduleDate($_GET['start_date'] ?? date('Y-m-d', strtotime($defaultStart)));
    $to = scheduleDate($_GET['end_date'] ?? date('Y-m-d', strtotime($defaultEnd)));
    if (!$from || !$to || $to < $from || $from->diff($to)->days >= $maxDays) {
        throw new DomainException('Pick a valid date range.');
    }
    return [$from, $to->modify('+1 day')];
}

function scheduleKind(string $kind): string
{
    if (!isset(scheduleTypes()[$kind])) {
        throw new DomainException('Choose a valid event type.');
    }
    return $kind;
}

function scheduleAssignee(PDO $db, int $familyId, string $value, bool $allowInactive = false): ?int
{
    if ($value === '') {
        return null;
    }
    $member = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $active = $allowInactive ? '' : " AND status = 'active'";
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND family_id = ?" . $active);
    if ($member === false || !$stmt->execute([$member, $familyId]) || !$stmt->fetchColumn()) {
        throw new DomainException('Choose an active member of your family.');
    }
    return $member;
}

function scheduleRule(?array $event): string
{
    return ($event['recurrence_rule'] ?? null) ?: ($event['repeat_rule'] ?? '');
}

function scheduleLockEvent(PDO $db, int $familyId, int $id): array
{
    $stmt = $db->prepare("SELECT * FROM events WHERE family_id = ? AND id = ? AND status != 'cancelled' FOR UPDATE");
    $stmt->execute([$familyId, $id]);
    $event = $stmt->fetch();
    if (!$event) {
        throw new DomainException('This event is no longer available.');
    }
    return $event;
}

function scheduleLockFocusUser(PDO $db, int $userId, int $familyId): void
{
    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND family_id = ? FOR UPDATE');
    $stmt->execute([$userId, $familyId]);
    $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT id FROM events WHERE user_id = ? AND family_id = ? AND status = 'in_progress' AND focus_mode = 1 FOR UPDATE");
    $stmt->execute([$userId, $familyId]);
    if ($stmt->fetchColumn()) {
        throw new DomainException('Finish your current focus session first.');
    }
}

function scheduleFields(PDO $db, int $userId, int $familyId, ?array $original): array
{
    $title = trim(schedulePost('title'));
    $notes = str_replace(["\r\n", "\r"], "\n", schedulePost('notes', $original['notes'] ?? ''));
    if ($title === '' || !preg_match('/^.{1,200}$/usD', $title)) {
        throw new DomainException('Enter a title of up to 200 characters.');
    }
    if (strlen($notes) > 65535 || !preg_match('//u', $notes)) {
        throw new DomainException('Shorten the notes before saving.');
    }
    if (($original['status'] ?? null) === 'in_progress' && $original['focus_mode']) {
        throw new DomainException('Finish the focus session before editing it.');
    }

    $allDay = !empty($_POST['all_day']);
    $focus = !empty($_POST['focus_mode']);
    $duration = scheduleNumber('focus_duration', 0, 120);
    $day = scheduleDate(schedulePost('date', date('Y-m-d')));
    $endDate = scheduleDate(schedulePost('end_date', schedulePost('date', date('Y-m-d'))));
    if ($duration && !$original) {
        if ($duration < 5) {
            throw new DomainException('Choose a focus session of at least five minutes.');
        }
        scheduleLockFocusUser($db, $userId, $familyId);
        $start = new DateTimeImmutable();
        $end = $start->modify("+$duration minutes");
        $allDay = false;
        $focus = true;
    } elseif (!$day || !$endDate || $endDate < $day) {
        throw new DomainException('End date must be on or after the start date.');
    } elseif ($allDay) {
        $start = $day;
        $end = $endDate->modify('+1 day');
        $focus = false;
    } else {
        $pattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';
        $startTime = schedulePost('start_time');
        $endTime = schedulePost('end_time');
        if (!preg_match($pattern, $startTime) || !preg_match($pattern, $endTime)) {
            throw new DomainException('Enter a valid start and end time.');
        }
        $start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $startTime);
        $end = new DateTimeImmutable($endDate->format('Y-m-d') . ' ' . $endTime);
        // A title edit can preserve a legacy point event or a range that spans less than a minute.
        $sameShortRange = $original && $end == $start
            && substr($original['starts_at'], 0, 16) === $start->format('Y-m-d H:i')
            && substr($original['ends_at'] ?: $original['starts_at'], 0, 16) === $end->format('Y-m-d H:i');
        if ($end <= $start && !$sameShortRange) {
            throw new DomainException('End time must be after the start time.');
        }
        if ($original && substr($original['starts_at'], 0, 16) === $start->format('Y-m-d H:i')) {
            $start = new DateTimeImmutable($original['starts_at']);
        }
        if ($sameShortRange) {
            $end = $original['ends_at'] ? new DateTimeImmutable($original['ends_at']) : null;
        } elseif (!empty($original['ends_at']) && substr($original['ends_at'], 0, 16) === $end->format('Y-m-d H:i')) {
            $end = new DateTimeImmutable($original['ends_at']);
        }
        if ($end && $end < $start && !$sameShortRange) {
            throw new DomainException('End time must be after the start time.');
        }
    }

    $inputKind = schedulePost('kind', $original['kind'] ?? 'todo');
    $kind = $inputKind === ($original['kind'] ?? null) ? $inputKind : scheduleKind($inputKind);
    $priority = schedulePost('priority', $original['priority'] ?? 'medium');
    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
        throw new DomainException('Choose a valid priority.');
    }
    $color = schedulePost('color', $original['color'] ?? scheduleTypes()[$kind]['color'] ?? scheduleTypes()['other']['color']);
    if (!preg_match('/^#[0-9a-f]{6}$/iD', $color)) {
        throw new DomainException('Choose a valid color.');
    }
    $oldRule = scheduleRule($original);
    $rule = !empty($original['recurrence_parent_id']) ? $oldRule : schedulePost('repeat_rule');
    if ($rule !== $oldRule && $rule !== '' && !in_array($rule, SCHEDULE_REPEAT_RULES, true)) {
        throw new DomainException('Choose a valid repeat interval.');
    }
    $previousAssignee = (string)($original['assigned_to'] ?? '');
    $inputAssignee = schedulePost('assigned_to', $previousAssignee);
    $assignee = scheduleAssignee($db, $familyId, $inputAssignee, $inputAssignee === $previousAssignee);
    $responsible = $assignee ?: (int)(($original['created_by'] ?? null) ?: ($original['user_id'] ?? $userId));
    return [
        'user_id' => $responsible,
        'assigned_to' => $assignee,
        'title' => $title,
        'kind' => $kind,
        'notes' => $notes === '' ? null : $notes,
        'starts_at' => $start->format('Y-m-d H:i:s'),
        'ends_at' => $end?->format('Y-m-d H:i:s'),
        'all_day' => (int)$allDay,
        'color' => $color,
        'priority' => $priority,
        'reminder_minutes' => scheduleNumber('reminder_minutes', 0, 1440) ?: null,
        'recurrence_rule' => $rule ?: null,
        'focus_mode' => (int)$focus,
        'actual_start' => $duration && !$original ? $start->format('Y-m-d H:i:s') : null
    ];
}

function scheduleOccurrence(DateTimeImmutable $start, string $rule, int $index): DateTimeImmutable
{
    if ($rule === 'monthly' || $rule === 'yearly') {
        $interval = $rule === 'monthly' ? "$index months" : "$index years";
        $month = $start->modify('first day of this month')->modify("+$interval");
        return $month->setDate((int)$month->format('Y'), (int)$month->format('n'), min((int)$start->format('j'), (int)$month->format('t')));
    }
    if ($rule === 'weekdays') {
        return $start->modify("+$index weekdays");
    }
    return $start->modify($rule === 'weekly' ? "+$index weeks" : "+$index days");
}

function scheduleWriteEvent(PDO $db, int $familyId, int $userId, array $event, ?array $original): int
{
    $actualStart = $event['actual_start'];
    unset($event['actual_start']);
    $insertFields = ['family_id' => $familyId, 'created_by' => $userId] + $event + [
        'status' => 'pending', 'actual_start' => null, 'recurrence_parent_id' => null
    ];
    $columns = implode(', ', array_keys($insertFields));
    $placeholders = implode(',', array_fill(0, count($insertFields), '?'));
    $insert = $db->prepare("INSERT INTO events ($columns) VALUES ($placeholders)");

    if (!$original) {
        $insertFields['actual_start'] = $actualStart;
        $insertFields['status'] = $actualStart ? 'in_progress' : 'pending';
        $insert->execute(array_values($insertFields));
        $id = (int)$db->lastInsertId();
    } else {
        $id = (int)$original['id'];
        $hasSent = (bool)$db->query("SHOW COLUMNS FROM events LIKE 'reminder_sent_at'")->fetch();
        scheduleUpdateRow($db, $familyId, $id, $event, $original, $hasSent);
    }

    if (!$original || !$original['recurrence_parent_id']) {
        $oldRule = scheduleRule($original);
        $rule = $event['recurrence_rule'];
        if (in_array($oldRule, SCHEDULE_REPEAT_RULES, true) || in_array($rule, SCHEDULE_REPEAT_RULES, true)) {
            $stmt = $db->prepare('SELECT * FROM events WHERE family_id = ? AND recurrence_parent_id = ? ORDER BY starts_at, id FOR UPDATE');
            $stmt->execute([$familyId, $id]);
            $children = $stmt->fetchAll();
            $start = new DateTimeImmutable($event['starts_at']);
            $seconds = $event['ends_at'] ? strtotime($event['ends_at']) - $start->getTimestamp() : 0;
            $now = date('Y-m-d H:i:s');
            if ($rule === $oldRule && $children) {
                $oldStart = new DateTimeImmutable($original['starts_at']);
                $intervals = [];
                for ($index = 1, $limit = $rule === 'yearly' ? 5 : 10; $index <= $limit; $index++) {
                    $intervals[scheduleOccurrence($oldStart, $rule, $index)->format('Y-m-d H:i:s')] = $index;
                }
                foreach ($children as $child) {
                    if ($child['status'] !== 'pending' || $child['starts_at'] < $now || scheduleRule($child) !== $rule) {
                        continue;
                    }
                    $changes = array_diff_assoc($event, $original);
                    $index = $intervals[$child['starts_at']] ?? null;
                    if ($index && (isset($changes['starts_at']) || array_key_exists('ends_at', $changes))) {
                        $slot = scheduleOccurrence($start, $rule, $index);
                        $changes['starts_at'] = $slot->format('Y-m-d H:i:s');
                        $changes['ends_at'] = $event['ends_at'] ? $slot->modify("+$seconds seconds")->format('Y-m-d H:i:s') : null;
                    } else {
                        unset($changes['starts_at'], $changes['ends_at']);
                    }
                    if ($changes) {
                        scheduleUpdateRow($db, $familyId, (int)$child['id'], $changes, $child, $hasSent);
                    }
                }
            } else {
                $keep = [];
                foreach ($children as $child) {
                    if ($child['status'] === 'pending' && $child['starts_at'] >= $now) {
                        $db->prepare("UPDATE events SET status = 'cancelled' WHERE family_id = ? AND id = ?")
                            ->execute([$familyId, $child['id']]);
                    } elseif ($child['status'] === 'done' || $child['status'] === 'in_progress') {
                        $keep[substr($child['starts_at'], 0, 10)] = true;
                    }
                }
                if (in_array($rule, SCHEDULE_REPEAT_RULES, true)) {
                    $insertFields['recurrence_parent_id'] = $id;
                    $insertFields['status'] = 'pending';
                    $insertFields['actual_start'] = null;
                    for ($index = 1, $limit = $rule === 'yearly' ? 5 : 10; $index <= $limit; $index++) {
                        $slot = scheduleOccurrence($start, $rule, $index);
                        if (isset($keep[$slot->format('Y-m-d')]) || ($original && $slot->format('Y-m-d H:i:s') < $now)) {
                            continue;
                        }
                        $insertFields['starts_at'] = $slot->format('Y-m-d H:i:s');
                        $insertFields['ends_at'] = $event['ends_at'] ? $slot->modify("+$seconds seconds")->format('Y-m-d H:i:s') : null;
                        $insert->execute(array_values($insertFields));
                    }
                }
            }
        }
    }
    return $id;
}

function scheduleUpdateRow(PDO $db, int $familyId, int $id, array $event, array $original, bool $hasSent): void
{
    $set = implode(', ', array_map(static fn($field) => "$field = ?", array_keys($event)));
    $changedReminder = false;
    foreach (['starts_at', 'reminder_minutes', 'assigned_to'] as $key) {
        if (array_key_exists($key, $event) && (string)$event[$key] !== (string)$original[$key]) {
            $changedReminder = true;
        }
    }
    $reset = $hasSent && $changedReminder ? ', reminder_sent_at = NULL' : '';
    $db->prepare("UPDATE events SET $set, repeat_rule = NULL $reset WHERE id = ? AND family_id = ?")
        ->execute([...array_values($event), $id, $familyId]);
}
