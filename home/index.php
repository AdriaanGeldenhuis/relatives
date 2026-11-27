<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';

header('Cache-Control: private, no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../weather/location.php';
require_once __DIR__ . '/../shared/components/welcome-sky.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
} catch (PDOException $e) {
    error_log('Home page error: ' . $e->getMessage());
    http_response_code(503);
    echo 'Home is temporarily unavailable. Please try again.';
    exit;
}

if (!$user) {
    unset($_SESSION['user_id']);
    header('Location: /login.php?session_expired=1', true, 302);
    exit;
}

$userLocation = weatherTrackedLocation($db, (int)$user['id'], (int)$user['family_id'])
    ?? weatherSavedCity($db, (int)$user['id']);

$stats = [
    'shopping_items' => 0,
    'notes_count' => 0,
    'upcoming_events' => 0,
    'unread_messages' => 0,
    'completed_tasks' => 0,
    'active_lists' => 0
];

try {
    $stmt = $db->prepare("
        SELECT COUNT(CASE WHEN si.status = 'pending' THEN 1 END) AS shopping_items,
               COUNT(CASE WHEN si.status = 'bought' THEN 1 END) AS completed_tasks,
               COUNT(DISTINCT CASE WHEN si.status = 'pending' THEN sl.id END) AS active_lists
        FROM shopping_items si
        JOIN shopping_lists sl ON si.list_id = sl.id
        WHERE sl.family_id = ?
          AND (si.status = 'pending' OR (si.status = 'bought' AND si.bought_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)))
    ");
    $stmt->execute([$user['family_id']]);
    foreach ($stmt->fetch(PDO::FETCH_ASSOC) as $key => $count) {
        $stats[$key] = (int)$count;
    }
} catch (Exception $e) {
    error_log('Home shopping stats: ' . $e->getMessage());
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
          AND (starts_at >= NOW() OR (all_day = 1 AND starts_at >= CURDATE()))
          AND starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
          AND status = 'pending'
    ");
    $stmt->execute([$user['family_id']]);
    $stats['upcoming_events'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Events error: ' . $e->getMessage());
}

// Get family invite code for invite link
$inviteLink = '';
try {
    $stmt = $db->prepare("SELECT invite_code FROM families WHERE id = ?");
    $stmt->execute([$user['family_id']]);
    $inviteCode = $stmt->fetchColumn();
    if ($inviteCode) {
        $inviteLink = rtrim(APP_URL, '/') . '/register-join.php?code=' . rawurlencode($inviteCode);
    }
} catch (Exception $e) {
    error_log('Invite code error: ' . $e->getMessage());
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM messages m
        WHERE m.family_id = ?
          AND m.user_id != ?
          AND m.deleted_at IS NULL
          AND (m.expires_at IS NULL OR m.expires_at > NOW())
          AND NOT EXISTS (SELECT 1 FROM message_reads mr WHERE mr.message_id = m.id AND mr.user_id = ?)
    ");
    $stmt->execute([$user['family_id'], $user['id'], $user['id']]);
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
        LEFT JOIN users u ON al.user_id = u.id AND al.family_id = u.family_id
        WHERE al.family_id = ?
        ORDER BY al.created_at DESC, al.id DESC
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
            id,
            title,
            starts_at,
            location,
            all_day
        FROM events
        WHERE family_id = ?
          AND (starts_at >= NOW() OR (all_day = 1 AND starts_at >= CURDATE()))
          AND status = 'pending'
        ORDER BY starts_at, id
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
        SELECT u.id, u.full_name, u.avatar_color, u.role,
               CASE WHEN COALESCE(p.show_last_seen, 1) = 1 THEN u.last_login END AS last_login
        FROM users u
        LEFT JOIN user_privacy_settings p ON p.user_id = u.id
        WHERE u.family_id = ? AND u.status = 'active'
        ORDER BY u.full_name, u.id
    ");
    $stmt->execute([$user['family_id']]);
    $familyMembers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Members error: ' . $e->getMessage());
}

// Initial greeting and clock use the server timezone until the browser takes over.
$now = new DateTimeImmutable();
$hour = (int)$now->format('G');
$light = $hour >= 6 && $hour < 18 ? 'day' : 'night';
if ($hour < 12) {
    $greeting = 'Good morning';
    $greetingColor = 'morning';
} elseif ($hour < 18) {
    $greeting = 'Good afternoon';
    $greetingColor = 'afternoon';
} else {
    $greeting = 'Good evening';
    $greetingColor = 'evening';
}

$firstName = preg_split('/\s+/u', trim($user['name']), 2)[0];
$showWelcome = isset($_GET['welcome']) || isset($_GET['joined']);
$memberCount = count($familyMembers);
$today = $now->format('Y-m-d');
$tomorrow = $now->modify('+1 day')->format('Y-m-d');

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
    'tracking' => ['href' => '/tracking/app/', 'label' => 'Tracking', 'summary' => 'Family locations'],
    'weather' => ['href' => '/weather/', 'label' => 'Weather', 'summary' => 'Local forecast'],
    'notes' => ['href' => '/notes/', 'label' => 'Notes', 'summary' => 'Shared notes'],
    'shopping' => ['href' => '/shopping/', 'label' => 'Shopping', 'summary' => $shoppingSummary],
    'calendar' => ['href' => '/calendar/', 'label' => 'Calendar', 'summary' => $calendarSummary],
    'schedule' => ['href' => '/schedule/', 'label' => 'Schedule', 'summary' => 'Daily agenda'],
    'travel' => ['href' => '/holiday_traveling/', 'label' => 'Travel', 'summary' => 'Trips and itineraries'],
    'games' => ['href' => '/games/', 'label' => 'Games', 'summary' => 'Family arcade'],
    'messages' => ['href' => '/messages/', 'label' => 'Messages', 'summary' => $messageSummary],
];

$pageTitle = 'Home';
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
            <span aria-hidden="true">🎉</span>
            <div class="welcome-copy">
                <strong>Welcome to <?php echo htmlspecialchars($user['family_name']); ?>!</strong>
                <span>You're in. This is your family's command center.</span>
            </div>
            <button class="welcome-close" type="button" aria-label="Dismiss welcome message">✕</button>
        </div>
        <?php endif; ?>

        <section class="panel home-tracking-setup" id="homeTrackingSetup"
                 data-account="<?= (int)$user['id'] ?>" aria-labelledby="homeTrackingSetupTitle" hidden>
            <h2 class="family-section-title" id="homeTrackingSetupTitle">Set up family tracking</h2>
            <p>Choose whether to share this phone’s location with your family. You can stop sharing any time in Tracking.</p>
            <div class="family-actions">
                <a class="family-button family-button-primary" href="/tracking/app/?setup=1">Set up tracking</a>
                <button class="family-button" type="button">Not now</button>
            </div>
        </section>

        <section class="hero family-hero sky-hero welcome-hero" id="homeWelcome" aria-labelledby="homeWelcomeTitle"
                 data-time="<?= $greetingColor ?>" data-light="<?= $light ?>">
            <?php renderWelcomeLandscape(); ?>
            <div class="welcome-home-layout">
                <div class="hero-greeting family-hero-content">
                    <p class="hero-kicker">
                        <span id="heroDate"><?= $now->format('l, j F Y') ?></span>
                    </p>
                    <h1 class="family-hero-title" id="homeWelcomeTitle">
                        <span id="heroGreeting"><?= $greeting ?></span>, <span class="hero-name"><?php echo htmlspecialchars($firstName); ?></span>
                    </h1>
                    <p class="family-hero-summary">
                        The <?php echo htmlspecialchars($user['family_name']); ?> hub
                        <span class="dot-sep" aria-hidden="true">·</span>
                        <?= $memberCount ?> member<?= $memberCount === 1 ? '' : 's' ?>
                    </p>
                    <div class="family-actions">
                        <a class="family-button family-button-primary" href="/calendar/">Open Calendar</a>
                        <?php if ($inviteLink): ?>
                        <button class="family-button" type="button" id="homeInviteCopy" aria-controls="homeInviteLink">
                            Invite family
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($inviteLink): ?>
                    <label class="home-invite-link" id="homeInviteLink" hidden>
                        Invite link
                        <input type="url" readonly value="<?= htmlspecialchars($inviteLink) ?>">
                    </label>
                    <?php endif; ?>
                </div>

                <?php renderWelcomeWeather(); ?>
            </div>
        </section>

        <section class="panel home-up-next" style="--glow:#a4c8d4">
            <header class="panel-head">
                <span class="panel-glyph" aria-hidden="true">📅</span>
                <h2>Up next</h2>
                <a class="panel-link" href="/schedule/">Day agenda →</a>
            </header>
            <?php if (!empty($upcomingEvents)): ?>
            <?php foreach ([array_slice($upcomingEvents, 0, 1), array_slice($upcomingEvents, 1)] as $group => $items):
                if (!$items) continue;
                ?>
                <?php if ($group): ?><details class="home-more-events"><summary>More upcoming events</summary><?php endif; ?>
                <ul class="event-list">
                <?php foreach ($items as $event):
                    $startsAt = new DateTimeImmutable($event['starts_at']);
                    $diff = $startsAt->getTimestamp() - $now->getTimestamp();
                    $eventDate = $startsAt->format('Y-m-d');
                    $dayLabel = match ($eventDate) {
                        $today => 'Today',
                        $tomorrow => 'Tomorrow',
                        default => $startsAt->format('D j M'),
                    };
                    $tone = $eventDate === $today ? 'is-today' : '';
                    if ($event['all_day']) {
                        $when = $dayLabel . ' · all day';
                    } elseif ($diff < 3600 && $diff > 0) {
                        $when = 'In ' . ceil($diff / 60) . ' min';
                        $tone = 'is-urgent';
                    } else {
                        $when = $dayLabel . ' ' . $startsAt->format('H:i');
                    }
                ?>
                <li class="event-row <?= $tone ?>">
                    <time class="event-when" datetime="<?= $startsAt->format($event['all_day'] ? 'Y-m-d' : DATE_ATOM) ?>"<?= $event['all_day'] ? '' : ' data-event-start' ?>><?= $when ?></time>
                    <div class="event-body">
                        <a class="event-name" href="/calendar/?event=<?= (int)$event['id'] ?>"><?= htmlspecialchars($event['title']) ?></a>
                        <?php if ($event['location']): ?>
                        <span class="event-where"><span aria-hidden="true">📍</span> <?php echo htmlspecialchars($event['location']); ?></span>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
                </ul>
                <?php if ($group): ?></details><?php endif; ?>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="panel-empty">
                <p>Nothing scheduled</p>
                <a href="/schedule/add.php" class="btn btn-primary">Add an event</a>
            </div>
            <?php endif; ?>
        </section>

        <section class="apps-section" aria-labelledby="family-apps-heading">
            <div class="apps-heading">
                <h2 id="family-apps-heading" class="home-section-title family-section-title">Family apps</h2>
                <button type="button" class="launch-motion-toggle" aria-controls="family-apps-list homeWelcome" hidden>Pause motion</button>
            </div>
            <nav class="launcher" id="family-apps-list" aria-labelledby="family-apps-heading">
                <?php foreach ($launcherApps as $appKey => $app): ?>
                <a class="launch-tile" href="<?= $app['href'] ?>" data-app="<?= $appKey ?>">
                    <span class="launch-copy">
                        <span class="launch-label"><?= $app['label'] ?></span>
                        <span class="launch-summary"><?= $app['summary'] ?></span>
                    </span>
                    <?php renderFamilyAppArt($appKey); ?>
                    <span class="launch-arrow" aria-hidden="true">→</span>
                </a>
                <?php endforeach; ?>
            </nav>
        </section>

        <!-- ============ BENTO GRID ============ -->
        <div class="bento">

            <!-- This week pulse -->
            <section class="panel" style="--glow:#fbbf24">
                <header class="panel-head">
                    <span class="panel-glyph" aria-hidden="true">⚡</span>
                    <h2>This week</h2>
                </header>
                <div class="pulse-grid">
                    <div class="pulse-tile">
                        <span class="pulse-num"><?= $stats['completed_tasks'] ?></span>
                        <span class="pulse-label">✅ Items ticked off</span>
                    </div>
                    <a class="pulse-tile" href="/shopping/">
                        <span class="pulse-num"><?= $stats['active_lists'] ?></span>
                        <span class="pulse-label">🛒 Active lists</span>
                    </a>
                    <a class="pulse-tile" href="/notes/">
                        <span class="pulse-num"><?= $stats['notes_count'] ?></span>
                        <span class="pulse-label">📝 Family notes</span>
                    </a>
                    <a class="pulse-tile" href="/calendar/">
                        <span class="pulse-num"><?= $stats['upcoming_events'] ?></span>
                        <span class="pulse-label">📅 Events, 7 days</span>
                    </a>
                </div>
            </section>

            <section class="panel" style="--glow:#f472b6">
                <header class="panel-head">
                    <span class="panel-glyph" aria-hidden="true">👨‍👩‍👧‍👦</span>
                    <h2>Family</h2>
                    <a class="panel-link" href="/profile/">Profile →</a>
                </header>
                <ul class="family-list">
                    <?php foreach ($familyMembers as $member):
                        $lastLogin = $member['last_login'] ? new DateTimeImmutable($member['last_login']) : null;
                    ?>
                    <li class="family-row">
                        <span class="family-avatar" style="background-color: <?= htmlspecialchars($member['avatar_color'] ?? '#667eea') ?>" aria-hidden="true">
                            <?php if (is_file(avatarPath($member['id']))): ?>
                            <img src="<?= htmlspecialchars(avatarUrl($member['id'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($member['full_name'], 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <div class="family-meta">
                            <span class="family-name"><?php echo htmlspecialchars($member['full_name']); ?></span>
                            <span class="family-role"><?php echo ucfirst(htmlspecialchars($member['role'])); ?></span>
                        </div>
                        <?php if ($lastLogin): ?>
                        <span class="family-seen">Last active <time datetime="<?= $lastLogin->format(DATE_ATOM) ?>"><?= $lastLogin->format('j M') ?></time></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
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
                            <?php if ($activity['user_id'] && is_file(avatarPath($activity['user_id']))): ?>
                            <img src="<?= htmlspecialchars(avatarUrl($activity['user_id'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($activity['full_name'] ?? '?', 0, 1))) ?>
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

<script type="application/json" id="homeWeatherLocation"><?= json_encode([
    'saved' => $userLocation, 'default' => WEATHER_DEFAULT_PLACE,
], JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) ?></script>

<script><?php readfile(__DIR__ . '/js/home.js'); ?></script>
<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
