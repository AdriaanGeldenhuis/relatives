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

function renderWeatherHeroPanel(bool $home = false): void
{
    ?>
    <div class="sky-panel" data-weather-panel<?= $home ? ' data-device-clock' : '' ?>>
        <div class="sky-readout" data-sky-readout<?= $home ? '' : ' hidden' ?>>
            <div class="sky-place">
                <span class="sky-label"><?= $home ? 'Local weather' : 'Local time' ?></span>
                <?php if ($home): ?>
                <strong data-sky-place>On this device</strong>
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
        <div class="sky-switch" role="group" aria-label="Weather snapshot" hidden>
            <button type="button" data-sky-view="now" aria-pressed="true">Now</button>
            <button type="button" data-sky-view="daylight" aria-pressed="false">Daylight</button>
        </div>
        <div class="sky-view sky-now" data-sky-content="now">
            <span class="sky-temp" data-sky-temp hidden></span>
            <div class="sky-condition">
                <strong data-sky-condition role="status"><?= $home ? 'Use your location for current weather' : 'Choose a place to see current conditions' ?></strong>
                <p data-sky-feels hidden></p>
                <p data-sky-wind hidden></p>
            </div>
            <span class="sky-condition-icon" data-sky-icon aria-hidden="true" hidden></span>
        </div>
        <div class="sky-view sky-daylight" data-sky-content="daylight" hidden>
            <dl>
                <div><dt>Sunrise</dt><dd><time data-sky-sunrise></time></dd></div>
                <div><dt>Sunset</dt><dd><time data-sky-sunset></time></dd></div>
            </dl>
            <p data-sky-sun-note></p>
        </div>
        <?php if ($home): ?>
        <div class="sky-links">
            <button type="button" data-sky-locate hidden>Use my location</button>
            <a href="/weather/" data-sky-forecast>Full forecast <span aria-hidden="true">→</span></a>
        </div>
        <p class="sky-feedback" data-sky-feedback role="status"></p>
        <?php endif; ?>
    </div>
    <?php
}
