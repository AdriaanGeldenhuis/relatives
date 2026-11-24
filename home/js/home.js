/**
 * ============================================
 * Relatives Home
 * Offline status, app shortcut motion, day clock, event countdowns, and weather
 * ============================================
 */

(function () {
    'use strict';

    const connectionStatus = document.getElementById('home-connection');
    if (connectionStatus) {
        const updateConnection = () => { connectionStatus.hidden = navigator.onLine; };
        window.addEventListener('online', updateConnection);
        window.addEventListener('offline', updateConnection);
        updateConnection();

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/home/sw.js', { updateViaCache: 'none' })
                .catch((error) => console.warn('Offline home could not be prepared.', error));
        }
    }

    const offlineAppStatus = document.getElementById('offline-app-status');
    if (offlineAppStatus) {
        const shortcuts = document.querySelectorAll('[data-app-url]');
        const showConnected = (connected) => {
            offlineAppStatus.textContent = connected
                ? 'You’re back online. Choose an app or reload home.'
                : 'You’re offline. Family activity and apps will be available when you reconnect.';
            shortcuts.forEach((shortcut) => {
                if (connected) {
                    shortcut.href = shortcut.dataset.appUrl;
                    shortcut.removeAttribute('aria-disabled');
                } else {
                    shortcut.removeAttribute('href');
                    shortcut.setAttribute('aria-disabled', 'true');
                }
            });
        };

        window.addEventListener('online', () => showConnected(true));
        window.addEventListener('offline', () => showConnected(false));
    }

    const motionToggle = document.querySelector('.launch-motion-toggle');
    if (motionToggle) {
        const apps = motionToggle.closest('.apps-section');
        const preferenceKey = 'relatives-home-motion-paused';
        const setPaused = (paused) => {
            apps.toggleAttribute('data-motion-paused', paused);
            motionToggle.textContent = paused ? 'Resume motion' : 'Pause motion';
        };

        try {
            setPaused(sessionStorage.getItem(preferenceKey) === 'true');
        } catch {
            // The control still works when storage is restricted.
        }
        motionToggle.hidden = false;
        motionToggle.addEventListener('click', () => {
            const paused = !apps.hasAttribute('data-motion-paused');
            setPaused(paused);
            try {
                sessionStorage.setItem(preferenceKey, String(paused));
            } catch {
                // Keep the current choice for this page.
            }
        });

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(({ target, isIntersecting }) => {
                    target.toggleAttribute('data-offscreen', !isIntersecting);
                });
            });
            apps.querySelectorAll('.launch-tile').forEach((tile) => observer.observe(tile));
        }
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    // ============================================
    // DAY-RING CLOCK (fills as the day passes)
    // ============================================
    var hmEl = document.getElementById('clockHM');
    var secEl = document.getElementById('clockSec');
    var ringEl = document.getElementById('clockRing');
    var capEl = document.getElementById('dayCap');
    var dateEl = document.getElementById('heroDate');
    const welcomeHero = document.querySelector('.hero[data-time]');
    const greeting = document.getElementById('heroGreeting');
    const periodIcon = document.getElementById('heroPeriodIcon');
    const periodIcons = { morning: '🌅', afternoon: '☀️', evening: '🌙' };

    function tick() {
        var now = new Date();
        const hour = now.getHours();
        const period = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
        if (welcomeHero && welcomeHero.dataset.time !== period) {
            welcomeHero.dataset.time = period;
            if (greeting) greeting.textContent = `Good ${period}`;
            if (periodIcon) periodIcon.textContent = periodIcons[period];
        }

        if (hmEl) hmEl.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes());
        if (secEl) secEl.textContent = pad(now.getSeconds());

        var pct = (now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds()) / 864;
        if (ringEl) ringEl.style.setProperty('--day', pct.toFixed(2));
        if (capEl) capEl.textContent = Math.round(pct) + '% of today';
    }

    function tickDate() {
        if (dateEl) {
            dateEl.textContent = new Date().toLocaleDateString('en-ZA', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
            });
        }
    }

    if (!welcomeHero?.classList.contains('sky-hero')) {
        tick();
        tickDate();
        setInterval(tick, 1000);
        setInterval(tickDate, 60000);
    }

    // ============================================
    // LIVE EVENT COUNTDOWNS ("In 38 min" ticks down)
    // ============================================
    function refreshEventTimes() {
        var rows = document.querySelectorAll('.event-row[data-epoch]');
        if (!rows.length) return;

        var now = Date.now();
        var todayKey = new Date().toDateString();
        var tomorrowKey = new Date(now + 86400000).toDateString();

        rows.forEach(function (row) {
            var when = row.querySelector('.event-when');
            if (!when) return;

            var d = new Date(parseInt(row.dataset.epoch, 10) * 1000);
            var diff = d.getTime() - now;
            var hm = pad(d.getHours()) + ':' + pad(d.getMinutes());
            var label, tone = '';

            if (diff <= 0) {
                label = 'Now';
                tone = 'is-urgent';
            } else if (diff < 3600000) {
                label = 'In ' + Math.ceil(diff / 60000) + ' min';
                tone = 'is-urgent';
            } else if (d.toDateString() === todayKey) {
                label = 'Today ' + hm;
                tone = 'is-today';
            } else if (d.toDateString() === tomorrowKey) {
                label = 'Tomorrow ' + hm;
            } else {
                label = d.toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' }) + ', ' + hm;
            }

            when.textContent = label;
            row.classList.remove('is-urgent', 'is-today');
            if (tone) row.classList.add(tone);
        });
    }

    refreshEventTimes();
    setInterval(refreshEventTimes, 30000);

    // Home uses a fresh shared location or the device's location permission.
    const weatherPanel = welcomeHero?.querySelector('[data-weather-panel]');
    if (weatherPanel) {
        const locationButton = weatherPanel.querySelector('[data-sky-locate]');
        const forecastLink = weatherPanel.querySelector('[data-sky-forecast]');
        const feedback = weatherPanel.querySelector('[data-sky-feedback]');
        const shortcut = document.querySelector('[data-app="weather"] .launch-summary');
        const shortcutFallback = shortcut.textContent;
        const sky = window.RelativesWeatherHero.mount(welcomeHero, {
            onTime: ({ period, date }) => {
                const phrase = `Good ${period}`;
                if (greeting.textContent !== phrase) greeting.textContent = phrase;
                if (periodIcon.textContent !== periodIcons[period]) periodIcon.textContent = periodIcons[period];
                if (dateEl.textContent !== date) dateEl.textContent = date;
            }
        });

        let location;
        let activeRequest;
        let lastAttempt = 0;
        let hasWeather = false;

        function showFeedback(message) {
            feedback.textContent = message;
            feedback.hidden = !message;
        }

        async function loadWeather(place, quietly = false) {
            activeRequest?.abort();
            const request = new AbortController();
            activeRequest = request;
            location = place;
            lastAttempt = Date.now();

            const forecastQuery = new URLSearchParams({ lat: place.lat.toFixed(2), lon: place.lon.toFixed(2) });
            forecastLink.href = `/weather/?${forecastQuery}`;
            if (!quietly) {
                hasWeather = false;
                shortcut.textContent = shortcutFallback;
                sky.update({ place: 'Near you', source: place.source, message: 'Loading current weather…' });
            }
            showFeedback('');
            try {
                const query = new URLSearchParams({ action: 'current', lat: place.lat, lon: place.lon });
                const response = await fetch(`/weather/api/api.php?${query}`, { signal: request.signal, cache: 'no-store' });
                if (!response.ok) throw new Error('Weather request failed.');
                const current = await response.json();
                if (request.signal.aborted) return;
                if (!Number.isFinite(current.temperature)) throw new Error('Current conditions are missing.');
                hasWeather = true;
                const providerName = current.location_name || current.location;
                sky.update({
                    place: providerName && providerName !== 'Unknown' ? providerName : 'Near you',
                    source: place.source,
                    current,
                    offset: current.timezone
                });
                shortcut.textContent = [`${current.temperature}°`, current.description || current.condition].filter(Boolean).join(' · ');
            } catch {
                if (request.signal.aborted) return;
                if (!hasWeather) sky.update({ place: 'Near you', source: place.source, message: 'Current weather is unavailable' });
                showFeedback(hasWeather
                    ? 'Weather could not refresh. Showing the last available conditions.'
                    : 'Try your location again or choose a place under Full forecast.');
            } finally {
                if (activeRequest === request) activeRequest = null;
            }
        }

        function locate() {
            if (locationButton.disabled) return;
            locationButton.disabled = true;
            showFeedback('Finding your location…');
            navigator.geolocation.getCurrentPosition(({ coords }) => {
                locationButton.disabled = false;
                loadWeather({ lat: coords.latitude, lon: coords.longitude, source: 'Device location' });
            }, error => {
                locationButton.disabled = false;
                showFeedback(error.code === 1
                    ? 'Location access is off. You can still choose a city under Full forecast.'
                    : 'Your location could not be found. Try again or choose a city.');
            }, { timeout: 8000, maximumAge: 120000 });
        }

        if (navigator.geolocation) {
            locationButton.hidden = false;
            locationButton.addEventListener('click', locate);
        }
        const tracked = window.USER_LOCATION;
        if (Number.isFinite(tracked?.lat) && Number.isFinite(tracked?.lng) && tracked.trackedAt > Date.now() / 1000 - 1800) {
            loadWeather({ lat: tracked.lat, lon: tracked.lng, source: 'Last shared location' });
        } else if (navigator.geolocation) {
            navigator.permissions?.query({ name: 'geolocation' }).then(permission => {
                if (permission.state === 'granted' && !location) locate();
            }).catch(() => {});
        } else {
            sky.update({ message: 'Choose a city under Full forecast' });
        }

        function refresh() {
            if (location && !document.hidden && navigator.onLine && !activeRequest && !locationButton.disabled
                && Date.now() - lastAttempt >= 600000) {
                loadWeather(location, true);
            }
        }
        setInterval(refresh, 600000);
        document.addEventListener('visibilitychange', refresh);
    }

    // ============================================
    // COUNT-UP NUMBERS (with a spring pop at the end)
    // ============================================
    function countUp(el) {
        var target = parseInt(el.dataset.count, 10) || 0;
        if (target === 0) { el.textContent = '0'; return; }

        var duration = 900;
        var start = performance.now();

        function frame(t) {
            var p = Math.min((t - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased);
            if (p < 1) {
                requestAnimationFrame(frame);
            } else {
                el.classList.add('pop');
            }
        }

        requestAnimationFrame(frame);
    }

    var counters = document.querySelectorAll('[data-count]');
    if ('IntersectionObserver' in window) {
        var seen = new WeakSet();
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && !seen.has(entry.target)) {
                    seen.add(entry.target);
                    countUp(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.2 });
        counters.forEach(function (el) { observer.observe(el); });

        // Safety net: whatever hasn't animated after 4s just shows its value
        setTimeout(function () {
            counters.forEach(function (el) {
                if (!seen.has(el)) {
                    seen.add(el);
                    observer.unobserve(el);
                    el.textContent = parseInt(el.dataset.count, 10) || 0;
                }
            });
        }, 4000);
    } else {
        counters.forEach(countUp);
    }

    // ============================================
    // INVITE LINK COPY
    // ============================================
    function notify(message, type) {
        if (window.Toast && window.Toast[type]) {
            window.Toast[type](message);
        }
    }

    window.copyInviteLink = function () {
        var input = document.getElementById('homeInviteLink');
        var link = input ? input.value : '';
        if (!link) {
            notify('Invite link not found', 'error');
            return;
        }

        // textarea + execCommand works inside WebViews where the async API doesn't
        var ta = document.createElement('textarea');
        ta.value = link;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.setAttribute('readonly', '');
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, 99999);

        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);

        if (ok) {
            notify('📨 Invite link copied!', 'success');
        } else if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function () {
                notify('📨 Invite link copied!', 'success');
            }).catch(function () {
                notify('Long-press to copy the link', 'info');
            });
        } else {
            notify('Long-press to copy the link', 'info');
        }
    };
})();
