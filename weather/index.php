<?php
/**
 * ============================================
 * RELATIVES - WEATHER CENTER
 * Styled exactly like Schedule page
 * ============================================
 */

require_once __DIR__ . '/../core/session_boot.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

// Get user's last known location
$userLocation = null;
try {
    // First try tracking_current (most recent location)
    $stmt = $db->prepare("
        SELECT lat, lng, accuracy_m, updated_at as created_at
        FROM tracking_current
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $userLocation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback to tracking_locations if no current location
    if (!$userLocation) {
        $stmt = $db->prepare("
            SELECT lat, lng, accuracy_m, created_at
            FROM tracking_locations
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $userLocation = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Location fetch error: ' . $e->getMessage());
}

$pageTitle = 'Weather';
$activePage = 'weather';
$pageCSS = [
    '/shared/css/aurora.css',
    '/weather/css/weather.css'
];
$pageJS = ['/weather/js/weather.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Aurora background -->
<div class="aurora" aria-hidden="true">
    <div class="aurora-blob aurora-a"></div>
    <div class="aurora-blob aurora-b"></div>
    <div class="aurora-blob aurora-c"></div>
    <div class="stars stars-a"></div>
    <div class="stars stars-b"></div>
    <div class="shooting-star"></div>
    <div class="aurora-grid"></div>
</div>

<!-- Main Content -->
<main class="main-content">
    <div class="hub">

        <!-- Compact page header -->
        <header class="page-head" style="--glow:#38bdf8; --page-glow: rgba(56, 189, 248, 0.14)">
            <span class="ph-glyph" aria-hidden="true">🌤️</span>
            <div class="ph-titles">
                <h1>Weather</h1>
                <p class="ph-sub"><?php echo date('l, j F'); ?></p>
            </div>
            <div class="ph-actions" id="weatherActions" style="display: none;">
                <button onclick="WeatherWidget.getInstance().refresh()" class="pill-btn">
                    <span aria-hidden="true">🔄</span> Refresh
                </button>
                <button onclick="WeatherWidget.getInstance().toggleUnits()" class="pill-btn">
                    <span aria-hidden="true">🌡️</span> Units
                </button>
                <button onclick="WeatherWidget.getInstance().shareWeather()" class="pill-btn">
                    <span aria-hidden="true">📤</span> Share
                </button>
            </div>
        </header>

        <!-- Current conditions hero -->
        <section class="weather-hero">
            <div class="location-search">
                <div class="search-input-wrapper">
                    <span class="search-icon" aria-hidden="true">🔍</span>
                    <input
                        type="text"
                        id="locationSearch"
                        placeholder="Search city..."
                        autocomplete="off"
                    >
                    <button id="useCurrentLocation" class="location-btn" title="Use current location">
                        <span>📍</span>
                    </button>
                </div>
                <div id="searchResults" class="search-results" style="display: none;"></div>
            </div>

            <!-- Current Weather Display -->
            <div id="currentWeather" class="current-weather">
                <div class="weather-loading">
                    <div class="loading-spinner">☁️</div>
                    <p><?php echo $userLocation ? 'Loading weather...' : 'Search for a location'; ?></p>
                </div>
            </div>
        </section>

        <!-- Today's Weather Summary Card -->
        <div class="today-card glass-card" id="weatherStats" style="display: none;">
            <div class="today-header">
                <div class="today-title">📅 Today's Overview</div>
                <div class="today-temps" id="todayTemps">
                    <span class="temp-hi">--°</span>
                    <span class="temp-sep">/</span>
                    <span class="temp-lo">--°</span>
                </div>
            </div>
            <div class="today-grid" id="weatherDetails">
                <div class="today-stat" id="statHumidity">
                    <span class="stat-icon">💧</span>
                    <span class="stat-label">Humidity</span>
                    <span class="stat-value">--%</span>
                </div>
                <div class="today-stat" id="statWind">
                    <span class="stat-icon">💨</span>
                    <span class="stat-label">Wind</span>
                    <span class="stat-value">-- km/h</span>
                </div>
                <div class="today-stat" id="statVisibility">
                    <span class="stat-icon">👁️</span>
                    <span class="stat-label">Visibility</span>
                    <span class="stat-value">-- km</span>
                </div>
                <div class="today-stat" id="statPressure">
                    <span class="stat-icon">🌡️</span>
                    <span class="stat-label">Pressure</span>
                    <span class="stat-value">-- hPa</span>
                </div>
                <div class="today-stat" id="statUV">
                    <span class="stat-icon">☀️</span>
                    <span class="stat-label">UV Index</span>
                    <span class="stat-value">--</span>
                </div>
                <div class="today-stat" id="statSunrise">
                    <span class="stat-icon">🌅</span>
                    <span class="stat-label">Sunrise</span>
                    <span class="stat-value">--</span>
                </div>
                <div class="today-stat" id="statSunset">
                    <span class="stat-icon">🌇</span>
                    <span class="stat-label">Sunset</span>
                    <span class="stat-value">--</span>
                </div>
                <div class="today-stat" id="statRain">
                    <span class="stat-icon">🌧️</span>
                    <span class="stat-label">Rain Chance</span>
                    <span class="stat-value">--%</span>
                </div>
            </div>
        </div>

        <!-- 7-Day Forecast Section -->
        <div class="notes-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span>📅</span> 7-Day Forecast
                </h2>
                <div class="filter-buttons">
                    <button class="filter-btn active" data-view="cards" onclick="WeatherWidget.getInstance().setView('cards')">
                        <span>📊</span> Cards
                    </button>
                    <button class="filter-btn" data-view="list" onclick="WeatherWidget.getInstance().setView('list')">
                        <span>📋</span> List
                    </button>
                </div>
            </div>

            <div id="weeklyForecast" class="notes-grid forecast-grid">
                <!-- Skeleton loading -->
                <?php for ($i = 0; $i < 7; $i++): ?>
                <div class="note-card skeleton-card">
                    <div class="skeleton skeleton-icon"></div>
                    <div class="skeleton skeleton-text"></div>
                    <div class="skeleton skeleton-text-sm"></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Hourly Forecast Section -->
        <div class="notes-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span>🕐</span> 24-Hour Forecast
                </h2>
            </div>

            <div class="hourly-scroll">
                <div id="hourlyForecast" class="hourly-grid">
                    <div class="weather-loading">
                        <div class="loading-spinner">☁️</div>
                        <p>Loading hourly forecast...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Weather Insights Section -->
        <div class="notes-section" id="insightsSection" style="display: none;">
            <div class="section-header">
                <h2 class="section-title">
                    <span>💡</span> AI Insights
                </h2>
            </div>
            <div id="weatherInsights" class="insights-grid"></div>
        </div>

        <!-- Weather Alerts Container -->
        <div id="weatherAlerts" class="alerts-container"></div>
    </div>
</main>

<!-- Day Detail Modal -->
<div id="dayDetailModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Day Details</h2>
            <button onclick="WeatherWidget.getInstance().closeModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="dayDetailContent">
            <div class="weather-loading">
                <div class="loading-spinner">☁️</div>
                <p>Loading...</p>
            </div>
        </div>
    </div>
</div>

<!-- Share Modal -->
<div id="shareModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2>📤 Share Weather</h2>
            <button onclick="WeatherWidget.getInstance().closeShareModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="modal-description">Choose export format:</p>
            <div class="share-options">
                <button onclick="WeatherWidget.getInstance().exportWeatherPDF()" class="btn btn-primary">
                    <span>📄</span>
                    <span>Export as PDF</span>
                </button>
                <button onclick="WeatherWidget.getInstance().exportWeatherCSV()" class="btn btn-secondary">
                    <span>📊</span>
                    <span>Export as CSV</span>
                </button>
                <button onclick="WeatherWidget.getInstance().exportWeatherText()" class="btn btn-secondary">
                    <span>📝</span>
                    <span>Export as Text</span>
                </button>
                <button onclick="WeatherWidget.getInstance().shareWeatherWhatsApp()" class="btn btn-whatsapp">
                    <span>💬</span>
                    <span>Share to WhatsApp</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Pass user location to JavaScript -->
<script>
window.USER_LOCATION = <?php echo $userLocation ? json_encode([
    'lat' => (float)$userLocation['lat'],
    'lng' => (float)$userLocation['lng'],
    'accuracy' => isset($userLocation['accuracy_m']) ? (int)$userLocation['accuracy_m'] : null,
    'timestamp' => $userLocation['created_at']
]) : 'null'; ?>;
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
