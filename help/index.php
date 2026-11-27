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
$pageInlineStylesheets = [__DIR__ . '/css/help.css'];
require __DIR__ . '/../shared/components/header.php';
?>
<main class="family-main">
    <header class="family-hero photo-hero photo-hero-compact">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Help</h1>
            <p class="family-hero-summary">Set up your family and find answers to common questions.</p>
            <label class="help-search" for="helpSearch">Find a help topic
                <input id="helpSearch" type="search" placeholder="Search help" autocomplete="off">
            </label>
        </div>
        <?php renderFamilyScene('family'); ?>
    </header>
    <section class="family-panel help-topics" aria-labelledby="help-topics">
        <h2 class="family-section-title" id="help-topics">Common tasks</h2>
        <details>
            <summary>Invite family</summary>
            <p>Select Invite family on the family hub and share the link. Your family owner can replace the invite code in Family administration.</p>
            <a href="/home/">Open your family hub</a>
        </details>
        <details>
            <summary>Plan an event together</summary>
            <p>Add an event from Calendar or Schedule. Both show family events, and you can link a note to an event to keep the details together.</p>
            <a href="/schedule/add.php">Add an event</a>
        </details>
        <details>
            <summary>Find the shopping items assigned to you</summary>
            <p>Choose a shopping list and select For me. Assign an item from its edit menu, or select several items and choose Assign.</p>
            <a href="/shopping/">Open Shopping</a>
        </details>
        <details>
            <summary>Make a photo collage</summary>
            <p>Choose photos in the collage builder, arrange them, and download the result. Add that image to a note to share it with your family.</p>
            <a href="/collage/">Open the collage builder</a>
        </details>
        <details>
            <summary>See weather for another city</summary>
            <p>Choose Use my location on the family hub or pick a city in Weather. The Live sky on the hub follows the current conditions for its displayed location.</p>
            <a href="/weather/">Open Weather</a>
        </details>
        <details>
            <summary>Keep your travel details together</summary>
            <p>Create a trip, then open Packing for your checklist, Wallet for reservations, or Expenses to record shared costs.</p>
            <a href="/holiday_traveling/">Open Travel</a>
        </details>
        <details>
            <summary>Set up notifications on this device</summary>
            <p>Open notification settings to enable or test alerts on this device. On an iPhone or iPad, add Relatives to your Home Screen before enabling alerts.</p>
            <a href="/profile/notifications.php">Open device notification settings</a>
        </details>
        <details>
            <summary>Location and Wake</summary>
            <p>Set up sharing on each phone that wants to share. The Android app asks for location and the tracking notification. Choose Allow all the time if you want the phone to respond to refresh requests while the app is closed. In a browser, keep the map visible for updates.</p>
            <a href="/tracking/app/?setup=1">Set up family tracking</a>
        </details>
        <p id="helpEmpty" role="status" hidden>No help topics match your search.</p>
    </section>
</main>
<script>
(() => {
    const search = document.getElementById('helpSearch');
    const topics = Array.from(document.querySelectorAll('.help-topics details'));
    const empty = document.getElementById('helpEmpty');
    search.addEventListener('input', () => {
        const words = search.value.toLocaleLowerCase().trim().split(/\s+/).filter(Boolean);
        for (const topic of topics) {
            const content = topic.textContent.toLocaleLowerCase();
            topic.hidden = !words.every(word => content.includes(word));
        }
        empty.hidden = topics.some(topic => !topic.hidden);
    });
})();
</script>
<?php require __DIR__ . '/../shared/components/footer.php'; ?>
