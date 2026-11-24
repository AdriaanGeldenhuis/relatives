<?php
declare(strict_types=1);

$activeTab = 'privacy';
$pageTitle = 'Privacy Policy | Relatives';
$legalHeading = 'Privacy policy';
$legalSummary = 'How Relatives handles your personal information and how you can manage it.';
require __DIR__ . '/layout-start.php';
?>
        <article class="legal-document">
            <?php require __DIR__ . '/tabs/privacy.php'; ?>
        </article>
    </main>
</body>
</html>
