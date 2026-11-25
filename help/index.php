<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    header('Location: /login.php?redirect=%2Fhelp%2F', true, 303);
    exit;
}
$pageTitle = 'Help';
require __DIR__ . '/../shared/components/header.php';
?>
<main class="family-main">
    <header class="family-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Help</h1>
            <p class="family-hero-summary">Set up your family and find answers to common questions.</p>
        </div>
    </header>
    <section class="family-panel">
        <h2 class="family-section-title">Location and Wake</h2>
        <p>Open Tracking and choose Enable live location on the phone that will share. Android can refresh in the background when you grant background location and keep the tracking notification enabled.</p>
        <p><a href="/tracking/">Open Tracking</a></p>
        <h2 class="family-section-title">Notifications</h2>
        <p>Open notification settings to enable or test alerts on this device. On an iPhone or iPad, add Relatives to your Home Screen before enabling alerts.</p>
        <p><a href="/profile/notifications.php">Set up device notifications</a></p>
        <h2 class="family-section-title">Invite family</h2>
        <p>Family owners and administrators can copy an invite code from Family administration.</p>
        <p><a href="/profile/">Open your profile</a></p>
    </section>
</main>
<?php require __DIR__ . '/../shared/components/footer.php'; ?>
