<?php
/**
 * RELATIVES - NOTIFICATIONS CENTER - COMPLETE REBUILD v2.0
 * Optimized for Native App | Production Ready
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

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
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get filter from URL
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'unread', 'message', 'shopping', 'calendar', 'schedule', 'tracking', 'weather', 'note', 'system'];
if (!in_array($filter, $validFilters)) {
    $filter = 'all';
}

// Build query based on filter
$whereClause = "n.user_id = ?";
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
               u.avatar_color,
               TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
        FROM notifications n
        LEFT JOIN users u ON n.from_user_id = u.id
        WHERE $whereClause
        ORDER BY n.created_at DESC
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
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $unreadCount = 0;
}

// Group notifications by date
$groupedNotifications = [];
foreach ($notifications as $notif) {
    $date = date('Y-m-d', strtotime($notif['created_at']));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date === $today) {
        $dateLabel = 'Today';
    } elseif ($date === $yesterday) {
        $dateLabel = 'Yesterday';
    } else {
        $dateLabel = date('l, F j', strtotime($date));
    }
    
    if (!isset($groupedNotifications[$dateLabel])) {
        $groupedNotifications[$dateLabel] = [];
    }
    
    $groupedNotifications[$dateLabel][] = $notif;
}

// Notification type configurations
$notifTypes = [
    'message' => ['icon' => '💬', 'color' => '#667eea', 'label' => 'Message'],
    'shopping' => ['icon' => '🛒', 'color' => '#f093fb', 'label' => 'Shopping'],
    'calendar' => ['icon' => '📅', 'color' => '#4facfe', 'label' => 'Calendar'],
    'schedule' => ['icon' => '⏰', 'color' => '#43e97b', 'label' => 'Schedule'],
    'tracking' => ['icon' => '📍', 'color' => '#fa709a', 'label' => 'Location'],
    'weather' => ['icon' => '🌤️', 'color' => '#30cfd0', 'label' => 'Weather'],
    'note' => ['icon' => '📝', 'color' => '#a8edea', 'label' => 'Notes'],
    'system' => ['icon' => '⚙️', 'color' => '#95a5a6', 'label' => 'System']
];

// Function to format relative time
function formatTimeAgo($secondsAgo) {
    if ($secondsAgo < 60) return 'Just now';
    if ($secondsAgo < 3600) return floor($secondsAgo / 60) . 'm';
    if ($secondsAgo < 86400) return floor($secondsAgo / 3600) . 'h';
    if ($secondsAgo < 604800) return floor($secondsAgo / 86400) . 'd';
    return date('M j', time() - $secondsAgo);
}

$pageTitle = 'Notifications';
$activePage = 'notifications';
require_once __DIR__ . '/../shared/components/header.php';
?>

<link rel="stylesheet" href="/notifications/css/notifications.css">

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <!-- Hero Section - Compact -->
        <section class="hero-section">
            <div class="greeting-card">
                <h1 class="greeting-text">
                    <span class="greeting-icon">🔔</span>
                    Notifications
                </h1>
                
                <div class="quick-actions">
                    <?php if ($unreadCount > 0): ?>
                        <button onclick="markAllRead()" class="quick-action-btn">
                            <span class="qa-icon">✓</span>
                            <span class="qa-text">Mark Read</span>
                        </button>
                    <?php endif; ?>
                    <?php if (!empty($notifications)): ?>
                        <button onclick="showClearConfirm()" class="quick-action-btn">
                            <span class="qa-icon">🗑️</span>
                            <span class="qa-text">Clear</span>
                        </button>
                    <?php endif; ?>
                    <button onclick="showPreferences()" class="quick-action-btn">
                        <span class="qa-icon">⚙️</span>
                        <span class="qa-text">Settings</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Unread Banner - Compact -->
        <?php if ($unreadCount > 0): ?>
        <div class="unread-banner glass-card">
            <div class="unread-icon">🔔</div>
            <div class="unread-text">
                <strong><?php echo $unreadCount; ?></strong> unread
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Tabs - Compact -->
        <div class="filter-tabs glass-card">
            <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <span class="tab-icon">📋</span>
                <span class="tab-text">All</span>
            </a>
            <a href="?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                <span class="tab-icon">🔔</span>
                <span class="tab-text">Unread</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="tab-badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="?filter=message" class="filter-tab <?php echo $filter === 'message' ? 'active' : ''; ?>">
                <span class="tab-icon">💬</span>
            </a>
            <a href="?filter=shopping" class="filter-tab <?php echo $filter === 'shopping' ? 'active' : ''; ?>">
                <span class="tab-icon">🛒</span>
            </a>
            <a href="?filter=calendar" class="filter-tab <?php echo $filter === 'calendar' ? 'active' : ''; ?>">
                <span class="tab-icon">📅</span>
            </a>
            <a href="?filter=tracking" class="filter-tab <?php echo $filter === 'tracking' ? 'active' : ''; ?>">
                <span class="tab-icon">📍</span>
            </a>
        </div>

        <!-- Notifications Content -->
        <div class="notifications-content">
            
            <?php if (empty($notifications)): ?>
                <!-- Empty State -->
                <div class="empty-state glass-card">
                    <div class="empty-icon">🔕</div>
                    <h2>No notifications</h2>
                    <p>
                        <?php if ($filter === 'unread'): ?>
                            You're all caught up!
                        <?php elseif ($filter !== 'all'): ?>
                            No <?php echo $filter; ?> notifications yet.
                        <?php else: ?>
                            Check back later for updates.
                        <?php endif; ?>
                    </p>
                    <?php if ($filter !== 'all'): ?>
                        <a href="/notifications/" class="btn btn-primary">
                            View All
                        </a>
                    <?php endif; ?>
                </div>
                
            <?php else: ?>
                
                <!-- Notification Groups -->
                <?php foreach ($groupedNotifications as $dateLabel => $dateNotifications): ?>
                    <div class="notification-group">
                        <div class="group-header glass-card">
                            <span class="group-icon">📅</span>
                            <span class="group-title"><?php echo htmlspecialchars($dateLabel); ?></span>
                            <span class="group-count"><?php echo count($dateNotifications); ?></span>
                        </div>

                        <div class="notifications-list">
                            <?php foreach ($dateNotifications as $notif): ?>
                                <?php
                                $typeConfig = $notifTypes[$notif['type']] ?? $notifTypes['system'];
                                $isRead = $notif['is_read'] == 1;
                                ?>
                                <div class="notification-card glass-card <?php echo $isRead ? 'read' : 'unread'; ?>" 
                                     data-notification-id="<?php echo $notif['id']; ?>"
                                     onclick="handleNotificationClick(<?php echo $notif['id']; ?>, <?php echo $notif['action_url'] ? "'" . htmlspecialchars($notif['action_url'], ENT_QUOTES) . "'" : 'null'; ?>)">
                                    
                                    <div class="notification-type-icon" style="background: <?php echo $typeConfig['color']; ?>">
                                        <?php echo $notif['icon'] ?: $typeConfig['icon']; ?>
                                    </div>

                                    <div class="notification-content">
                                        <div class="notification-header">
                                            <?php if ($notif['from_user_id']): ?>
                                                <div class="notif-avatar" style="background: <?php echo htmlspecialchars($notif['avatar_color'] ?? '#667eea'); ?>">
                                                    <?php echo strtoupper(substr($notif['full_name'] ?? '?', 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="notif-meta">
                                                <span class="notif-from">
                                                    <?php echo $notif['from_user_id'] ? htmlspecialchars($notif['full_name'] ?? 'User') : 'System'; ?>
                                                </span>
                                                <span class="notif-time">
                                                    <?php echo formatTimeAgo($notif['seconds_ago']); ?>
                                                </span>
                                            </div>
                                            <?php if (!$isRead): ?>
                                                <div class="unread-dot"></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="notification-body">
                                            <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <?php if (!empty($notif['message'])): ?>
                                                <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($notif['action_url']): ?>
                                            <div class="notification-action">
                                                <span class="action-text">Tap to view</span>
                                                <span class="action-arrow">→</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <button onclick="event.stopPropagation(); deleteNotification(<?php echo $notif['id']; ?>)" 
                                            class="notification-delete" 
                                            title="Delete">
                                        🗑️
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Clear Confirmation Modal -->
<div id="clearConfirmModal" class="modal">
    <div class="modal-content">
        <button onclick="closeModal('clearConfirmModal')" class="modal-close">✕</button>
        <div class="modal-body">
            <div class="modal-icon">🗑️</div>
            <h2>Clear Read Notifications</h2>
            <p>Delete all read notifications? This cannot be undone.</p>
            
            <div class="modal-actions">
                <button onclick="clearAllRead()" class="btn btn-danger">
                    Yes, Clear All
                </button>
                <button onclick="closeModal('clearConfirmModal')" class="btn btn-secondary">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Preferences Modal -->
<div id="preferencesModal" class="modal modal-large">
    <div class="modal-content">
        <button onclick="closeModal('preferencesModal')" class="modal-close">✕</button>
        <div class="modal-body">
            <div class="modal-icon">⚙️</div>
            <h2>Notification Settings</h2>
            <div id="preferencesContent">
                <div class="loading">Loading...</div>
            </div>
        </div>
    </div>
</div>

<script>
window.currentUser = <?php echo json_encode([
    'id' => $user['id'],
    'name' => $user['name'] ?? $user['full_name'] ?? 'User'
]); ?>;
</script>

<script src="/notifications/js/notifications.js"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>