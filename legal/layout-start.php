<?php
if (!isset($legalHeading, $legalSummary, $activeTab)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../core/session_boot.php';
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/bootstrap.php';
    $user ??= (new Auth($db))->getCurrentUser();
}
require __DIR__ . '/../shared/components/public-head.php';
?>
<body class="family-page legal-page">
    <style><?php readfile(__DIR__ . '/../shared/css/family-surfaces.css'); readfile(__DIR__ . '/css/legal.css'); ?></style>
    <?php require __DIR__ . '/../shared/components/site-navigation.php'; ?>
    <main class="family-main legal-main">
        <header class="family-hero">
            <div class="family-hero-content">
                <h1 class="family-hero-title"><?= htmlspecialchars($legalHeading) ?></h1>
                <p class="family-hero-summary"><?= htmlspecialchars($legalSummary) ?></p>
            </div>
            <div class="family-hero-art" aria-hidden="true"><span class="legal-paper"><i></i><i></i><i></i></span></div>
        </header>
        <nav class="legal-nav" aria-label="Legal documents">
            <?php foreach ([
                'terms' => ['/legal/', 'Terms'],
                'privacy' => ['/legal/privacy.php', 'Privacy policy'],
                'deletion' => ['/legal/delete-account.php', 'Delete account'],
                'location' => ['/legal/?doc=location', 'Location policy'],
                'dpa' => ['/legal/?doc=dpa', 'Data agreement'],
                'cookies' => ['/legal/?doc=cookies', 'Cookies'],
                'eula' => ['/legal/?doc=eula', 'App license'],
            ] as $document => [$href, $label]): ?>
                <a href="<?= $href ?>"<?= $activeTab === $document ? ' aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
