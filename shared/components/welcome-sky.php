<?php
declare(strict_types=1);

function renderWelcomeSkyAssets(bool $public = false): void
{
    ?>
    <style><?php readfile(__DIR__ . '/../css/welcome-sky.css'); ?></style>
    <?php if ($public): ?>
    <script defer src="/shared/js/welcome-sky.js?v=<?= filemtime(__DIR__ . '/../js/welcome-sky.js') ?>"></script>
    <?php endif; ?>
    <?php
}

function renderWelcomeLandscape(): void
{
    $photos = [];
    foreach (['sun', 'cloud', 'rain', 'night'] as $scene) {
        $asset = "/shared/public/welcome-$scene.webp";
        $version = filemtime(__DIR__ . '/../..' . $asset);
        $photos[] = "--photo-$scene: url('$asset?v=$version')";
    }
    ?>
    <div class="welcome-scene" style="<?= htmlspecialchars(implode('; ', $photos)) ?>" aria-hidden="true">
        <span class="welcome-celestial"></span>
        <span class="welcome-clouds"></span>
        <span class="welcome-hills"></span>
        <span class="welcome-photo"></span>
        <div class="welcome-rain">
            <?php for ($drop = 0; $drop < 20; $drop++): ?>
            <span style="--drop: <?= $drop ?>"></span>
            <?php endfor; ?>
        </div>
    </div>
    <?php
}

function renderWelcomeWeather(?string $city = null): void
{
    ?>
    <section class="welcome-weather" aria-label="Current weather" data-weather-panel<?= $city === null ? ' data-device-clock' : '' ?>>
        <?php renderWelcomeLandscape(); ?>
        <div class="welcome-reading" data-sky-readout>
            <span class="welcome-temperature" data-sky-temp hidden></span>
            <div class="welcome-conditions">
                <strong data-sky-condition role="status">Current weather unavailable</strong>
                <div class="welcome-place">
                    <span data-sky-place><?= htmlspecialchars($city ?? 'On this device') ?></span>
                    <small data-sky-source hidden></small>
                </div>
            </div>
            <time class="welcome-time" data-sky-clock data-sky-time hidden></time>
        </div>
        <?php if ($city === null): ?>
        <div class="welcome-weather-links">
            <button type="button" data-sky-locate hidden>Use my location</button>
            <a href="/weather/" data-sky-forecast>Full forecast <span aria-hidden="true">→</span></a>
        </div>
        <p class="welcome-feedback" data-sky-feedback role="status"></p>
        <?php endif; ?>
    </section>
    <?php
}
