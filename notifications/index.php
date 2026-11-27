<?php
require_once __DIR__ . '/../shared/components/avatar.php';
require_once __DIR__ . '/../shared/components/family-scenes.php';

header('Cache-Control: private, no-store');

require_once __DIR__ . '/../core/session_boot.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CSRF.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
} catch (Exception $e) {
    error_log('Notifications page error: ' . $e->getMessage());
    die('Error loading page. Please try again.');
}

// Handle AJAX POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Reload Notifications and try again.']);
        exit;
    }
    
    try {
        $response = ['success' => false];
        
        switch ($_POST['action']) {
            case 'mark_read':
                $notifId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
                if ($notifId) {
                    $stmt = $db->prepare("
                        UPDATE notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$notifId, $user['id']]);
                    $response['success'] = true;
                }
                break;
                
            case 'mark_all_read':
                $type = $_POST['type'] ?? null;
                $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0";
                $params = [$user['id']];
                
                if ($type && $type !== 'all') {
                    $sql .= " AND type = ?";
                    $params[] = $type;
                }
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $response['success'] = true;
                break;
                
            case 'delete_notification':
                $notifId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
                if ($notifId) {
                    $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
                    $stmt->execute([$notifId, $user['id']]);
                    $response['success'] = true;
                }
                break;
                
            case 'clear_all':
                $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
                $stmt->execute([$user['id']]);
                $response['success'] = true;
                break;
        }
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        error_log('Notification update failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not update notifications.']);
        exit;
    }
}

$notifTypes = [
    'all' => ['label' => 'All'],
    'unread' => ['label' => 'Unread'],
    'message' => ['icon' => '💬', 'label' => 'Messages'],
    'shopping' => ['icon' => '🛒', 'label' => 'Shopping'],
    'calendar' => ['icon' => '📅', 'label' => 'Calendar'],
    'schedule' => ['icon' => '⏰', 'label' => 'Schedule'],
    'tracking' => ['icon' => '📍', 'label' => 'Location'],
    'weather' => ['icon' => '🌤️', 'label' => 'Weather'],
    'note' => ['icon' => '📝', 'label' => 'Notes'],
    'system' => ['icon' => '⚙️', 'label' => 'System'],
];

// Get filter from URL
$filter = $_GET['filter'] ?? 'all';
if (!is_string($filter) || !array_key_exists($filter, $notifTypes)) {
    $filter = 'all';
}

// Build query based on filter
require_once __DIR__ . '/../core/LocationNotice.php';
$locationVisible = LocationNotice::visible($db);
$whereClause = "n.user_id = ? AND $locationVisible";
$params = [$user['id']];

if ($filter === 'unread') {
    $whereClause .= " AND n.is_read = 0";
} elseif ($filter !== 'all') {
    $whereClause .= " AND n.type = ?";
    $params[] = $filter;
}

// Fetch notifications
try {
    $stmt = $db->prepare("
        SELECT n.*, 
               u.full_name,
               TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
        FROM notifications n
        LEFT JOIN users u ON n.from_user_id = u.id
        WHERE $whereClause
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Notifications fetch error: ' . $e->getMessage());
    $notifications = [];
}

// Get unread count
try {
    $stmt = $db->prepare("SELECT COUNT(CASE WHEN is_read = 0 THEN 1 END) AS unread_count, MAX(is_read) AS has_read
        FROM notifications n WHERE n.user_id = ? AND $locationVisible");
    $stmt->execute([$user['id']]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreadCount = (int) $counts['unread_count'];
    $hasRead = (bool) $counts['has_read'];
} catch (Exception $e) {
    $unreadCount = 0;
    $hasRead = false;
}

function formatTimeAgo(int $secondsAgo): string
{
    if ($secondsAgo < 60) return 'Just now';
    if ($secondsAgo < 3600) return floor($secondsAgo / 60) . 'm ago';
    if ($secondsAgo < 86400) return floor($secondsAgo / 3600) . 'h ago';
    if ($secondsAgo < 604800) return floor($secondsAgo / 86400) . 'd ago';
    return date('M j', time() - $secondsAgo);
}

$ajax = ($_GET['ajax'] ?? null) === '1';
if (!$ajax) {
    $pageTitle = 'Notifications';
    $pageCSS = ['/notifications/css/notifications.css?build=' . filemtime(__DIR__ . '/css/notifications.css')];
    $pageJS = ['/notifications/js/notifications.js'];
    $pageJSVersion = filemtime(__DIR__ . '/js/notifications.js');
    require __DIR__ . '/../shared/components/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<main class="family-main notifications-main" id="notificationsContent" data-unread="<?= $unreadCount ?>">
    <header class="family-hero app-hero photo-hero photo-hero-compact">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Notifications</h1>
            <?php if (!$unreadCount): ?><p class="family-hero-summary">You’re all caught up.</p><?php endif; ?>
            <div class="family-actions">
                <?php if ($unreadCount): ?>
                    <a href="?filter=unread" class="btn btn-primary" data-notification-filter="unread"
                       <?= $filter === 'unread' ? 'aria-current="page"' : '' ?>><?= $unreadCount ?> unread</a>
                    <button type="button" class="btn" data-notification-action="mark_all_read">Mark all read</button>
                <?php endif; ?>
                <?php if ($hasRead): ?>
                    <button type="button" class="btn" data-notification-action="confirm_clear" aria-haspopup="dialog" aria-controls="clearConfirmModal">Clear read</button>
                <?php endif; ?>
                <button type="button" class="btn" data-notification-action="preferences" aria-haspopup="dialog" aria-controls="preferencesModal">Settings</button>
                <a class="btn" href="/profile/notifications.php#deviceAlerts">Device alerts</a>
            </div>
        </div>
        <?php renderFamilyScene('family'); ?>
    </header>

    <nav class="notifications-filters" aria-label="Filter notifications">
        <?php foreach ($notifTypes as $key => $type):
            if ($key === 'unread' && $unreadCount) continue;
            ?>
            <a href="<?= $key === 'all' ? '/notifications/' : '?filter=' . $key ?>" class="btn"
                data-notification-filter="<?= $key ?>" <?= $key === $filter ? 'aria-current="page"' : '' ?>>
                <?= $type['label'] ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$notifications): ?>
        <section class="notifications-empty">
            <h2 class="family-section-title">No notifications</h2>
            <p><?= $filter === 'unread' ? 'You’re all caught up.' : 'There are no notifications in this view.' ?></p>
            <?php if ($filter !== 'all'): ?>
                <a href="/notifications/" class="btn btn-primary" data-notification-filter="all">View all</a>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <ul class="notification-list" aria-label="Notifications">
            <?php foreach ($notifications as $notif): ?>
                <?php
                $type = $notifTypes[$notif['type']] ?? $notifTypes['system'];
                $isRead = (bool) $notif['is_read'];
                $id = (int) $notif['id'];
                $authorId = (int) $notif['from_user_id'];
                $actionUrl = trim($notif['action_url'] ?? '');
                $hasLink = preg_match('~^https?://~i', $actionUrl)
                    || (str_starts_with($actionUrl, '/') && !str_starts_with($actionUrl, '//') && !str_contains($actionUrl, chr(92)));
                ?>
                <li class="notification-card" data-notification-id="<?= $id ?>" <?= $isRead ? '' : 'data-unread' ?>>
                    <span class="notification-type-icon" aria-hidden="true"><?= htmlspecialchars($notif['icon'] ?: ($type['icon'] ?? $notifTypes['system']['icon'])) ?></span>
                    <div class="notification-detail">
                        <div class="notification-meta">
                            <span><?= $type['label'] ?></span>
                            <?php if (!$isRead): ?><span class="notification-unread">Unread</span><?php endif; ?>
                        </div>
                        <h2><?= htmlspecialchars($notif['title']) ?></h2>
                        <?php if ($notif['message']): ?><p class="notification-message"><?= htmlspecialchars($notif['message']) ?></p><?php endif; ?>
                        <div class="notification-meta notification-byline">
                            <?php if ($authorId && is_file(avatarPath($authorId))): ?>
                                <img class="notification-avatar" src="<?= avatarUrl($authorId) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($authorId ? ($notif['full_name'] ?? 'Family member') : 'System') ?></span>
                            <time datetime="<?= date(DATE_ATOM, strtotime($notif['created_at'])) ?>"><?= formatTimeAgo((int) $notif['seconds_ago']) ?></time>
                        </div>
                        <?php if ($hasLink): ?>
                            <a class="notification-link" href="<?= htmlspecialchars($actionUrl) ?>" data-notification-action="open">View details <span aria-hidden="true">→</span></a>
                        <?php endif; ?>
                    </div>
                    <div class="notification-actions">
                        <?php if (!$isRead): ?>
                            <button type="button" class="notifications-icon" aria-label="Mark as read" title="Mark as read" data-notification-action="mark_read">✓</button>
                        <?php endif; ?>
                        <button type="button" class="notifications-icon" aria-label="Delete notification" title="Delete notification" data-notification-action="delete_notification">×</button>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>

<?php if ($ajax) exit; ?>

<dialog id="clearConfirmModal" class="notifications-dialog" aria-labelledby="clearNotificationsTitle">
    <div class="notifications-dialog-inner">
        <header>
            <h2 id="clearNotificationsTitle">Clear read notifications?</h2>
            <button type="button" class="notifications-icon" aria-label="Close" data-notification-action="close">×</button>
        </header>
        <p>This permanently deletes your read notifications.</p>
        <div class="notification-actions">
            <button type="button" class="btn" data-notification-action="close" autofocus>Cancel</button>
            <button type="button" class="btn btn-danger" data-notification-action="clear_all">Clear read</button>
        </div>
    </div>
</dialog>

<dialog id="preferencesModal" data-csrf="<?= htmlspecialchars(CSRF::generate()) ?>" class="notifications-dialog notifications-dialog-wide" aria-labelledby="notificationSettingsTitle">
    <div class="notifications-dialog-inner">
        <header>
            <h2 id="notificationSettingsTitle">Notification settings</h2>
        <a href="/profile/notifications.php">Device notification settings</a>
            <button type="button" class="notifications-icon" aria-label="Close" data-notification-action="close">×</button>
        </header>
        <div id="preferencesContent" aria-live="polite"></div>
    </div>
</dialog>

<?php require __DIR__ . '/../shared/components/footer.php'; ?>
