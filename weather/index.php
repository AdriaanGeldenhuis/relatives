<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';

header('Cache-Control: private, no-store');
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=%2Fweather%2F');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/location.php';
require_once __DIR__ . '/../core/CSRF.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    unset($_SESSION['user_id']);
    header('Location: /login.php?redirect=%2Fweather%2F');
    exit;
}

$userLocation = weatherTrackedLocation($db, (int)$user['id'], (int)$user['family_id']);

$pageTitle = 'Weather';
$pageCSS = ['/weather/css/weather.css?build=' . filemtime(__DIR__ . '/css/weather.css')];
$pageInlineStylesheets = [__DIR__ . '/../shared/css/welcome-sky.css'];
$pageJS = ['/weather/js/weather.js'];
$pageJSVersion = filemtime(__DIR__ . '/js/weather.js');

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="family-main wx-main" id="weatherApp">
    <div class="wx-toolbar">
        <h1 class="wx-page-title">Weather</h1>
        <div class="wx-location-tools">
            <div class="wx-search">
                <label class="wx-sr-only" for="locationSearch">Search for a city or town</label>
                <input type="search" id="locationSearch" placeholder="Find a city" maxlength="100"
                       autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="searchResults">
                <div class="wx-search-popover" id="searchPopover" hidden>
                    <ul id="searchResults" role="listbox" aria-label="Matching places"></ul>
                    <p id="searchStatus" role="status"></p>
                </div>
            </div>
            <button type="button" class="wx-button wx-location-button" id="useCurrentLocation" hidden>
                <span class="wx-location-icon" aria-hidden="true"></span>My location
            </button>
        </div>
        <div class="wx-tool-actions">
            <button type="button" class="wx-button wx-unit" id="weatherUnits" aria-label="Fahrenheit temperatures" aria-pressed="false" hidden>
                <span>°C</span><span>°F</span>
            </button>
            <button type="button" class="wx-button" id="weatherRefresh" aria-label="Refresh weather" hidden><span class="wx-refresh" aria-hidden="true">↻</span></button>
            <button type="button" class="wx-button" id="weatherShare" hidden>Share</button>
        </div>
    </div>
    <div class="wx-places" id="weatherPlaces" role="group" aria-label="Quick locations" hidden></div>
    <p id="weatherLocationStatus" class="wx-status" role="status"></p>

    <?php renderWeatherHero(); ?>

    <p class="wx-status" id="weatherStatus" role="status"></p>
    <noscript><p class="wx-message">Enable JavaScript to search for a location and load its forecast.</p></noscript>

    <div class="wx-outlook">
        <section class="wx-card wx-rain-card" id="weatherPlan" aria-labelledby="rainHeading" hidden>
            <h2 id="rainHeading">Rain outlook</h2>
            <p id="rainReading" class="wx-rain-reading"></p>
            <p id="rainNote" class="wx-support"></p>
            <button type="button" class="wx-text-button" id="showRainForecast" hidden>Show in graph <span aria-hidden="true">→</span></button>
        </section>

        <section class="wx-card wx-timeline" id="hourlySection" aria-labelledby="hourlyHeading" hidden>
            <div class="wx-section-head">
                <div><h2 id="hourlyHeading">Next 24 hours</h2><p class="wx-support">3-hour intervals · local time</p></div>
                <div class="wx-segmented" role="group" aria-label="Forecast metric">
                    <button type="button" data-metric="temperature" aria-pressed="true">Temp</button>
                    <button type="button" data-metric="precipitation" id="precipitationMetric" aria-pressed="false">Rain</button>
                    <button type="button" data-metric="wind_speed" aria-pressed="false">Wind</button>
                </div>
            </div>
            <div class="wx-chart-reading" id="forecastReading" aria-live="polite"></div>
            <div class="wx-chart-scroll">
                <div class="wx-chart-track" id="forecastTrack">
                    <canvas id="forecastGraph" aria-hidden="true"></canvas>
                    <div class="wx-chart-slots" id="forecastSlots" role="group" aria-label="Forecast times"></div>
                </div>
            </div>
        </section>

        <section class="wx-card wx-daylight" id="weatherDaylight" aria-labelledby="daylightHeading" hidden>
            <h2 id="daylightHeading">Daylight</h2>
            <p id="daylightNote" class="wx-support"></p>
            <div class="wx-daylight-track" aria-hidden="true"><span></span></div>
            <dl>
                <div><dt>Sunrise</dt><dd><time id="daylightSunrise"></time></dd></div>
                <div><dt>Sunset</dt><dd><time id="daylightSunset"></time></dd></div>
            </dl>
        </section>
    </div>

    <section class="wx-section" id="dailySection" aria-labelledby="dailyHeading" hidden>
        <div class="wx-section-head">
            <h2 id="dailyHeading">Daily forecast</h2>
            <div class="wx-segmented" role="group" aria-label="Forecast layout">
                <button type="button" data-view="list" aria-pressed="true">List</button>
                <button type="button" data-view="cards" aria-pressed="false">Cards</button>
            </div>
        </div>
        <div id="weeklyForecast" class="wx-daily"></div>
    </section>

    <section class="wx-section wx-card" id="currentWeather" aria-labelledby="currentHeading" hidden>
        <h2 id="currentHeading">Current conditions</h2>
        <div id="currentMetrics"></div>
    </section>
    <p class="wx-source" id="weatherSource" hidden>Forecast and observations from OpenWeather.</p>
</main>

<dialog id="dayDetailModal" class="wx-dialog" aria-labelledby="weatherDayTitle">
    <header>
        <h2 id="weatherDayTitle"></h2>
        <form method="dialog"><button class="wx-button" aria-label="Close day details" autofocus>Close</button></form>
    </header>
    <div id="dayDetailContent" class="wx-dialog-body"></div>
</dialog>

<dialog id="shareModal" class="wx-dialog wx-share-dialog" aria-labelledby="weatherShareTitle">
    <header>
        <h2 id="weatherShareTitle">Share weather</h2>
        <form method="dialog"><button class="wx-button" aria-label="Close sharing" autofocus>Close</button></form>
    </header>
    <div class="wx-dialog-body wx-share-options">
        <button type="button" class="wx-button" data-export="print">Print / save as PDF</button>
        <button type="button" class="wx-button" data-export="csv">Download CSV</button>
        <button type="button" class="wx-button" data-export="text">Download text</button>
        <button type="button" class="wx-button" data-export="whatsapp">Share to WhatsApp</button>
    </div>
</dialog>

<script type="application/json" id="weatherSettings"><?= json_encode([
    'userId' => (int)$user['id'],
    'trackedLocation' => $userLocation,
    'savedCity' => weatherSavedCity($db, (int) $user['id']),
    'defaultCity' => WEATHER_DEFAULT_PLACE,
    'csrf' => CSRF::generate()
], JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
