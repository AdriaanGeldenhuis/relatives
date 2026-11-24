<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/EventNoteLinks.php';
require_once __DIR__ . '/../schedule/planner.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    header('Location: /login.php');
    exit;
}
header('Cache-Control: private, no-store');

$familyId = (int)$user['family_id'];
$today = new DateTimeImmutable('today');
$day = scheduleDate($_GET['date'] ?? null);
if (!$day && isset($_GET['month'], $_GET['year']) && is_scalar($_GET['month']) && is_scalar($_GET['year'])) {
    $day = scheduleDate(sprintf('%04d-%02d-01', (int)$_GET['year'], (int)$_GET['month']));
}
$selectedDay = $day ?? $today;
$view = $_GET['view'] ?? null;
$view = in_array($view, ['month', 'week', 'day'], true) ? $view : 'month';
$selectedEvent = eventNoteId($_GET['event'] ?? null);
$notice = '';
if (isset($_GET['event'])) {
    $event = $selectedEvent ? eventNotePreview($db, $familyId, $selectedEvent) : null;
    if ($event) {
        if (!$day || !scheduleOnDay([$event], $day)) {
            $selectedDay = scheduleDate(substr($event['starts_at'], 0, 10)) ?? $today;
        }
    } else {
        $notice = 'This event is no longer available.';
        $selectedEvent = null;
    }
}

$selectedDate = $selectedDay->format('Y-m-d');
if ($view === 'week') {
    $firstDay = $selectedDay->modify('monday this week');
    $end = $firstDay->modify('+7 days');
} elseif ($view === 'day') {
    $firstDay = $selectedDay;
    $end = $selectedDay->modify('+1 day');
} else {
    $firstDay = $selectedDay->modify('first day of this month')->modify('monday this week');
    $end = $selectedDay->modify('last day of this month')->modify('sunday this week')->modify('+1 day');
}

$events = scheduleEvents($db, $familyId, $firstDay, max($end, $selectedDay->modify('+8 days')));
foreach ($events as &$event) {
    $event['version'] = scheduleEventVersion($event);
}
unset($event);
$data = [
    'date' => $selectedDate,
    'first' => $firstDay->format('Y-m-d'),
    'end' => $end->format('Y-m-d'),
    'view' => $view,
    'events' => $events,
    'selectedEvent' => $selectedEvent ? (string)$selectedEvent : null,
    'notice' => $notice
];
if (($_GET['ajax'] ?? null) === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo scheduleJson($data);
    exit;
}

$stmt = $db->prepare("SELECT id, full_name FROM users WHERE family_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$familyId]);
$familyMembers = $stmt->fetchAll();
$types = scheduleTypes();
$prefill = null;
if (($_GET['new'] ?? null) === '1') {
    if (isset($_GET['note'])) {
        $noteId = eventNoteId($_GET['note']);
        $note = $noteId ? eventNoteFind($db, $familyId, $noteId) : null;
        if ($note) {
            $prefill = ['note' => ['id' => (string)$note['id'], 'title' => eventNoteLabel($note)]];
        } else {
            $data['notice'] = 'This note is no longer available.';
        }
    } else {
        $prefill = ['title' => is_string($_GET['content'] ?? null) ? $_GET['content'] : ''];
    }
}
$plannerStyles = __DIR__ . '/../schedule/css/schedule.css';
$pageTitle = 'Calendar';
$pageCSS = [
    '/schedule/css/schedule.css?build=' . filemtime($plannerStyles),
    '/calendar/css/calendar.css?build=' . filemtime(__DIR__ . '/css/calendar.css')
];
$pageJS = ['/shared/js/event-editor.js', '/calendar/js/calendar.js'];
$pageJSVersion = max(filemtime(__DIR__ . '/js/calendar.js'), filemtime(__DIR__ . '/../shared/js/event-editor.js'));

require_once __DIR__ . '/../shared/components/header.php';
?>
<main class="planner-main calendar-main">
    <header class="planner-hero calendar-hero">
        <div>
            <h1>Family calendar</h1>
            <p>Birthdays, appointments and plans for the whole family.</p>
            <div class="planner-hero-actions">
                <button type="button" class="btn btn-primary" data-calendar-add><span aria-hidden="true">＋</span> Add event</button>
                <a class="planner-hero-button" id="calendarScheduleLink" href="/schedule/?date=<?= $selectedDate ?>">Open Schedule</a>
            </div>
        </div>
        <div class="calendar-art" aria-hidden="true">
            <div class="calendar-art-paper">
                <span></span><span></span><span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
    </header>

    <div class="planner-toolbar calendar-toolbar">
        <div class="planner-views" aria-label="Calendar views">
            <a href="?date=<?= $selectedDate ?>&amp;view=month" data-calendar-view="month">Month</a>
            <a href="?date=<?= $selectedDate ?>&amp;view=week" data-calendar-view="week">Week</a>
            <a href="?date=<?= $selectedDate ?>&amp;view=day" data-calendar-view="day">Day</a>
        </div>
        <div class="planner-date-actions">
            <button type="button" class="planner-icon-button" id="calendarPrevious" aria-label="Previous month">‹</button>
            <button type="button" class="planner-control" id="calendarToday">Today</button>
            <button type="button" class="planner-icon-button" id="calendarNext" aria-label="Next month">›</button>
            <button type="button" class="planner-control" id="calendarPick">Pick date</button>
        </div>
    </div>

    <header class="calendar-heading">
        <h2 id="calendarPeriod" tabindex="-1"><?= $selectedDay->format('F Y') ?></h2>
        <div class="calendar-filters">
            <label><span class="planner-sr-only">Search calendar</span><input type="search" id="calendarSearch" placeholder="Search calendar…" autocomplete="off"></label>
            <label><span class="planner-sr-only">Filter by event type</span>
                <select id="calendarKind"><option value="">All types</option>
                    <?php foreach ($types as $kind => $type): ?><option value="<?= $kind ?>"><?= $type['name'] ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span class="planner-sr-only">Filter by assignee</span>
                <select id="calendarAssignee"><option value="">Everyone</option><option value="unassigned">Unassigned</option>
                    <?php foreach ($familyMembers as $member): ?><option value="<?= (int)$member['id'] ?>"><?= scheduleEscape($member['full_name']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
    </header>

    <div id="calendarContent"></div>
    <p id="calendarNotice" class="calendar-notice" role="status" <?= $data['notice'] === '' ? 'hidden' : '' ?>><?= scheduleEscape($data['notice']) ?></p>
    <noscript><p>Enable JavaScript to use the calendar.</p></noscript>
</main>

<?php require __DIR__ . '/../shared/components/event-editor.php'; ?>

<dialog id="calendarDateDialog" class="planner-modal" aria-labelledby="calendarDateHeading">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2 id="calendarDateHeading">Pick a date</h2><button type="button" class="modal-close" aria-label="Close" data-calendar-close>&times;</button></header>
        <form id="calendarDateForm" class="modal-body">
            <div class="form-group"><label for="calendarDateInput">Date</label><input id="calendarDateInput" type="date" class="form-control" required></div>
            <footer class="modal-actions"><button type="submit" class="btn btn-primary">Go to date</button></footer>
        </form>
    </div>
</dialog>

<dialog id="calendarEventDialog" class="planner-modal" aria-labelledby="calendarEventTitle">
    <div class="modal-content">
        <header class="modal-header"><h2 id="calendarEventTitle"></h2><button type="button" class="modal-close" aria-label="Close" data-calendar-close>&times;</button></header>
        <div class="modal-body">
            <dl class="calendar-event-details" id="calendarEventDetails"></dl>
            <p id="calendarEventNotes" class="calendar-event-notes" hidden></p>
            <section class="calendar-related" aria-labelledby="calendarRelatedHeading">
                <h3 id="calendarRelatedHeading">Linked notes</h3>
                <div id="calendarNoteLinks"></div>
                <div class="calendar-related-actions">
                    <a class="planner-control" id="calendarNewNote">New linked note</a>
                    <form id="calendarLinkNoteForm" hidden>
                        <label><span class="planner-sr-only">Choose a note</span><select id="calendarNoteChoice" class="form-control" required></select></label>
                        <button type="submit" class="planner-control">Link note</button>
                    </form>
                </div>
            </section>
            <p id="calendarEventNotice" class="planner-form-hint" role="status" hidden></p>
            <footer class="modal-actions calendar-event-actions">
                <button type="button" class="btn btn-secondary" data-event-action="delete">Delete</button>
                <button type="button" class="btn btn-secondary" data-event-action="copy">Copy link</button>
                <a class="btn btn-secondary" id="calendarEventSchedule">Open in Schedule</a>
                <button type="button" class="btn btn-secondary" id="calendarEventComplete" data-event-action="complete">Complete</button>
                <button type="button" class="btn btn-primary" data-event-action="edit">Edit event</button>
            </footer>
        </div>
    </div>
</dialog>

<script type="application/json" id="calendarData"><?= scheduleJson($data + [
    'types' => $types, 'prefill' => $prefill, 'csrf' => CSRF::generate()
]) ?></script>
<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
