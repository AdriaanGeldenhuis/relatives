<?php
declare(strict_types=1);

require_once __DIR__ . '/welcome-sky.php';

function renderWeatherHero(): void
{
    ?>
    <section class="wx-hero welcome-hero" id="weatherHero" aria-labelledby="weatherHeroTitle">
        <?php renderWelcomeLandscape(); ?>
        <div class="wx-hero-content" data-weather-panel>
            <div class="wx-hero-top">
                <div class="wx-hero-place">
                    <h2 id="weatherHeroTitle">Current weather</h2>
                    <span id="weatherHeroLocationSource" hidden></span>
                </div>
                <div class="wx-hero-clock" data-sky-readout hidden>
                    <time data-sky-clock data-sky-time hidden></time>
                    <span data-sky-date hidden></span>
                </div>
            </div>
            <div class="wx-hero-bottom">
                <div class="wx-hero-reading">
                    <span class="wx-hero-temperature" data-sky-temp hidden></span>
                    <div>
                        <strong data-sky-condition>Loading current weather…</strong>
                        <p data-sky-feels hidden></p>
                    </div>
                </div>
                <p class="wx-observation" id="weatherHeroSummary" hidden></p>
            </div>
        </div>
    </section>
    <?php
}
