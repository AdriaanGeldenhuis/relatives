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
$pageJS = ['/weather/js/weather.js'];
$pageJSVersion = filemtime(__DIR__ . '/js/weather.js');

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="family-main wx-main" id="weatherApp">
    <header class="family-hero sky-hero" data-light="unknown">
        <?php renderWeatherSky(); ?>
        <div class="sky-layout">
            <div class="family-hero-content">
                <h1 class="family-hero-title" id="weatherHeroTitle">Weather</h1>
                <p class="family-hero-summary" id="weatherHeroSummary">Search for a city or use your location to see its weather.</p>
                <div class="family-actions">
                    <button type="button" class="family-button family-button-primary" id="useCurrentLocation" hidden>Use my location</button>
                    <button type="button" class="family-button" id="weatherUnits" aria-label="Fahrenheit temperatures" aria-pressed="false" hidden>°C / °F</button>
                    <button type="button" class="family-button" id="weatherRefresh" hidden>Refresh</button>
                    <button type="button" class="family-button" id="weatherShare" hidden>Share</button>
                </div>
                <p id="weatherLocationStatus" class="sky-feedback" role="status"></p>
            </div>
            <?php renderWeatherHeroPanel(); ?>
        </div>
    </header>

    <div class="wx-toolbar">
        <div class="wx-search">
            <label class="wx-sr-only" for="locationSearch">Search for a city or town</label>
            <input type="search" id="locationSearch" placeholder="Search city or town…" maxlength="100"
                   autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="searchResults">
            <div class="wx-search-popover" id="searchPopover" hidden>
                <ul id="searchResults" role="listbox" aria-label="Matching places"></ul>
                <p id="searchStatus" role="status"></p>
            </div>
        </div>
        <div class="wx-places" id="weatherPlaces" aria-label="Quick locations"></div>
    </div>

    <p class="wx-status" id="weatherStatus" role="status"></p>
    <noscript><p class="wx-message">Enable JavaScript to search for a location and load its forecast.</p></noscript>

    <section class="wx-overview" id="weatherOverview" aria-label="Weather at a glance" hidden>
        <div class="wx-card wx-current" id="currentWeather"></div>
        <div class="wx-card wx-plan" id="weatherPlan"></div>
    </section>

    <section class="wx-section" id="hourlySection" aria-labelledby="hourlyHeading" hidden>
        <div class="wx-section-head">
            <div><h2 class="family-section-title" id="hourlyHeading">Next 24 hours</h2><p>3-hour intervals · local time</p></div>
        </div>
        <div id="hourlyForecast" class="wx-hourly"></div>
    </section>

    <section class="wx-section" id="dailySection" aria-labelledby="dailyHeading" hidden>
        <div class="wx-section-head">
            <div><h2 class="family-section-title" id="dailyHeading">Daily forecast</h2><p>Choose a day to see its full forecast.</p></div>
            <div class="wx-layout" role="group" aria-label="Forecast layout">
                <button type="button" data-view="cards" aria-pressed="true">Cards</button>
                <button type="button" data-view="list" aria-pressed="false">List</button>
            </div>
        </div>
        <div id="weeklyForecast" class="wx-daily"></div>
    </section>
    <p class="wx-source" id="weatherSource" hidden>Weather data from OpenWeather.</p>
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
    'csrf' => CSRF::generate()
], JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
