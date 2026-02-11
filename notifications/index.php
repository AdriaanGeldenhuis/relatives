<?php
/**
 * RELATIVES - NOTIFICATIONS CENTER - COMPLETE REBUILD v2.0
 * Optimized for Native App | Production Ready
 */

session_name('RELATIVES_SESSION');
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
$cacheVersion = '10.1.0'; // v10.1.0 - AJAX navigation (no page refresh)
require_once __DIR__ . '/../shared/components/header.php';
?>

<link rel="stylesheet" href="/notifications/css/notifications.css?v=<?php echo $cacheVersion; ?>">

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">

        <!-- Hero Section (Same as Schedule) -->
        <div class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">🔔</span>
                    <span class="greeting-name">Notifications</span>
                </h1>
                <p class="greeting-subtitle">Stay updated with your family activities</p>

                <div class="quick-actions">
                    <?php if ($unreadCount > 0): ?>
                        <button onclick="markAllRead()" class="quick-action-btn">
                            <span class="qa-icon">✓</span>
                            <span>Mark Read</span>
                        </button>
                    <?php endif; ?>
                    <?php if (!empty($notifications)): ?>
                        <button onclick="showClearConfirm()" class="quick-action-btn">
                            <span class="qa-icon">🗑️</span>
                            <span>Clear</span>
                        </button>
                    <?php endif; ?>
                    <button onclick="showPreferences()" class="quick-action-btn">
                        <span class="qa-icon">⚙️</span>
                        <span>Settings</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filter Section (Same as Schedule) -->
        <div class="search-filter-section">
            <div class="filter-buttons">
                <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    <span>📋</span> All
                </a>
                <a href="?filter=unread" class="filter-btn <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                    <span>🔔</span> Unread
                    <?php if ($unreadCount > 0): ?>
                        <span class="filter-badge"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?filter=message" class="filter-btn <?php echo $filter === 'message' ? 'active' : ''; ?>">
                    <span>💬</span> Messages
                </a>
                <a href="?filter=shopping" class="filter-btn <?php echo $filter === 'shopping' ? 'active' : ''; ?>">
                    <span>🛒</span> Shopping
                </a>
                <a href="?filter=calendar" class="filter-btn <?php echo $filter === 'calendar' ? 'active' : ''; ?>">
                    <span>📅</span> Calendar
                </a>
                <a href="?filter=schedule" class="filter-btn <?php echo $filter === 'schedule' ? 'active' : ''; ?>">
                    <span>⏰</span> Schedule
                </a>
            </div>
        </div>

        <!-- Stats Bar (Same as Schedule week-stats-bar) -->
        <?php if ($unreadCount > 0 || !empty($notifications)): ?>
        <div class="week-stats-bar glass-card">
            <div class="week-stats-title">Status:</div>
            <div class="week-stats-chips">
                <div class="stat-chip unread-chip">
                    🔔 <?php echo $unreadCount; ?> unread
                </div>
                <div class="stat-chip total-chip">
                    📋 <?php echo count($notifications); ?> total
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notifications Content -->
        <div class="notes-section">

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
                        <button onclick="loadNotificationsData('all', 'fadeIn')" class="btn btn-primary btn-lg">
                            View All
                        </button>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <?php
                $totalNotifs = count($notifications);
                $readNotifs = count(array_filter($notifications, fn($n) => $n['is_read'] == 1));
                $readPercentage = $totalNotifs > 0 ? round(($readNotifs / $totalNotifs) * 100) : 0;
                ?>

                <!-- Progress Bar -->
                <div class="list-actions glass-card">
                    <div class="list-progress">
                        <div class="progress-text">
                            <span class="progress-icon">✓</span>
                            <?php echo $readNotifs; ?> of <?php echo $totalNotifs; ?> read (<?php echo $readPercentage; ?>%)
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $readPercentage; ?>%"></div>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if ($readNotifs > 0): ?>
                            <button onclick="showClearConfirm()" class="btn btn-secondary btn-sm">
                                <span class="btn-icon">🗑️</span>
                                <span>Clear Read</span>
                            </button>
                        <?php endif; ?>
                        <?php if ($unreadCount > 0): ?>
                            <button onclick="markAllRead()" class="btn btn-secondary btn-sm">
                                <span class="btn-icon">✓</span>
                                <span>Mark All Read</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notifications Grid (Styled like schedule notes) -->
                <div class="notes-grid">
                    <?php foreach ($notifications as $notif): ?>
                        <?php
                        $typeConfig = $notifTypes[$notif['type']] ?? $notifTypes['system'];
                        $isRead = $notif['is_read'] == 1;
                        ?>
                        <div class="note-card notification-card <?php echo $isRead ? 'read' : 'unread'; ?>"
                             data-notification-id="<?php echo $notif['id']; ?>"
                             style="background: <?php echo $typeConfig['color']; ?>;"
                             onclick="handleNotificationClick(<?php echo $notif['id']; ?>, <?php echo $notif['action_url'] ? "'" . htmlspecialchars($notif['action_url'], ENT_QUOTES) . "'" : 'null'; ?>)">

                            <!-- Header with type icon and actions -->
                            <div class="note-header">
                                <div class="notif-type-badge">
                                    <?php echo $notif['icon'] ?: $typeConfig['icon']; ?>
                                    <span class="type-label"><?php echo $typeConfig['label']; ?></span>
                                </div>

                                <div class="note-actions">
                                    <?php if (!$isRead): ?>
                                        <button onclick="event.stopPropagation(); markAsRead(<?php echo $notif['id']; ?>)"
                                                class="note-action"
                                                title="Mark Read">
                                            ✓
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="event.stopPropagation(); deleteNotification(<?php echo $notif['id']; ?>)"
                                            class="note-action"
                                            title="Delete">
                                        🗑️
                                    </button>
                                </div>
                            </div>

                            <!-- Notification Title -->
                            <div class="note-title">
                                <?php echo htmlspecialchars($notif['title']); ?>
                                <?php if (!$isRead): ?>
                                    <span class="unread-badge">NEW</span>
                                <?php endif; ?>
                            </div>

                            <!-- Notification Message -->
                            <?php if (!empty($notif['message'])): ?>
                                <div class="note-body">
                                    <?php echo htmlspecialchars($notif['message']); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Action Link -->
                            <?php if ($notif['action_url']): ?>
                                <div class="notif-action-hint">
                                    <span>Tap to view</span>
                                    <span class="action-arrow">→</span>
                                </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="note-footer">
                                <div class="note-author">
                                    <?php if ($notif['from_user_id']): ?>
                                        <div class="author-avatar-mini" style="background: <?php echo htmlspecialchars($notif['avatar_color'] ?? '#667eea'); ?>">
                                            <img src="/saves/<?php echo (int)$notif['from_user_id']; ?>/avatar/avatar.webp"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                 style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                            <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($notif['full_name'] ?? '?', 0, 1)); ?></span>
                                        </div>
                                        <span><?php echo htmlspecialchars($notif['full_name'] ?? 'User'); ?></span>
                                    <?php else: ?>
                                        <div class="author-avatar-mini" style="background: #95a5a6;">
                                            ⚙
                                        </div>
                                        <span>System</span>
                                    <?php endif; ?>
                                </div>
                                <span class="notif-time">
                                    <?php echo formatTimeAgo($notif['seconds_ago']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

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

<script src="/notifications/js/notifications.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>