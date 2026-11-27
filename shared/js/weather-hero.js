'use strict';

(() => {
    const dateFormat = new Intl.DateTimeFormat('en-ZA', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC'
    });
    const clockFormat = new Intl.DateTimeFormat('en-ZA', {
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC'
    });
    const androidApp = Boolean(window.TrackingBridge) || /\bRelativesAndroid(?:\/|App\b)/.test(navigator.userAgent);

    async function getDeviceLocation() {
        if (!window.isSecureContext) throw new Error('Device location requires an HTTPS connection.');
        const policy = document.permissionsPolicy ?? document.featurePolicy;
        if (policy && !policy.allowsFeature('geolocation')) {
            throw new Error('This page cannot request location. Open Relatives directly in your browser.');
        }
        if (!navigator.geolocation) throw new Error('Device location is unavailable in this browser.');
        if (androidApp) {
            const bridge = window.TrackingBridge;
            if (!bridge?.prepareWeatherLocation) {
                throw new Error('Update Relatives and Android System WebView to use device location in the app.');
            }
            if (!await bridge.prepareWeatherLocation()) {
                throw new Error('The app could not start a location request. Try again.');
            }
        }

        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(({ coords }) => {
                // Weather needs neighbourhood-level accuracy, including when opening the full forecast.
                resolve({ lat: Number(coords.latitude.toFixed(2)), lon: Number(coords.longitude.toFixed(2)) });
            }, ({ code }) => {
                const denied = androidApp
                    ? 'Location permission was denied. Allow location for Relatives in Android Settings and try again.'
                    : 'Location permission was denied. Allow location for this site in your browser and try again.';
                const message = code === 1 ? denied : code === 3
                    ? 'Finding your location took too long. Try again.'
                    : 'Your device could not find a location. Check that device location is on and try again.';
                reject(new Error(message));
            }, { timeout: 12000, maximumAge: 120000 });
        });
    }

    async function hasLocationPermission() {
        // The Android bridge accepts only a location request initiated by the page's button.
        if (androidApp || !window.isSecureContext || !navigator.geolocation) return false;
        try {
            return (await navigator.permissions?.query({ name: 'geolocation' }))?.state === 'granted';
        } catch {
            return false;
        }
    }

    async function request(action, parameters, signal) {
        const query = new URLSearchParams({ action, ...parameters });
        const response = await fetch(`/weather/api/api.php?${query}`, { cache: action === 'welcome' ? 'default' : 'no-store', signal });
        if (!response.ok) {
            const error = new Error('Weather request failed.');
            error.status = response.status;
            throw error;
        }
        return response.json();
    }

    function skyFor(current) {
        if (!current) return 'unknown';
        const condition = String(current.condition || current.description || '').toLowerCase();
        if (/storm|thunder/.test(condition)) return 'storm';
        if (/rain|drizzle/.test(condition)) return 'rain';
        if (/snow|sleet/.test(condition)) return 'snow';
        if (/fog|mist|haze|smoke|dust|ash|sand/.test(condition)) return 'fog';
        if (/cloud|overcast/.test(condition)) return 'cloud';
        if (/clear|sunny/.test(condition)) return 'clear';
        return 'unknown';
    }

    function sunState(now, sunrise, sunset, fallback) {
        const twilight = 30 * 60 * 1000;
        if (!sunrise || !sunset) return { light: fallback, phase: fallback, altitude: 0, darkness: fallback === 'night' ? 1 : 0 };

        const fromSunrise = now - sunrise;
        const untilSunset = sunset - now;
        const rising = Math.abs(fromSunrise) <= Math.abs(untilSunset);
        const distance = rising ? fromSunrise : untilSunset;
        if (Math.abs(distance) <= twilight) {
            const progress = distance / twilight;
            return {
                light: 'twilight', phase: rising ? 'dawn' : 'dusk',
                altitude: progress * 18, darkness: Math.max(0, -progress)
            };
        }
        const night = now < sunrise || now >= sunset;
        const light = night ? 'night' : 'day';
        return { light, phase: light, altitude: 0, darkness: night ? 1 : 0 };
    }

    function rainIntensity(current) {
        if (Number.isFinite(current.rain_mm) && current.rain_mm > 0) {
            if (current.rain_mm >= 7.6) return 'heavy';
            return current.rain_mm < 2.5 ? 'light' : 'steady';
        }
        const description = `${current.condition ?? ''} ${current.description ?? ''}`.toLowerCase();
        if (/heavy|extreme|thunder|storm/.test(description)) return 'heavy';
        return /light|drizzle/.test(description) ? 'light' : 'steady';
    }

    function mount(hero, { formatTemperature = value => `${Math.round(value)}°C`, onTime, onDaylight, initial = {} } = {}) {
        const panel = hero.querySelector('[data-weather-panel]');
        const field = name => panel.querySelector(`[data-sky-${name}]`);
        const fields = Object.fromEntries(['readout', 'place', 'source', 'clock', 'time', 'date', 'temp',
            'condition', 'feels', 'daylight', 'sunrise', 'sunset'].map(name => [name, field(name)]));
        const deviceClock = panel.hasAttribute('data-device-clock');
        let data = initial;

        function text(element, value, hideWhenEmpty = false) {
            if (!element) return;
            if (element.textContent !== value) element.textContent = value;
            if (hideWhenEmpty) element.hidden = !value;
        }

        function paint() {
            const { place, source, current, offset, message } = data;
            const now = Date.now();
            const deviceOffset = -new Date(now).getTimezoneOffset() * 60;
            const offsetSeconds = Number.isFinite(offset) ? offset : deviceClock ? deviceOffset : null;
            const shifted = instant => new Date(instant + offsetSeconds * 1000);
            const local = offsetSeconds === null ? null : shifted(now);
            const hour = local?.getUTCHours();
            const periodFor = hours => hours < 12 ? 'morning' : hours < 18 ? 'afternoon' : 'evening';
            const period = local ? periodFor(hour) : '';
            const time = local ? clockFormat.format(local) : '';
            const date = local ? dateFormat.format(local) : '';
            const sunrise = Number.isFinite(current?.sunrise) && current.sunrise > 0 ? current.sunrise * 1000 : null;
            const sunset = Number.isFinite(current?.sunset) && current.sunset > 0 ? current.sunset * 1000 : null;
            const hasSunTimes = Boolean(local && sunrise && sunset && sunset > sunrise
                && shifted(sunrise).toISOString().slice(0, 10) === local.toISOString().slice(0, 10)
                && shifted(sunset).toISOString().slice(0, 10) === local.toISOString().slice(0, 10));
            let fallbackLight = 'unknown';
            if (current && /[dn]$/.test(current.icon ?? '') && (!current.dt || Math.abs(now - current.dt * 1000) < 5400000)) {
                fallbackLight = current.icon.endsWith('n') ? 'night' : 'day';
            } else if (local) {
                fallbackLight = hour >= 6 && hour < 18 ? 'day' : 'night';
            }
            const sun = sunState(now, hasSunTimes ? sunrise : null, hasSunTimes ? sunset : null, fallbackLight);
            const { light } = sun;
            const sky = skyFor(current);
            hero.toggleAttribute('data-solar-update', hero.dataset.phase === sun.phase && hero.dataset.sky === sky);
            hero.dataset.time = period;
            hero.dataset.light = light;
            hero.dataset.sky = sky;
            hero.dataset.phase = sun.phase;
            hero.style.setProperty('--sun-altitude', `${sun.altitude.toFixed(2)}%`);
            hero.style.setProperty('--night-mix', sun.darkness.toFixed(3));
            const overcast = sky === 'cloud' && (Number.isFinite(current.clouds)
                ? current.clouds >= 65 : !/few|scattered/i.test(current.description ?? ''));
            hero.toggleAttribute('data-overcast', overcast);
            if (sky === 'rain' || sky === 'storm') hero.dataset.rainIntensity = rainIntensity(current);
            else delete hero.dataset.rainIntensity;

            text(fields.date, date, true);
            text(fields.time, time);
            if (time) fields.time.dateTime = time;
            else fields.time.removeAttribute('datetime');
            fields.clock.hidden = !time;
            fields.readout.hidden = !time && !deviceClock;
            if (onTime) {
                const greetingTime = deviceClock ? new Date(now + deviceOffset * 1000) : local;
                if (greetingTime) onTime({ period: periodFor(greetingTime.getUTCHours()), date: dateFormat.format(greetingTime) });
            }

            text(fields.place, place || 'On this device');
            text(fields.source, [source, current?.stale ? 'Last available reading' : ''].filter(Boolean).join(' · '), true);
            const temp = Number.isFinite(current?.temperature) ? formatTemperature(current.temperature) : '';
            text(fields.temp, temp, true);
            text(fields.condition, current?.description || current?.condition || message
                || (deviceClock ? 'Use your location for current weather' : 'Choose a place to see current conditions'));
            text(fields.feels, Number.isFinite(current?.feels_like)
                ? `Feels like ${formatTemperature(current.feels_like)}` : '', true);
            onDaylight?.(hasSunTimes ? { sunrise, sunset, now, offset: offsetSeconds } : null);
            if (fields.daylight) fields.daylight.hidden = !hasSunTimes;
            if (!hasSunTimes) return;

            const sunTimes = [[fields.sunrise, sunrise], [fields.sunset, sunset]];
            for (const [element, instant] of sunTimes) {
                if (!element) continue;
                const localTime = clockFormat.format(shifted(instant));
                text(element, localTime);
                element.dateTime = localTime;
            }
        }

        paint();
        setInterval(() => { if (!document.hidden) paint(); }, 15000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) paint(); });
        return { update: next => { data = next; paint(); } };
    }

    window.RelativesWeather = { mountHero: mount, getDeviceLocation, hasLocationPermission, request };
})();
