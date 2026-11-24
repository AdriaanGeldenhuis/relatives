<?php
$sections = [
    ['id' => 'activeTrips', 'title' => 'Active trips', 'trips' => $activeTrips],
    ['id' => 'upcomingTrips', 'title' => 'Upcoming trips', 'trips' => $upcomingTrips],
    ['id' => 'pastTrips', 'title' => 'Past trips', 'trips' => $pastTrips]
];
?>

<div class="ht-dashboard">
    <header class="family-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Holiday &amp; traveling</h1>
            <p class="family-hero-summary">Plan family trips and keep your bookings, packing and expenses together.</p>
            <div class="family-actions">
                <a href="/holiday_traveling/trip_create.php" class="family-button family-button-primary">＋ New trip</a>
                <?php if ($activeTrips): ?>
                    <a href="#activeTrips" class="family-button">Active trips</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="family-hero-art ht-hero-art" aria-hidden="true">
            <span class="ht-art-paper ht-art-back"></span>
            <span class="ht-art-paper ht-art-front"><i></i><i></i><i></i></span>
        </div>
    </header>

    <?php if (!$trips): ?>
        <section class="ht-empty-state ht-board-empty">
            <h2 class="family-section-title">No trips yet</h2>
            <p class="ht-empty-description">Add a destination and travel dates to get started.</p>
            <a href="/holiday_traveling/trip_create.php" class="ht-btn ht-btn-primary">Create a trip</a>
        </section>
    <?php else: ?>
        <nav class="ht-board-nav" aria-label="Trip sections">
            <?php foreach ($sections as $section): ?>
                <?php if (!$section['trips']) continue; ?>
                <a href="#<?= $section['id'] ?>"><?= $section['title'] ?> <span><?= count($section['trips']) ?></span></a>
            <?php endforeach; ?>
        </nav>

        <?php foreach ($sections as $section): ?>
            <?php if (!$section['trips']) continue; ?>
            <section class="ht-board-section" id="<?= $section['id'] ?>" aria-labelledby="<?= $section['id'] ?>Heading">
                <h2 class="family-section-title" id="<?= $section['id'] ?>Heading"><?= $section['title'] ?></h2>
                <div class="ht-board-grid">
                    <?php foreach ($section['trips'] as $trip): ?>
                        <?php
                        $id = (int)$trip['id'];
                        $active = $trip['status'] === 'active';
                        $travelers = (int)$trip['travelers_count'];
                        $tripTitle = trim($trip['title']);
                        ?>
                        <article class="ht-trip-card ht-board-card" data-status="<?= htmlspecialchars($trip['status']) ?>">
                            <div class="ht-board-card-top">
                                <?= ht_status_badge($trip['status']) ?>
                                <?php if ($trip['active_plan_version']): ?>
                                    <span class="ht-plan-badge">Plan v<?= (int)$trip['active_plan_version'] ?></span>
                                <?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($trip['destination']) ?></h3>
                            <?php if ($tripTitle !== '' && strcasecmp($tripTitle, $trip['destination']) !== 0): ?>
                                <p class="ht-board-trip-title"><?= htmlspecialchars($tripTitle) ?></p>
                            <?php endif; ?>
                            <div class="ht-board-card-meta">
                                <span><?= ht_format_date($trip['start_date'], substr($trip['start_date'], 0, 4) === substr($trip['end_date'], 0, 4) ? 'j M' : 'j M Y') ?> – <?= ht_format_date($trip['end_date'], 'j M Y') ?></span>
                                <span><?= $travelers ?> <?= $travelers === 1 ? 'traveler' : 'travelers' ?></span>
                                <?php if ($trip['budget_comfort']): ?>
                                    <span><?= htmlspecialchars(ht_format_currency((float)$trip['budget_comfort'], $trip['budget_currency'])) ?> budget</span>
                                <?php endif; ?>
                            </div>
                            <div class="ht-board-card-actions">
                                <a href="/holiday_traveling/trip_view.php?id=<?= $id ?>" class="ht-btn ht-btn-secondary">View trip</a>
                                <?php if ($active): ?>
                                    <a href="/holiday_traveling/wallet_view.php?id=<?= $id ?>" class="ht-btn ht-btn-outline">Wallet</a>
                                    <a href="/holiday_traveling/expenses_view.php?id=<?= $id ?>" class="ht-btn ht-btn-outline">Expenses</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
