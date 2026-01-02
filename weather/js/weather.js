/**
 * ============================================
 * RELATIVES v3.0 - WEATHER CENTER
 * AUTO-LOADS CURRENT LOCATION ON PAGE LOAD
 * ============================================
 */

class WeatherWidget {
    static instance = null;
    
    constructor() {
        if (WeatherWidget.instance) {
            return WeatherWidget.instance;
        }
        
        this.location = null;
        this.currentWeather = null;
        this.forecast = [];
        this.hourlyForecast = [];
        this.cache = new Map();
        this.cacheTimeout = 10 * 60 * 1000;
        this.searchTimeout = null;
        this.locationAttempted = false;
        
        WeatherWidget.instance = this;
        this.init();
    }
    
    static getInstance() {
        if (!WeatherWidget.instance) {
            WeatherWidget.instance = new WeatherWidget();
        }
        return WeatherWidget.instance;
    }
    
    async init() {
        console.log('🌤️ Initializing Weather Widget...');
        
        this.setupLocationSearch();
        this.setupViewToggles();
        this.setupCurrentLocationButton();
        
        // AUTO-LOAD LOCATION ON PAGE LOAD
        if (window.USER_LOCATION && window.USER_LOCATION.lat && window.USER_LOCATION.lng) {
            console.log('📍 Using tracked location:', window.USER_LOCATION);
            await this.useTrackedLocation();
        } else {
            console.log('📍 Attempting browser geolocation...');
            this.getCurrentLocation(true);
        }
        
        setInterval(() => {
            if (this.location) {
                this.refresh();
            }
        }, 10 * 60 * 1000);
        
        console.log('✅ Weather Widget initialized');
    }
    
    setupCurrentLocationButton() {
        const btn = document.getElementById('useCurrentLocation');
        if (!btn) return;
        
        btn.addEventListener('click', () => {
            this.getCurrentLocation(false);
        });
    }
    
    async getCurrentLocation(silent = false) {
        if (this.locationAttempted && silent) {
            console.log('⏭️ Location already attempted');
            return;
        }
        
        this.locationAttempted = true;
        
        const btn = document.getElementById('useCurrentLocation');
        const locationEl = document.getElementById('weatherLocation');
        
        if (!silent && btn) {
            btn.classList.add('loading');
            btn.innerHTML = '<span class="location-icon spinning">🔄</span>';
        }
        
        if (silent && locationEl) {
            locationEl.innerHTML = `
                <span class="location-icon">📍</span>
                <span class="location-text">Getting your location...</span>
            `;
        }
        
        if (window.USER_LOCATION && window.USER_LOCATION.lat && window.USER_LOCATION.lng) {
            await this.useTrackedLocation();
            if (!silent && btn) {
                btn.classList.remove('loading');
                btn.innerHTML = '<span class="location-icon">📍</span>';
            }
            return;
        }
        
        if ('geolocation' in navigator) {
            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    console.log('✅ Location obtained:', position.coords);
                    
                    this.location = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };
                    
                    await this.loadLocationName();
                    await this.loadAllWeatherData();
                    this.renderAll();
                    
                    if (!silent && btn) {
                        btn.classList.remove('loading');
                        btn.innerHTML = '<span class="location-icon">📍</span>';
                    }
                    
                    if (!silent) {
                        this.showNotification('Weather loaded for current location', 'success');
                    }
                },
                (error) => {
                    console.error('❌ Geolocation error:', error);
                    
                    if (!silent && btn) {
                        btn.classList.remove('loading');
                        btn.innerHTML = '<span class="location-icon">📍</span>';
                    }
                    
                    if (locationEl) {
                        locationEl.innerHTML = `
                            <span class="location-icon">🔍</span>
                            <span class="location-text">Search for a location above</span>
                        `;
                    }
                    
                    if (!silent) {
                        let errorMsg = 'Unable to get location.';
                        if (error.code === 1) {
                            errorMsg = 'Location access denied. Please enable location permissions.';
                        }
                        this.showNotification(errorMsg, 'error');
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 300000
                }
            );
        } else {
            if (locationEl) {
                locationEl.innerHTML = `
                    <span class="location-icon">🔍</span>
                    <span class="location-text">Search for a location above</span>
                `;
            }
            if (!silent) {
                this.showNotification('Geolocation not supported', 'error');
            }
        }
    }
    
    async useTrackedLocation() {
        if (!window.USER_LOCATION) return;
        
        this.location = {
            lat: window.USER_LOCATION.lat,
            lng: window.USER_LOCATION.lng
        };
        
        const locationEl = document.getElementById('weatherLocation');
        if (locationEl) {
            locationEl.innerHTML = `
                <span class="location-icon">📍</span>
                <span class="location-text">Loading your tracked location...</span>
            `;
        }
        
        await this.loadLocationName();
        await this.loadAllWeatherData();
        this.renderAll();
        
        console.log('✅ Weather loaded from tracked location');
    }
    
    async loadLocationName() {
        if (!this.location) return;
        
        try {
            const response = await fetch(`/weather/api/api.php?action=current&lat=${this.location.lat}&lon=${this.location.lng}`);
            const data = await response.json();
            
            if (data.location_name || data.location) {
                this.location.city = data.location_name || data.location;
                
                const locationEl = document.getElementById('weatherLocation');
                if (locationEl) {
                    locationEl.innerHTML = `
                        <span class="location-icon">📍</span>
                        <span class="location-text">${this.location.city}</span>
                    `;
                }
            }
        } catch (error) {
            console.error('Failed to load location name:', error);
        }
    }
    
    renderAll() {
        this.renderCurrentWeather();
        this.renderWeeklyForecast();
        this.renderHourlyForecast();
        this.renderInsights();
        this.renderWeatherDetails();
        this.checkWeatherAlerts();
    }
    
    setupLocationSearch() {
        const searchInput = document.getElementById('locationSearch');
        const searchResults = document.getElementById('searchResults');
        
        if (!searchInput) return;
        
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            clearTimeout(this.searchTimeout);
            
            if (query.length < 2) {
                if (searchResults) {
                    searchResults.style.display = 'none';
                }
                return;
            }
            
            this.searchTimeout = setTimeout(() => {
                this.searchLocation(query);
            }, 300);
        });
        
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                if (searchResults) {
                    searchResults.style.display = 'none';
                }
            }
        });
    }
    
    async searchLocation(query) {
        const searchResults = document.getElementById('searchResults');
        if (!searchResults) return;
        
        try {
            const response = await fetch(`/weather/api/api.php?action=search&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (data.results && data.results.length > 0) {
                searchResults.innerHTML = data.results.map(result => `
                    <div class="search-result-item" 
                         data-lat="${result.lat}" 
                         data-lon="${result.lon}"
                         data-name="${result.display}">
                        <div class="result-name">${result.name}</div>
                        <div class="result-details">${result.state ? result.state + ', ' : ''}${result.country}</div>
                    </div>
                `).join('');
                
                searchResults.style.display = 'block';
                
                searchResults.querySelectorAll('.search-result-item').forEach(item => {
                    item.addEventListener('click', () => {
                        this.selectLocation(
                            parseFloat(item.dataset.lat),
                            parseFloat(item.dataset.lon),
                            item.dataset.name
                        );
                    });
                });
            } else {
                searchResults.innerHTML = `
                    <div class="search-result-item">
                        <div class="result-name">No locations found</div>
                        <div class="result-details">Try a different search term</div>
                    </div>
                `;
                searchResults.style.display = 'block';
            }
        } catch (error) {
            console.error('Location search error:', error);
            searchResults.style.display = 'none';
        }
    }
    
    async selectLocation(lat, lon, name) {
        const searchResults = document.getElementById('searchResults');
        const searchInput = document.getElementById('locationSearch');
        
        this.location = {
            lat: lat,
            lng: lon,
            city: name
        };
        
        const locationEl = document.getElementById('weatherLocation');
        if (locationEl) {
            locationEl.innerHTML = `
                <span class="location-icon">📍</span>
                <span class="location-text">${name}</span>
            `;
        }
        
        if (searchInput) {
            searchInput.value = '';
        }
        
        if (searchResults) {
            searchResults.style.display = 'none';
        }
        
        this.cache.clear();
        
        const currentEl = document.getElementById('currentWeather');
        if (currentEl) {
            currentEl.innerHTML = `
                <div class="weather-loading">
                    <div class="loading-animation">
                        <div class="loading-cloud">☁️</div>
                        <div class="loading-sun">☀️</div>
                    </div>
                    <p class="loading-text">Loading weather data...</p>
                </div>
            `;
        }
        
        await this.loadAllWeatherData();
        this.renderAll();
        
        this.showNotification(`Weather updated for ${name}`, 'success');
    }
    
    async getVoiceSummary(dayOffset = 0) {
        if (!this.forecast || this.forecast.length === 0) {
            await this.loadForecast();
        }
        
        if (!this.forecast || this.forecast.length === 0) {
            return "Weather data is not available right now.";
        }
        
        if (dayOffset < 0 || dayOffset >= this.forecast.length) {
            return "Weather forecast is not available for that day.";
        }
        
        const day = this.forecast[dayOffset];
        const locationName = this.location.city || 'your location';
        
        let dayName;
        if (dayOffset === 0) {
            dayName = 'Today';
        } else if (dayOffset === 1) {
            dayName = 'Tomorrow';
        } else {
            dayName = day.day_name;
        }
        
        const temp = day.temp_max || day.temperature;
        const condition = day.description || day.condition || 'clear';
        
        return `${dayName} in ${locationName} will be ${temp}° and ${condition}.`;
    }
    
    async loadAllWeatherData() {
        if (!this.location) {
            console.error('No location available');
            return;
        }
        
        try {
            await Promise.all([
                this.loadCurrentWeather(),
                this.loadForecast(),
                this.loadHourlyForecast()
            ]);
        } catch (error) {
            console.error('Failed to load weather data:', error);
            this.showNotification('Failed to load weather data', 'error');
        }
    }
    
    async loadCurrentWeather() {
        const cacheKey = `current_${this.location.lat}_${this.location.lng}`;
        const cached = this.getFromCache(cacheKey);
        
        if (cached) {
            this.currentWeather = cached;
            return;
        }
        
        try {
            const url = `/weather/api/api.php?action=current&lat=${this.location.lat}&lon=${this.location.lng}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.currentWeather = data;
            this.saveToCache(cacheKey, data);
            
            console.log('🌡️ Current weather loaded:', data);
        } catch (error) {
            console.error('Failed to load current weather:', error);
            throw error;
        }
    }
    
    async loadForecast() {
        const cacheKey = `forecast_${this.location.lat}_${this.location.lng}`;
        const cached = this.getFromCache(cacheKey);
        
        if (cached) {
            this.forecast = cached;
            return;
        }
        
        try {
            const url = `/weather/api/api.php?action=forecast&lat=${this.location.lat}&lon=${this.location.lng}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.forecast = data.forecast || [];
            this.saveToCache(cacheKey, this.forecast);
            
            console.log('📅 Forecast loaded:', this.forecast);
        } catch (error) {
            console.error('Failed to load forecast:', error);
            throw error;
        }
    }
    
    async loadHourlyForecast() {
        const cacheKey = `hourly_${this.location.lat}_${this.location.lng}`;
        const cached = this.getFromCache(cacheKey);
        
        if (cached) {
            this.hourlyForecast = cached;
            return;
        }
        
        try {
            const url = `/weather/api/api.php?action=hourly&lat=${this.location.lat}&lon=${this.location.lng}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.hourlyForecast = data.hourly || [];
            this.saveToCache(cacheKey, this.hourlyForecast);
            
            console.log('🕐 Hourly forecast loaded:', this.hourlyForecast);
        } catch (error) {
            console.error('Failed to load hourly forecast:', error);
            throw error;
        }
    }
    
    renderCurrentWeather() {
        const currentEl = document.getElementById('currentWeather');
        if (!currentEl || !this.currentWeather) return;
        
        const sunrise = new Date(this.currentWeather.sunrise * 1000);
        const sunset = new Date(this.currentWeather.sunset * 1000);
        const now = new Date();
        const isDaytime = now >= sunrise && now <= sunset;
        
        currentEl.innerHTML = `
            <div class="current-weather-display">
                <div class="weather-main">
                    <div class="weather-icon-large">${this.getWeatherEmoji(this.currentWeather.condition, isDaytime)}</div>
                    <div class="temperature-display">${this.currentWeather.temperature}°</div>
                    <div class="weather-description">${this.currentWeather.description}</div>
                    <div class="feels-like">Feels like ${this.currentWeather.feels_like}°C</div>
                </div>
                
                <div class="weather-details-grid">
                    <div class="detail-card">
                        <div class="detail-icon">💧</div>
                        <div class="detail-label">Humidity</div>
                        <div class="detail-value">${this.currentWeather.humidity}%</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">💨</div>
                        <div class="detail-label">Wind</div>
                        <div class="detail-value">${this.currentWeather.wind_speed} km/h</div>
                        <div class="wind-direction-indicator">
                            <div class="wind-arrow" style="transform: rotate(${this.currentWeather.wind_direction}deg)">↑</div>
                            <div class="wind-compass">
                                <span class="compass-label n">N</span>
                                <span class="compass-label e">E</span>
                                <span class="compass-label s">S</span>
                                <span class="compass-label w">W</span>
                            </div>
                        </div>
                        ${this.currentWeather.wind_gust ? `<div style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 4px;">Gusts: ${this.currentWeather.wind_gust} km/h</div>` : ''}
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">☁️</div>
                        <div class="detail-label">Cloud Cover</div>
                        <div class="detail-value">${this.currentWeather.clouds}%</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">👁️</div>
                        <div class="detail-label">Visibility</div>
                        <div class="detail-value">${this.currentWeather.visibility} km</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">🌡️</div>
                        <div class="detail-label">Pressure</div>
                        <div class="detail-value">${this.currentWeather.pressure} hPa</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">🌅</div>
                        <div class="detail-label">Sunrise</div>
                        <div class="detail-value">${sunrise.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">🌇</div>
                        <div class="detail-label">Sunset</div>
                        <div class="detail-value">${sunset.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-icon">🌡️</div>
                        <div class="detail-label">High / Low</div>
                        <div class="detail-value">${this.currentWeather.temp_max}° / ${this.currentWeather.temp_min}°</div>
                    </div>
                </div>
            </div>
        `;
        
        const cards = currentEl.querySelectorAll('.detail-card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.05}s`;
        });
    }
    
    renderWeeklyForecast() {
        const forecastEl = document.getElementById('weeklyForecast');
        if (!forecastEl || !this.forecast || this.forecast.length === 0) return;
        
        const html = this.forecast.map((day, index) => `
            <div class="forecast-card" 
                 onclick="WeatherWidget.getInstance().showDayDetail('${day.date}')"
                 style="animation-delay: ${index * 0.05}s">
                <div class="forecast-card-header">
                    <div class="forecast-day">${this.formatDayName(day.day_name, index)}</div>
                    <div class="forecast-date">${this.formatDate(day.date)}</div>
                </div>
                <div class="forecast-icon">${this.getWeatherEmoji(day.condition)}</div>
                <div class="forecast-temps">
                    <span class="temp-high">${day.temp_max}°</span>
                    <span class="temp-divider">/</span>
                    <span class="temp-low">${day.temp_min}°</span>
                </div>
                <div class="forecast-condition">${day.description}</div>
                <div class="forecast-details">
                    <div class="detail-item">
                        <span class="detail-item-icon">💧</span>
                        <span class="detail-item-value">${day.precipitation}%</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-icon">💨</span>
                        <span class="detail-item-value">${day.wind_speed} km/h</span>
                    </div>
                </div>
                <div class="forecast-card-glow"></div>
            </div>
        `).join('');
        
        forecastEl.innerHTML = html;
    }
    
    renderHourlyForecast() {
        const hourlyEl = document.getElementById('hourlyForecast');
        if (!hourlyEl || !this.hourlyForecast.length) return;
        
        const html = this.hourlyForecast.map((hour, index) => `
            <div class="hourly-card" style="animation-delay: ${index * 0.03}s">
                <div class="hourly-time">${hour.time}</div>
                <div class="hourly-icon">${this.getWeatherEmoji(hour.condition)}</div>
                <div class="hourly-temp">${hour.temperature}°</div>
                <div class="hourly-precip">
                    <span class="precip-icon">💧</span>
                    <span class="precip-value">${hour.precipitation}%</span>
                </div>
            </div>
        `).join('');
        
        hourlyEl.innerHTML = html;
    }
    
    renderInsights() {
        const insightsEl = document.getElementById('weatherInsights');
        if (!insightsEl) return;
        
        const insights = this.generateInsights();
        
        if (insights.length === 0) {
            insightsEl.innerHTML = `
                <div class="insight-card">
                    <div class="insight-header">
                        <div class="insight-icon">ℹ️</div>
                        <div class="insight-title">No insights available</div>
                    </div>
                    <div class="insight-body">Check back later for AI-powered weather insights.</div>
                </div>
            `;
            return;
        }
        
        const html = insights.map((insight, index) => `
            <div class="insight-card" style="animation-delay: ${index * 0.1}s">
                <div class="insight-header">
                    <div class="insight-icon">${insight.icon}</div>
                    <div class="insight-title">${insight.title}</div>
                </div>
                <div class="insight-body">${insight.body}</div>
            </div>
        `).join('');
        
        insightsEl.innerHTML = html;
    }
    
    renderWeatherDetails() {
        const detailsEl = document.getElementById('weatherDetails');
        if (!detailsEl || !this.currentWeather) return;
        
        const sunrise = new Date(this.currentWeather.sunrise * 1000);
        const sunset = new Date(this.currentWeather.sunset * 1000);
        const daylightMinutes = Math.round((sunset - sunrise) / 1000 / 60);
        const daylightHours = Math.floor(daylightMinutes / 60);
        const daylightMins = daylightMinutes % 60;
        
        detailsEl.innerHTML = `
            <div class="detail-card">
                <div class="detail-icon">🌅</div>
                <div class="detail-label">Daylight</div>
                <div class="detail-value">${daylightHours}h ${daylightMins}m</div>
            </div>
            <div class="detail-card">
                <div class="detail-icon">🧭</div>
                <div class="detail-label">Wind Direction</div>
                <div class="detail-value">${this.getWindDirection(this.currentWeather.wind_direction)}</div>
            </div>
            <div class="detail-card">
                <div class="detail-icon">🌡️</div>
                <div class="detail-label">Dew Point</div>
                <div class="detail-value">${this.calculateDewPoint(this.currentWeather.temperature, this.currentWeather.humidity)}°C</div>
            </div>
            <div class="detail-card">
                <div class="detail-icon">🌫️</div>
                <div class="detail-label">Cloud Type</div>
                <div class="detail-value">${this.getCloudType(this.currentWeather.clouds)}</div>
            </div>
        `;
    }
    
    generateInsights() {
        const insights = [];
        
        if (!this.currentWeather || !this.forecast) return insights;
        
        if (this.currentWeather.temperature > 30) {
            insights.push({
                icon: '🌡️',
                title: 'Hot Day Ahead',
                body: `It's ${this.currentWeather.temperature}°C! Stay hydrated and seek shade during peak hours (11 AM - 3 PM). Apply SPF 30+ sunscreen every 2 hours if outdoors.`
            });
        } else if (this.currentWeather.temperature < 10) {
            insights.push({
                icon: '🥶',
                title: 'Cold Weather Alert',
                body: `Bundle up! It's only ${this.currentWeather.temperature}°C. Wear multiple layers, protect extremities, and keep warm indoors when possible.`
            });
        }
        
        const highRainChance = this.forecast.some(day => day.precipitation > 70);
        if (highRainChance) {
            const rainyDay = this.forecast.find(day => day.precipitation > 70);
            insights.push({
                icon: '☔',
                title: 'Rain Expected Soon',
                body: `Heavy rain likely on ${rainyDay.day_name} (${rainyDay.precipitation}% chance). Plan indoor activities and don't forget your umbrella!`
            });
        }
        
        if (this.currentWeather.wind_speed > 40) {
            insights.push({
                icon: '💨',
                title: 'Strong Wind Warning',
                body: `Windy conditions of ${this.currentWeather.wind_speed} km/h from the ${this.getWindDirection(this.currentWeather.wind_direction)}. Secure loose objects and be cautious when driving high-profile vehicles.`
            });
        }
        
        const hour = new Date().getHours();
        if (this.currentWeather.clouds < 30 && hour >= 10 && hour <= 16) {
            insights.push({
                icon: '☀️',
                title: 'High UV Index',
                body: 'Clear skies mean strong UV rays during peak hours. Wear SPF 30+ sunscreen, UV-blocking sunglasses, and a wide-brimmed hat. Seek shade between 11 AM - 3 PM.'
            });
        }
        
        if (this.currentWeather.temperature >= 18 && 
            this.currentWeather.temperature <= 26 && 
            this.currentWeather.clouds < 50 &&
            this.currentWeather.wind_speed < 20) {
            insights.push({
                icon: '🌤️',
                title: 'Perfect Weather Conditions',
                body: 'Ideal conditions for outdoor activities! Temperature is comfortable, winds are light, and skies are mostly clear. Great time for hiking, picnics, or outdoor sports.'
            });
        }
        
        if (this.currentWeather.humidity > 80) {
            insights.push({
                icon: '💧',
                title: 'High Humidity',
                body: `Humidity is at ${this.currentWeather.humidity}%, making it feel muggier than the actual temperature. Stay hydrated and avoid strenuous outdoor activities during peak heat.`
            });
        } else if (this.currentWeather.humidity < 30) {
            insights.push({
                icon: '🌵',
                title: 'Low Humidity',
                body: `Very dry conditions with ${this.currentWeather.humidity}% humidity. Use moisturizer, drink extra water, and consider using a humidifier indoors.`
            });
        }
        
        if (this.currentWeather.visibility < 5) {
            insights.push({
                icon: '🌫️',
                title: 'Reduced Visibility',
                body: `Visibility is limited to ${this.currentWeather.visibility} km. Drive carefully, use headlights, and maintain extra following distance on the road.`
            });
        }
        
        if (this.forecast.length >= 2) {
            const tempChange = this.forecast[1].temp_max - this.forecast[0].temp_max;
            if (Math.abs(tempChange) >= 5) {
                insights.push({
                    icon: tempChange > 0 ? '📈' : '📉',
                    title: tempChange > 0 ? 'Warming Trend' : 'Cooling Trend',
                    body: `Temperatures ${tempChange > 0 ? 'rising' : 'dropping'} by ${Math.abs(tempChange)}°C tomorrow. ${tempChange > 0 ? 'Dress lighter' : 'Pack warmer clothes'} than today.`
                });
            }
        }
        
        return insights;
    }
    
    checkWeatherAlerts() {
        const alertsEl = document.getElementById('weatherAlerts');
        if (!alertsEl || !this.currentWeather) return;
        
        const alerts = [];
        
        if (this.currentWeather.temperature > 35) {
            alerts.push({
                type: 'danger',
                icon: '🌡️',
                title: 'Extreme Heat Warning',
                message: 'Dangerously hot conditions. Stay indoors in air conditioning if possible, stay hydrated (drink water every 15-20 minutes), and check on elderly neighbors and pets.'
            });
        } else if (this.currentWeather.temperature < 5) {
            alerts.push({
                type: 'warning',
                icon: '❄️',
                title: 'Freezing Weather Alert',
                message: 'Near-freezing temperatures. Protect pipes, bring pets indoors, and wear insulated, layered clothing. Watch for ice on roads and walkways.'
            });
        }
        
        if (this.currentWeather.wind_speed > 50) {
            alerts.push({
                type: 'danger',
                icon: '💨',
                title: 'Severe Wind Warning',
                message: 'Dangerous wind conditions exceeding 50 km/h. Avoid driving high-profile vehicles, secure or bring indoors all loose outdoor items, and stay away from trees and power lines.'
            });
        } else if (this.currentWeather.wind_speed > 35) {
            alerts.push({
                type: 'warning',
                icon: '💨',
                title: 'Strong Wind Advisory',
                message: 'Strong winds expected. Secure outdoor furniture, be cautious while driving, and avoid outdoor activities involving lightweight objects.'
            });
        }
        
        if (this.currentWeather.visibility < 2) {
            alerts.push({
                type: 'danger',
                icon: '🌫️',
                title: 'Severe Visibility Warning',
                message: 'Extremely poor visibility below 2 km. Avoid non-essential travel. If driving is necessary, use fog lights, reduce speed significantly, and increase following distance.'
            });
        } else if (this.currentWeather.visibility < 5) {
            alerts.push({
                type: 'warning',
                icon: '🌫️',
                title: 'Low Visibility Advisory',
                message: 'Limited visibility conditions. Drive carefully with headlights on, reduce speed, and use fog lights if available.'
            });
        }
        
        const todayForecast = this.forecast[0];
        if (todayForecast && todayForecast.precipitation > 80) {
            alerts.push({
                type: 'warning',
                icon: '🌧️',
                title: 'Heavy Rain Warning',
                message: `Very high chance of rain (${todayForecast.precipitation}%). Carry an umbrella, wear waterproof clothing, and watch for flooding in low-lying areas. Avoid driving through standing water.`
            });
        } else if (todayForecast && todayForecast.precipitation > 60) {
            alerts.push({
                type: 'info',
                icon: '🌦️',
                title: 'Rain Likely Today',
                message: `${todayForecast.precipitation}% chance of rain. Carry an umbrella and plan for possible delays if traveling.`
            });
        }
        
        if (this.currentWeather.humidity > 85 && this.currentWeather.temperature > 25) {
            alerts.push({
                type: 'warning',
                icon: '💧',
                title: 'High Heat Index',
                message: 'Combination of high temperature and humidity creates uncomfortable conditions. Limit outdoor exposure, stay hydrated, and take frequent breaks in air-conditioned spaces.'
            });
        }
        
        if (alerts.length > 0) {
            alertsEl.innerHTML = alerts.map((alert, index) => `
                <div class="weather-alert alert-${alert.type}" style="animation-delay: ${index * 0.1}s">
                    <div class="alert-icon">${alert.icon}</div>
                    <div class="alert-content">
                        <h4 class="alert-title">${alert.title}</h4>
                        <p class="alert-message">${alert.message}</p>
                    </div>
                    <button class="alert-close" onclick="this.parentElement.remove()">✕</button>
                </div>
            `).join('');
        } else {
            alertsEl.innerHTML = '';
        }
    }
    
    async showDayDetail(date) {
        const modal = document.getElementById('dayDetailModal');
        const content = document.getElementById('dayDetailContent');
        
        if (!modal || !content) return;
        
        modal.classList.add('active');
        content.innerHTML = `
            <div class="weather-loading">
                <div class="loading-animation">
                    <div class="loading-cloud">☁️</div>
                    <div class="loading-sun">☀️</div>
                </div>
                <p class="loading-text">Loading detailed forecast...</p>
            </div>
        `;
        
        try {
            const url = `/weather/api/api.php?action=detailed&date=${date}&lat=${this.location.lat}&lon=${this.location.lng}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            content.innerHTML = `
                <div class="detail-header">
                    <h2>
                        <span class="detail-emoji">${this.getWeatherEmoji(data.dominant_condition)}</span>
                        ${data.day_name}, ${this.formatDate(data.date)}
                    </h2>
                    <p class="detail-summary">${data.summary}</p>
                </div>
                
                <div class="detail-temp-range">
                    <div class="temp-item temp-high">
                        <span class="temp-label">High</span>
                        <span class="temp-value">${data.temp_max}°</span>
                    </div>
                    <div class="temp-item temp-avg">
                        <span class="temp-label">Average</span>
                        <span class="temp-value">${data.temp_avg}°</span>
                    </div>
                    <div class="temp-item temp-low">
                        <span class="temp-label">Low</span>
                        <span class="temp-value">${data.temp_min}°</span>
                    </div>
                </div>
                
                <div class="detail-hourly-section">
                    <h3>📊 Hourly Breakdown</h3>
                    <div class="detail-hourly-grid">
                        ${data.hourly.map((hour, index) => `
                            <div class="detail-hourly-card" style="animation-delay: ${index * 0.03}s">
                                <div class="dhc-time">${hour.time}</div>
                                <div class="dhc-icon">${this.getWeatherEmoji(hour.condition)}</div>
                                <div class="dhc-temp">${hour.temperature}°</div>
                                <div class="dhc-details">
                                    <div class="dhc-detail"><span>Feels Like</span><span>${hour.feels_like}°</span></div>
                                    <div class="dhc-detail"><span>💧 Humidity</span><span>${hour.humidity}%</span></div>
                                    <div class="dhc-detail"><span>💨 Wind</span><span>${hour.wind_speed} km/h</span></div>
                                    <div class="dhc-detail"><span>🧭 Direction</span><span>${this.getWindDirection(hour.wind_direction)}</span></div>
                                    ${hour.wind_gust ? `<div class="dhc-detail"><span>💨 Gusts</span><span>${hour.wind_gust} km/h</span></div>` : ''}
                                    <div class="dhc-detail"><span>🌧️ Rain</span><span>${hour.precipitation}%</span></div>
                                    <div class="dhc-detail"><span>☁️ Clouds</span><span>${hour.clouds}%</span></div>
                                    <div class="dhc-detail"><span>👁️ Visibility</span><span>${hour.visibility} km</span></div>
                                    <div class="dhc-detail"><span>🌡️ Pressure</span><span>${hour.pressure} hPa</span></div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            
        } catch (error) {
            console.error('Failed to load day details:', error);
            content.innerHTML = `
                <div class="detail-error">
                    <div class="error-icon">⚠️</div>
                    <h3>Failed to Load Details</h3>
                    <p>${error.message}</p>
                    <button onclick="WeatherWidget.getInstance().showDayDetail('${date}')" class="glass-btn">
                        <span class="btn-icon">🔄</span>
                        <span class="btn-text">Retry</span>
                    </button>
                </div>
            `;
        }
    }
    
    closeModal() {
        const modal = document.getElementById('dayDetailModal');
        if (modal) {
            modal.classList.remove('active');
        }
    }
    
    setupViewToggles() {
        const tabs = document.querySelectorAll('.tab-btn');
        const forecastEl = document.getElementById('weeklyForecast');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                const view = tab.dataset.view;
                
                if (forecastEl) {
                    forecastEl.className = `weekly-forecast ${view}-view`;
                }
            });
        });
    }
    
    async refresh() {
        if (!this.location) {
            this.showNotification('Please select a location first', 'info');
            return;
        }
        
        console.log('🔄 Refreshing weather data...');
        
        this.cache.clear();
        
        const currentEl = document.getElementById('currentWeather');
        if (currentEl) {
            currentEl.innerHTML = `
                <div class="weather-loading">
                    <div class="loading-animation">
                        <div class="loading-cloud">☁️</div>
                        <div class="loading-sun">☀️</div>
                    </div>
                    <p class="loading-text">Refreshing weather data...</p>
                </div>
            `;
        }
        
        try {
            await this.loadAllWeatherData();
            this.renderAll();
            this.showNotification('Weather data refreshed!', 'success');
        } catch (error) {
            console.error('Refresh failed:', error);
            this.showNotification('Failed to refresh weather', 'error');
        }
    }
    
    getWeatherEmoji(condition, isDaytime = true) {
        const lower = condition.toLowerCase();
        
        if (lower.includes('clear') || lower.includes('sunny')) {
            return isDaytime ? '☀️' : '🌙';
        }
        if (lower.includes('partly') && lower.includes('cloud')) {
            return isDaytime ? '⛅' : '☁️';
        }
        if (lower.includes('overcast') || lower.includes('cloudy')) {
            return '☁️';
        }
        if (lower.includes('drizzle')) {
            return '🌦️';
        }
        if (lower.includes('rain')) {
            if (lower.includes('heavy')) return '🌧️';
            if (lower.includes('light')) return '🌦️';
            return '🌧️';
        }
        if (lower.includes('thunder') || lower.includes('storm')) {
            return '⛈️';
        }
        if (lower.includes('snow')) {
            return '❄️';
        }
        if (lower.includes('mist') || lower.includes('fog') || lower.includes('haze')) {
            return '🌫️';
        }
        if (lower.includes('wind')) {
            return '💨';
        }
        if (lower.includes('tornado')) {
            return '🌪️';
        }
        
        return isDaytime ? '🌤️' : '🌙';
    }
    
    getWindDirection(degrees) {
        const directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 
                          'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        const index = Math.round(degrees / 22.5) % 16;
        return directions[index];
    }
    
    getCloudType(cloudCover) {
        if (cloudCover === 0) return 'Clear';
        if (cloudCover <= 25) return 'Few Clouds';
        if (cloudCover <= 50) return 'Scattered';
        if (cloudCover <= 75) return 'Broken';
        return 'Overcast';
    }
    
    calculateDewPoint(temp, humidity) {
        const a = 17.27;
        const b = 237.7;
        const alpha = ((a * temp) / (b + temp)) + Math.log(humidity / 100);
        const dewPoint = (b * alpha) / (a - alpha);
        return Math.round(dewPoint);
    }
    
    formatDayName(dayName, index) {
        if (index === 0) return 'Today';
        if (index === 1) return 'Tomorrow';
        return dayName.substring(0, 3);
    }
    
    formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric' 
        });
    }
    
    saveToCache(key, data) {
        this.cache.set(key, {
            data,
            timestamp: Date.now()
        });
    }
    
    getFromCache(key) {
        const cached = this.cache.get(key);
        if (!cached) return null;
        
        const age = Date.now() - cached.timestamp;
        if (age > this.cacheTimeout) {
            this.cache.delete(key);
            return null;
        }
        
        return cached.data;
    }
    
    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `weather-notification notification-${type}`;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);
        
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new WeatherWidget();
});

window.WeatherWidget = WeatherWidget;

console.log('✅ Weather Widget JavaScript loaded with auto-location');