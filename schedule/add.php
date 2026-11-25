<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';
require_once __DIR__ . '/planner.php';

header('Cache-Control: private, no-store');

$day = scheduleDate($_GET['date'] ?? null);
$viewMode = ($_GET['view'] ?? null) === 'week' ? 'week' : 'day';
$user = null;
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/bootstrap.php';
    $user = (new Auth($db))->getCurrentUser();
}
if (!$user) {
    unset($_SESSION['user_id']);
    $returnTo = $day ? scheduleAddUrl($day->format('Y-m-d'), $viewMode) : '/schedule/add.php';
    header('Location: /login.php?redirect=' . rawurlencode($returnTo), true, 303);
    exit;
}

require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/api/write.php';

$familyId = (int) $user['family_id'];
$userId = (int) $user['id'];
$types = scheduleTypes();
$now = new DateTimeImmutable();
$selectedDate = $day ? $day->format('Y-m-d') : $now->format('Y-m-d');
$start = $selectedDate === $now->format('Y-m-d') ? $now : new DateTimeImmutable($selectedDate . ' 09:00');
$end = $start->modify('+1 hour');
$eventFormAction = scheduleAddUrl($selectedDate, $viewMode);
$eventFormBack = '/schedule/?' . http_build_query(['date' => $selectedDate, 'view' => $viewMode]);
$eventFormValues = [
    'date' => $selectedDate,
    'end_date' => $end->format('Y-m-d'),
    'start_time' => $start->format('H:i'),
    'end_time' => $end->format('H:i'),
    'kind' => 'event',
    'priority' => 'medium',
    'color' => $types['event']['color'],
];
$eventFormError = '';
$csrfToken = CSRF::generate();
$submittedToken = $_POST['csrf_token'] ?? null;
$hasValidToken = is_string($submittedToken) && CSRF::validate($submittedToken);
session_write_close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventFormValues = array_replace($eventFormValues, array_filter($_POST, 'is_string'));
    if (!$hasValidToken) {
        http_response_code(403);
        $eventFormError = 'Your form expired. Review your event and save it again.';
    } elseif ((new SubscriptionManager($db))->isFamilyLocked($familyId)) {
        http_response_code(402);
        $eventFormError = 'Subscribe to continue editing your schedule.';
    } else {
        try {
            $saved = scheduleSaveEvent($db, $familyId, $userId);
            header('Location: /schedule/?' . http_build_query([
                'date' => substr($saved['starts_at'], 0, 10), 'view' => $viewMode,
            ]), true, 303);
            exit;
        } catch (DomainException $error) {
            http_response_code(422);
            $eventFormError = $error->getMessage();
        } catch (Throwable $error) {
            error_log('Schedule form failed: ' . $error->getMessage());
            http_response_code(500);
            $eventFormError = 'Could not save the event. Try again.';
        }
    }
}

$familyMembers = scheduleFamilyMembers($db, $familyId);
$pageTitle = 'Add event';
$pageCSS = ['/schedule/css/schedule.css?build=' . filemtime(__DIR__ . '/css/schedule.css')];
require __DIR__ . '/../shared/components/header.php';
?>
<main class="planner-main planner-add-main">
    <section class="planner-event-page" aria-labelledby="eventEditorHeading">
        <header class="modal-header">
            <h1 id="eventEditorHeading">Add event</h1>
            <a href="<?= scheduleEscape($eventFormBack) ?>" class="btn btn-secondary">Back to Schedule</a>
        </header>
        <?php require __DIR__ . '/../shared/components/event-form.php'; ?>
    </section>
</main>
<?php require __DIR__ . '/../shared/components/footer.php'; ?>
