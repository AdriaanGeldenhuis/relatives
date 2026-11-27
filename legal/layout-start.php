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
require_once __DIR__ . '/../shared/components/family-scenes.php';
require __DIR__ . '/../shared/components/public-head.php';
?>
<body class="family-page legal-page" data-family-theme>
    <style><?php readfile(__DIR__ . '/../shared/css/family-surfaces.css'); readfile(__DIR__ . '/css/legal.css'); ?></style>
    <?php require __DIR__ . '/../shared/components/site-navigation.php'; ?>
    <main class="family-main legal-main">
        <header class="family-hero photo-hero photo-hero-compact">
            <div class="family-hero-content">
                <h1 class="family-hero-title"><?= htmlspecialchars($legalHeading) ?></h1>
                <p class="family-hero-summary"><?= htmlspecialchars($legalSummary) ?></p>
            </div>
            <?php renderFamilyScene('notes'); ?>
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
        <details class="legal-contents" id="legalContents" hidden>
            <summary>On this page</summary>
            <nav aria-label="On this page"></nav>
        </details>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const list = document.getElementById('legalContents');
            const navigation = list.querySelector('nav');
            const sections = document.querySelectorAll('.legal-document h3');
            if (sections.length < 2) return;
            for (const [index, section] of sections.entries()) {
                section.id ||= 'legal-section-' + (index + 1);
                section.tabIndex = -1;
                const link = document.createElement('a');
                link.href = '#' + section.id;
                link.textContent = section.textContent;
                navigation.append(link);
            }
            list.hidden = false;
        });
        </script>
