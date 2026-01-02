<?php
declare(strict_types=1);

session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }
    
} catch (Exception $e) {
    error_log('Weather page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get user's last known location from tracking
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

$pageTitle = 'Weather Forecast';
$activePage = 'weather';
$pageCSS = ['/weather/css/weather.css'];
$pageJS = ['/weather/js/weather.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="container">
        <!-- Hero Weather Card -->
        <section class="weather-hero">
            <div class="hero-gradient"></div>
            
            <div class="weather-header">
                <h1 class="page-title">
                    <span class="title-icon">🌤️</span>
                    <span class="title-text">Weather Center</span>
                </h1>
            </div>

            <!-- Location Search -->
            <div class="location-search">
                <div class="search-input-wrapper">
                    <span class="search-icon">🔍</span>
                    <input 
                        type="text" 
                        id="locationSearch" 
                        placeholder="Search for a city or use current location..."
                        autocomplete="off"
                    >
                    <button id="useCurrentLocation" class="use-location-btn" title="Use current location">
                        <span class="location-icon">📍</span>
                    </button>
                </div>
                <div id="searchResults" class="search-results-dropdown" style="display: none;"></div>
            </div>

            <!-- Current Location Display -->
            <div class="location-display" id="weatherLocation">
                <span class="location-icon">📍</span>
                <span class="location-text">
                    <?php if ($userLocation): ?>
                        Loading your location...
                    <?php else: ?>
                        Search for a location or enable location services
                    <?php endif; ?>
                </span>
            </div>

            <!-- Current Weather - Hero Card -->
            <div id="currentWeather" class="current-weather-hero">
                <div class="weather-loading">
                    <div class="loading-animation">
                        <div class="loading-cloud">☁️</div>
                        <div class="loading-sun">☀️</div>
                    </div>
                    <p class="loading-text">
                        <?php if ($userLocation): ?>
                            Loading weather for your current location...
                        <?php else: ?>
                            Search for a location to view weather
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </section>

        <!-- 7-Day Forecast -->
        <section class="forecast-section">
            <div class="section-header">
                <div class="section-title-wrapper">
                    <span class="section-icon">📅</span>
                    <h2 class="section-title">7-Day Forecast</h2>
                </div>
                <div class="forecast-tabs">
                    <button class="tab-btn active" data-view="cards">
                        <span>📊</span> Cards
                    </button>
                    <button class="tab-btn" data-view="list">
                        <span>📋</span> List
                    </button>
                </div>
            </div>
            
            <div id="weeklyForecast" class="weekly-forecast cards-view">
                <div class="forecast-loading">
                    <?php for ($i = 0; $i < 7; $i++): ?>
                        <div class="forecast-card skeleton-card">
                            <div class="skeleton skeleton-circle"></div>
                            <div class="skeleton skeleton-text"></div>
                            <div class="skeleton skeleton-text-sm"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </section>

        <!-- Hourly Forecast -->
        <section class="hourly-section">
            <div class="section-header">
                <div class="section-title-wrapper">
                    <span class="section-icon">🕐</span>
                    <h2 class="section-title">24-Hour Forecast</h2>
                </div>
            </div>
            
            <div class="hourly-scroll-container">
                <div id="hourlyForecast" class="hourly-forecast">
                    <div class="weather-loading">
                        <div class="loading-animation">
                            <div class="loading-cloud">☁️</div>
                            <div class="loading-sun">☀️</div>
                        </div>
                        <p class="loading-text">Loading hourly forecast...</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Weather Insights (AI-Generated) -->
        <section class="insights-section">
            <div class="section-header">
                <div class="section-title-wrapper">
                    <span class="section-icon">🧠</span>
                    <h2 class="section-title">Smart Insights</h2>
                </div>
            </div>
            
            <div id="weatherInsights" class="insights-grid">
                <div class="insight-card">
                    <div class="insight-header">
                        <div class="insight-icon">⏳</div>
                        <div class="insight-title">Analyzing weather patterns...</div>
                    </div>
                    <div class="insight-body">
                        AI-powered insights will appear here based on current conditions.
                    </div>
                </div>
            </div>
        </section>

        <!-- Weather Details Grid -->
        <section class="details-section">
            <div class="section-header">
                <div class="section-title-wrapper">
                    <span class="section-icon">📊</span>
                    <h2 class="section-title">Weather Details</h2>
                </div>
            </div>
            
            <div id="weatherDetails" class="details-grid">
                <div class="detail-card skeleton-card">
                    <div class="skeleton skeleton-circle"></div>
                    <div class="skeleton skeleton-text"></div>
                </div>
                <div class="detail-card skeleton-card">
                    <div class="skeleton skeleton-circle"></div>
                    <div class="skeleton skeleton-text"></div>
                </div>
                <div class="detail-card skeleton-card">
                    <div class="skeleton skeleton-circle"></div>
                    <div class="skeleton skeleton-text"></div>
                </div>
                <div class="detail-card skeleton-card">
                    <div class="skeleton skeleton-circle"></div>
                    <div class="skeleton skeleton-text"></div>
                </div>
            </div>
        </section>

        <!-- Weather Alerts Container -->
        <div id="weatherAlerts" class="weather-alerts-container"></div>
    </div>
</main>

<!-- Day Detail Modal -->
<div id="dayDetailModal" class="modal weather-modal">
    <div class="modal-overlay" onclick="WeatherWidget.getInstance().closeModal()"></div>
    <div class="modal-content weather-detail-content">
        <button class="modal-close glass-btn" onclick="WeatherWidget.getInstance().closeModal()">
            <span class="close-icon">✕</span>
        </button>
        <div id="dayDetailContent" class="detail-inner">
            <div class="weather-loading">
                <div class="loading-animation">
                    <div class="loading-cloud">☁️</div>
                    <div class="loading-sun">☀️</div>
                </div>
                <p class="loading-text">Loading details...</p>
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