/**
 * ============================================
 * RELATIVES v3.0 - WEATHER CENTER
 * AUTO-LOADS CURRENT LOCATION ON PAGE LOAD
 * ENHANCED LOCATION SEARCH WITH AUTOCOMPLETE
 * ============================================
 */

console.log('%c🌤️ Weather Widget Loading v3.0...', 'font-size: 16px; font-weight: bold; color: #667eea;');

// ============================================
// WEATHER WIDGET
// ============================================

class WeatherWidget {
    static instance = null;

    // Popular South African cities for fuzzy autocomplete
    static POPULAR_CITIES = [
        { name: 'Johannesburg', state: 'Gauteng', country: 'ZA', lat: -26.2041, lon: 28.0473 },
        { name: 'Cape Town', state: 'Western Cape', country: 'ZA', lat: -33.9249, lon: 18.4241 },
        { name: 'Durban', state: 'KwaZulu-Natal', country: 'ZA', lat: -29.8587, lon: 31.0218 },
        { name: 'Pretoria', state: 'Gauteng', country: 'ZA', lat: -25.7461, lon: 28.1881 },
        { name: 'Port Elizabeth', state: 'Eastern Cape', country: 'ZA', lat: -33.9608, lon: 25.6022 },
        { name: 'Bloemfontein', state: 'Free State', country: 'ZA', lat: -29.0852, lon: 26.1596 },
        { name: 'East London', state: 'Eastern Cape', country: 'ZA', lat: -33.0153, lon: 27.9116 },
        { name: 'Kimberley', state: 'Northern Cape', country: 'ZA', lat: -28.7282, lon: 24.7499 },
        { name: 'Polokwane', state: 'Limpopo', country: 'ZA', lat: -23.9045, lon: 29.4689 },
        { name: 'Nelspruit', state: 'Mpumalanga', country: 'ZA', lat: -25.4653, lon: 30.9785 },
        { name: 'Pietermaritzburg', state: 'KwaZulu-Natal', country: 'ZA', lat: -29.6006, lon: 30.3794 },
        { name: 'Rustenburg', state: 'North West', country: 'ZA', lat: -25.6668, lon: 27.2419 },
        { name: 'George', state: 'Western Cape', country: 'ZA', lat: -33.9631, lon: 22.4617 },
        { name: 'Stellenbosch', state: 'Western Cape', country: 'ZA', lat: -33.9346, lon: 18.8668 },
        { name: 'Sandton', state: 'Gauteng', country: 'ZA', lat: -26.1076, lon: 28.0567 },
        { name: 'Soweto', state: 'Gauteng', country: 'ZA', lat: -26.2227, lon: 27.8577 },
        { name: 'Centurion', state: 'Gauteng', country: 'ZA', lat: -25.8603, lon: 28.1894 },
        { name: 'Benoni', state: 'Gauteng', country: 'ZA', lat: -26.1887, lon: 28.3211 },
        { name: 'Roodepoort', state: 'Gauteng', country: 'ZA', lat: -26.1625, lon: 27.8728 },
        { name: 'Midrand', state: 'Gauteng', country: 'ZA', lat: -25.9891, lon: 28.1268 },
        { name: 'Vereeniging', state: 'Gauteng', country: 'ZA', lat: -26.6736, lon: 27.9318 },
        { name: 'Welkom', state: 'Free State', country: 'ZA', lat: -27.9778, lon: 26.7358 },
        { name: 'Richards Bay', state: 'KwaZulu-Natal', country: 'ZA', lat: -28.7807, lon: 32.0383 },
        { name: 'Vanderbijlpark', state: 'Gauteng', country: 'ZA', lat: -26.7117, lon: 27.8381 },
        { name: 'Krugersdorp', state: 'Gauteng', country: 'ZA', lat: -26.1027, lon: 27.7666 },
        { name: 'Upington', state: 'Northern Cape', country: 'ZA', lat: -28.4478, lon: 21.2561 },
        { name: 'Paarl', state: 'Western Cape', country: 'ZA', lat: -33.7342, lon: 18.9622 },
        { name: 'Potchefstroom', state: 'North West', country: 'ZA', lat: -26.7145, lon: 27.0970 },
        { name: 'Witbank', state: 'Mpumalanga', country: 'ZA', lat: -25.8708, lon: 29.2347 },
        { name: 'Klerksdorp', state: 'North West', country: 'ZA', lat: -26.8667, lon: 26.6667 },
        { name: 'Alberton', state: 'Gauteng', country: 'ZA', lat: -26.2678, lon: 28.1222 },
        { name: 'Boksburg', state: 'Gauteng', country: 'ZA', lat: -26.2123, lon: 28.2555 },
        { name: 'Germiston', state: 'Gauteng', country: 'ZA', lat: -26.2155, lon: 28.1676 },
        { name: 'Randburg', state: 'Gauteng', country: 'ZA', lat: -26.0936, lon: 28.0064 },
        { name: 'Springs', state: 'Gauteng', country: 'ZA', lat: -26.2547, lon: 28.4428 },
        { name: 'Uitenhage', state: 'Eastern Cape', country: 'ZA', lat: -33.7667, lon: 25.4000 },
        { name: 'Newcastle', state: 'KwaZulu-Natal', country: 'ZA', lat: -27.7500, lon: 29.9333 },
        { name: 'Grahamstown', state: 'Eastern Cape', country: 'ZA', lat: -33.3042, lon: 26.5312 },
        { name: 'Knysna', state: 'Western Cape', country: 'ZA', lat: -34.0356, lon: 23.0488 },
        { name: 'Hermanus', state: 'Western Cape', country: 'ZA', lat: -34.4187, lon: 19.2345 },
        { name: 'Mossel Bay', state: 'Western Cape', country: 'ZA', lat: -34.1830, lon: 22.1458 },
        { name: 'Worcester', state: 'Western Cape', country: 'ZA', lat: -33.6461, lon: 19.4483 },
        { name: 'Franschhoek', state: 'Western Cape', country: 'ZA', lat: -33.9133, lon: 19.1200 },
        { name: 'Oudtshoorn', state: 'Western Cape', country: 'ZA', lat: -33.5878, lon: 22.2015 },
        { name: 'Somerset West', state: 'Western Cape', country: 'ZA', lat: -34.0849, lon: 18.8506 },
        { name: 'Ballito', state: 'KwaZulu-Natal', country: 'ZA', lat: -29.5390, lon: 31.2140 },
        { name: 'Umhlanga', state: 'KwaZulu-Natal', country: 'ZA', lat: -29.7256, lon: 31.0856 }
    ];

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

        await this.loadAllWeatherData();
        this.renderAll();
        
        console.log('✅ Weather loaded from tracked location');
    }
    
    updateLocationDisplay() {
        if (!this.location?.city) return;
        const locationEl = document.getElementById('weatherLocation');
        if (locationEl) {
            locationEl.innerHTML = `
                <span class="location-icon">📍</span>
                <span class="location-text">${this.location.city}</span>
            `;
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

        // Handle keyboard navigation
        let selectedIndex = -1;

        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            selectedIndex = -1;

            clearTimeout(this.searchTimeout);

            if (query.length < 1) {
                if (searchResults) {
                    searchResults.style.display = 'none';
                }
                return;
            }

            // Show local results immediately (no delay)
            const localResults = this.fuzzySearchLocal(query);
            if (localResults.length > 0) {
                this.renderSearchResults(localResults, query, true);
            }

            // Then fetch API results with short delay
            if (query.length >= 2) {
                this.searchTimeout = setTimeout(() => {
                    this.searchLocation(query, localResults);
                }, 150);
            }
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', (e) => {
            const items = searchResults.querySelectorAll('.search-result-item:not(.no-results)');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                this.highlightSearchItem(items, selectedIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                this.highlightSearchItem(items, selectedIndex);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                items[selectedIndex].click();
            } else if (e.key === 'Escape') {
                searchResults.style.display = 'none';
                selectedIndex = -1;
            }
        });

        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                if (searchResults) {
                    searchResults.style.display = 'none';
                }
            }
        });
    }

    highlightSearchItem(items, index) {
        items.forEach((item, i) => {
            item.classList.toggle('highlighted', i === index);
        });
        if (items[index]) {
            items[index].scrollIntoView({ block: 'nearest' });
        }
    }

    // Fuzzy search through local cities database
    fuzzySearchLocal(query) {
        const lowerQuery = query.toLowerCase();
        const results = [];

        for (const city of WeatherWidget.POPULAR_CITIES) {
            const lowerName = city.name.toLowerCase();
            const lowerState = city.state.toLowerCase();

            // Calculate fuzzy match score
            let score = 0;

            // Exact start match (highest priority)
            if (lowerName.startsWith(lowerQuery)) {
                score = 100 - lowerName.length; // Shorter names rank higher
            }
            // Contains match
            else if (lowerName.includes(lowerQuery)) {
                score = 50;
            }
            // State match
            else if (lowerState.includes(lowerQuery)) {
                score = 30;
            }
            // Fuzzy character match (handles typos)
            else if (this.fuzzyMatch(lowerQuery, lowerName)) {
                score = 20;
            }

            if (score > 0) {
                results.push({
                    ...city,
                    score,
                    display: `${city.name}, ${city.state}, South Africa`,
                    isLocal: true
                });
            }
        }

        // Sort by score (highest first) and limit to 5
        return results
            .sort((a, b) => b.score - a.score)
            .slice(0, 5);
    }

    // Simple fuzzy matching for typo tolerance
    fuzzyMatch(query, target) {
        if (query.length < 2) return false;

        let queryIndex = 0;
        let matches = 0;

        for (let i = 0; i < target.length && queryIndex < query.length; i++) {
            if (target[i] === query[queryIndex]) {
                matches++;
                queryIndex++;
            }
        }

        // Allow up to 2 missing characters for typo tolerance
        return matches >= query.length - 2 && matches >= query.length * 0.6;
    }

    renderSearchResults(results, query, isLoading = false) {
        const searchResults = document.getElementById('searchResults');
        if (!searchResults) return;

        const lowerQuery = query.toLowerCase();

        const html = results.map(result => {
            // Highlight matching text
            const highlightedName = this.highlightMatch(result.name, lowerQuery);
            const locationIcon = result.isLocal ? '📍' : '🌍';

            return `
                <div class="search-result-item"
                     data-lat="${result.lat}"
                     data-lon="${result.lon}"
                     data-name="${result.display || result.name}">
                    <div class="result-icon">${locationIcon}</div>
                    <div class="result-content">
                        <div class="result-name">${highlightedName}</div>
                        <div class="result-details">${result.state ? result.state + ', ' : ''}${result.country === 'ZA' ? 'South Africa' : result.country}</div>
                    </div>
                </div>
            `;
        }).join('');

        const loadingIndicator = isLoading ? `
            <div class="search-loading">
                <span class="loading-dot"></span>
                <span class="loading-dot"></span>
                <span class="loading-dot"></span>
            </div>
        ` : '';

        searchResults.innerHTML = html + loadingIndicator;
        searchResults.style.display = 'block';

        // Attach click handlers
        searchResults.querySelectorAll('.search-result-item').forEach(item => {
            item.addEventListener('click', () => {
                this.selectLocation(
                    parseFloat(item.dataset.lat),
                    parseFloat(item.dataset.lon),
                    item.dataset.name
                );
            });
        });
    }

    highlightMatch(text, query) {
        if (!query) return text;
        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    async searchLocation(query, localResults = []) {
        const searchResults = document.getElementById('searchResults');
        if (!searchResults) return;

        try {
            const response = await fetch(`/weather/api/api.php?action=search&q=${encodeURIComponent(query)}`);
            const data = await response.json();

            // Combine local and API results, removing duplicates
            let allResults = [...localResults];

            if (data.results && data.results.length > 0) {
                const localNames = new Set(localResults.map(r => r.name.toLowerCase()));

                for (const result of data.results) {
                    if (!localNames.has(result.name.toLowerCase())) {
                        allResults.push({
                            ...result,
                            display: result.display,
                            isLocal: false
                        });
                    }
                }
            }

            if (allResults.length > 0) {
                // Limit to 8 results total
                this.renderSearchResults(allResults.slice(0, 8), query, false);
            } else {
                searchResults.innerHTML = `
                    <div class="search-result-item no-results">
                        <div class="result-icon">🔍</div>
                        <div class="result-content">
                            <div class="result-name">No locations found</div>
                            <div class="result-details">Try a different spelling or nearby city</div>
                        </div>
                    </div>
                `;
                searchResults.style.display = 'block';
            }
        } catch (error) {
            console.error('Location search error:', error);
            // Keep showing local results if API fails
            if (localResults.length > 0) {
                this.renderSearchResults(localResults, query, false);
            }
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

            // Set city name from weather response (avoids extra API call)
            if (!this.location.city && (data.location_name || data.location)) {
                this.location.city = data.location_name || data.location;
                this.updateLocationDisplay();
            }

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

        // Compact hero-style current weather (like schedule greeting)
        currentEl.innerHTML = `
            <div class="weather-display">
                <div class="weather-icon-main">${this.getWeatherEmoji(this.currentWeather.condition, isDaytime)}</div>
                <div class="weather-temp">${this.currentWeather.temperature}°</div>
                <div class="weather-desc">${this.currentWeather.description}</div>
                <div class="weather-feels">Feels like ${this.currentWeather.feels_like}°C</div>
                <div class="weather-location">
                    <span>📍</span>
                    <span>${this.location?.city || 'Current Location'}</span>
                </div>
            </div>
        `;

        // Show quick actions
        const actionsEl = document.getElementById('weatherActions');
        if (actionsEl) actionsEl.style.display = 'flex';

        // Update stats bar
        this.updateStatsBar();
    }

    updateStatsBar() {
        const statsEl = document.getElementById('weatherStats');
        if (!statsEl || !this.currentWeather) return;
        statsEl.style.display = 'block';
    }
    
    renderWeeklyForecast() {
        const forecastEl = document.getElementById('weeklyForecast');
        if (!forecastEl || !this.forecast || this.forecast.length === 0) return;

        const html = this.forecast.map((day, index) => `
            <div class="note-card"
                 onclick="WeatherWidget.getInstance().showDayDetail('${day.date}')"
                 style="animation-delay: ${index * 0.05}s">
                <div class="forecast-day">${this.formatDayName(day.day_name, index)}</div>
                <div class="forecast-date">${this.formatDate(day.date)}</div>
                <div class="forecast-icon">${this.getWeatherEmoji(day.condition)}</div>
                <div class="forecast-temps">
                    <span class="temp-high">${day.temp_max}°</span>
                    <span class="temp-low">${day.temp_min}°</span>
                </div>
                <div class="forecast-condition">${day.description}</div>
            </div>
        `).join('');

        forecastEl.innerHTML = html;
    }

    setView(view) {
        const forecastEl = document.getElementById('weeklyForecast');
        const buttons = document.querySelectorAll('.filter-btn[data-view]');

        buttons.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });

        if (forecastEl) {
            forecastEl.classList.toggle('list-view', view === 'list');
        }
    }
    
    renderHourlyForecast() {
        const hourlyEl = document.getElementById('hourlyForecast');
        if (!hourlyEl || !this.hourlyForecast.length) return;

        const html = this.hourlyForecast.map((hour, index) => `
            <div class="hourly-card" style="animation-delay: ${index * 0.03}s">
                <div class="hourly-time">${hour.time}</div>
                <div class="hourly-icon">${this.getWeatherEmoji(hour.condition)}</div>
                <div class="hourly-temp">${hour.temperature}°</div>
            </div>
        `).join('');

        hourlyEl.innerHTML = html;
    }

    toggleUnits() {
        // Toggle between Celsius and Fahrenheit
        this.useFahrenheit = !this.useFahrenheit;
        if (this.location) {
            this.refresh();
        }
    }

    shareWeather() {
        if (!this.currentWeather || !this.location) {
            this.showNotification('No weather data to share', 'error');
            return;
        }
        document.getElementById('shareModal').classList.add('active');
    }

    closeShareModal() {
        document.getElementById('shareModal').classList.remove('active');
    }

    exportWeatherPDF() {
        this.closeShareModal();
        if (!this.currentWeather || !this.location) return;

        const location = this.location.city || 'Your Location';
        const weather = this.currentWeather;
        const forecast = this.forecast || [];

        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Weather - ${location}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
                    .current { background: #f5f5f5; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                    .current-temp { font-size: 48px; font-weight: bold; }
                    .current-desc { font-size: 18px; color: #666; }
                    .forecast { display: flex; gap: 10px; flex-wrap: wrap; }
                    .forecast-day { background: #f5f5f5; padding: 15px; border-radius: 8px; text-align: center; min-width: 100px; }
                    .forecast-date { font-weight: bold; margin-bottom: 5px; }
                    .forecast-temp { font-size: 14px; color: #666; }
                </style>
            </head>
            <body>
                <h1>🌤️ Weather for ${location}</h1>
                <div class="current">
                    <div class="current-temp">${weather.temperature}°${this.useFahrenheit ? 'F' : 'C'}</div>
                    <div class="current-desc">${weather.description}</div>
                    <p>💧 Humidity: ${weather.humidity}% | 💨 Wind: ${weather.wind_speed} km/h</p>
                </div>
                ${forecast.length > 0 ? `
                    <h2>📅 5-Day Forecast</h2>
                    <div class="forecast">
                        ${forecast.slice(0, 5).map(day => `
                            <div class="forecast-day">
                                <div class="forecast-date">${new Date(day.date).toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric' })}</div>
                                <div style="font-size: 24px;">${day.icon || '☁️'}</div>
                                <div class="forecast-temp">${day.temp_max}° / ${day.temp_min}°</div>
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
            </body>
            </html>
        `;

        const printWindow = window.open('', '_blank');
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.onload = function() {
            printWindow.print();
        };

        this.showNotification('📄 PDF ready to print!', 'success');
    }

    shareWeatherWhatsApp() {
        this.closeShareModal();
        if (!this.currentWeather || !this.location) return;

        const location = this.location.city || 'Your Location';
        const weather = this.currentWeather;
        const forecast = this.forecast || [];

        let message = `🌤️ *Weather in ${location}*\n\n`;
        message += `🌡️ *${weather.temperature}°${this.useFahrenheit ? 'F' : 'C'}* - ${weather.description}\n`;
        message += `💧 Humidity: ${weather.humidity}%\n`;
        message += `💨 Wind: ${weather.wind_speed} km/h\n`;

        if (forecast.length > 0) {
            message += `\n📅 *Forecast:*\n`;
            forecast.slice(0, 5).forEach(day => {
                const date = new Date(day.date).toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric' });
                message += `${date}: ${day.temp_max}°/${day.temp_min}° ${day.description || ''}\n`;
            });
        }

        const encodedMessage = encodeURIComponent(message);
        window.open(`https://wa.me/?text=${encodedMessage}`, '_blank');

        this.showNotification('💬 Opening WhatsApp...', 'success');
    }

    renderInsights() {
        const insightsEl = document.getElementById('weatherInsights');
        const sectionEl = document.getElementById('insightsSection');
        if (!insightsEl) return;

        const insights = this.generateInsights();

        if (insights.length === 0) {
            if (sectionEl) sectionEl.style.display = 'none';
            return;
        }

        if (sectionEl) sectionEl.style.display = 'block';

        const html = insights.map((insight, index) => `
            <div class="insight-card" style="animation-delay: ${index * 0.1}s">
                <button class="insight-close" onclick="this.parentElement.remove(); WeatherWidget.getInstance().checkInsightsEmpty();">✕</button>
                <div class="insight-header">
                    <div class="insight-icon">${insight.icon}</div>
                    <div class="insight-title">${insight.title}</div>
                </div>
                <div class="insight-body">${insight.body}</div>
            </div>
        `).join('');

        insightsEl.innerHTML = html;
    }

    checkInsightsEmpty() {
        const insightsEl = document.getElementById('weatherInsights');
        const sectionEl = document.getElementById('insightsSection');
        if (insightsEl && sectionEl && insightsEl.children.length === 0) {
            sectionEl.style.display = 'none';
        }
    }
    
    renderWeatherDetails() {
        if (!this.currentWeather) return;

        const sunrise = new Date(this.currentWeather.sunrise * 1000);
        const sunset = new Date(this.currentWeather.sunset * 1000);

        // Update temperature range from forecast
        const todayTempsEl = document.getElementById('todayTemps');
        if (todayTempsEl && this.forecast && this.forecast[0]) {
            const today = this.forecast[0];
            todayTempsEl.innerHTML = `
                <span class="temp-hi">${today.temp_max}°</span>
                <span class="temp-sep">/</span>
                <span class="temp-lo">${today.temp_min}°</span>
            `;
        }

        // Update stat cards
        this.updateStatCard('statHumidity', `${this.currentWeather.humidity}%`);
        this.updateStatCard('statWind', `${this.currentWeather.wind_speed} km/h`);
        this.updateStatCard('statVisibility', `${this.currentWeather.visibility} km`);
        this.updateStatCard('statPressure', `${this.currentWeather.pressure} hPa`);
        this.updateStatCard('statUV', this.currentWeather.clouds < 30 ? 'High' : 'Low');
        this.updateStatCard('statSunrise', sunrise.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}));
        this.updateStatCard('statSunset', sunset.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}));

        // Rain chance from forecast
        if (this.forecast && this.forecast[0]) {
            this.updateStatCard('statRain', `${this.forecast[0].precipitation || 0}%`);
        }
    }

    updateStatCard(id, value) {
        const el = document.getElementById(id);
        if (el) {
            const valueEl = el.querySelector('.stat-value');
            if (valueEl) {
                valueEl.textContent = value;
            }
        }
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
                                    <div class="dhc-detail"><span>🌡️ Feels Like</span><span>${hour.feels_like}°</span></div>
                                    <div class="dhc-detail"><span>💧 Humidity</span><span>${hour.humidity}%</span></div>
                                    <div class="dhc-detail"><span>💨 Wind</span><span>${hour.wind_speed} km/h ${this.getWindDirection(hour.wind_direction)}</span></div>
                                    ${hour.wind_gust ? `<div class="dhc-detail"><span>💨 Gusts</span><span>${hour.wind_gust} km/h</span></div>` : ''}
                                    <div class="dhc-detail"><span>🌧️ Rain Chance</span><span>${hour.precipitation}%</span></div>
                                    ${hour.rain_mm > 0 ? `<div class="dhc-detail"><span>☔ Rain</span><span>${hour.rain_mm} mm</span></div>` : ''}
                                    ${hour.snow_mm > 0 ? `<div class="dhc-detail"><span>❄️ Snow</span><span>${hour.snow_mm} mm</span></div>` : ''}
                                    <div class="dhc-detail"><span>☁️ Clouds</span><span>${hour.clouds}%</span></div>
                                    <div class="dhc-detail"><span>👁️ Visibility</span><span>${hour.visibility} km</span></div>
                                    <div class="dhc-detail"><span>🌡️ Pressure</span><span>${hour.pressure} hPa</span></div>
                                    ${hour.dew_point !== undefined ? `<div class="dhc-detail"><span>💦 Dew Point</span><span>${hour.dew_point}°</span></div>` : ''}
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