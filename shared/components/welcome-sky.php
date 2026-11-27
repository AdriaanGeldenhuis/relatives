<?php
declare(strict_types=1);

const WELCOME_SKY_SCENES = ['sun', 'dawn', 'dusk', 'cloud', 'rain', 'night'];

function welcomeSkyPhotoPath(string $scene): string
{
    return __DIR__ . "/../public/welcome-$scene.webp";
}

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
    foreach (WELCOME_SKY_SCENES as $scene) {
        $version = filemtime(welcomeSkyPhotoPath($scene));
        $query = http_build_query(['scene' => $scene, 'v' => $version]);
        $photos[] = "--photo-$scene: url('/weather/api/welcome-image.php?$query')";
    }
    ?>
    <div class="welcome-scene" style="<?= htmlspecialchars(implode('; ', $photos)) ?>" aria-hidden="true">
        <span class="welcome-hills"></span>
        <span class="welcome-photo"></span>
        <span class="welcome-atmosphere"></span>
        <span class="welcome-solar-horizon"><span class="welcome-sun"></span></span>
        <div class="welcome-precipitation">
            <?php for ($drop = 0; $drop < 52; $drop++): ?>
            <span style="--drop-x: <?= ($drop * 37 + 8) % 103 ?>%; --drop-y: <?= ($drop * 61 + 19) % 103 ?>%; --drop-delay: -<?= ($drop * 173 + 41) % 1480 ?>ms"></span>
            <?php endfor; ?>
        </div>
        <span class="welcome-scrim"></span>
    </div>
    <?php
}

function renderWelcomeWeather(?string $city = null): void
{
    ?>
    <section class="welcome-weather" aria-label="Current weather" data-weather-panel<?= $city === null ? ' data-device-clock' : '' ?>>
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
        <div class="welcome-daylight" data-sky-daylight aria-label="Sunrise and sunset" hidden>
            <span>Sunrise <time data-sky-sunrise></time></span>
            <span>Sunset <time data-sky-sunset></time></span>
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
