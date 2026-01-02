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
// PARTICLE SYSTEM (OPTIMIZED FOR MOBILE)
// ============================================
class ParticleSystem {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) return;
        
        this.ctx = this.canvas.getContext('2d');
        this.particles = [];
        
        // Mobile optimization: fewer particles
        this.particleCount = isMobile ? 50 : Math.min(150, window.innerWidth / 8);
        this.mouse = { x: null, y: null, radius: isMobile ? 120 : 180 };
        this.connectionDistance = isMobile ? 80 : 120;
        
        this.resize();
        this.init();
        
        // Use requestAnimationFrame for better performance
        this.animationId = null;
        this.animate();
        
        window.addEventListener('resize', () => this.resize());
        
        if (!isMobile) {
            window.addEventListener('mousemove', (e) => {
                this.mouse.x = e.clientX;
                this.mouse.y = e.clientY;
            });
            
            window.addEventListener('mouseleave', () => {
                this.mouse.x = null;
                this.mouse.y = null;
            });
        }
    }
    
    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
    }
    
    init() {
        this.particles = [];
        const colors = [
            'rgba(102, 126, 234, ',
            'rgba(118, 75, 162, ',
            'rgba(240, 147, 251, ',
            'rgba(79, 172, 254, '
        ];
        
        for (let i = 0; i < this.particleCount; i++) {
            const baseColor = colors[Math.floor(Math.random() * colors.length)];
            this.particles.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height,
                size: Math.random() * (isMobile ? 2 : 3) + 1,
                baseSize: Math.random() * (isMobile ? 2 : 3) + 1,
                speedX: (Math.random() - 0.5) * (isMobile ? 0.5 : 0.8),
                speedY: (Math.random() - 0.5) * (isMobile ? 0.5 : 0.8),
                baseColor: baseColor,
                opacity: Math.random() * 0.5 + 0.3
            });
        }
    }
    
    animate() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        this.particles.forEach((particle, index) => {
            particle.x += particle.speedX;
            particle.y += particle.speedY;
            
            if (particle.x > this.canvas.width || particle.x < 0) {
                particle.speedX *= -1;
            }
            if (particle.y > this.canvas.height || particle.y < 0) {
                particle.speedY *= -1;
            }
            
            if (!isMobile && this.mouse.x !== null && this.mouse.y !== null) {
                const dx = this.mouse.x - particle.x;
                const dy = this.mouse.y - particle.y;
                const distance = Math.sqrt(dx * dx + dy * dy);
                
                if (distance < this.mouse.radius) {
                    const force = (this.mouse.radius - distance) / this.mouse.radius;
                    const angle = Math.atan2(dy, dx);
                    
                    particle.x -= Math.cos(angle) * force * 3;
                    particle.y -= Math.sin(angle) * force * 3;
                    particle.size = particle.baseSize * (1 + force * 0.5);
                } else {
                    particle.size += (particle.baseSize - particle.size) * 0.1;
                }
            } else {
                particle.size = particle.baseSize;
            }
            
            this.ctx.beginPath();
            this.ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
            
            const gradient = this.ctx.createRadialGradient(
                particle.x, particle.y, 0,
                particle.x, particle.y, particle.size * 3
            );
            gradient.addColorStop(0, particle.baseColor + particle.opacity + ')');
            gradient.addColorStop(1, particle.baseColor + '0)');
            
            this.ctx.fillStyle = gradient;
            this.ctx.fill();
            
            // Only draw connections on non-mobile for performance
            if (!isMobile) {
                for (let j = index + 1; j < this.particles.length; j++) {
                    const other = this.particles[j];
                    const dx2 = other.x - particle.x;
                    const dy2 = other.y - particle.y;
                    const distance2 = Math.sqrt(dx2 * dx2 + dy2 * dy2);
                    
                    if (distance2 < this.connectionDistance) {
                        const opacity = (1 - distance2 / this.connectionDistance) * 0.3;
                        this.ctx.beginPath();
                        this.ctx.strokeStyle = `rgba(255, 255, 255, ${opacity})`;
                        this.ctx.lineWidth = 1;
                        this.ctx.moveTo(particle.x, particle.y);
                        this.ctx.lineTo(other.x, other.y);
                        this.ctx.stroke();
                    }
                }
            }
        });
        
        this.animationId = requestAnimationFrame(() => this.animate());
    }
    
    destroy() {
        if (this.animationId) {
            cancelAnimationFrame(this.animationId);
        }
    }
}

// ============================================
// HOME WEATHER WIDGET (ENHANCED WITH RAIN %)
// ============================================
class HomeWeatherWidget {
    static instance = null;
    
    constructor() {
        if (HomeWeatherWidget.instance) return HomeWeatherWidget.instance;
        
        this.weatherData = null;
        this.location = null;
        this.container = document.getElementById('homeWeatherWidget');
        
        HomeWeatherWidget.instance = this;
        this.init();
    }
    
    static getInstance() {
        if (!HomeWeatherWidget.instance) {
            HomeWeatherWidget.instance = new HomeWeatherWidget();
        }
        return HomeWeatherWidget.instance;
    }
    
    async init() {
        if (!this.container) return;
        
        console.log('🌤️ Initializing Home Weather Widget...');
        
        // Check for user location from tracking
        if (window.USER_LOCATION && window.USER_LOCATION.lat && window.USER_LOCATION.lng) {
            console.log('📍 Using tracked location:', window.USER_LOCATION);
            this.location = {
                lat: window.USER_LOCATION.lat,
                lng: window.USER_LOCATION.lng
            };
            await this.loadWeather();
        } else {
            console.log('📍 No tracked location, trying browser location...');
            this.requestBrowserLocation();
        }
    }
    
    requestBrowserLocation() {
        if ('geolocation' in navigator) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    this.location = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };
                    this.loadWeather();
                },
                (error) => {
                    console.warn('Geolocation error:', error);
                    this.showManualSearch();
                }
            );
        } else {
            this.showManualSearch();
        }
    }
    
    showManualSearch() {
        if (!this.container) return;
        
        this.container.innerHTML = `
            <div class="weather-manual-search">
                <div class="wms-icon">🌍</div>
                <h3>Location Access Needed</h3>
                <p>Enable location services or search for your city to view weather</p>
                <a href="/weather/" class="wms-btn">
                    <span>🔍</span>
                    <span>Search Weather</span>
                </a>
            </div>
        `;
    }
    
    async loadWeather() {
        if (!this.location) return;
        
        try {
            const url = `/weather/api/api.php?action=current&lat=${this.location.lat}&lon=${this.location.lng}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.weatherData = data;
            this.render();
            
            console.log('✅ Weather loaded:', data);
        } catch (error) {
            console.error('Weather load error:', error);
            this.showError();
        }
    }
    
    render() {
        if (!this.container || !this.weatherData) return;
        
        const weather = this.weatherData;
        const sunrise = new Date(weather.sunrise * 1000);
        const sunset = new Date(weather.sunset * 1000);
        const now = new Date();
        const isDaytime = now >= sunrise && now <= sunset;
        
        const weatherEmoji = this.getWeatherEmoji(weather.condition, isDaytime);
        
        // Calculate rain probability (from clouds and humidity)
        const rainProbability = this.calculateRainProbability(weather);
        
        this.container.innerHTML = `
            <div class="weather-widget-content" onclick="window.location.href='/weather/'">
                <div class="wwc-header">
                    <div class="wwc-location">
                        <span class="wwc-location-icon">📍</span>
                        <span class="wwc-location-name">${weather.location}</span>
                    </div>
                    <a href="/weather/" class="wwc-full-link" onclick="event.stopPropagation()">
                        <span>View Full Forecast</span>
                        <span class="wwc-arrow">→</span>
                    </a>
                </div>
                
                <div class="wwc-main">
                    <div class="wwc-current">
                        <div class="wwc-icon">${weatherEmoji}</div>
                        <div class="wwc-temp-group">
                            <div class="wwc-temp">${weather.temperature}°</div>
                            <div class="wwc-feels">Feels like ${weather.feels_like}°</div>
                        </div>
                    </div>
                    
                    <div class="wwc-description">${weather.description}</div>
                    
                    <div class="wwc-details-grid">
                        <div class="wwc-detail wwc-detail-rain ${rainProbability >= 70 ? 'high-chance' : rainProbability >= 40 ? 'medium-chance' : 'low-chance'}">
                            <div class="wwc-detail-icon">💧</div>
                            <div class="wwc-detail-content">
                                <div class="wwc-detail-label">Rain Chance</div>
                                <div class="wwc-detail-value">${rainProbability}%</div>
                            </div>
                        </div>
                        
                        <div class="wwc-detail">
                            <div class="wwc-detail-icon">💧</div>
                            <div class="wwc-detail-content">
                                <div class="wwc-detail-label">Humidity</div>
                                <div class="wwc-detail-value">${weather.humidity}%</div>
                            </div>
                        </div>
                        
                        <div class="wwc-detail">
                            <div class="wwc-detail-icon">💨</div>
                            <div class="wwc-detail-content">
                                <div class="wwc-detail-label">Wind</div>
                                <div class="wwc-detail-value">${weather.wind_speed} km/h</div>
                            </div>
                        </div>
                        
                        <div class="wwc-detail">
                            <div class="wwc-detail-icon">☁️</div>
                            <div class="wwc-detail-content">
                                <div class="wwc-detail-label">Cloud Cover</div>
                                <div class="wwc-detail-value">${weather.clouds}%</div>
                            </div>
                        </div>
                        
                        <div class="wwc-detail">
                            <div class="wwc-detail-icon">🌅</div>
                            <div class="wwc-detail-content">
                                <div class="wwc-detail-label">Sunrise</div>
                                <div class="wwc-detail-value">${sunrise.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</div>
                            </div>
                        </div>
                        
                        <div class="wwc-detail">
                            <div class="wwc-detail-icon">🌇</div>
                            <div class="wwc-detail-content">
                                <div class="wwc-detail-label">Sunset</div>
                                <div class="wwc-detail-value">${sunset.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="wwc-footer">
                    <div class="wwc-powered">Powered by OpenWeather</div>
                    <div class="wwc-updated">Updated: ${new Date().toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</div>
                </div>
            </div>
        `;
        
        // Animate in
        setTimeout(() => {
            if (this.container) {
                this.container.classList.add('loaded');
            }
        }, 100);
    }
    
    calculateRainProbability(weather) {
        // Calculate rain probability based on multiple factors
        let probability = 0;
        
        // Check if it's already raining
        const condition = weather.condition.toLowerCase();
        if (condition.includes('rain') || condition.includes('drizzle')) {
            return 100;
        }
        if (condition.includes('thunder') || condition.includes('storm')) {
            return 95;
        }
        
        // Base calculation on clouds and humidity
        const cloudFactor = weather.clouds * 0.4; // 40% weight
        const humidityFactor = (weather.humidity - 50) * 0.6; // 60% weight (normalized from 50%)
        
        probability = Math.max(0, Math.min(100, cloudFactor + humidityFactor));
        
        // Adjust based on conditions
        if (condition.includes('overcast')) {
            probability += 15;
        } else if (condition.includes('partly') || condition.includes('scattered')) {
            probability += 5;
        } else if (condition.includes('clear') || condition.includes('sunny')) {
            probability = Math.min(probability, 20);
        }
        
        // Cap at 100%
        return Math.min(100, Math.round(probability));
    }
    
    showError() {
        if (!this.container) return;
        
        this.container.innerHTML = `
            <div class="weather-widget-error">
                <div class="wwe-icon">⚠️</div>
                <h3>Weather Unavailable</h3>
                <p>Unable to load weather data. Please try again later.</p>
                <button onclick="HomeWeatherWidget.getInstance().init()" class="wwe-retry">
                    <span>🔄</span>
                    <span>Retry</span>
                </button>
            </div>
        `;
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
        
        return isDaytime ? '🌤️' : '🌙';
    }
    
    async getVoiceSummary() {
        if (!this.weatherData) {
            await this.loadWeather();
        }
        
        if (!this.weatherData) {
            return "Weather information is not available right now.";
        }
        
        const w = this.weatherData;
        const location = w.location || 'your location';
        const rainChance = this.calculateRainProbability(w);
        
        return `Current weather in ${location}: ${w.temperature} degrees and ${w.description}. ` +
               `Feels like ${w.feels_like} degrees. ` +
               `Humidity is ${w.humidity} percent with ${w.wind_speed} kilometers per hour winds. ` +
               `Rain probability is ${rainChance} percent.`;
    }
}

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
class AIAssistant {
    static instance = null;
    
    constructor() {
        if (AIAssistant.instance) return AIAssistant.instance;
        this.insights = [];
        AIAssistant.instance = this;
        this.init();
    }
    
    static getInstance() {
        if (!AIAssistant.instance) {
            AIAssistant.instance = new AIAssistant();
        }
        return AIAssistant.instance;
    }
    
    async init() {
        console.log('🤖 Initializing AI Assistant...');
        await this.generateInsights();
        this.initActivityHeatmap();
        this.animateProgressCircles();
        
        console.log('✅ AI Assistant initialized');
    }
    
    async generateInsights() {
        const insightsEl = document.getElementById('aiInsights');
        if (!insightsEl) return;
        
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        const stats = {
            shopping: parseInt(document.querySelector('[href="/shopping/"] .stat-number')?.textContent || 0),
            events: parseInt(document.querySelector('[href="/calendar/"] .stat-number')?.textContent || 0),
            messages: parseInt(document.querySelector('[href="/messages/"] .stat-number')?.textContent || 0),
            completed: parseInt(document.querySelector('[onclick*="Analytics"] .stat-number')?.textContent || 0)
        };
        
        this.insights = [];
        
        if (stats.shopping > 10) {
            this.insights.push({ 
                icon: '🛒', 
                text: `You have ${stats.shopping} pending shopping items. Consider grouping by store.`, 
                category: 'Shopping',
                priority: 'high'
            });
        } else if (stats.shopping > 0) {
            this.insights.push({ 
                icon: '🛒', 
                text: `${stats.shopping} items on your shopping list. Well organized!`, 
                category: 'Shopping',
                priority: 'low'
            });
        }
        
        if (stats.events > 0) {
            this.insights.push({ 
                icon: '📅', 
                text: `${stats.events} upcoming events this week. Stay on schedule!`, 
                category: 'Calendar',
                priority: 'medium'
            });
        } else {
            this.insights.push({ 
                icon: '📅', 
                text: 'Your calendar is clear this week. Time to relax or plan ahead.', 
                category: 'Calendar',
                priority: 'low'
            });
        }
        
        if (stats.messages > 5) {
            this.insights.push({ 
                icon: '💬', 
                text: `${stats.messages} unread messages waiting. Your family is active!`, 
                category: 'Messages',
                priority: 'high'
            });
        }
        
        if (stats.completed > 20) {
            this.insights.push({ 
                icon: '🔥', 
                text: `Amazing! ${stats.completed} tasks completed this week. You're on fire!`, 
                category: 'Productivity',
                priority: 'high'
            });
        }
        
        const hour = new Date().getHours();
        if (hour >= 9 && hour < 12) {
            this.insights.push({ 
                icon: '☕', 
                text: 'Morning peak productivity! Perfect time for important tasks.', 
                category: 'Productivity',
                priority: 'medium'
            });
        } else if (hour >= 17 && hour < 20) {
            this.insights.push({ 
                icon: '👨‍👩‍👧‍👦', 
                text: 'Family time! Perfect moment to connect and share your day.', 
                category: 'Family',
                priority: 'medium'
            });
        } else if (hour >= 22 || hour < 6) {
            this.insights.push({ 
                icon: '🌙', 
                text: 'Late night browsing? Don\'t forget to get enough rest!', 
                category: 'Wellness',
                priority: 'low'
            });
        }
        
        const day = new Date().getDay();
        if (day === 0 || day === 6) {
            this.insights.push({ 
                icon: '🎉', 
                text: 'It\'s the weekend! Great time for family activities or relaxation.', 
                category: 'Family',
                priority: 'medium'
            });
        }
        
        // Add weather insight if available
        const weatherWidget = HomeWeatherWidget.getInstance();
        if (weatherWidget.weatherData) {
            const temp = weatherWidget.weatherData.temperature;
            const rainChance = weatherWidget.calculateRainProbability(weatherWidget.weatherData);
            
            if (rainChance >= 70) {
                this.insights.push({
                    icon: '☔',
                    text: `High rain probability (${rainChance}%). Don't forget your umbrella!`,
                    category: 'Weather',
                    priority: 'high'
                });
            } else if (temp > 30) {
                this.insights.push({
                    icon: '🌡️',
                    text: `It's ${temp}°C outside! Stay hydrated and avoid peak sun hours.`,
                    category: 'Weather',
                    priority: 'high'
                });
            } else if (temp < 10) {
                this.insights.push({
                    icon: '🥶',
                    text: `Cold day at ${temp}°C. Bundle up if heading outdoors!`,
                    category: 'Weather',
                    priority: 'medium'
                });
            }
        }
        
        this.renderInsights();
    }
    
    renderInsights() {
        const insightsEl = document.getElementById('aiInsights');
        if (!insightsEl || this.insights.length === 0) return;
        
        insightsEl.innerHTML = this.insights.map((insight, index) => `
            <div class="insight-item insight-${insight.priority}" style="animation-delay: ${index * 0.1}s">
                <span class="insight-icon">${insight.icon}</span>
                <div>
                    <div class="insight-text">${insight.text}</div>
                    <span class="insight-category">${insight.category}</span>
                </div>
            </div>
        `).join('');
    }
    
    initActivityHeatmap() {
        const canvas = document.getElementById('activityHeatmap');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        canvas.width = canvas.offsetWidth;
        canvas.height = canvas.offsetHeight;
        
        const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        const hours = isMobile ? 12 : 24; // Show fewer hours on mobile
        const cellWidth = canvas.width / hours;
        const cellHeight = canvas.height / 7;
        
        for (let day = 0; day < 7; day++) {
            for (let hour = 0; hour < hours; hour++) {
                const actualHour = isMobile ? hour * 2 : hour; // Every 2 hours on mobile
                const peakHours = actualHour >= 8 && actualHour <= 10 || actualHour >= 17 && actualHour <= 20;
                const weekendBoost = (day === 5 || day === 6) ? 0.3 : 0;
                const baseIntensity = Math.random() * 0.5;
                const intensity = Math.min(1, baseIntensity + (peakHours ? 0.4 : 0) + weekendBoost);
                
                const colors = [
                    'rgba(102, 126, 234, 0.1)',
                    'rgba(102, 126, 234, 0.3)',
                    'rgba(102, 126, 234, 0.5)',
                    'rgba(102, 126, 234, 0.7)',
                    'rgba(102, 126, 234, 0.9)'
                ];
                const color = colors[Math.floor(intensity * (colors.length - 1))];
                
                ctx.fillStyle = color;
                ctx.fillRect(hour * cellWidth, day * cellHeight, cellWidth - 1, cellHeight - 1);
            }
        }
        
        // Day labels (only on non-mobile or larger screens)
        if (!isMobile || window.innerWidth > 600) {
            ctx.fillStyle = 'rgba(255, 255, 255, 0.8)';
            ctx.font = '10px Plus Jakarta Sans';
            ctx.textAlign = 'right';
            days.forEach((day, index) => {
                ctx.fillText(day, canvas.width - 5, (index + 0.6) * cellHeight);
            });
        }
    }
    
    animateProgressCircles() {
        document.querySelectorAll('.progress-circle').forEach(circle => {
            const progress = parseInt(circle.dataset.progress || 0);
            const progressFill = circle.querySelector('.progress-fill');
            const progressValue = circle.querySelector('.progress-value');
            
            if (!progressFill || !progressValue) return;
            
            const circumference = 283;
            const offset = circumference - (progress / 100) * circumference;
            
            setTimeout(() => {
                progressFill.style.strokeDashoffset = offset;
                let current = 0;
                const duration = 2000;
                const increment = progress / (duration / 16);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= progress) {
                        current = progress;
                        clearInterval(timer);
                    }
                    progressValue.textContent = Math.floor(current) + '%';
                }, 16);
            }, 500);
        });
    }
    
    static openSmartSearch() {
        AIAssistant.showToast('🔍 Smart Search coming soon!', 'info');
    }
    
    static generateSuggestions() {
        const suggestions = [
            '💡 Create a shopping list for the weekend',
            '📅 Schedule a family movie night',
            '📝 Write a note about meal planning',
            '🎂 Add upcoming birthdays to calendar',
            '🏃 Plan outdoor family activities'
        ];
        
        const random = suggestions[Math.floor(Math.random() * suggestions.length)];
        AIAssistant.showToast(random, 'info');
    }
    
    static openAnalytics() {
        AIAssistant.showToast('📊 Advanced Analytics coming soon!', 'info');
    }
    
    static showToast(message, type = 'info') {
        document.querySelectorAll('.home-toast').forEach(t => t.remove());
        const toast = document.createElement('div');
        toast.className = `home-toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    showToast(message, type = 'info') {
        AIAssistant.showToast(message, type);
    }
}

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