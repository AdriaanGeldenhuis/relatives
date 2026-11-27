'use strict';

(() => {
    const setup = document.getElementById('homeTrackingSetup');
    const installedApp = window.TrackingBridge || /\bRelativesAndroid(?:\/|App\b)/.test(navigator.userAgent) ||
        window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    if (!setup || !installedApp) return;

    const key = `relatives.trackingSetupSeen.${setup.dataset.account}`;
    try {
        if (localStorage.getItem(key) === '1') return;
    } catch (error) {
        // The setup is still available when the app cannot persist the choice.
    }

    function dismissSetup() {
        setup.hidden = true;
        try { localStorage.setItem(key, '1'); } catch (error) {}
    }

    setup.querySelector('a').addEventListener('click', dismissSetup);
    setup.querySelector('button').addEventListener('click', dismissSetup);
    setup.hidden = false;
})();

(() => {
    const byId = id => document.getElementById(id);
    const dateFormat = new Intl.DateTimeFormat('en-ZA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const shortDate = new Intl.DateTimeFormat('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' });
    const clockFormat = new Intl.DateTimeFormat('en-ZA', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    const date = byId('heroDate');
    const connectionStatus = byId('home-connection');
    const offlineStatus = byId('offline-app-status');

    if (connectionStatus) {
        const updateConnection = () => { connectionStatus.hidden = navigator.onLine; };
        window.addEventListener('online', updateConnection);
        window.addEventListener('offline', updateConnection);
        updateConnection();

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/home/sw.js', { updateViaCache: 'none' })
                .catch(error => console.warn('Offline home could not be prepared.', error));
        }
    }

    if (offlineStatus) {
        const shortcuts = document.querySelectorAll('[data-app-url]');
        function showConnected(connected) {
            offlineStatus.textContent = connected
                ? 'You’re back online. Choose an app or reload home.'
                : 'You’re offline. Family activity and apps will be available when you reconnect.';
            shortcuts.forEach(shortcut => {
                if (connected) {
                    shortcut.href = shortcut.dataset.appUrl;
                    shortcut.removeAttribute('aria-disabled');
                } else {
                    shortcut.removeAttribute('href');
                    shortcut.setAttribute('aria-disabled', 'true');
                }
            });
        }

        window.addEventListener('online', () => showConnected(true));
        window.addEventListener('offline', () => showConnected(false));
        if (window.location.pathname === '/home/offline.html') showConnected(navigator.onLine);

        const offlineHero = offlineStatus.closest('.hero');
        const clock = byId('clockHM');
        const seconds = byId('clockSec');
        const ring = byId('clockRing');
        const caption = byId('dayCap');
        function updateClock() {
            const now = new Date();
            const hour = now.getHours();
            const second = now.getSeconds();
            offlineHero.dataset.time = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
            const formattedDate = dateFormat.format(now);
            if (date.textContent !== formattedDate) date.textContent = formattedDate;
            clock.textContent = clockFormat.format(now);
            seconds.textContent = String(second).padStart(2, '0');
            const progress = (hour * 3600 + now.getMinutes() * 60 + second) / 864;
            ring.style.setProperty('--day', progress.toFixed(2));
            caption.textContent = `${Math.round(progress)}% of today`;
        }
        updateClock();
        setInterval(() => { if (!document.hidden) updateClock(); }, 1000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) updateClock(); });
        return;
    }

    const welcomeClose = document.querySelector('.welcome-close');
    welcomeClose?.addEventListener('click', () => welcomeClose.closest('.welcome-banner').remove());

    const inviteCopy = byId('homeInviteCopy');
    if (inviteCopy) {
        const invite = byId('homeInviteLink');
        const input = invite.querySelector('input');
        inviteCopy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(input.value);
            } catch {
                invite.hidden = false;
                input.focus();
                input.select();
                try {
                    if (!document.execCommand('copy')) return;
                } catch {
                    return;
                }
                invite.hidden = true;
                inviteCopy.focus();
            }
            window.Toast?.success('Invite link copied.');
        });
    }

    const motionToggle = document.querySelector('.launch-motion-toggle');
    if (motionToggle) {
        const apps = motionToggle.closest('.apps-section');
        const preferenceKey = 'relatives-home-motion-paused';
        function setPaused(paused) {
            apps.toggleAttribute('data-motion-paused', paused);
            motionToggle.textContent = paused ? 'Resume motion' : 'Pause motion';
        }

        try {
            setPaused(sessionStorage.getItem(preferenceKey) === 'true');
        } catch {
            // Keep the control available when storage is restricted.
        }
        motionToggle.hidden = false;
        motionToggle.addEventListener('click', () => {
            const paused = !apps.hasAttribute('data-motion-paused');
            setPaused(paused);
            try {
                sessionStorage.setItem(preferenceKey, String(paused));
            } catch {
                // The choice still applies to this page.
            }
        });

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(entries => {
                entries.forEach(({ target, isIntersecting }) => target.toggleAttribute('data-offscreen', !isIntersecting));
            });
            apps.querySelectorAll('.launch-tile').forEach(tile => observer.observe(tile));
        }
    }

    const eventTimes = document.querySelectorAll('[data-event-start]');
    if (eventTimes.length) {
        function refreshEventTimes() {
            if (document.hidden) return;
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(now.getDate() + 1);
            eventTimes.forEach(time => {
                const startsAt = new Date(time.dateTime);
                const diff = startsAt - now;
                const today = startsAt.toDateString() === now.toDateString();
                const urgent = diff > -60000 && diff < 3600000;
                const day = today ? 'Today' : startsAt.toDateString() === tomorrow.toDateString() ? 'Tomorrow' : shortDate.format(startsAt);
                const label = urgent ? (diff <= 0 ? 'Now' : `In ${Math.ceil(diff / 60000)} min`) : `${day} ${clockFormat.format(startsAt)}`;
                if (time.textContent !== label) time.textContent = label;
                const row = time.closest('.event-row');
                row.classList.toggle('is-urgent', urgent);
                row.classList.toggle('is-today', today && !urgent);
            });
        }
        refreshEventTimes();
        setInterval(refreshEventTimes, 30000);
        document.addEventListener('visibilitychange', refreshEventTimes);
    }

    const hero = document.querySelector('.hero.sky-hero');
    if (!hero) return;

    const locationButton = hero.querySelector('[data-sky-locate]');
    const forecastLink = hero.querySelector('[data-sky-forecast]');
    const feedback = hero.querySelector('[data-sky-feedback]');
    const weatherShortcut = document.querySelector('[data-app="weather"]');
    const shortcutSummary = weatherShortcut.querySelector('.launch-summary');
    const defaultSummary = shortcutSummary.textContent;
    const greeting = byId('heroGreeting');
    const periodIcon = byId('heroPeriodIcon');
    const periodIcons = { morning: '🌅', afternoon: '☀️', evening: '🌙' };
    const weather = window.RelativesWeather;
    const sky = weather.mountHero(hero, {
        onTime: ({ period, date: formattedDate }) => {
            const phrase = `Good ${period}`;
            if (greeting.textContent !== phrase) greeting.textContent = phrase;
            if (periodIcon.textContent !== periodIcons[period]) periodIcon.textContent = periodIcons[period];
            if (date.textContent !== formattedDate) date.textContent = formattedDate;
        }
    });

    let location;
    let activeRequest;
    let lastAttempt = 0;
    let reading;

    async function loadWeather(place, quietly = false) {
        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        location = place;
        lastAttempt = Date.now();

        const coordinates = { lat: place.lat, lon: place.lon };
        const forecastUrl = `/weather/?${new URLSearchParams(coordinates)}`;
        forecastLink.href = weatherShortcut.href = forecastUrl;
        if (!quietly) {
            reading = null;
            shortcutSummary.textContent = defaultSummary;
            sky.update({ place: place.name || 'Near you', source: place.source, offset: place.offset, message: 'Loading current weather…' });
            feedback.textContent = '';
        }
        try {
            const current = await weather.request('current', coordinates, request.signal);
            if (request.signal.aborted) return;
            if (!Number.isFinite(current?.temperature)) throw new Error('Current conditions are missing.');
            const providerName = current.location_name || current.location;
            reading = {
                place: place.name || (providerName && providerName !== 'Unknown' ? providerName : 'Near you'),
                source: place.source,
                current,
                offset: current.timezone ?? place.offset
            };
            sky.update(reading);
            shortcutSummary.textContent = [`${current.temperature}°C`, current.description || current.condition].filter(Boolean).join(' · ');
            if (!locationButton.disabled) feedback.textContent = '';
        } catch (error) {
            if (request.signal.aborted) return;
            if (reading) sky.update({ ...reading, current: { ...reading.current, stale: true } });
            else sky.update({ place: place.name || 'Near you', source: place.source, offset: place.offset, message: 'Current weather is unavailable' });
            if (locationButton.disabled) return;
            feedback.textContent = error.status === 401 ? 'Sign in again to load weather.' : '';
        } finally {
            if (activeRequest === request) activeRequest = null;
        }
    }

    async function locate(quietly = false) {
        if (locationButton.disabled) return;
        locationButton.disabled = true;
        if (!quietly) feedback.textContent = 'Finding your location…';
        try {
            const coordinates = await weather.getDeviceLocation();
            loadWeather({ ...coordinates, source: 'Device location' });
        } catch (error) {
            if (!quietly) feedback.textContent = `${error.message} You can also choose a city under Full forecast.`;
        } finally {
            locationButton.disabled = false;
        }
    }

    locationButton.hidden = false;
    locationButton.addEventListener('click', () => locate());
    const places = JSON.parse(byId('homeWeatherLocation').textContent);
    if (places.saved) {
        loadWeather({ ...places.saved, source: places.saved.name ? 'Saved city' : 'Last shared location' });
    } else {
        loadWeather({ ...places.default, source: 'Default city' });
        weather.hasLocationPermission().then(granted => { if (granted && location?.source === 'Default city') locate(true); });
    }

    function refresh() {
        if (location && !document.hidden && navigator.onLine && !activeRequest && !locationButton.disabled
            && Date.now() - lastAttempt >= 600000) {
            loadWeather(location, true);
        }
    }
    setInterval(refresh, 600000);
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('online', refresh);
})();
