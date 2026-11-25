<?php
declare(strict_types=1);

function calendarUrl(string $date, string $view, array $filters = [], ?int $event = null): string
{
    $params = ['date' => $date, 'view' => $view] + $filters;
    if ($event) $params['event'] = $event;
    return '/calendar/?' . http_build_query($params);
}

function calendarStep(DateTimeImmutable $day, string $view, int $direction): string
{
    if ($view === 'month') {
        $month = $day->modify('first day of this month')->modify(($direction > 0 ? '+' : '-') . '1 month');
        return $month->modify('+' . (min((int)$month->format('t'), (int)$day->format('j')) - 1) . ' days')->format('Y-m-d');
    }
    return $day->modify(($direction * ($view === 'week' ? 7 : 1)) . ' days')->format('Y-m-d');
}

function calendarFilter(array $events, array $filters, array $types): array
{
    $lower = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $words = preg_split('/\s+/u', $lower($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($events, static function (array $event) use ($filters, $types, $words, $lower): bool {
        if (isset($filters['kind']) && $filters['kind'] !== $event['kind']) return false;
        $assignee = $filters['assignee'] ?? '';
        if ($assignee !== '' && ($assignee === 'unassigned' ? $event['assigned_to'] : (string)$event['assigned_to'] !== $assignee)) return false;
        if (!$words) return true;
        $text = $lower(implode(' ', [$event['title'], $event['notes'] ?? $event['description'], $event['location'],
            $event['assigned_to_name'], $types[$event['kind']]['name'] ?? 'Other']));
        foreach ($words as $word) {
            if (!str_contains($text, $word)) return false;
        }
        return true;
    }));
}

function calendarEventColor(array $event, array $types): string
{
    return preg_match('/^#[a-f0-9]{6}$/iD', $event['color'] ?? '') ? $event['color'] : ($types[$event['kind']]['color'] ?? '#52654c');
}

function calendarEntry(array $event, DateTimeImmutable $day, string $view, array $filters, array $types, bool $mini = false): void
{
    $date = $day->format('Y-m-d');
    $time = scheduleDayTime($event, $day);
    $status = ['done' => 'Completed', 'in_progress' => 'In progress'][$event['status']] ?? '';
    $detail = array_filter([$time, $types[$event['kind']]['name'] ?? 'Other', $event['assigned_to_name'], $event['location'], $status]);
    ?>
    <a role="button" class="<?= $mini ? 'calendar-mini-event' : 'calendar-agenda-event' ?><?= $mini && $status === 'Completed' ? ' is-done' : '' ?>"
       href="<?= scheduleEscape(calendarUrl($date, $view, $filters, (int)$event['id'])) ?>"
       data-cal-event="<?= (int)$event['id'] ?>" data-cal-date="<?= $date ?>" style="--event-color: <?= calendarEventColor($event, $types) ?>">
        <?php if ($mini): ?><span class="calendar-mini-time"><?= explode('–', $time)[0] ?></span><span><?= scheduleEscape($event['title']) ?></span>
        <?php else: ?><strong><?= scheduleEscape($event['title']) ?></strong><span><?= scheduleEscape(implode(' · ', $detail)) ?></span><?php endif; ?>
    </a>
    <?php
}

function calendarRender(array $data, array $types, array $filters, DateTimeImmutable $today): void
{
    $view = $data['view'];
    $day = new DateTimeImmutable($data['date']);
    $first = new DateTimeImmutable($data['first']);
    $end = new DateTimeImmutable($data['end']);
    $events = calendarFilter($data['events'], $filters, $types);
    $hasFilters = (bool)$filters;
    if ($view === 'week'): ?>
        <div class="calendar-week">
            <?php for ($date = $first; $date < $end; $date = $date->modify('+1 day')):
                $daily = scheduleOnDay($events, $date); ?>
                <section class="calendar-week-day<?= $date == $today ? ' is-today' : '' ?>">
                    <h3><a href="<?= scheduleEscape(calendarUrl($date->format('Y-m-d'), 'day', $filters)) ?>" data-week-date="<?= $date->format('Y-m-d') ?>"><?= $date->format('l, j F Y') ?></a></h3>
                    <div class="calendar-day-list">
                        <?php foreach ($daily as $event) calendarEntry($event, $date, $view, $filters, $types); ?>
                        <?php if (!$daily): ?><p class="calendar-empty">No events</p><?php endif; ?>
                    </div>
                </section>
            <?php endfor; ?>
        </div>
    <?php return; endif; ?>
    <div class="<?= $view === 'month' ? 'calendar-layout' : 'calendar-single-day' ?>">
        <?php if ($view === 'month'): ?>
            <section class="calendar-month" aria-label="Month grid">
                <div class="calendar-weekdays">
                    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?><span><?= $weekday ?></span><?php endforeach; ?>
                </div>
                <div class="calendar-grid">
                    <?php for ($date = $first; $date < $end; $date = $date->modify('+1 day')):
                        $daily = scheduleOnDay($events, $date);
                        $count = count($daily);
                        $key = $date->format('Y-m-d');
                        $selected = $date == $day;
                        $url = scheduleEscape(calendarUrl($key, 'month', $filters)); ?>
                        <div class="calendar-cell<?= $selected ? ' is-selected' : '' ?><?= $date->format('m') !== $day->format('m') ? ' is-outside' : '' ?>">
                            <a role="button" class="calendar-cell-date<?= $date == $today ? ' is-today' : '' ?>" href="<?= $url ?>"
                               data-select-date="<?= $key ?>" aria-label="<?= $date->format('l, j F Y') ?>, <?= $count ?> <?= $count === 1 ? 'event' : 'events' ?>"
                               aria-pressed="<?= $selected ? 'true' : 'false' ?>"<?= $date == $today ? ' aria-current="date"' : '' ?>>
                                <?= $date->format('j') ?><span class="calendar-day-count" aria-hidden="true"><?= $count ?: '' ?></span>
                            </a>
                            <div class="calendar-cell-events">
                                <?php foreach (array_slice($daily, 0, 3) as $event) calendarEntry($event, $date, $view, $filters, $types, true); ?>
                                <?php if ($count > 3): ?><a class="calendar-more" href="<?= $url ?>" data-select-date="<?= $key ?>">+<?= $count - 3 ?> more</a><?php endif; ?>
                            </div>
                            <span class="calendar-dots" aria-hidden="true">
                                <?php foreach (array_slice($daily, 0, 3) as $event): ?><i style="background: <?= calendarEventColor($event, $types) ?>"></i><?php endforeach; ?>
                            </span>
                        </div>
                    <?php endfor; ?>
                </div>
            </section>
        <?php endif; ?>
        <aside class="calendar-side" aria-label="Day agenda">
            <section class="calendar-day-panel">
                <header><h3><?= $day->format('l, j F Y') ?></h3><button type="button" class="btn btn-primary" data-calendar-add aria-haspopup="dialog" aria-controls="eventEditor">Add event</button></header>
                <div class="calendar-day-list">
                    <?php $daily = scheduleOnDay($events, $day); ?>
                    <?php foreach ($daily as $event) calendarEntry($event, $day, $view, $filters, $types); ?>
                    <?php if (!$daily): ?><p class="calendar-empty"><?= $hasFilters ? 'No matching events for this day.' : 'Nothing scheduled for this day.' ?></p><?php endif; ?>
                </div>
            </section>
            <?php
            $limit = $day->modify('+8 days')->format('Y-m-d');
            $upcoming = array_filter($events, static fn($event) => substr($event['starts_at'], 0, 10) > $data['date'] &&
                substr($event['starts_at'], 0, 10) < $limit && $event['status'] !== 'done');
            usort($upcoming, static fn($a, $b) => $a['starts_at'] <=> $b['starts_at']);
            if ($upcoming): ?>
                <section class="calendar-day-panel">
                    <header><h3>Next 7 days</h3></header>
                    <div class="calendar-day-list">
                        <?php foreach (array_slice($upcoming, 0, 5) as $event):
                            $date = new DateTimeImmutable(substr($event['starts_at'], 0, 10)); ?>
                            <div class="calendar-upcoming"><span><?= $date->format('j M') ?></span><?php calendarEntry($event, $date, $view, $filters, $types); ?></div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
    <?php
}
