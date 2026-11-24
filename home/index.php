<?php
declare(strict_types=1);

// Start session with correct name to match bootstrap config
require_once __DIR__ . '/../core/session_boot.php';

header('Cache-Control: private, no-store');

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
        LIMIT 8
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
    $greeting = 'Good morning';
    $greetingIcon = '🌅';
    $greetingColor = 'morning';
} elseif ($hour < 18) {
    $greeting = 'Good afternoon';
    $greetingIcon = '☀️';
    $greetingColor = 'afternoon';
} else {
    $greeting = 'Good evening';
    $greetingIcon = '🌙';
    $greetingColor = 'evening';
}

$firstName = explode(' ', $user['name'])[0];
$showWelcome = isset($_GET['welcome']) || isset($_GET['joined']);

// App launcher shortcuts and their at-a-glance status.
$messageSummary = match ($stats['unread_messages']) {
    0 => 'Family conversations',
    1 => '1 unread message',
    default => $stats['unread_messages'] . ' unread messages',
};
$shoppingSummary = match ($stats['shopping_items']) {
    0 => 'Family shopping lists',
    1 => '1 item to buy',
    default => $stats['shopping_items'] . ' items to buy',
};
$calendarSummary = match ($stats['upcoming_events']) {
    0 => 'Family events',
    1 => '1 event this week',
    default => $stats['upcoming_events'] . ' events this week',
};

$launcherApps = [
    'tracking' => ['href' => '/tracking/app/', 'icon' => '📍', 'label' => 'Tracking', 'summary' => 'Family locations', 'glow' => '#f6a491'],
    'weather' => ['href' => '/weather/', 'label' => 'Weather', 'summary' => 'Local forecast', 'glow' => '#8ccbea'],
    'notes' => ['href' => '/notes/', 'icon' => '📝', 'label' => 'Notes', 'summary' => 'Shared notes', 'glow' => '#be9be4'],
    'shopping' => ['href' => '/shopping/', 'label' => 'Shopping', 'summary' => $shoppingSummary, 'glow' => '#f5ba55'],
    'calendar' => ['href' => '/calendar/', 'icon' => '📅', 'label' => 'Calendar', 'summary' => $calendarSummary, 'glow' => '#6bcba1'],
    'schedule' => ['href' => '/schedule/', 'icon' => '⏰', 'label' => 'Schedule', 'summary' => 'Daily agenda', 'glow' => '#e992ba'],
    'travel' => ['href' => '/holiday_traveling/', 'icon' => '✈️', 'label' => 'Travel', 'summary' => 'Trips and itineraries', 'glow' => '#6fcbbf'],
    'games' => ['href' => '/games/', 'icon' => '🎮', 'label' => 'Games', 'summary' => 'Family arcade', 'glow' => '#a89aee'],
    'messages' => ['href' => '/messages/', 'icon' => '💬', 'label' => 'Messages', 'summary' => $messageSummary, 'glow' => '#7fb5f1'],
];

$pageTitle = 'Home';
$activePage = 'home';
$homeStylesheet = __DIR__ . '/css/home.css';

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content family-main">
    <div class="hub">
        <p class="offline-status" id="home-connection" role="status" hidden>
            You’re offline. Live family activity and apps need a connection.
        </p>

        <?php if ($showWelcome): ?>
        <div class="welcome-banner" role="status">
            <span class="welcome-emoji" aria-hidden="true">🎉</span>
            <div class="welcome-copy">
                <strong>Welcome to <?php echo htmlspecialchars($user['family_name']); ?>!</strong>
                <span>You're in. This is your family's command center.</span>
            </div>
            <button class="welcome-close" onclick="this.parentElement.remove()" aria-label="Dismiss">✕</button>
        </div>
        <?php endif; ?>

        <section class="hero family-hero" data-time="<?php echo $greetingColor; ?>">
            <div class="hero-main">
                <div class="hero-greeting family-hero-content">
                    <p class="hero-kicker">
                        <span aria-hidden="true"><?php echo $greetingIcon; ?></span>
                        <span id="heroDate"><?php echo date('l, j F Y'); ?></span>
                    </p>
                    <h1 class="family-hero-title">
                        <?php echo $greeting; ?>, <span class="hero-name"><?php echo htmlspecialchars($firstName); ?></span>
                    </h1>
                    <p class="family-hero-summary">
                        The <?php echo htmlspecialchars($user['family_name']); ?> hub
                        <span class="dot-sep" aria-hidden="true">·</span>
                        <?php echo $stats['family_members']; ?> member<?php echo $stats['family_members'] === 1 ? '' : 's'; ?>
                    </p>
                    <div class="family-actions">
                        <a class="family-button family-button-primary" href="/calendar/">Open Calendar</a>
                        <?php if ($inviteLink): ?>
                        <button class="family-button" type="button" onclick="copyInviteLink()">
                            <span aria-hidden="true">📨</span> Invite family
                        </button>
                        <input type="hidden" id="homeInviteLink" value="<?php echo htmlspecialchars($inviteLink); ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <?php $dayPct = round((((int)date('H')) * 3600 + ((int)date('i')) * 60 + (int)date('s')) / 864, 1); ?>
                <div class="hero-clock" aria-hidden="true">
                    <div class="clock-ring" id="clockRing" style="--day: <?php echo $dayPct; ?>">
                        <div class="clock-core">
                            <span class="clock-hm" id="clockHM"><?php echo date('H:i'); ?></span>
                            <span class="clock-sec" id="clockSec"><?php echo date('s'); ?></span>
                        </div>
                    </div>
                    <span class="clock-cap" id="dayCap"><?php echo round($dayPct); ?>% of today</span>
                </div>
            </div>
        </section>

        <section class="apps-section" aria-labelledby="family-apps-heading">
            <h2 id="family-apps-heading" class="home-section-title family-section-title">Family apps</h2>
            <nav class="launcher" aria-labelledby="family-apps-heading">
                <?php foreach ($launcherApps as $appKey => $app): ?>
                <a class="launch-tile" href="<?= $app['href'] ?>" data-app="<?= $appKey ?>" style="--glow: <?= $app['glow'] ?>">
                    <?php if ($appKey === 'weather'): ?>
                    <span class="launch-rain" aria-hidden="true">
                        <?php for ($drop = 0; $drop < 16; $drop++): ?>
                        <span style="--drop: <?= $drop ?>"></span>
                        <?php endfor; ?>
                    </span>
                    <?php endif; ?>
                    <span class="launch-copy">
                        <span class="launch-label"><?= $app['label'] ?></span>
                        <span class="launch-summary"><?= $app['summary'] ?></span>
                    </span>
                    <span class="launch-art" aria-hidden="true">
                        <?php if ($appKey === 'shopping'): ?>
                        <span class="launch-trolley"><span class="launch-basket"></span></span>
                        <?php elseif ($appKey === 'weather'): ?>
                        <span class="launch-cloud"></span>
                        <?php else: ?>
                        <span class="launch-icon"><?= $app['icon'] ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="launch-arrow" aria-hidden="true">→</span>
                </a>
                <?php endforeach; ?>
            </nav>
        </section>

        <!-- ============ BENTO GRID ============ -->
        <div class="bento">

            <!-- Up next -->
            <section class="panel panel-today" style="--glow:#34d399">
                <header class="panel-head">
                    <span class="panel-glyph" aria-hidden="true">📅</span>
                    <h2>Up next</h2>
                    <a class="panel-link" href="/calendar/">Calendar →</a>
                </header>
                <?php if (!empty($upcomingEvents)): ?>
                <ul class="event-list">
                    <?php foreach ($upcomingEvents as $event):
                        $eventTime = strtotime($event['starts_at']);
                        $diff = $eventTime - time();
                        $isToday = date('Y-m-d', $eventTime) === date('Y-m-d');
                        $isTomorrow = date('Y-m-d', $eventTime) === date('Y-m-d', strtotime('+1 day'));

                        if ($diff < 3600 && $diff > 0) {
                            $when = 'In ' . ceil($diff / 60) . ' min';
                            $tone = 'is-urgent';
                        } elseif ($isToday) {
                            $when = 'Today ' . date('H:i', $eventTime);
                            $tone = 'is-today';
                        } elseif ($isTomorrow) {
                            $when = 'Tomorrow ' . date('H:i', $eventTime);
                            $tone = '';
                        } else {
                            $when = date('D j M, H:i', $eventTime);
                            $tone = '';
                        }
                    ?>
                    <li class="event-row <?php echo $tone; ?>" data-epoch="<?php echo $eventTime; ?>">
                        <span class="event-when"><?php echo $when; ?></span>
                        <div class="event-body">
                            <span class="event-name"><?php echo htmlspecialchars($event['title']); ?></span>
                            <?php if ($event['location']): ?>
                            <span class="event-where"><span aria-hidden="true">📍</span> <?php echo htmlspecialchars($event['location']); ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="panel-empty">
                    <span aria-hidden="true">🌴</span>
                    <p>Nothing scheduled — enjoy the calm.</p>
                    <a href="/calendar/" class="pill-btn pill-ghost">Plan something</a>
                </div>
                <?php endif; ?>
            </section>

            <!-- This week pulse -->
            <section class="panel panel-pulse" style="--glow:#fbbf24">
                <header class="panel-head">
                    <span class="panel-glyph" aria-hidden="true">⚡</span>
                    <h2>This week</h2>
                </header>
                <div class="pulse-grid">
                    <div class="pulse-tile">
                        <span class="pulse-num" data-count="<?php echo $stats['completed_tasks']; ?>"><?php echo $stats['completed_tasks']; ?></span>
                        <span class="pulse-label">✅ Items ticked off</span>
                    </div>
                    <a class="pulse-tile" href="/shopping/">
                        <span class="pulse-num" data-count="<?php echo $stats['active_lists']; ?>"><?php echo $stats['active_lists']; ?></span>
                        <span class="pulse-label">🛒 Active lists</span>
                    </a>
                    <a class="pulse-tile" href="/notes/">
                        <span class="pulse-num" data-count="<?php echo $stats['notes_count']; ?>"><?php echo $stats['notes_count']; ?></span>
                        <span class="pulse-label">📝 Family notes</span>
                    </a>
                    <a class="pulse-tile" href="/calendar/">
                        <span class="pulse-num" data-count="<?php echo $stats['upcoming_events']; ?>"><?php echo $stats['upcoming_events']; ?></span>
                        <span class="pulse-label">📅 Events, 7 days</span>
                    </a>
                </div>
            </section>

            <!-- Family presence -->
            <section class="panel panel-family" style="--glow:#f472b6">
                <header class="panel-head">
                    <span class="panel-glyph" aria-hidden="true">👨‍👩‍👧‍👦</span>
                    <h2>Family</h2>
                    <a class="panel-link" href="/profile/">Profile →</a>
                </header>
                <ul class="family-list">
                    <?php foreach ($familyMembers as $member):
                        $lastActive = !empty($member['last_active']) ? strtotime($member['last_active']) : false;
                        $isOnline = $lastActive !== false && (time() - $lastActive) < 300;
                        if ($isOnline) {
                            $seen = 'Online';
                        } elseif ($lastActive === false) {
                            $seen = 'Offline';
                        } else {
                            $sd = time() - $lastActive;
                            if ($sd < 3600) { $seen = floor($sd / 60) . 'm ago'; }
                            elseif ($sd < 86400) { $seen = floor($sd / 3600) . 'h ago'; }
                            else { $seen = date('j M', $lastActive); }
                        }
                    ?>
                    <li class="family-row">
                        <span class="family-avatar <?php echo $isOnline ? 'is-on' : ''; ?>" style="background: <?php echo htmlspecialchars($member['avatar_color']); ?>">
                            <?php
                            $avatarPath = __DIR__ . "/../saves/{$member['id']}/avatar/avatar.webp";
                            if (file_exists($avatarPath)):
                            ?>
                            <img src="<?php echo avatarUrl($member['id']); ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                            <?php endif; ?>
                            <i class="presence <?php echo $isOnline ? 'on' : ''; ?>" aria-hidden="true"></i>
                        </span>
                        <div class="family-meta">
                            <span class="family-name"><?php echo htmlspecialchars($member['full_name']); ?></span>
                            <span class="family-role"><?php echo ucfirst(htmlspecialchars($member['role'])); ?></span>
                        </div>
                        <span class="family-seen <?php echo $isOnline ? 'on' : ''; ?>"><?php echo $seen; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="weather-card panel-weather" aria-labelledby="home-weather-title">
                <h2 id="home-weather-title">Weather</h2>
                <a class="hero-weather" id="heroWeather" href="/weather/" aria-label="Open weather forecast">
                    <div class="wx-skeleton">
                        <span class="wx-skel-icon" aria-hidden="true">🌤️</span>
                        <span class="wx-skel-bar"></span>
                        <span class="wx-skel-bar short"></span>
                    </div>
                </a>
            </section>

            <!-- Recent activity -->
            <?php if (!empty($recentActivity)): ?>
            <section class="panel panel-activity" style="--glow:#38bdf8">
                <header class="panel-head">
                    <span class="panel-glyph" aria-hidden="true">🛰️</span>
                    <h2>Latest in the family</h2>
                </header>
                <ul class="feed">
                    <?php foreach ($recentActivity as $activity):
                        $time = strtotime($activity['created_at']);
                        $ad = time() - $time;
                        if ($ad < 60) { $ago = 'Just now'; }
                        elseif ($ad < 3600) { $ago = floor($ad / 60) . 'm ago'; }
                        elseif ($ad < 86400) { $ago = floor($ad / 3600) . 'h ago'; }
                        else { $ago = date('j M, H:i', $time); }
                    ?>
                    <li class="feed-row">
                        <span class="feed-avatar" style="background: <?php echo htmlspecialchars($activity['avatar_color'] ?? '#8b5cf6'); ?>" aria-hidden="true">
                            <?php
                            $activityAvatarPath = __DIR__ . "/../saves/{$activity['user_id']}/avatar/avatar.webp";
                            if (!empty($activity['user_id']) && file_exists($activityAvatarPath)):
                            ?>
                            <img src="<?php echo avatarUrl($activity['user_id']); ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <?php echo strtoupper(substr($activity['full_name'] ?? '?', 0, 1)); ?>
                            <?php endif; ?>
                        </span>
                        <p class="feed-text">
                            <strong><?php echo htmlspecialchars($activity['full_name'] ?? 'Someone'); ?></strong>
                            <?php echo htmlspecialchars(str_replace('_', ' ', $activity['action'])); ?>
                            <?php if ($activity['entity_type']): ?>
                            <em class="feed-tag"><?php echo htmlspecialchars($activity['entity_type']); ?></em>
                            <?php endif; ?>
                        </p>
                        <span class="feed-time"><?php echo $ago; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

        </div>
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

<script><?php readfile(__DIR__ . '/js/home.js'); ?></script>
<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
