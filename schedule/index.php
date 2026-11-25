<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';
require_once __DIR__ . '/planner.php';

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
        echo scheduleJson(['error' => 'Sign in again to use Schedule.']);
    } else {
        header('Location: /login.php?redirect=%2Fschedule%2F');
    }
    exit;
}

require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/EventNoteLinks.php';
$csrfToken = CSRF::generate();
session_write_close();

$familyId = (int)$user['family_id'];
$day = scheduleDate($_GET['date'] ?? null) ?? new DateTimeImmutable('today');
$viewMode = ($_GET['view'] ?? null) === 'week' ? 'week' : 'day';
$selectedEvent = null;
$notice = '';
if (isset($_GET['event'])) {
    $id = eventNoteId($_GET['event']);
    $target = $id ? eventNotePreview($db, $familyId, $id) : null;
    if ($target) {
        $selectedEvent = (string)$id;
        $viewMode = 'day';
        if (!scheduleOnDay([$target], $day)) {
            $day = scheduleDate(substr($target['starts_at'], 0, 10)) ?? $day;
        }
    } else {
        $notice = 'This event is no longer available.';
    }
}
$selectedDate = $day->format('Y-m-d');
$types = scheduleTypes();

$familyMembers = scheduleFamilyMembers($db, $familyId);
$weekStart = $day->modify('monday this week');
$weekEvents = scheduleEvents($db, $familyId, $weekStart, $weekStart->modify('+7 days'));
$events = scheduleOnDay($weekEvents, $day);
foreach ($events as &$event) {
    $event['version'] = scheduleEventVersion($event);
}
unset($event);

$activeFocusSession = scheduleActiveFocus($db, $familyId, (int)$user['id']);
if ($ajax) {
    ob_start();
    require __DIR__ . '/agenda.php';
    $html = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo scheduleJson(['html' => $html, 'events' => $events, 'date' => $selectedDate, 'view' => $viewMode,
        'activeFocusSession' => $activeFocusSession, 'selectedEvent' => $selectedEvent, 'notice' => $notice]);
    exit;
}

$pageTitle = 'Schedule';
$pageCSS = ['/schedule/css/schedule.css?build=' . filemtime(__DIR__ . '/css/schedule.css')];
$pageJS = [
    '/shared/js/event-editor.js',
    '/schedule/js/schedule.js'
];
$pageJSVersion = max(filemtime(__DIR__ . '/js/schedule.js'), filemtime(__DIR__ . '/../shared/js/event-editor.js'));

require_once __DIR__ . '/../shared/components/header.php';
?>
<main class="planner-main">
    <header class="planner-hero app-hero">
        <div>
            <h1>Family schedule</h1>
            <p>See what’s coming up and make time for what matters.</p>
            <div class="planner-hero-actions">
                <button type="button" class="btn btn-primary" data-schedule-add aria-haspopup="dialog" aria-controls="eventEditor"><span aria-hidden="true">＋</span> Add event</button>
                <button type="button" class="planner-hero-button" onclick="startFocusMode()" id="scheduleFocusButton"><?= $activeFocusSession ? 'Resume focus' : 'Start focus' ?></button>
                <details class="planner-tools">
                    <summary class="planner-hero-button">Tools</summary>
                    <div>
                        <button type="button" onclick="showTemplates()">Templates</button>
                        <button type="button" onclick="showAnalytics()">Analytics</button>
                        <button type="button" onclick="exportSchedule()">Export day</button>
                    </div>
                </details>
            </div>
        </div>
        <?php renderFamilyAppArt('schedule', true); ?>
    </header>
    <?php require __DIR__ . '/agenda.php'; ?>
</main>

<?php require __DIR__ . '/../shared/components/event-editor.php'; ?>

<dialog id="datePickerModal" class="planner-modal" aria-labelledby="datePickerHeading">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2 id="datePickerHeading">Pick a date</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('datePickerModal')">&times;</button></header>
        <form class="modal-body" onsubmit="goToPickedDate(event)">
            <div class="form-group"><label for="datePickerInput">Date</label><input id="datePickerInput" type="date" class="form-control" required></div>
            <footer class="modal-actions"><button type="submit" class="btn btn-primary">Go to date</button></footer>
        </form>
    </div>
</dialog>

<dialog id="focusSessionModal" class="planner-modal" aria-labelledby="focusSessionHeading">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2 id="focusSessionHeading">Start focus</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('focusSessionModal')">&times;</button></header>
        <form class="modal-body" onsubmit="createFocusSession(event)">
            <div class="form-group"><label for="focusTitle">What will you focus on?</label><input id="focusTitle" class="form-control" maxlength="200" required></div>
            <div class="form-group"><label for="focusDuration">Duration</label>
                <select id="focusDuration" class="form-control">
                    <option value="25">25 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option><option value="90">90 minutes</option><option value="120">120 minutes</option>
                </select>
            </div>
            <footer class="modal-actions"><button type="submit" class="btn btn-primary">Start now</button></footer>
        </form>
    </div>
</dialog>

<dialog id="focusTimerModal" class="planner-modal" aria-labelledby="focusTimerHeading">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2 id="focusTimerHeading">Focus timer</h2><button type="button" class="modal-close" aria-label="Hide timer" onclick="closeModal('focusTimerModal')">&times;</button></header>
        <div class="modal-body planner-focus">
            <p id="focusTask"></p><output id="focusTimer" aria-label="Time remaining"></output>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="focusPauseButton" onclick="toggleFocusPause()">Pause</button>
                <button type="button" class="btn btn-primary" onclick="finishFocus()">Finish</button>
            </div>
        </div>
    </div>
</dialog>

<dialog id="ratingModal" class="planner-modal" aria-labelledby="ratingHeading">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2 id="ratingHeading">Finish focus</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('ratingModal')">&times;</button></header>
        <div class="modal-body">
            <p>How productive was this session?</p>
            <div class="rating-stars">
                <?php for ($rating = 1; $rating <= 5; $rating++): ?><button type="button" onclick="selectRating(<?= $rating ?>)" class="star-btn" aria-label="Rate <?= $rating ?> out of 5">★</button><?php endfor; ?>
            </div>
            <footer class="modal-actions"><button type="button" class="btn btn-secondary" onclick="selectRating(0)">Finish without rating</button></footer>
        </div>
    </div>
</dialog>

<dialog id="analyticsModal" class="planner-modal">
    <div class="modal-content modal-large">
        <header class="modal-header"><h2>Productivity analytics</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('analyticsModal')">&times;</button></header>
        <div class="modal-body" id="analyticsContent" aria-live="polite"></div>
    </div>
</dialog>

<dialog id="templatesModal" class="planner-modal">
    <div class="modal-content">
        <header class="modal-header"><h2>Schedule templates</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('templatesModal')">&times;</button></header>
        <div class="modal-body" id="templatesContent">
            <?php foreach (scheduleTemplates() as $name => $slots): ?>
                <button type="button" class="planner-control" data-template="<?= scheduleEscape($name) ?>" data-count="<?= count($slots) ?>"><?= scheduleEscape($name) ?> · <?= count($slots) ?> events</button>
            <?php endforeach; ?>
        </div>
    </div>
</dialog>

<dialog id="bulkTypeModal" class="planner-modal">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2>Change event type</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('bulkTypeModal')">&times;</button></header>
        <div class="modal-body type-grid">
            <?php foreach ($types as $value => $info): ?>
                <button type="button" onclick="applyBulkType('<?= $value ?>')" class="type-btn"><?= $info['name'] ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</dialog>

<dialog id="bulkAssignModal" class="planner-modal">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2>Assign events</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('bulkAssignModal')">&times;</button></header>
        <div class="modal-body assign-grid">
            <button type="button" onclick="applyBulkAssign('')" class="assign-btn">Unassigned</button>
            <?php foreach ($familyMembers as $member): ?>
                <button type="button" onclick="applyBulkAssign(<?= (int)$member['id'] ?>)" class="assign-btn"><?= scheduleEscape($member['full_name']) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</dialog>

<dialog id="exportModal" class="planner-modal">
    <div class="modal-content modal-small">
        <header class="modal-header"><h2>Export day</h2><button type="button" class="modal-close" aria-label="Close" onclick="closeModal('exportModal')">&times;</button></header>
        <div class="modal-body type-grid">
            <button type="button" onclick="exportAsPDF()" class="type-btn">Print or save PDF</button>
            <button type="button" onclick="exportAsCSV()" class="type-btn">Download CSV</button>
            <button type="button" onclick="exportAsText()" class="type-btn">Download text</button>
            <button type="button" onclick="shareToWhatsApp()" class="type-btn">Open WhatsApp</button>
        </div>
    </div>
</dialog>

<script type="application/json" id="scheduleData"><?= scheduleJson([
    'selectedDate' => $selectedDate, 'viewMode' => $viewMode, 'allEvents' => $events, 'types' => $types,
    'csrf' => $csrfToken, 'activeFocusSession' => $activeFocusSession, 'selectedEvent' => $selectedEvent, 'notice' => $notice
]) ?></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
