<?php
/**
 * ============================================
 * RELATIVES - WEATHER CENTER
 * Styled exactly like Schedule page
 * ============================================
 */

session_start();

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
    $stmt = $db->prepare("
        SELECT latitude, longitude, accuracy_m, created_at
        FROM tracking_locations
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $userLocation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Location fetch error: ' . $e->getMessage());
}

$pageTitle = 'Weather';
$activePage = 'weather';
$cacheVersion = '4.2.1';
$pageCSS = ['/weather/css/weather.css?v=' . $cacheVersion];
$pageJS = ['/weather/js/weather.js?v=' . $cacheVersion];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<!-- Main Content -->
<main class="main-content">
    <div class="container">

        <!-- Hero Section (Same as Schedule) -->
        <div class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">🌤️</span>
                    <span class="greeting-name">Weather Center</span>
                </h1>
                <p class="greeting-subtitle">Real-time forecasts & weather insights</p>

                <!-- Location Search (Compact) -->
                <div class="location-search">
                    <div class="search-input-wrapper">
                        <span class="search-icon">🔍</span>
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

                <!-- Quick Actions (Same style as Schedule) -->
                <div class="quick-actions" id="weatherActions" style="display: none;">
                    <button onclick="WeatherWidget.getInstance().refresh()" class="quick-action-btn">
                        <span class="qa-icon">🔄</span>
                        <span>Refresh</span>
                    </button>
                    <button onclick="WeatherWidget.getInstance().toggleUnits()" class="quick-action-btn">
                        <span class="qa-icon">🌡️</span>
                        <span>Units</span>
                    </button>
                    <button onclick="WeatherWidget.getInstance().shareWeather()" class="quick-action-btn">
                        <span class="qa-icon">📤</span>
                        <span>Share</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Today's Weather Details (Combined Stats Bar) -->
        <div class="stats-bar glass-card" id="weatherStats" style="display: none;">
            <div class="stats-title">Today</div>
            <div id="weatherDetails" class="stats-chips">
                <div class="stat-chip" id="statHumidity">💧 --</div>
                <div class="stat-chip" id="statWind">💨 --</div>
                <div class="stat-chip" id="statVisibility">👁️ --</div>
                <div class="stat-chip" id="statPressure">🌡️ --</div>
                <div class="stat-chip" id="statUV">☀️ --</div>
                <div class="stat-chip" id="statSunrise">🌅 --</div>
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

<!-- Pass user location to JavaScript -->
<script>
window.USER_LOCATION = <?php echo $userLocation ? json_encode([
    'lat' => (float)$userLocation['latitude'],
    'lng' => (float)$userLocation['longitude'],
    'accuracy' => (int)$userLocation['accuracy_m'],
    'timestamp' => $userLocation['created_at']
]) : 'null'; ?>;
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
