<?php
declare(strict_types=1);

/**
 * ============================================
 * HOME PAGE - FAMILY HUB DASHBOARD v3.1
 * Mobile-First Native App Optimized
 * ============================================
 */

// Start session with correct name to match bootstrap config
if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
    session_start();
}

// Quick session check
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

// Load bootstrap
require_once __DIR__ . '/../core/bootstrap.php';

// Validate session with database
try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }
    
} catch (Exception $e) {
    error_log('Home page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get user's last known location for weather
$userLocation = null;
try {
    // First try tracking_current (most recent location)
    $stmt = $db->prepare("
        SELECT lat, lng, accuracy_m, updated_at as created_at
        FROM tracking_current
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $userLocation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback to tracking_locations if no current location
    if (!$userLocation) {
        $stmt = $db->prepare("
            SELECT lat, lng, accuracy_m, created_at
            FROM tracking_locations
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $userLocation = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Location fetch error: ' . $e->getMessage());
}

// Get stats
$stats = [
    'shopping_items' => 0,
    'notes_count' => 0,
    'upcoming_events' => 0,
    'family_members' => 0,
    'unread_messages' => 0,
    'completed_tasks' => 0,
    'active_lists' => 0
];

try {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM shopping_items si
        JOIN shopping_lists sl ON si.list_id = sl.id
        WHERE sl.family_id = ? AND si.status = 'pending'
    ");
    $stmt->execute([$user['family_id']]);
    $stats['shopping_items'] = (int)$stmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM shopping_items si
        JOIN shopping_lists sl ON si.list_id = sl.id
        WHERE sl.family_id = ? AND si.status = 'bought'
        AND si.bought_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$user['family_id']]);
    $stats['completed_tasks'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT sl.id) FROM shopping_lists sl
        JOIN shopping_items si ON sl.id = si.list_id
        WHERE sl.family_id = ? AND si.status = 'pending'
    ");
    $stmt->execute([$user['family_id']]);
    $stats['active_lists'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Stats error: ' . $e->getMessage());
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notes WHERE family_id = ?");
    $stmt->execute([$user['family_id']]);
    $stats['notes_count'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Notes error: ' . $e->getMessage());
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM events 
        WHERE family_id = ? 
          AND starts_at >= NOW() 
          AND starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
          AND status = 'pending'
    ");
    $stmt->execute([$user['family_id']]);
    $stats['upcoming_events'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Events error: ' . $e->getMessage());
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE family_id = ? AND status = 'active'");
    $stmt->execute([$user['family_id']]);
    $stats['family_members'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Members error: ' . $e->getMessage());
}

// Get family invite code for invite link
$inviteLink = '';
try {
    $stmt = $db->prepare("SELECT invite_code FROM families WHERE id = ?");
    $stmt->execute([$user['family_id']]);
    $inviteCode = $stmt->fetchColumn();
    if ($inviteCode) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        $inviteLink = $baseUrl . '/register-join.php?code=' . urlencode($inviteCode);
    }
} catch (Exception $e) {
    error_log('Invite code error: ' . $e->getMessage());
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT m.id) 
        FROM messages m
        LEFT JOIN message_reads mr ON m.id = mr.message_id AND mr.user_id = ?
        WHERE m.family_id = ? 
          AND m.user_id != ?
          AND m.deleted_at IS NULL
          AND mr.id IS NULL
    ");
    $stmt->execute([$user['id'], $user['family_id'], $user['id']]);
    $stats['unread_messages'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Messages error: ' . $e->getMessage());
}

// Get recent activity
$recentActivity = [];
try {
    $stmt = $db->prepare("
        SELECT
            al.action,
            al.entity_type,
            al.created_at,
            u.id as user_id,
            u.full_name,
            u.avatar_color
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.family_id = ?
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user['family_id']]);
    $recentActivity = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Activity error: ' . $e->getMessage());
}

// Get upcoming events
$upcomingEvents = [];
try {
    $stmt = $db->prepare("
        SELECT 
            title,
            starts_at,
            location,
            kind as priority
        FROM events 
        WHERE family_id = ? 
          AND starts_at >= NOW()
          AND status = 'pending'
        ORDER BY starts_at ASC
        LIMIT 5
    ");
    $stmt->execute([$user['family_id']]);
    $upcomingEvents = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Events error: ' . $e->getMessage());
}

// Get family members
$familyMembers = [];
try {
    $stmt = $db->prepare("
        SELECT 
            id,
            full_name,
            avatar_color,
            last_login as last_active,
            role
        FROM users 
        WHERE family_id = ? AND status = 'active'
        ORDER BY last_login DESC
    ");
    $stmt->execute([$user['family_id']]);
    $familyMembers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Members error: ' . $e->getMessage());
}

// Greeting
$hour = (int)date('H');
if ($hour < 12) {
    $greeting = 'Good Morning';
    $greetingIcon = '🌅';
    $greetingColor = 'morning';
} elseif ($hour < 18) {
    $greeting = 'Good Afternoon';
    $greetingIcon = '☀️';
    $greetingColor = 'afternoon';
} else {
    $greeting = 'Good Evening';
    $greetingIcon = '🌙';
    $greetingColor = 'evening';
}

$firstName = explode(' ', $user['name'])[0];
$showWelcome = isset($_GET['welcome']) || isset($_GET['joined']);

$pageTitle = 'Home';
$activePage = 'home';
$pageCSS = ['/home/css/home.css'];
$pageJS = ['/home/js/home.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        <?php if ($showWelcome): ?>
            <div class="welcome-banner">
                <div class="welcome-content">
                    <div class="welcome-icon">🎉</div>
                    <div class="welcome-text">
                        <h2>Welcome to <?php echo htmlspecialchars($user['family_name']); ?>!</h2>
                        <p>You've successfully joined your family hub. Start exploring!</p>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="welcome-close" aria-label="Close welcome banner">✕</button>
                </div>
            </div>
        <?php endif; ?>
        
        <section class="hero-section" data-time="<?php echo $greetingColor; ?>">
            <div class="greeting-card">
                <div class="greeting-time" id="liveTime" role="timer"><?php echo date('l, F j, Y • g:i A'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon" aria-hidden="true"><?php echo $greetingIcon; ?></span>
                    <?php echo $greeting; ?>, 
                    <span class="greeting-name"><?php echo htmlspecialchars($firstName); ?></span>!
                </h1>
                <p class="greeting-subtitle">Welcome back to your AI-powered family hub</p>
                
                <div class="quick-actions">
                    <?php if ($inviteLink): ?>
                    <button class="quick-action-btn" onclick="copyInviteLink()" aria-label="Copy invite link">
                        <span class="qa-icon" aria-hidden="true">📨</span>
                        <span class="qa-text">Invite Member</span>
                    </button>
                    <?php endif; ?>
                    <button class="quick-action-btn" onclick="AIAssistant.generateSuggestions()" aria-label="Get AI suggestions">
                        <span class="qa-icon" aria-hidden="true">✨</span>
                        <span class="qa-text">AI Suggestions</span>
                    </button>
                </div>

                <?php if ($inviteLink): ?>
                <input type="hidden" id="homeInviteLink" value="<?php echo htmlspecialchars($inviteLink); ?>">
                <?php endif; ?>
            </div>
        </section>

        <!-- INTEGRATED WEATHER WIDGET -->
        <section class="weather-widget-home" id="homeWeatherWidget" aria-label="Weather information">
            <div class="weather-widget-loading">
                <div class="loading-pulse">
                    <div class="pulse-icon" aria-hidden="true">🌤️</div>
                    <p>Loading weather data...</p>
                </div>
            </div>
        </section>

        <section class="ai-dashboard">
            <div class="dashboard-header">
                <div class="dashboard-title">
                    <span class="title-icon" aria-hidden="true">🤖</span>
                    <h2>AI Intelligence Center</h2>
                    <span class="ai-badge pulse" aria-label="Live updates">Live</span>
                </div>
            </div>

            <div class="ai-grid">
                <div class="ai-card insights-card" data-tilt>
                    <div class="ai-card-header">
                        <div class="ai-card-icon" aria-hidden="true">🧠</div>
                        <h3>Smart Insights</h3>
                    </div>
                    <div class="ai-card-body">
                        <div id="aiInsights" class="insights-list">
                            <div class="insight-item loading">
                                <div class="skeleton skeleton-text"></div>
                                <div class="skeleton skeleton-text"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ai-card calendar-card" data-tilt>
                    <div class="ai-card-header">
                        <div class="ai-card-icon" aria-hidden="true">📅</div>
                        <h3>Upcoming Schedule</h3>
                    </div>
                    <div class="ai-card-body">
                        <?php if (!empty($upcomingEvents)): ?>
                            <div class="upcoming-events">
                                <?php foreach ($upcomingEvents as $event): ?>
                                    <div class="event-item" data-priority="<?php echo htmlspecialchars($event['priority']); ?>">
                                        <div class="event-time">
                                            <?php
                                            $eventTime = strtotime($event['starts_at']);
                                            $diff = $eventTime - time();
                                            $isToday = date('Y-m-d', $eventTime) === date('Y-m-d');
                                            $isTomorrow = date('Y-m-d', $eventTime) === date('Y-m-d', strtotime('+1 day'));

                                            if ($diff < 3600 && $diff > 0) {
                                                echo '<span class="urgent">In ' . ceil($diff / 60) . ' min</span>';
                                            } elseif ($isToday) {
                                                echo '<span class="today">Today at ' . date('g:i A', $eventTime) . '</span>';
                                            } elseif ($isTomorrow) {
                                                echo 'Tomorrow at ' . date('g:i A', $eventTime);
                                            } else {
                                                echo date('M j, g:i A', $eventTime);
                                            }
                                            ?>
                                        </div>
                                        <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <?php if ($event['location']): ?>
                                            <div class="event-location"><span aria-hidden="true">📍</span> <?php echo htmlspecialchars($event['location']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon" aria-hidden="true">📅</div>
                                <p>No upcoming events</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="stats-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="title-icon" aria-hidden="true">📈</span>
                    Family Hub Overview
                </h2>
            </div>

            <div class="stats-grid">
                <a href="/messages/" class="stat-card" data-color="blue" data-tilt aria-label="Messages section">
                    <div class="stat-icon">
                        <div class="icon-wrapper" aria-hidden="true">💬</div>
                        <?php if ($stats['unread_messages'] > 0): ?>
                            <span class="stat-badge" aria-label="<?php echo $stats['unread_messages']; ?> unread"><?php echo $stats['unread_messages']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-number" data-count="<?php echo $stats['unread_messages']; ?>" aria-label="<?php echo $stats['unread_messages']; ?> unread messages">0</div>
                    <div class="stat-label">Unread Messages</div>
                    <div class="stat-trend">
                        <span class="trend-icon" aria-hidden="true">📊</span>
                        <span class="trend-text">Real-time</span>
                    </div>
                    <div class="stat-action">View Messages →</div>
                    <div class="stat-glow" aria-hidden="true"></div>
                </a>

                <a href="/shopping/" class="stat-card" data-color="orange" data-tilt aria-label="Shopping section">
                    <div class="stat-icon">
                        <div class="icon-wrapper" aria-hidden="true">🛒</div>
                        <?php if ($stats['shopping_items'] > 0): ?>
                            <span class="stat-badge" aria-label="<?php echo $stats['shopping_items']; ?> items"><?php echo $stats['shopping_items']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-number" data-count="<?php echo $stats['shopping_items']; ?>" aria-label="<?php echo $stats['shopping_items']; ?> shopping items">0</div>
                    <div class="stat-label">Shopping Items</div>
                    <div class="stat-trend">
                        <span class="trend-icon" aria-hidden="true">📈</span>
                        <span class="trend-text"><?php echo $stats['active_lists']; ?> active lists</span>
                    </div>
                    <div class="stat-action">View Shopping →</div>
                    <div class="stat-glow" aria-hidden="true"></div>
                </a>

                <a href="/notes/" class="stat-card" data-color="purple" data-tilt aria-label="Notes section">
                    <div class="stat-icon">
                        <div class="icon-wrapper" aria-hidden="true">📝</div>
                    </div>
                    <div class="stat-number" data-count="<?php echo $stats['notes_count']; ?>" aria-label="<?php echo $stats['notes_count']; ?> family notes">0</div>
                    <div class="stat-label">Family Notes</div>
                    <div class="stat-trend">
                        <span class="trend-icon" aria-hidden="true">✍️</span>
                        <span class="trend-text">Organized</span>
                    </div>
                    <div class="stat-action">View Notes →</div>
                    <div class="stat-glow" aria-hidden="true"></div>
                </a>

                <a href="/calendar/" class="stat-card" data-color="green" data-tilt aria-label="Calendar section">
                    <div class="stat-icon">
                        <div class="icon-wrapper" aria-hidden="true">📅</div>
                        <?php if ($stats['upcoming_events'] > 0): ?>
                            <span class="stat-badge" aria-label="<?php echo $stats['upcoming_events']; ?> upcoming"><?php echo $stats['upcoming_events']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-number" data-count="<?php echo $stats['upcoming_events']; ?>" aria-label="<?php echo $stats['upcoming_events']; ?> upcoming events">0</div>
                    <div class="stat-label">Upcoming Events</div>
                    <div class="stat-trend">
                        <span class="trend-icon" aria-hidden="true">⏰</span>
                        <span class="trend-text">Next 7 days</span>
                    </div>
                    <div class="stat-action">View Calendar →</div>
                    <div class="stat-glow" aria-hidden="true"></div>
                </a>

                <div class="stat-card" data-color="blue" data-tilt>
                    <div class="stat-icon">
                        <div class="icon-wrapper" aria-hidden="true">👨‍👩‍👧‍👦</div>
                    </div>
                    <div class="stat-number" data-count="<?php echo $stats['family_members']; ?>" aria-label="<?php echo $stats['family_members']; ?> family members">0</div>
                    <div class="stat-label">Family Members</div>
                    <div class="stat-trend">
                        <span class="trend-icon" aria-hidden="true">👥</span>
                        <span class="trend-text">Active</span>
                    </div>
                    <div class="stat-action">View Members →</div>
                    <div class="stat-glow" aria-hidden="true"></div>
                </div>

                <div class="stat-card" data-color="pink" data-tilt onclick="AIAssistant.openAnalytics()">
                    <div class="stat-icon">
                        <div class="icon-wrapper" aria-hidden="true">📊</div>
                    </div>
                    <div class="stat-number" data-count="<?php echo $stats['completed_tasks']; ?>" aria-label="<?php echo $stats['completed_tasks']; ?> tasks completed">0</div>
                    <div class="stat-label">Tasks Completed</div>
                    <div class="stat-trend">
                        <span class="trend-icon" aria-hidden="true">🔥</span>
                        <span class="trend-text">This week</span>
                    </div>
                    <div class="stat-action">View Analytics →</div>
                    <div class="stat-glow" aria-hidden="true"></div>
                </div>
            </div>
        </section>

        <section class="members-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="title-icon" aria-hidden="true">👥</span>
                    Family Members
                </h2>
            </div>

            <div class="members-grid">
                <?php foreach ($familyMembers as $member): ?>
                    <div class="member-card" data-tilt>
                        <div class="member-avatar" style="background: <?php echo htmlspecialchars($member['avatar_color']); ?>" aria-label="<?php echo htmlspecialchars($member['full_name']); ?>">
                            <?php
                            $avatarPath = __DIR__ . "/../saves/{$member['id']}/avatar/avatar.webp";
                            if (file_exists($avatarPath)):
                            ?>
                                <img src="/saves/<?php echo $member['id']; ?>/avatar/avatar.webp?v=<?php echo $cacheVersion; ?>"
                                     alt="<?php echo htmlspecialchars($member['full_name']); ?>"
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" loading="lazy">
                            <?php else: ?>
                                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                            <?php endif; ?>
                            <?php
                            $lastActive = strtotime($member['last_active']);
                            $isOnline = (time() - $lastActive) < 300;
                            ?>
                            <span class="member-status <?php echo $isOnline ? 'online' : 'offline'; ?>" aria-label="<?php echo $isOnline ? 'Online' : 'Offline'; ?>"></span>
                        </div>
                        <div class="member-info">
                            <h3 class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></h3>
                            <p class="member-role"><?php echo ucfirst(htmlspecialchars($member['role'])); ?></p>
                            <p class="member-activity">
                                <?php
                                if ($isOnline) {
                                    echo '<span class="online-badge"><span aria-hidden="true">🟢</span> Online</span>';
                                } else {
                                    $diff = time() - $lastActive;
                                    if ($diff < 3600) {
                                        echo floor($diff / 60) . ' min ago';
                                    } elseif ($diff < 86400) {
                                        echo floor($diff / 3600) . ' hours ago';
                                    } else {
                                        echo date('M j', $lastActive);
                                    }
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if (!empty($recentActivity)): ?>
        <section class="activity-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="title-icon" aria-hidden="true">⚡</span>
                    Recent Activity
                </h2>
            </div>
            
            <div class="activity-timeline">
                <?php foreach ($recentActivity as $index => $activity): ?>
                    <div class="activity-item" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="activity-avatar" style="background: <?php echo htmlspecialchars($activity['avatar_color'] ?? '#667eea'); ?>" aria-hidden="true">
                            <?php
                            $activityAvatarPath = __DIR__ . "/../saves/{$activity['user_id']}/avatar/avatar.webp";
                            if (!empty($activity['user_id']) && file_exists($activityAvatarPath)):
                            ?>
                                <img src="/saves/<?php echo $activity['user_id']; ?>/avatar/avatar.webp?v=<?php echo $cacheVersion; ?>"
                                     alt="<?php echo htmlspecialchars($activity['full_name'] ?? ''); ?>"
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" loading="lazy">
                            <?php else: ?>
                                <?php echo strtoupper(substr($activity['full_name'] ?? '?', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="activity-content">
                            <div class="activity-text">
                                <strong><?php echo htmlspecialchars($activity['full_name'] ?? 'Someone'); ?></strong>
                                <?php echo htmlspecialchars($activity['action']); ?>
                                <?php if ($activity['entity_type']): ?>
                                    <span class="entity-badge"><?php echo htmlspecialchars($activity['entity_type']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="activity-time">
                                <?php
                                $time = strtotime($activity['created_at']);
                                $diff = time() - $time;
                                
                                if ($diff < 60) {
                                    echo 'Just now';
                                } elseif ($diff < 3600) {
                                    echo floor($diff / 60) . ' minutes ago';
                                } elseif ($diff < 86400) {
                                    echo floor($diff / 3600) . ' hours ago';
                                } else {
                                    echo date('M j, g:i A', $time);
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </div>
</main>

<!-- Pass user location to JavaScript -->
<script>
window.USER_LOCATION = <?php echo $userLocation ? json_encode([
    'lat' => (float)$userLocation['lat'],
    'lng' => (float)$userLocation['lng'],
    'accuracy' => isset($userLocation['accuracy_m']) ? (int)$userLocation['accuracy_m'] : null,
    'timestamp' => $userLocation['created_at']
]) : 'null'; ?>;
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>