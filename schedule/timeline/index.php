<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../planner.php';
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    header('Location: /login.php');
    exit;
}
header('Cache-Control: private, no-store');
$day = scheduleDate($_GET['date'] ?? null) ?? new DateTimeImmutable('today');
$date = $day->format('Y-m-d');
$tomorrow = $day->modify('+1 day');
$events = scheduleEvents($db, (int)$user['family_id'], $day, $tomorrow);
$types = scheduleTypes();
$allDay = [];
$blocks = [];
$lanes = [];
$timelineStart = 7 * 60;
$timelineEnd = 20 * 60;
$anchor = $day->getTimestamp();

foreach ($events as $event) {
    $event['time_label'] = scheduleDayTime($event, $day);
    if ($event['all_day']) {
        $allDay[] = $event;
        continue;
    }
    $start = max(0, intdiv(strtotime($event['starts_at']) - $anchor, 60));
    $end = $event['ends_at'] ? min(1440, intdiv(strtotime($event['ends_at']) - $anchor + 59, 60)) : $start;
    $visibleEnd = min(1440, max($start + 25, $end));
    $lane = 0;
    while (isset($lanes[$lane]) && $lanes[$lane] > $start) {
        $lane++;
    }
    $lanes[$lane] = $visibleEnd;
    $blocks[] = ['event' => $event, 'start' => $start, 'end' => $visibleEnd, 'lane' => $lane];
    $timelineStart = min($timelineStart, intdiv($start, 60) * 60);
    $timelineEnd = max($timelineEnd, (int)(ceil($visibleEnd / 60) * 60));
}

$timelineStart = max(0, $timelineStart);
$timelineEnd = min(1440, $timelineEnd);
$timelineMinutes = $timelineEnd - $timelineStart;
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$pageTitle = 'Schedule timeline';
$pageCSS = [
    '/schedule/css/schedule.css?build=' . filemtime(__DIR__ . '/../css/schedule.css'),
    '/schedule/timeline/css/timeline.css?build=' . filemtime(__DIR__ . '/css/timeline.css')
];
$pageJS = ['/schedule/timeline/js/timeline.js'];
$pageJSVersion = filemtime(__DIR__ . '/js/timeline.js');
require_once __DIR__ . '/../../shared/components/header.php';
?>
<main class="planner-main">
    <header class="planner-hero timeline-hero">
        <div>
            <h1>Timeline</h1>
            <p><?= $day->format('l, j F Y') ?></p>
        </div>
    </header>
    <div class="planner-toolbar timeline-toolbar">
        <div class="planner-views" aria-label="Schedule views">
            <a href="/schedule/?date=<?= $date ?>&amp;view=day">Day</a>
            <a href="/schedule/?date=<?= $date ?>&amp;view=week">Week</a>
            <a href="?date=<?= $date ?>" aria-current="page">Timeline</a>
            <a href="/calendar/?date=<?= $date ?>">Calendar</a>
        </div>
        <div class="planner-date-actions">
            <a class="planner-icon-button" href="?date=<?= $day->modify('-1 day')->format('Y-m-d') ?>" aria-label="Previous day">‹</a>
            <a class="planner-control" href="?date=<?= $today ?>">Today</a>
            <a class="planner-icon-button" href="?date=<?= $tomorrow->format('Y-m-d') ?>" aria-label="Next day">›</a>
        </div>
    </div>

    <?php if (!$events): ?>
        <div class="planner-empty"><h2>Nothing scheduled</h2><p>No events on <?= $day->format('l') ?>.</p><a class="btn btn-primary" href="/schedule/?date=<?= $date ?>">Open day agenda</a></div>
    <?php else: ?>
        <div class="timeline-board">
            <section class="timeline-chart planner-panel" aria-labelledby="timelineHeading">
                <h2 id="timelineHeading">Day timeline</h2>
                <?php if ($allDay): ?>
                    <div class="timeline-all-day">
                        <h3>All day</h3>
                        <div>
                            <?php foreach ($allDay as $item): ?>
                                <button type="button" class="planner-control" data-timeline-event="<?= (int)$item['id'] ?>"><?= scheduleEscape($item['title']) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($blocks): ?>
                    <div class="timeline-scroll" tabindex="0" aria-label="Scheduled times">
                        <div class="timeline-scale" style="--timeline-lanes: <?= max(1, count($lanes)) ?>; --timeline-height: <?= intdiv($timelineMinutes * 13, 20) ?>px">
                            <?php for ($minute = $timelineStart; $minute <= $timelineEnd; $minute += 60): ?>
                                <div class="timeline-hour" style="top: <?= (($minute - $timelineStart) / $timelineMinutes) * 100 ?>%">
                                    <span><?= sprintf('%02d:00', intdiv($minute, 60)) ?></span>
                                </div>
                            <?php endfor; ?>
                            <?php foreach ($blocks as ['event' => $item, 'start' => $start, 'end' => $end, 'lane' => $lane]):
                                $color = preg_match('/^#[0-9a-f]{6}$/iD', $item['color'] ?? '') ? $item['color'] : ($types[$item['kind']]['color'] ?? '#327451'); ?>
                                <button type="button" class="timeline-block<?= $item['status'] === 'done' ? ' is-done' : '' ?>"
                                    data-timeline-event="<?= (int)$item['id'] ?>" aria-label="<?= scheduleEscape($item['title'] . ', ' . $item['time_label']) ?>"
                                    style="--top: <?= (($start - $timelineStart) / $timelineMinutes) * 100 ?>%; --height: <?= (($end - $start) / $timelineMinutes) * 100 ?>%; --lane: <?= $lane ?>; --accent: <?= $color ?>">
                                    <span><?= scheduleEscape($item['title']) ?></span>
                                </button>
                            <?php endforeach; ?>
                            <?php if ($date === $today): ?>
                                <div id="timelineNow" class="timeline-now" data-start="<?= $timelineStart ?>" data-end="<?= $timelineEnd ?>" data-date="<?= $date ?>" aria-hidden="true" hidden></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
            <aside class="timeline-details planner-panel">
                <h2>Events</h2>
                <?php foreach ($events as $item): ?>
                    <button type="button" class="timeline-summary" data-timeline-event="<?= (int)$item['id'] ?>">
                        <strong><?= scheduleEscape($item['title']) ?></strong>
                        <span><?= scheduleDayTime($item, $day) ?><?= $item['assigned_to_name'] ? ' · ' . scheduleEscape($item['assigned_to_name']) : '' ?></span>
                    </button>
                <?php endforeach; ?>
            </aside>
        </div>
    <?php endif; ?>
</main>
<dialog id="timelineEventDialog" class="planner-modal" aria-labelledby="timelineEventTitle">
    <header class="modal-header"><h2 id="timelineEventTitle"></h2><button type="button" id="timelineClose" class="modal-close" aria-label="Close">&times;</button></header>
    <div class="modal-body">
        <dl class="timeline-info" id="timelineEventData"></dl>
        <p id="timelineEventNotes" class="timeline-event-notes"></p>
        <footer class="modal-actions">
            <a class="btn btn-secondary" id="timelineCalendarLink" href="/calendar/?date=<?= $date ?>">Open in Calendar</a>
            <a class="btn btn-primary" id="timelineEditLink" href="/schedule/?date=<?= $date ?>">Edit event</a>
        </footer>
    </div>
</dialog>
<script type="application/json" id="timelineData"><?= scheduleJson([
    'events' => array_map(static fn($item) => [
        'id' => (string)$item['id'], 'title' => $item['title'], 'time' => scheduleDayTime($item, $day),
        'kind' => $types[$item['kind']]['name'] ?? 'Other', 'status' => $item['status'],
        'notes' => $item['notes'] ?: $item['description'], 'assigned' => $item['assigned_to_name']
    ], $events),
    'date' => $date
]) ?></script>
<?php require_once __DIR__ . '/../../shared/components/footer.php'; ?>
