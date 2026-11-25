<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';
require_once __DIR__ . '/../schedule/planner.php';

$ajax = ($_GET['ajax'] ?? null) === '1';
header('Cache-Control: private, no-store');

$user = null;
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/bootstrap.php';
    $user = (new Auth($db))->getCurrentUser();
}

if (!$user) {
    unset($_SESSION['user_id']);
    if ($ajax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo scheduleJson(['error' => 'Sign in again to use Calendar.']);
    } else {
        header('Location: /login.php?redirect=%2Fcalendar%2F');
    }
    exit;
}

require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/EventNoteLinks.php';
require_once __DIR__ . '/view.php';
$csrfToken = CSRF::generate();
session_write_close();

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
$openedEvent = null;
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

$queryEnd = $view === 'week' ? $end : max($end, $selectedDay->modify('+8 days'));
$events = scheduleEvents($db, $familyId, $firstDay, $queryEnd);
foreach ($events as &$event) {
    $event['version'] = scheduleEventVersion($event);
    if ((int)$event['id'] === $selectedEvent) $openedEvent = $event;
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
$types = scheduleTypes();
$filters = [];
foreach (['q', 'kind', 'assignee'] as $name) {
    $filters[$name] = is_string($_GET[$name] ?? null) ? mb_substr(trim($_GET[$name]), 0, 200) : '';
}
$filters = array_filter($filters, static fn(string $value): bool => $value !== '');
$data['filters'] = $filters;
if ($ajax) {
    ob_start();
    calendarRender($data, $types, $filters, $today);
    $data['html'] = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo scheduleJson($data);
    exit;
}

$familyMembers = scheduleFamilyMembers($db, $familyId);
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
    <header class="planner-hero calendar-hero app-hero">
        <div>
            <h1>Family calendar</h1>
            <p>Birthdays, appointments and plans for the whole family.</p>
            <div class="planner-hero-actions">
                <button type="button" class="btn btn-primary" data-calendar-add aria-haspopup="dialog" aria-controls="eventEditor"><span aria-hidden="true">＋</span> Add event</button>
                <a class="planner-hero-button" id="calendarScheduleLink" href="/schedule/?date=<?= $selectedDate ?>">Open Schedule</a>
            </div>
        </div>
        <?php renderFamilyAppArt('calendar', true); ?>
    </header>

    <div class="planner-toolbar calendar-toolbar">
        <div class="planner-views" aria-label="Calendar views">
            <a href="<?= scheduleEscape(calendarUrl($selectedDate, 'month', $filters)) ?>" data-calendar-view="month"<?= $view === 'month' ? ' aria-current="page"' : '' ?>>Month</a>
            <a href="<?= scheduleEscape(calendarUrl($selectedDate, 'week', $filters)) ?>" data-calendar-view="week"<?= $view === 'week' ? ' aria-current="page"' : '' ?>>Week</a>
            <a href="<?= scheduleEscape(calendarUrl($selectedDate, 'day', $filters)) ?>" data-calendar-view="day"<?= $view === 'day' ? ' aria-current="page"' : '' ?>>Day</a>
        </div>
        <form class="planner-date-actions" action="/calendar/">
            <input type="hidden" name="view" value="<?= $view ?>">
            <?php foreach ($filters as $name => $value): ?><input type="hidden" name="<?= $name ?>" value="<?= scheduleEscape($value) ?>" data-date-filter><?php endforeach; ?>
            <button type="submit" name="date" value="<?= calendarStep($selectedDay, $view, -1) ?>" class="planner-icon-button" id="calendarPrevious" aria-label="Previous <?= $view ?>">‹</button>
            <button type="submit" name="date" value="<?= $today->format('Y-m-d') ?>" class="planner-control" id="calendarToday">Today</button>
            <button type="submit" name="date" value="<?= calendarStep($selectedDay, $view, 1) ?>" class="planner-icon-button" id="calendarNext" aria-label="Next <?= $view ?>">›</button>
            <button type="button" class="planner-control" id="calendarPick">Pick date</button>
        </form>
    </div>

    <header class="calendar-heading">
        <h2 id="calendarPeriod" tabindex="-1"><?php
            if ($view === 'day') echo $selectedDay->format('l, j F Y');
            elseif ($view === 'week') echo $firstDay->format('j M') . ' – ' . $end->modify('-1 day')->format('j M');
            else echo $selectedDay->format('F Y');
        ?></h2>
        <form class="calendar-filters" id="calendarFilters" action="/calendar/">
            <input type="hidden" name="date" value="<?= $selectedDate ?>">
            <input type="hidden" name="view" value="<?= $view ?>">
            <label><span class="planner-sr-only">Search calendar</span><input type="search" name="q" id="calendarSearch" value="<?= scheduleEscape($filters['q'] ?? '') ?>" placeholder="Search calendar…" maxlength="200" autocomplete="off"></label>
            <label><span class="planner-sr-only">Filter by event type</span>
                <select name="kind" id="calendarKind"><option value="">All types</option>
                    <?php foreach ($types as $kind => $type): ?><option value="<?= $kind ?>"<?= $kind === ($filters['kind'] ?? '') ? ' selected' : '' ?>><?= $type['name'] ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span class="planner-sr-only">Filter by assignee</span>
                <select name="assignee" id="calendarAssignee"><option value="">Everyone</option><option value="unassigned"<?= ($filters['assignee'] ?? '') === 'unassigned' ? ' selected' : '' ?>>Unassigned</option>
                    <?php foreach ($familyMembers as $member): ?><option value="<?= (int)$member['id'] ?>"<?= (string)$member['id'] === ($filters['assignee'] ?? '') ? ' selected' : '' ?>><?= scheduleEscape($member['full_name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="planner-control calendar-apply-filters">Apply filters</button>
        </form>
    </header>

    <?php if ($openedEvent): ?>
        <section id="calendarEventFallback" class="calendar-day-panel calendar-event-fallback">
            <header><h3><?= scheduleEscape($openedEvent['title']) ?></h3>
                <a class="planner-control" href="/schedule/?date=<?= $selectedDate ?>&amp;event=<?= $selectedEvent ?>">Open in Schedule</a>
            </header>
            <p><?= $selectedDay->format('l, j F Y') ?> · <?= scheduleDayTime($openedEvent, $selectedDay) ?></p>
            <?php if ($openedEvent['location']): ?><p><?= scheduleEscape($openedEvent['location']) ?></p><?php endif; ?>
            <?php $details = $openedEvent['notes'] ?? $openedEvent['description'] ?? ''; ?>
            <?php if ($details !== ''): ?>
                <p class="calendar-event-notes"><?= scheduleEscape($details) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <div id="calendarContent"><?php calendarRender($data, $types, $filters, $today); ?></div>
    <p id="calendarNotice" class="calendar-notice" role="status" <?= $data['notice'] === '' ? 'hidden' : '' ?>><?= scheduleEscape($data['notice']) ?></p>
    <noscript><p>Enable JavaScript to edit events.</p></noscript>
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
    'types' => $types, 'prefill' => $prefill, 'csrf' => $csrfToken
]) ?></script>
<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
