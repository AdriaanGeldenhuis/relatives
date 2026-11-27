<?php

function renderWeatherSky(): void
{
    ?>
    <div class="sky-scene" aria-hidden="true">
        <span class="sky-stars"></span>
        <span class="sky-orbit"></span>
        <span class="sky-disc"></span>
        <span class="sky-clouds"></span>
        <span class="sky-precipitation">
            <?php for ($drop = 0; $drop < 22; $drop++): ?>
            <span style="--drop: <?= $drop ?>"></span>
            <?php endfor; ?>
        </span>
    </div>
    <?php
}

function renderWeatherHeroPanel(string $surface = 'forecast'): void
{
    $home = $surface === 'home';
    $welcome = $surface === 'welcome';
    $showPlace = $home || $welcome;
    ?>
    <section class="sky-panel" aria-label="<?= $showPlace ? 'Current weather' : 'Weather snapshot' ?>" data-weather-panel<?= $home ? ' data-device-clock' : '' ?>>
        <div class="sky-readout" data-sky-readout<?= $showPlace ? '' : ' hidden' ?>>
            <div class="sky-place">
                <span class="sky-label"><?= $showPlace ? 'Current weather' : 'Local time' ?></span>
                <?php if ($showPlace): ?>
                <strong data-sky-place><?= $welcome ? htmlspecialchars(WEATHER_DEFAULT_PLACE['name']) : 'On this device' ?></strong>
                <small data-sky-source hidden></small>
                <?php else: ?>
                <strong data-sky-period hidden></strong>
                <small data-sky-date hidden></small>
                <?php endif; ?>
            </div>
            <div class="sky-clock" data-sky-clock hidden>
                <time data-sky-time></time>
                <?php if ($home): ?><span data-sky-period></span><?php endif; ?>
            </div>
        </div>
        <?php if (!$welcome): ?>
        <div class="sky-switch" role="group" aria-label="Weather snapshot" hidden>
            <button type="button" data-sky-view="now" aria-pressed="true">Now</button>
            <button type="button" data-sky-view="daylight" aria-pressed="false">Daylight</button>
        </div>
        <?php endif; ?>
        <div class="sky-view sky-now" data-sky-content="now">
            <span class="sky-temp" data-sky-temp hidden></span>
            <div class="sky-condition">
                <strong data-sky-condition role="status"><?= $showPlace ? 'Current conditions unavailable' : 'Choose a place to see current conditions' ?></strong>
                <p data-sky-feels hidden></p>
                <p data-sky-wind hidden></p>
            </div>
            <span class="sky-condition-icon" data-sky-icon aria-hidden="true" hidden></span>
        </div>
        <?php if (!$welcome): ?>
        <div class="sky-view sky-daylight" data-sky-content="daylight" hidden>
            <dl>
                <div><dt>Sunrise</dt><dd><time data-sky-sunrise></time></dd></div>
                <div><dt>Sunset</dt><dd><time data-sky-sunset></time></dd></div>
            </dl>
            <p data-sky-sun-note></p>
        </div>
        <?php endif; ?>
        <?php if ($home): ?>
        <div class="sky-links">
            <button type="button" data-sky-locate hidden>Use my location</button>
            <a href="/weather/" data-sky-forecast>Full forecast <span aria-hidden="true">→</span></a>
        </div>
        <p class="sky-feedback" data-sky-feedback role="status"></p>
        <?php endif; ?>
    </section>
    <?php
}
