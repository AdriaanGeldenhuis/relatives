/**
 * ============================================
 * RELATIVES v3.1 - HOME JAVASCRIPT
 * Mobile-First Native App Optimized
 * WITH ENHANCED WEATHER WIDGET (Rain % Included)
 * ============================================ */

console.log('🏠 Home JavaScript v3.1 loading...');

// ============================================
// MOBILE DETECTION & OPTIMIZATION
// ============================================
const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
const isNativeApp = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

console.log('📱 Device Info:', { isMobile, isNativeApp, isTouchDevice });

// ============================================
// PARTICLE SYSTEM - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS, class is a no-op stub
// ============================================
class ParticleSystem {
    constructor(canvasId) {
        // Disabled - canvas hidden via CSS for performance
        this.animationId = null;
    }
    animate() {
        // No-op: particle system disabled for performance
    }
}

// ============================================
// HOME WEATHER WIDGET (ENHANCED WITH RAIN %)
// ============================================
var HomeWeatherWidgetInstance = null;

function HomeWeatherWidget() {
    if (HomeWeatherWidgetInstance) return HomeWeatherWidgetInstance;

    this.weatherData = null;
    this.location = null;
    this.container = document.getElementById('homeWeatherWidget');

    HomeWeatherWidgetInstance = this;
    this.init();
}

HomeWeatherWidget.getInstance = function() {
    if (!HomeWeatherWidgetInstance) {
        HomeWeatherWidgetInstance = new HomeWeatherWidget();
    }
    return HomeWeatherWidgetInstance;
};

HomeWeatherWidget.prototype.init = function() {
    var self = this;
    if (!this.container) return;

    console.log('🌤️ Initializing Home Weather Widget...');

    // Check for user location from tracking
    if (window.USER_LOCATION && window.USER_LOCATION.lat && window.USER_LOCATION.lng) {
        console.log('📍 Using tracked location:', window.USER_LOCATION);
        this.location = {
            lat: window.USER_LOCATION.lat,
            lng: window.USER_LOCATION.lng
        };
        this.loadWeather();
    } else {
        console.log('📍 No tracked location, trying browser location...');
        this.requestBrowserLocation();
    }
};

HomeWeatherWidget.prototype.requestBrowserLocation = function() {
    var self = this;
    if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                self.location = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                self.loadWeather();
            },
            function(error) {
                console.warn('Geolocation error:', error);
                self.showManualSearch();
            }
        );
    } else {
        this.showManualSearch();
    }
};

HomeWeatherWidget.prototype.showManualSearch = function() {
    if (!this.container) return;

    this.container.innerHTML =
        '<div class="weather-manual-search">' +
            '<div class="wms-icon">🌍</div>' +
            '<h3>Location Access Needed</h3>' +
            '<p>Enable location services or search for your city to view weather</p>' +
            '<a href="/weather/" class="wms-btn">' +
                '<span>🔍</span>' +
                '<span>Search Weather</span>' +
            '</a>' +
        '</div>';
};

HomeWeatherWidget.prototype.loadWeather = function() {
    var self = this;
    if (!this.location) return;

    Promise.all([
        fetch('/weather/api/api.php?action=current&lat=' + this.location.lat + '&lon=' + this.location.lng),
        fetch('/weather/api/api.php?action=forecast&lat=' + this.location.lat + '&lon=' + this.location.lng)
    ]).then(function(responses) {
        var currentRes = responses[0];
        var forecastRes = responses[1];

        if (!currentRes.ok) {
            throw new Error('HTTP ' + currentRes.status);
        }

        return currentRes.json().then(function(data) {
            if (data.error) {
                throw new Error(data.error);
            }

            self.weatherData = data;

            // Get today's high/low from forecast
            if (forecastRes.ok) {
                return forecastRes.json().then(function(forecastData) {
                    if (forecastData.forecast && forecastData.forecast[0]) {
                        self.weatherData.temp_high = forecastData.forecast[0].temp_max;
                        self.weatherData.temp_low = forecastData.forecast[0].temp_min;
                    }
                    self.render();
                    console.log('✅ Weather loaded:', data);
                });
            } else {
                self.render();
                console.log('✅ Weather loaded:', data);
            }
        });
    }).catch(function(error) {
        console.error('Weather load error:', error);
        self.showError();
    });
};

HomeWeatherWidget.prototype.render = function() {
    var self = this;
    if (!this.container || !this.weatherData) return;

    var weather = this.weatherData;
    var sunrise = new Date(weather.sunrise * 1000);
    var sunset = new Date(weather.sunset * 1000);
    var now = new Date();
    var isDaytime = now >= sunrise && now <= sunset;

    var weatherEmoji = this.getWeatherEmoji(weather.condition, isDaytime);

    // Calculate rain probability (from clouds and humidity)
    var rainProbability = this.calculateRainProbability(weather);

    var hiloHtml = weather.temp_high !== undefined ?
        '<div class="wwc-hilo">' +
            '<span class="wwc-hi">H: ' + weather.temp_high + '°</span>' +
            '<span class="wwc-lo">L: ' + weather.temp_low + '°</span>' +
        '</div>' : '';

    var rainClass = rainProbability >= 70 ? 'high-chance' : (rainProbability >= 40 ? 'medium-chance' : 'low-chance');

    this.container.innerHTML =
        '<div class="weather-widget-content" onclick="window.location.href=\'/weather/\'">' +
            '<div class="wwc-header">' +
                '<div class="wwc-location">' +
                    '<span class="wwc-location-icon">📍</span>' +
                    '<span class="wwc-location-name">' + weather.location + '</span>' +
                '</div>' +
                '<a href="/weather/" class="wwc-full-link" onclick="event.stopPropagation()">' +
                    '<span>View Full Forecast</span>' +
                    '<span class="wwc-arrow">→</span>' +
                '</a>' +
            '</div>' +
            '<div class="wwc-main">' +
                '<div class="wwc-current">' +
                    '<div class="wwc-icon">' + weatherEmoji + '</div>' +
                    '<div class="wwc-temp-group">' +
                        '<div class="wwc-temp">' + weather.temperature + '°</div>' +
                        '<div class="wwc-feels">Feels like ' + weather.feels_like + '°</div>' +
                        hiloHtml +
                    '</div>' +
                '</div>' +
                '<div class="wwc-description">' + weather.description + '</div>' +
                '<div class="wwc-details-grid">' +
                    '<div class="wwc-detail wwc-detail-rain ' + rainClass + '">' +
                        '<div class="wwc-detail-icon">💧</div>' +
                        '<div class="wwc-detail-content">' +
                            '<div class="wwc-detail-label">Rain Chance</div>' +
                            '<div class="wwc-detail-value">' + rainProbability + '%</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wwc-detail">' +
                        '<div class="wwc-detail-icon">💧</div>' +
                        '<div class="wwc-detail-content">' +
                            '<div class="wwc-detail-label">Humidity</div>' +
                            '<div class="wwc-detail-value">' + weather.humidity + '%</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wwc-detail">' +
                        '<div class="wwc-detail-icon">💨</div>' +
                        '<div class="wwc-detail-content">' +
                            '<div class="wwc-detail-label">Wind</div>' +
                            '<div class="wwc-detail-value">' + weather.wind_speed + ' km/h</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wwc-detail">' +
                        '<div class="wwc-detail-icon">☁️</div>' +
                        '<div class="wwc-detail-content">' +
                            '<div class="wwc-detail-label">Cloud Cover</div>' +
                            '<div class="wwc-detail-value">' + weather.clouds + '%</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wwc-detail">' +
                        '<div class="wwc-detail-icon">🌅</div>' +
                        '<div class="wwc-detail-content">' +
                            '<div class="wwc-detail-label">Sunrise</div>' +
                            '<div class="wwc-detail-value">' + sunrise.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wwc-detail">' +
                        '<div class="wwc-detail-icon">🌇</div>' +
                        '<div class="wwc-detail-content">' +
                            '<div class="wwc-detail-label">Sunset</div>' +
                            '<div class="wwc-detail-value">' + sunset.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}) + '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="wwc-footer">' +
                '<div class="wwc-powered">Powered by OpenWeather</div>' +
                '<div class="wwc-updated">Updated: ' + new Date().toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}) + '</div>' +
            '</div>' +
        '</div>';

    // Animate in
    setTimeout(function() {
        if (self.container) {
            self.container.classList.add('loaded');
        }
    }, 100);
};

HomeWeatherWidget.prototype.calculateRainProbability = function(weather) {
    // Calculate rain probability based on multiple factors
    var probability = 0;

    // Check if it's already raining
    var condition = weather.condition.toLowerCase();
    if (condition.indexOf('rain') !== -1 || condition.indexOf('drizzle') !== -1) {
        return 100;
    }
    if (condition.indexOf('thunder') !== -1 || condition.indexOf('storm') !== -1) {
        return 95;
    }

    // Base calculation on clouds and humidity
    var cloudFactor = weather.clouds * 0.4; // 40% weight
    var humidityFactor = (weather.humidity - 50) * 0.6; // 60% weight (normalized from 50%)

    probability = Math.max(0, Math.min(100, cloudFactor + humidityFactor));

    // Adjust based on conditions
    if (condition.indexOf('overcast') !== -1) {
        probability += 15;
    } else if (condition.indexOf('partly') !== -1 || condition.indexOf('scattered') !== -1) {
        probability += 5;
    } else if (condition.indexOf('clear') !== -1 || condition.indexOf('sunny') !== -1) {
        probability = Math.min(probability, 20);
    }

    // Cap at 100%
    return Math.min(100, Math.round(probability));
};

HomeWeatherWidget.prototype.showError = function() {
    if (!this.container) return;

    this.container.innerHTML =
        '<div class="weather-widget-error">' +
            '<div class="wwe-icon">⚠️</div>' +
            '<h3>Weather Unavailable</h3>' +
            '<p>Unable to load weather data. Please try again later.</p>' +
            '<button onclick="HomeWeatherWidget.getInstance().init()" class="wwe-retry">' +
                '<span>🔄</span>' +
                '<span>Retry</span>' +
            '</button>' +
        '</div>';
};

HomeWeatherWidget.prototype.getWeatherEmoji = function(condition, isDaytime) {
    if (isDaytime === undefined) isDaytime = true;
    var lower = condition.toLowerCase();

    if (lower.indexOf('clear') !== -1 || lower.indexOf('sunny') !== -1) {
        return isDaytime ? '☀️' : '🌙';
    }
    if (lower.indexOf('partly') !== -1 && lower.indexOf('cloud') !== -1) {
        return isDaytime ? '⛅' : '☁️';
    }
    if (lower.indexOf('overcast') !== -1 || lower.indexOf('cloudy') !== -1) {
        return '☁️';
    }
    if (lower.indexOf('drizzle') !== -1) {
        return '🌦️';
    }
    if (lower.indexOf('rain') !== -1) {
        if (lower.indexOf('heavy') !== -1) return '🌧️';
        if (lower.indexOf('light') !== -1) return '🌦️';
        return '🌧️';
    }
    if (lower.indexOf('thunder') !== -1 || lower.indexOf('storm') !== -1) {
        return '⛈️';
    }
    if (lower.indexOf('snow') !== -1) {
        return '❄️';
    }
    if (lower.indexOf('mist') !== -1 || lower.indexOf('fog') !== -1 || lower.indexOf('haze') !== -1) {
        return '🌫️';
    }
    if (lower.indexOf('wind') !== -1) {
        return '💨';
    }

    return isDaytime ? '🌤️' : '🌙';
};

HomeWeatherWidget.prototype.getVoiceSummary = function() {
    var self = this;
    if (!this.weatherData) {
        return this.loadWeather().then(function() {
            return self._getVoiceSummaryText();
        });
    }
    return Promise.resolve(this._getVoiceSummaryText());
};

HomeWeatherWidget.prototype._getVoiceSummaryText = function() {
    if (!this.weatherData) {
        return "Weather information is not available right now.";
    }

    var w = this.weatherData;
    var location = w.location || 'your location';
    var rainChance = this.calculateRainProbability(w);

    return 'Current weather in ' + location + ': ' + w.temperature + ' degrees and ' + w.description + '. ' +
           'Feels like ' + w.feels_like + ' degrees. ' +
           'Humidity is ' + w.humidity + ' percent with ' + w.wind_speed + ' kilometers per hour winds. ' +
           'Rain probability is ' + rainChance + ' percent.';
};

// ============================================
// NUMBER ANIMATION (OPTIMIZED)
// ============================================
function animateNumber(element, start, end, duration) {
    const startTime = performance.now();
    const range = end - start;
    
    function updateNumber(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        const easeOutElastic = (t) => {
            const c4 = (2 * Math.PI) / 3;
            return t === 0 ? 0 : t === 1 ? 1 :
                Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * c4) + 1;
        };
        
        const current = Math.floor(start + range * easeOutElastic(progress));
        element.textContent = current;
        
        if (progress < 1) {
            requestAnimationFrame(updateNumber);
        } else {
            element.textContent = end;
        }
    }
    
    requestAnimationFrame(updateNumber);
}

// ============================================
// 3D TILT EFFECT (MOBILE-SAFE)
// ============================================
class TiltEffect {
    constructor(element) {
        // Disable tilt on mobile for performance
        if (isMobile || isTouchDevice) {
            return;
        }
        
        this.element = element;
        this.width = element.offsetWidth;
        this.height = element.offsetHeight;
        this.settings = {
            max: 12,
            perspective: 1200,
            scale: 1.05,
            speed: 400,
            easing: 'cubic-bezier(0.03, 0.98, 0.52, 0.99)',
            glare: true
        };
        
        this.init();
    }
    
    init() {
        this.element.style.transform = 'perspective(1200px) rotateX(0deg) rotateY(0deg)';
        this.element.style.transition = `transform ${this.settings.speed}ms ${this.settings.easing}`;
        
        if (this.settings.glare && !this.element.querySelector('.tilt-glare')) {
            const glare = document.createElement('div');
            glare.className = 'tilt-glare';
            glare.style.cssText = `
                position: absolute;
                inset: 0;
                background: linear-gradient(135deg, 
                    rgba(255,255,255,0) 0%, 
                    rgba(255,255,255,0.1) 50%, 
                    rgba(255,255,255,0) 100%);
                opacity: 0;
                pointer-events: none;
                transition: opacity ${this.settings.speed}ms ${this.settings.easing};
                border-radius: inherit;
            `;
            this.element.style.position = 'relative';
            this.element.appendChild(glare);
        }
        
        this.element.addEventListener('mouseenter', () => this.onMouseEnter());
        this.element.addEventListener('mousemove', (e) => this.onMouseMove(e));
        this.element.addEventListener('mouseleave', () => this.onMouseLeave());
    }
    
    onMouseEnter() {
        this.width = this.element.offsetWidth;
        this.height = this.element.offsetHeight;
        
        const glare = this.element.querySelector('.tilt-glare');
        if (glare) glare.style.opacity = '1';
    }
    
    onMouseMove(e) {
        const rect = this.element.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        const percentX = (x / this.width) - 0.5;
        const percentY = (y / this.height) - 0.5;
        
        const tiltX = percentY * this.settings.max;
        const tiltY = -percentX * this.settings.max;
        
        this.element.style.transform = `
            perspective(${this.settings.perspective}px) 
            rotateX(${tiltX}deg) 
            rotateY(${tiltY}deg) 
            scale3d(${this.settings.scale}, ${this.settings.scale}, ${this.settings.scale})
        `;
        
        const glare = this.element.querySelector('.tilt-glare');
        if (glare) {
            const angle = Math.atan2(percentY, percentX) * (180 / Math.PI);
            glare.style.background = `
                linear-gradient(${angle + 45}deg, 
                    rgba(255,255,255,0) 0%, 
                    rgba(255,255,255,0.15) 50%, 
                    rgba(255,255,255,0) 100%)
            `;
        }
    }
    
    onMouseLeave() {
        this.element.style.transform = `
            perspective(${this.settings.perspective}px) 
            rotateX(0deg) 
            rotateY(0deg) 
            scale3d(1, 1, 1)
        `;
        
        const glare = this.element.querySelector('.tilt-glare');
        if (glare) glare.style.opacity = '0';
    }
}

// ============================================
// AI ASSISTANT (ENHANCED)
// ============================================
var AIAssistantInstance = null;

function AIAssistant() {
    if (AIAssistantInstance) return AIAssistantInstance;
    this.insights = [];
    AIAssistantInstance = this;
    this.init();
}

AIAssistant.getInstance = function() {
    if (!AIAssistantInstance) {
        AIAssistantInstance = new AIAssistant();
    }
    return AIAssistantInstance;
};

AIAssistant.prototype.init = function() {
    var self = this;
    console.log('🤖 Initializing AI Assistant...');
    this.generateInsights().then(function() {
        self.initActivityHeatmap();
        self.animateProgressCircles();
        console.log('✅ AI Assistant initialized');
    });
};

AIAssistant.prototype.generateInsights = function() {
    var self = this;
    var insightsEl = document.getElementById('aiInsights');
    if (!insightsEl) return Promise.resolve();

    return new Promise(function(resolve) {
        setTimeout(resolve, 1000);
    }).then(function() {
        var shoppingEl = document.querySelector('[href="/shopping/"] .stat-number');
        var eventsEl = document.querySelector('[href="/calendar/"] .stat-number');
        var messagesEl = document.querySelector('[href="/messages/"] .stat-number');
        var completedEl = document.querySelector('[onclick*="Analytics"] .stat-number');

        var stats = {
            shopping: parseInt((shoppingEl && shoppingEl.textContent) || 0),
            events: parseInt((eventsEl && eventsEl.textContent) || 0),
            messages: parseInt((messagesEl && messagesEl.textContent) || 0),
            completed: parseInt((completedEl && completedEl.textContent) || 0)
        };

        self.insights = [];

        if (stats.shopping > 10) {
            self.insights.push({
                icon: '🛒',
                text: 'You have ' + stats.shopping + ' pending shopping items. Consider grouping by store.',
                category: 'Shopping',
                priority: 'high'
            });
        } else if (stats.shopping > 0) {
            self.insights.push({
                icon: '🛒',
                text: stats.shopping + ' items on your shopping list. Well organized!',
                category: 'Shopping',
                priority: 'low'
            });
        }

        if (stats.events > 0) {
            self.insights.push({
                icon: '📅',
                text: stats.events + ' upcoming events this week. Stay on schedule!',
                category: 'Calendar',
                priority: 'medium'
            });
        } else {
            self.insights.push({
                icon: '📅',
                text: 'Your calendar is clear this week. Time to relax or plan ahead.',
                category: 'Calendar',
                priority: 'low'
            });
        }

        if (stats.messages > 5) {
            self.insights.push({
                icon: '💬',
                text: stats.messages + ' unread messages waiting. Your family is active!',
                category: 'Messages',
                priority: 'high'
            });
        }

        if (stats.completed > 20) {
            self.insights.push({
                icon: '🔥',
                text: 'Amazing! ' + stats.completed + ' tasks completed this week. You\'re on fire!',
                category: 'Productivity',
                priority: 'high'
            });
        }

        var hour = new Date().getHours();
        if (hour >= 9 && hour < 12) {
            self.insights.push({
                icon: '☕',
                text: 'Morning peak productivity! Perfect time for important tasks.',
                category: 'Productivity',
                priority: 'medium'
            });
        } else if (hour >= 17 && hour < 20) {
            self.insights.push({
                icon: '👨‍👩‍👧‍👦',
                text: 'Family time! Perfect moment to connect and share your day.',
                category: 'Family',
                priority: 'medium'
            });
        } else if (hour >= 22 || hour < 6) {
            self.insights.push({
                icon: '🌙',
                text: 'Late night browsing? Don\'t forget to get enough rest!',
                category: 'Wellness',
                priority: 'low'
            });
        }

        var day = new Date().getDay();
        if (day === 0 || day === 6) {
            self.insights.push({
                icon: '🎉',
                text: 'It\'s the weekend! Great time for family activities or relaxation.',
                category: 'Family',
                priority: 'medium'
            });
        }

        // Add weather insight if available
        var weatherWidget = HomeWeatherWidget.getInstance();
        if (weatherWidget.weatherData) {
            var temp = weatherWidget.weatherData.temperature;
            var rainChance = weatherWidget.calculateRainProbability(weatherWidget.weatherData);

            if (rainChance >= 70) {
                self.insights.push({
                    icon: '☔',
                    text: 'High rain probability (' + rainChance + '%). Don\'t forget your umbrella!',
                    category: 'Weather',
                    priority: 'high'
                });
            } else if (temp > 30) {
                self.insights.push({
                    icon: '🌡️',
                    text: 'It\'s ' + temp + '°C outside! Stay hydrated and avoid peak sun hours.',
                    category: 'Weather',
                    priority: 'high'
                });
            } else if (temp < 10) {
                self.insights.push({
                    icon: '🥶',
                    text: 'Cold day at ' + temp + '°C. Bundle up if heading outdoors!',
                    category: 'Weather',
                    priority: 'medium'
                });
            }
        }

        self.renderInsights();
    });
};

AIAssistant.prototype.renderInsights = function() {
    var insightsEl = document.getElementById('aiInsights');
    if (!insightsEl || this.insights.length === 0) return;

    var html = '';
    for (var i = 0; i < this.insights.length; i++) {
        var insight = this.insights[i];
        html += '<div class="insight-item insight-' + insight.priority + '" style="animation-delay: ' + (i * 0.1) + 's">' +
            '<span class="insight-icon">' + insight.icon + '</span>' +
            '<div>' +
                '<div class="insight-text">' + insight.text + '</div>' +
                '<span class="insight-category">' + insight.category + '</span>' +
            '</div>' +
        '</div>';
    }
    insightsEl.innerHTML = html;
};

AIAssistant.prototype.initActivityHeatmap = function() {
    var canvas = document.getElementById('activityHeatmap');
    if (!canvas) return;

    var ctx = canvas.getContext('2d');
    canvas.width = canvas.offsetWidth;
    canvas.height = canvas.offsetHeight;

    var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    var hours = isMobile ? 12 : 24; // Show fewer hours on mobile
    var cellWidth = canvas.width / hours;
    var cellHeight = canvas.height / 7;

    for (var day = 0; day < 7; day++) {
        for (var hour = 0; hour < hours; hour++) {
            var actualHour = isMobile ? hour * 2 : hour; // Every 2 hours on mobile
            var peakHours = (actualHour >= 8 && actualHour <= 10) || (actualHour >= 17 && actualHour <= 20);
            var weekendBoost = (day === 5 || day === 6) ? 0.3 : 0;
            var baseIntensity = Math.random() * 0.5;
            var intensity = Math.min(1, baseIntensity + (peakHours ? 0.4 : 0) + weekendBoost);

            var colors = [
                'rgba(102, 126, 234, 0.1)',
                'rgba(102, 126, 234, 0.3)',
                'rgba(102, 126, 234, 0.5)',
                'rgba(102, 126, 234, 0.7)',
                'rgba(102, 126, 234, 0.9)'
            ];
            var color = colors[Math.floor(intensity * (colors.length - 1))];

            ctx.fillStyle = color;
            ctx.fillRect(hour * cellWidth, day * cellHeight, cellWidth - 1, cellHeight - 1);
        }
    }

    // Day labels (only on non-mobile or larger screens)
    if (!isMobile || window.innerWidth > 600) {
        ctx.fillStyle = 'rgba(255, 255, 255, 0.8)';
        ctx.font = '10px Plus Jakarta Sans';
        ctx.textAlign = 'right';
        for (var i = 0; i < days.length; i++) {
            ctx.fillText(days[i], canvas.width - 5, (i + 0.6) * cellHeight);
        }
    }
};

AIAssistant.prototype.animateProgressCircles = function() {
    var circles = document.querySelectorAll('.progress-circle');
    for (var i = 0; i < circles.length; i++) {
        (function(circle) {
            var progress = parseInt(circle.dataset.progress || 0);
            var progressFill = circle.querySelector('.progress-fill');
            var progressValue = circle.querySelector('.progress-value');

            if (!progressFill || !progressValue) return;

            var circumference = 283;
            var offset = circumference - (progress / 100) * circumference;

            setTimeout(function() {
                progressFill.style.strokeDashoffset = offset;
                var current = 0;
                var duration = 2000;
                var increment = progress / (duration / 16);

                var timer = setInterval(function() {
                    current += increment;
                    if (current >= progress) {
                        current = progress;
                        clearInterval(timer);
                    }
                    progressValue.textContent = Math.floor(current) + '%';
                }, 16);
            }, 500);
        })(circles[i]);
    }
};

AIAssistant.openSmartSearch = function() {
    AIAssistant.showToast('🔍 Smart Search coming soon!', 'info');
};

AIAssistant.generateSuggestions = function() {
    var suggestions = [
        '💡 Create a shopping list for the weekend',
        '📅 Schedule a family movie night',
        '📝 Write a note about meal planning',
        '🎂 Add upcoming birthdays to calendar',
        '🏃 Plan outdoor family activities'
    ];

    var random = suggestions[Math.floor(Math.random() * suggestions.length)];
    AIAssistant.showToast(random, 'info');
};

AIAssistant.openAnalytics = function() {
    AIAssistant.showToast('📊 Advanced Analytics coming soon!', 'info');
};

AIAssistant.showToast = function(message, type) {
    if (type === undefined) type = 'info';
    var toasts = document.querySelectorAll('.home-toast');
    for (var i = 0; i < toasts.length; i++) {
        toasts[i].parentNode.removeChild(toasts[i]);
    }
    var toast = document.createElement('div');
    toast.className = 'home-toast toast-' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function() { toast.classList.add('show'); }, 10);
    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 3000);
};

AIAssistant.prototype.showToast = function(message, type) {
    AIAssistant.showToast(message, type);
};

// ============================================
// INVITE LINK COPY FUNCTION
// ============================================
function copyInviteLink() {
    try {
        // Get link from hidden input on home page or from inviteLinkDisplay
        const homeInput = document.getElementById('homeInviteLink');
        const displayEl = document.getElementById('inviteLinkDisplay');
        const link = homeInput ? homeInput.value : (displayEl ? displayEl.textContent.trim() : '');

        if (!link) {
            Toast.error('Invite link not found');
            return;
        }

        // Copy using textarea method (works in WebViews)
        const ta = document.createElement('textarea');
        ta.value = link;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        ta.setAttribute('readonly', '');
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, 99999);

        let success = false;
        try {
            success = document.execCommand('copy');
        } catch (e) {
            success = false;
        }
        document.body.removeChild(ta);

        if (success) {
            Toast.success('📨 Invite link copied!');
        } else {
            // Try clipboard API as backup
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(function() {
                    Toast.success('📨 Invite link copied!');
                }).catch(function() {
                    Toast.info('Long-press to copy the link');
                });
            } else {
                Toast.info('Long-press to copy the link');
            }
        }
    } catch (err) {
        console.log('copyInviteLink error:', err);
        Toast.info('Long-press to copy the link');
    }
}

// ============================================
// INITIALIZATION
// ============================================
let particleSystem = null;

document.addEventListener('DOMContentLoaded', () => {
    console.log('🏠 Initializing Home Page...');
    
    // Initialize particle system (desktop only for performance)
    if (!isMobile) {
        particleSystem = new ParticleSystem('particles');
    }
    
    // Animate stat numbers
    document.querySelectorAll('.stat-number[data-count]').forEach(element => {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !element.classList.contains('animated')) {
                    const target = parseInt(element.dataset.count);
                    animateNumber(element, 0, target, 2000);
                    element.classList.add('animated');
                }
            });
        });
        observer.observe(element);
    });
    
    // Initialize tilt effects
    document.querySelectorAll('[data-tilt]').forEach(card => new TiltEffect(card));
    
    // Initialize AI Assistant
    new AIAssistant();
    
    // Initialize Weather Widget
    new HomeWeatherWidget();
    
    // Animate greeting name
    const greetingName = document.querySelector('.greeting-name');
    if (greetingName) {
        const text = greetingName.textContent;
        greetingName.textContent = '';
        text.split('').forEach((char, index) => {
            const span = document.createElement('span');
            span.textContent = char;
            span.style.display = 'inline-block';
            span.style.animation = `letterPop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) ${index * 0.05}s backwards`;
            greetingName.appendChild(span);
        });
    }
    
    console.log('✅ Home Page Initialized');
});

// Modal handling
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => modal.classList.remove('active'));
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        AIAssistant.openSmartSearch();
    }
});

// Update time display
function updateTime() {
    const timeElement = document.querySelector('.greeting-time');
    if (!timeElement) return;
    const now = new Date();
    const newText = now.toLocaleDateString('en-ZA', { 
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
    if (timeElement.textContent !== newText) {
        timeElement.style.opacity = '0';
        setTimeout(() => {
            timeElement.textContent = newText;
            timeElement.style.opacity = '1';
        }, 300);
    }
}
updateTime();
setInterval(updateTime, 30000);

// Visibility change handling for native apps
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        // Pause animations when app is in background
        if (particleSystem && particleSystem.animationId) {
            cancelAnimationFrame(particleSystem.animationId);
        }
    } else {
        // Resume animations when app comes to foreground
        if (particleSystem && !particleSystem.animationId) {
            particleSystem.animate();
        }
        // Refresh weather data
        const weatherWidget = HomeWeatherWidget.getInstance();
        if (weatherWidget) {
            weatherWidget.loadWeather();
        }
    }
});

// Expose to window
window.AIAssistant = AIAssistant;
window.HomeWeatherWidget = HomeWeatherWidget;

console.log('✅ Home JavaScript v3.1 loaded');