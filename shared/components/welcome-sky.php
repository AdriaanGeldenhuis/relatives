<?php
declare(strict_types=1);

function renderWelcomeSkyAssets(): void
{
    ?>
    <style><?php readfile(__DIR__ . '/../css/welcome-sky.css'); ?></style>
    <script defer src="/shared/js/welcome-sky.js?v=<?= filemtime(__DIR__ . '/../js/welcome-sky.js') ?>"></script>
    <?php
}

function renderWelcomeLandscape(string $light): void
{
    $initial = $light === 'night' ? 'night' : 'sun';
    ?>
    <div class="welcome-scene" aria-hidden="true">
        <div class="welcome-landscape">
            <?php foreach (['sun', 'rain', 'night'] as $scene): ?>
            <img class="welcome-photo welcome-photo-<?= $scene ?>" src="/shared/public/welcome-<?= $scene ?>.webp"
                 width="1536" height="<?= $scene === 'night' ? 341 : 340 ?>" alt=""
                 fetchpriority="<?= $scene === $initial ? 'high' : 'low' ?>">
            <?php endforeach; ?>
        </div>
        <div class="welcome-rain">
            <?php for ($drop = 0; $drop < 20; $drop++): ?>
            <span style="--drop: <?= $drop ?>"></span>
            <?php endfor; ?>
        </div>
        <span class="welcome-sky-glow"></span>
    </div>
    <?php
}

function renderWelcomeSkyControls(): void
{
    ?>
    <div class="welcome-sky-controls" data-sky-controls role="group" aria-label="Welcome sky" hidden>
        <button type="button" data-sky-preview="live" aria-pressed="true"><span aria-hidden="true">●</span> Live</button>
        <button type="button" data-sky-preview="sun" aria-pressed="false" aria-label="Preview sun"><span aria-hidden="true">☀</span> Sun</button>
        <button type="button" data-sky-preview="rain" aria-pressed="false" aria-label="Preview rain"><span aria-hidden="true">☂</span> Rain</button>
        <button type="button" data-sky-preview="night" aria-pressed="false" aria-label="Preview night"><span aria-hidden="true">☾</span> Night</button>
    </div>
    <?php
}
