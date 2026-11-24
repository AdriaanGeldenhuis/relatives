<?php
$navigationPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navigationArea = explode('/', trim($navigationPath, '/'))[0];
$navigationUser = $user ?? null;
$navigationGroups = $navigationUser ? [
    'Family' => [
        'home' => ['/home/', 'Home', '🏠'], 'messages' => ['/messages/', 'Messages', '💬'],
        'notes' => ['/notes/', 'Notes', '📝'], 'shopping' => ['/shopping/', 'Shopping', '🛒'],
        'calendar' => ['/calendar/', 'Calendar', '📅'], 'schedule' => ['/schedule/', 'Schedule', '⏰'],
    ],
    'Explore' => [
        'weather' => ['/weather/', 'Weather', '🌤️'], 'tracking' => ['/tracking/app/', 'Tracking', '📍'],
        'holiday_traveling' => ['/holiday_traveling/', 'Holiday & Traveling', '✈️'], 'games' => ['/games/', 'Games', '🎮'],
    ],
    'Account' => ['profile' => ['/profile/', 'Profile & settings', '👤'], 'legal' => ['/legal/', 'Legal', '⚖️']],
] : [
    'Account' => [
        'login.php' => ['/login.php', 'Sign in', '👤'],
        'register-create.php' => ['/register-create.php', 'Create a family', '🏠'],
        'register-join.php' => ['/register-join.php', 'Join a family', '✉️'],
    ],
    'Legal' => ['legal' => ['/legal/', 'Legal', '⚖️'], 'privacy' => ['/legal/privacy.php', 'Privacy policy', '🔒'],
        'deletion' => ['/legal/delete-account.php', 'Delete account', ''],
    ],
];
if ($navigationUser && in_array($navigationUser['role'] ?? '', ['admin', 'owner'], true)) {
    $navigationGroups['Account']['admin'] = ['/admin/', 'Admin', '⚙️'];
}
$navigationHome = $navigationUser ? '/home/' : '/';
?>
<header class="global-header site-header">
    <div class="header-container">
        <div class="header-left">
            <button type="button" class="site-control" id="hamburgerMenuBtn" aria-label="Open menu" aria-controls="mobileSidebar" aria-expanded="false">
                <span class="site-menu-mark" aria-hidden="true"></span><span class="site-menu-label">Menu</span>
            </button>
            <a class="site-wordmark" href="<?= $navigationHome ?>" aria-label="Relatives home">Relatives<span>.</span></a>
        </div>
        <div class="header-right">
            <?php if ($navigationUser): ?>
                <button type="button" class="site-control" id="siteBack" aria-label="Go back" title="Go back">←</button>
                <div class="notification-bell">
                    <a href="/notifications/" class="site-control" id="notificationBell" aria-label="Notifications" title="Notifications">🔔</a>
                    <span class="notification-badge" id="notificationBadge" aria-hidden="true" hidden></span>
                </div>
                <a href="/profile/" class="site-control site-account" aria-label="Profile &amp; settings" title="Profile &amp; settings"><?= htmlspecialchars(mb_strtoupper(mb_substr($navigationUser['name'] ?? $navigationUser['full_name'] ?? 'R', 0, 1))) ?></a>
            <?php else: ?>
                <a class="site-control site-sign-in" href="/login.php">Sign in</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<dialog class="site-menu" id="mobileSidebar" aria-labelledby="siteMenuTitle">
    <div class="site-menu-head">
        <h2 id="siteMenuTitle">Relatives<span>.</span></h2>
        <button type="button" class="site-control" id="closeSidebarBtn" autofocus aria-label="Close menu">Close</button>
    </div>
    <nav class="site-menu-nav" aria-label="Main navigation">
        <?php foreach ($navigationGroups as $group => $links): ?>
            <div class="site-menu-group">
                <h3><?= $group ?></h3>
                <?php foreach ($links as $key => [$url, $label, $icon]): ?>
                    <?php
                    $current = $navigationUser ? $navigationArea === $key : (
                        $navigationPath === $url || ($key === 'legal' && $navigationPath === '/legal/index.php')
                    );
                    ?>
                    <a href="<?= $url ?>"<?= $current ? ' aria-current="page"' : '' ?>>
                        <?php if ($icon): ?><span aria-hidden="true"><?= $icon ?></span><?php endif; ?>
                        <?= htmlspecialchars($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <?php if ($navigationUser): ?>
        <div class="site-menu-foot">
            <span><?= htmlspecialchars($navigationUser['name'] ?? $navigationUser['full_name'] ?? 'Your account') ?></span>
            <a href="/logout.php" class="site-control">Sign out</a>
        </div>
    <?php endif; ?>
</dialog>
<script defer src="/shared/js/header.js?v=<?= filemtime(__DIR__ . '/../js/header.js') ?>"></script>
<?php if (!empty($unreadNotifCount)): ?>
    <script>window.unreadNotificationCount = <?= (int)$unreadNotifCount ?>;</script>
<?php endif; ?>
