<?php
$sections = [
    ['id' => 'activeTrips', 'title' => 'Active trips', 'trips' => $activeTrips],
    ['id' => 'upcomingTrips', 'title' => 'Upcoming trips', 'trips' => $upcomingTrips],
    ['id' => 'pastTrips', 'title' => 'Past trips', 'trips' => $pastTrips]
];
$today = new DateTimeImmutable('today');
$todayKey = $today->format('Y-m-d');
$current = array_filter($activeTrips, static fn(array $trip): bool => $trip['start_date'] <= $todayKey && $trip['end_date'] >= $todayKey);
$future = array_values(array_filter($upcomingTrips, static fn(array $trip): bool => $trip['start_date'] >= $todayKey));
usort($future, static fn(array $a, array $b): int => $a['start_date'] <=> $b['start_date']);
$featured = reset($current) ?: ($future[0] ?? null);
$featuredId = (int)($featured['id'] ?? 0);
?>

<div class="ht-dashboard">
    <header class="family-hero app-hero photo-hero photo-hero-wide">
        <div class="family-hero-content">
            <?php if ($featured): ?>
                <span class="hero-kicker-label"><?= $featured['status'] === 'active' ? 'On your trip' : 'Next family trip' ?></span>
                <h1 class="family-hero-title"><?= htmlspecialchars($featured['destination']) ?></h1>
                <?php if ($featured['status'] !== 'active'):
                    $daysToGo = $today->diff(new DateTimeImmutable($featured['start_date']))->days;
                    ?>
                    <p class="ht-hero-countdown"><?= match ($daysToGo) {
                        0 => 'Leaves today',
                        1 => 'Leaves tomorrow',
                        default => $daysToGo . ' days until departure'
                    } ?></p>
                <?php endif; ?>
                <p class="family-hero-summary">
                    <time datetime="<?= htmlspecialchars($featured['start_date']) ?>"><?= ht_format_date($featured['start_date'], 'j M Y') ?></time>
                    –
                    <time datetime="<?= htmlspecialchars($featured['end_date']) ?>"><?= ht_format_date($featured['end_date'], 'j M Y') ?></time>
                </p>
                <div class="family-actions">
                    <a href="/holiday_traveling/trip_view.php?id=<?= $featuredId ?>" class="family-button family-button-primary">Open trip</a>
                    <a href="/holiday_traveling/packing_view.php?id=<?= $featuredId ?>" class="family-button">Packing</a>
                    <a href="/weather/?place=<?= rawurlencode($featured['destination']) ?>" class="family-button">Destination weather</a>
                    <a href="/holiday_traveling/trip_create.php" class="family-button">New trip</a>
                </div>
            <?php else: ?>
                <h1 class="family-hero-title">Travel</h1>
                <p class="family-hero-summary">Plan a family trip and keep your itinerary, packing and bookings together.</p>
                <div class="family-actions">
                    <a href="/holiday_traveling/trip_create.php" class="family-button family-button-primary">＋ New trip</a>
                </div>
            <?php endif; ?>
        </div>
        <?php renderFamilyScene('travel'); ?>
    </header>

    <?php if (!$trips): ?>
        <section class="ht-empty-state ht-board-empty">
            <h2 class="family-section-title">No trips yet</h2>
            <p class="ht-empty-description">Add a destination and travel dates to get started.</p>
        </section>
    <?php else: ?>
        <nav class="ht-board-nav" id="tripBoard" aria-label="Trip sections">
            <a href="#tripBoard" data-trip-filter="all" aria-current="true">All trips <span><?= count($trips) ?></span></a>
            <?php foreach ($sections as $section): ?>
                <?php if (!$section['trips']) continue; ?>
                <a href="#<?= $section['id'] ?>" data-trip-filter="<?= $section['id'] ?>"><?= $section['title'] ?> <span><?= count($section['trips']) ?></span></a>
            <?php endforeach; ?>
        </nav>

        <?php foreach ($sections as $section): ?>
            <?php if (!$section['trips']) continue; ?>
            <section class="ht-board-section" id="<?= $section['id'] ?>" data-trip-section aria-labelledby="<?= $section['id'] ?>Heading">
                <h2 class="family-section-title" id="<?= $section['id'] ?>Heading"><?= $section['title'] ?></h2>
                <div class="ht-board-grid">
                    <?php foreach ($section['trips'] as $trip): ?>
                        <?php
                        $id = (int)$trip['id'];
                        $past = in_array($trip['status'], ['completed', 'cancelled'], true);
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
                                <?php if (!$past): ?>
                                    <a href="/holiday_traveling/packing_view.php?id=<?= $id ?>" class="ht-btn ht-btn-outline">Packing</a>
                                    <a href="/holiday_traveling/wallet_view.php?id=<?= $id ?>" class="ht-btn ht-btn-outline">Wallet</a>
                                <?php endif; ?>
                                <?php if ($trip['status'] !== 'cancelled'): ?>
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
