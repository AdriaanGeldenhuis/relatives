<?php
declare(strict_types=1);

$activeTab = $_GET['doc'] ?? 'terms';
if ($activeTab === 'privacy') {
    require __DIR__ . '/privacy.php';
    exit;
}
if (!in_array($activeTab, ['terms', 'location', 'dpa', 'cookies', 'eula'], true)) {
    $activeTab = 'terms';
}

$pageTitle = 'Legal Documents | Relatives';
$legalHeading = 'Legal documents';
$legalSummary = 'Read our policies and find out how to manage your data.';
require __DIR__ . '/layout-start.php';
?>
        <article class="legal-document">
            <?php require __DIR__ . '/tabs/' . $activeTab . '.php'; ?>
        </article>
    </main>
</body>
</html>
