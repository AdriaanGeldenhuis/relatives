<?php
declare(strict_types=1);

function renderFamilyAppArt(string $appKey, bool $hero = false): void
{
    ?>
    <?php if ($hero): ?>
    <span class="app-hero-scene" data-app="<?= htmlspecialchars($appKey) ?>" aria-hidden="true">
    <?php endif; ?>
        <?php if ($appKey === 'weather'): ?>
        <span class="launch-rain" aria-hidden="true">
            <?php for ($drop = 0; $drop < 16; $drop++): ?>
            <span style="--drop: <?= $drop ?>"></span>
            <?php endfor; ?>
        </span>
        <?php endif; ?>
        <span class="launch-art" aria-hidden="true">
            <?php if ($appKey === 'tracking'): ?>
            <span class="launch-map">
                <span class="launch-map-pin"></span>
            </span>
            <?php elseif ($appKey === 'weather'): ?>
            <span class="launch-cloud"></span>
            <?php elseif ($appKey === 'notes'): ?>
            <span class="launch-note">
                <span class="launch-paper">
                    <span class="launch-ink"></span>
                    <span class="launch-ink"></span>
                </span>
                <span class="launch-pen"></span>
            </span>
            <?php elseif ($appKey === 'shopping'): ?>
            <span class="launch-trolley">
                <span class="launch-basket"></span>
                <span class="launch-wheel"></span>
                <span class="launch-wheel"></span>
            </span>
            <?php elseif ($appKey === 'calendar'): ?>
            <span class="launch-calendar"><span class="launch-calendar-page"></span></span>
            <?php elseif ($appKey === 'messages'): ?>
            <span class="launch-message"><span></span><span></span><span></span></span>
            <?php else: ?>
            <span class="launch-icon"><?= match ($appKey) { 'schedule' => '⏰', 'travel' => '✈️', 'games' => '🎮', 'notifications' => '🔔' } ?></span>
            <?php endif; ?>
        </span>
    <?php if ($hero): ?>
    </span>
    <?php endif;
}
