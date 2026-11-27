'use strict';

(() => {
    const app = document.getElementById('weatherApp');
    if (!app) return;

    const places = [
        { name: 'Johannesburg', state: 'Gauteng', country: 'ZA', lat: -26.2041, lon: 28.0473 },
        { name: 'Cape Town', state: 'Western Cape', country: 'ZA', lat: -33.9249, lon: 18.4241 },
        { name: 'Durban', state: 'KwaZulu-Natal', country: 'ZA', lat: -29.8587, lon: 31.0218 },
        { name: 'Pretoria', state: 'Gauteng', country: 'ZA', lat: -25.7461, lon: 28.1881 },
        { name: 'Gqeberha', alias: 'Port Elizabeth', state: 'Eastern Cape', country: 'ZA', lat: -33.9608, lon: 25.6022 },
        { name: 'Bloemfontein', state: 'Free State', country: 'ZA', lat: -29.0852, lon: 26.1596 },
        { name: 'East London', state: 'Eastern Cape', country: 'ZA', lat: -33.0153, lon: 27.9116 },
        { name: 'Kimberley', state: 'Northern Cape', country: 'ZA', lat: -28.7282, lon: 24.7499 },
        { name: 'Polokwane', state: 'Limpopo', country: 'ZA', lat: -23.9045, lon: 29.4689 },
        { name: 'Mbombela', alias: 'Nelspruit', state: 'Mpumalanga', country: 'ZA', lat: -25.4653, lon: 30.9785 },
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
        { name: 'eMalahleni', alias: 'Witbank', state: 'Mpumalanga', country: 'ZA', lat: -25.8708, lon: 29.2347 },
        { name: 'Klerksdorp', state: 'North West', country: 'ZA', lat: -26.8667, lon: 26.6667 },
        { name: 'Alberton', state: 'Gauteng', country: 'ZA', lat: -26.2678, lon: 28.1222 },
        { name: 'Boksburg', state: 'Gauteng', country: 'ZA', lat: -26.2123, lon: 28.2555 },
        { name: 'Germiston', state: 'Gauteng', country: 'ZA', lat: -26.2155, lon: 28.1676 },
        { name: 'Randburg', state: 'Gauteng', country: 'ZA', lat: -26.0936, lon: 28.0064 },
        { name: 'Springs', state: 'Gauteng', country: 'ZA', lat: -26.2547, lon: 28.4428 },
        { name: 'Kariega', alias: 'Uitenhage', state: 'Eastern Cape', country: 'ZA', lat: -33.7667, lon: 25.4000 },
        { name: 'Newcastle', state: 'KwaZulu-Natal', country: 'ZA', lat: -27.7500, lon: 29.9333 },
        { name: 'Makhanda', alias: 'Grahamstown', state: 'Eastern Cape', country: 'ZA', lat: -33.3042, lon: 26.5312 },
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

    const byId = id => document.getElementById(id);
    const settings = JSON.parse(byId('weatherSettings').textContent);
    const preferenceKey = `relatives.weather.${settings.userId}`;
    const dayFormat = new Intl.DateTimeFormat('en-ZA', { weekday: 'long', day: 'numeric', month: 'short', timeZone: 'UTC' });
    const weekday = new Intl.DateTimeFormat('en-ZA', { weekday: 'short', timeZone: 'UTC' });
    const shortDate = new Intl.DateTimeFormat('en-ZA', { day: 'numeric', month: 'short', timeZone: 'UTC' });
    const timeFormat = new Intl.DateTimeFormat('en-ZA', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC' });
    const searchInput = byId('locationSearch');
    const searchPopover = byId('searchPopover');
    const searchResults = byId('searchResults');
    const searchStatus = byId('searchStatus');
    const quickPlaces = byId('weatherPlaces');
    const pageStatus = byId('weatherStatus');
    const locationStatus = byId('weatherLocationStatus');
    const locationButton = byId('useCurrentLocation');
    const unitButton = byId('weatherUnits');
    const refreshButton = byId('weatherRefresh');
    const dayDialog = byId('dayDetailModal');
    const shareDialog = byId('shareModal');
    const heroTitle = byId('weatherHeroTitle');
    const heroSummary = byId('weatherHeroSummary');
    const heroLocationSource = byId('weatherHeroLocationSource');
    const daylightCard = byId('weatherDaylight');
    const chartCard = byId('hourlySection');
    const forecastCanvas = byId('forecastGraph');
    const forecastTrack = byId('forecastTrack');
    const forecastSlots = byId('forecastSlots');
    const forecastReading = byId('forecastReading');
    const rainButton = byId('showRainForecast');
    const chartModes = app.querySelectorAll('[data-metric]');
    const chartMetrics = {
        temperature: { name: 'Temperature', color: '#ab5427', fill: '#ebb78948', format: temperature },
        precipitation: { name: 'Precipitation chance', color: '#24617f', fill: '#8fb9c9', format: value => measure(value, '%') },
        wind_speed: { name: 'Wind speed', color: '#466c68', fill: '#9dbbbd50', format: value => measure(value, ' km/h') }
    };
    const weather = window.RelativesWeather;

    let preferences = {};
    try {
        const saved = JSON.parse(localStorage.getItem(preferenceKey));
        if (saved && typeof saved === 'object' && !Array.isArray(saved)) preferences = saved;
    } catch {}
    let location = null;
    let current = null;
    let forecast = null;
    let weatherRequest;
    let lastWeatherAttempt = 0;
    let locating = 0;
    let searchRequest;
    let searchTimer;
    let results = [];
    let activeResult = -1;
    let saveCityRequest = Promise.resolve();
    let forecastMetric = 'temperature';
    let displayedHours = [];
    let selectedTime = null;
    let rainTime = null;
    const sky = weather.mountHero(byId('weatherHero'), { formatTemperature: temperature, onDaylight: renderDaylight });

    function savePreferences() {
        try {
            localStorage.setItem(preferenceKey, JSON.stringify(preferences));
        } catch {}
    }

    function validPlace(place) {
        return place && Number.isFinite(place.lat) && Math.abs(place.lat) <= 90
            && Number.isFinite(place.lon) && Math.abs(place.lon) <= 180 && typeof place.name === 'string';
    }

    function placeMatches(left, right) {
        if (!left || !right) return false;
        return Math.abs(left.lat - right.lat) < .01 && Math.abs(left.lon - right.lon) < .01;
    }

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[char]);
    }

    function tempNumber(celsius) {
        if (!Number.isFinite(celsius)) return null;
        return preferences.fahrenheit ? Math.round(celsius * 9 / 5 + 32) : Math.round(celsius);
    }

    function temperature(celsius) {
        const value = tempNumber(celsius);
        return value === null ? '—' : `${value}°${preferences.fahrenheit ? 'F' : 'C'}`;
    }

    function measure(value, unit = '') {
        return Number.isFinite(value) ? `${value}${unit}` : '—';
    }

    function localTime(timestamp, offset) {
        return Number.isFinite(timestamp) && timestamp > 0
            ? timeFormat.format((timestamp + (Number.isFinite(offset) ? offset : 0)) * 1000) : '—';
    }

    function calendarDate(date, formatter = dayFormat) {
        return formatter.format(new Date(`${date}T00:00:00Z`));
    }

    function localDate(timestamp, offset) {
        return new Date(timestamp + offset * 1000).toISOString().slice(0, 10);
    }

    function dayName(date) {
        const now = Date.now();
        const offset = forecast?.timezone ?? 0;
        if (date === localDate(now, offset)) return 'Today';
        if (date === localDate(now + 86400000, offset)) return 'Tomorrow';
        return calendarDate(date, weekday);
    }

    function weatherIcon({ icon, condition }) {
        icon = typeof icon === 'string' ? icon : '';
        if (icon.startsWith('01')) return icon.endsWith('n') ? '🌙' : '☀️';
        if (icon.startsWith('02')) return icon.endsWith('n') ? '☁️' : '🌤️';
        if (icon.startsWith('50')) return '🌫️';
        return { Clear: '☀️', Clouds: '☁️', Drizzle: '🌦️', Rain: '🌧️', Thunderstorm: '⛈️', Snow: '❄️' }[condition] ?? '';
    }

    function closeSearch() {
        clearTimeout(searchTimer);
        searchRequest?.abort();
        searchPopover.hidden = true;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeResult = -1;
    }

    function showResults(items, message = '') {
        const selected = results[activeResult];
        results = items;
        activeResult = items.findIndex(place => placeMatches(place, selected));
        searchResults.innerHTML = items.map((place, index) => `
            <li id="weatherResult${index}" role="option" aria-selected="false" data-result="${index}">
                ${escapeHTML(place.name)}
                <small>${escapeHTML([place.state, place.country].filter(Boolean).join(', '))}</small>
            </li>`).join('');
        searchStatus.textContent = message;
        searchPopover.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
        highlightResult(activeResult);
    }

    function highlightResult(index) {
        activeResult = index;
        Array.from(searchResults.children).forEach((item, position) => {
            item.setAttribute('aria-selected', String(index === position));
        });
        if (index < 0) {
            searchInput.removeAttribute('aria-activedescendant');
            return;
        }
        searchInput.setAttribute('aria-activedescendant', searchResults.children[index].id);
    }

    function startSearch() {
        closeSearch();
        results = [];
        const query = searchInput.value.trim();
        if (query.length < 2) return;
        const normalized = query.toLowerCase();
        const matches = place => [place.name, place.alias, place.state].some(word => word?.toLowerCase().includes(normalized));
        const local = places.filter(matches).slice(0, 5);
        showResults(local, 'Searching for places…');
        const controller = new AbortController();
        searchRequest = controller;
        searchTimer = setTimeout(async () => {
            try {
                const data = await weather.request('search', { q: query }, controller.signal);
                if (controller.signal.aborted) return;
                const combined = [...local];
                for (const place of data.results.filter(validPlace)) {
                    if (!combined.some(item => placeMatches(item, place) || (
                        item.name.toLowerCase() === place.name.toLowerCase() && item.country === place.country && item.state === place.state
                    ))) {
                        combined.push(place);
                    }
                }
                showResults(combined, combined.length ? '' : 'No places found. Try a nearby city or add the country.');
            } catch (error) {
                if (controller.signal.aborted) return;
                const message = error.status === 401 ? 'Sign in again to search more places.' : 'Online search is unavailable. Try one of the local suggestions or search again.';
                showResults(local, message);
            }
        }, 250);
    }

    function chooseResult(index) {
        if (!results[index] || searchPopover.hidden) return;
        selectPlace(results[index], true);
    }

    function renderQuickPlaces() {
        const recent = Array.isArray(preferences.places) ? preferences.places.filter(validPlace) : [];
        const choices = [];
        for (const place of [location, preferences.recent, ...recent, ...places.slice(0, 4)]) {
            if (validPlace(place) && !choices.some(other => placeMatches(other, place))) choices.push(place);
        }
        const focused = quickPlaces.contains(document.activeElement) ? document.activeElement.textContent : '';
        quickPlaces.replaceChildren();
        for (const place of choices.slice(0, 6)) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = placeMatches(place, location) ? locationName() : place.name;
            if (place.state || place.country) button.title = [place.state, place.country].filter(Boolean).join(', ');
            button.setAttribute('aria-pressed', String(placeMatches(place, location)));
            button.addEventListener('click', () => {
                if (!placeMatches(place, location)) selectPlace(place, true);
            });
            quickPlaces.append(button);
            if (focused === button.textContent) button.focus();
        }
        quickPlaces.hidden = false;
    }

    function rememberPlace(place) {
        preferences.recent = place;
        const recent = Array.isArray(preferences.places) ? preferences.places.filter(validPlace) : [];
        preferences.places = [place, ...recent.filter(other => !placeMatches(other, place))].slice(0, 5);
        savePreferences();
        saveCityRequest = saveCityRequest.then(async () => {
            const response = await fetch('/weather/api/api.php?action=save-city', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': settings.csrf },
                body: JSON.stringify({ name: place.name, lat: place.lat, lon: place.lon })
            });
            if (!response.ok) throw new Error('City could not be saved.');
        }).catch(() => {
            if (preferences.recent === place && placeMatches(location, place)) {
                locationStatus.textContent = 'City saved on this device. It could not be saved to your account.';
            }
        });
    }

    function selectPlace(place, remember = false) {
        locating++;
        locationButton.disabled = false;
        locationStatus.textContent = '';
        searchInput.value = '';
        closeSearch();
        if (!location || place.lat !== location.lat || place.lon !== location.lon) {
            current = null;
            forecast = null;
            selectedTime = null;
            if (dayDialog.open) dayDialog.close();
            if (shareDialog.open) shareDialog.close();
        }
        location = { ...place };
        if (remember) rememberPlace(location);
        renderQuickPlaces();
        loadWeather();
    }

    async function useDeviceLocation(quietly = false) {
        if (locationButton.disabled) return;
        const attempt = ++locating;
        locationButton.disabled = true;
        if (!quietly) locationStatus.textContent = 'Finding your location…';
        try {
            const coordinates = await weather.getDeviceLocation();
            if (attempt === locating) selectPlace({ ...coordinates, name: 'My location', source: 'Device location', useProviderName: true });
        } catch (error) {
            if (attempt === locating && !quietly) locationStatus.textContent = `${error.message} You can also search for a city.`;
        } finally {
            if (attempt === locating) locationButton.disabled = false;
        }
    }

    async function loadWeather(quiet = false) {
        weatherRequest?.abort();
        const controller = new AbortController();
        weatherRequest = controller;
        lastWeatherAttempt = Date.now();
        const params = { lat: location.lat, lon: location.lon };
        refreshButton.disabled = true;
        if (!quiet) pageStatus.textContent = `Loading weather for ${location.name}…`;
        renderWeather();

        const errors = [];
        const loadPart = async (action, parameters, apply) => {
            try {
                const data = await weather.request(action, parameters, controller.signal);
                if (controller.signal.aborted) return;
                apply(data);
                renderWeather();
            } catch (error) {
                if (!controller.signal.aborted) {
                    errors.push({ action, status: error.status });
                    if (action === 'current' && current) current = { ...current, stale: true };
                    if (action === 'forecast' && forecast) forecast = { ...forecast, stale: true };
                }
            }
        };
        await Promise.all([
            loadPart('current', params, data => {
                if (!Number.isFinite(data?.temperature)) throw new Error('Current conditions are missing.');
                current = data;
            }),
            loadPart('forecast', { ...params, include: 'hourly' }, data => {
                if (!Array.isArray(data?.forecast) || !data.forecast.length || !Array.isArray(data.hourly) || !data.hourly.length) {
                    throw new Error('Forecast is missing.');
                }
                forecast = data;
            })
        ]);
        if (controller.signal.aborted) return;
        weatherRequest = null;
        refreshButton.disabled = false;
        if (errors.some(error => error.status === 401)) {
            pageStatus.textContent = 'Your session expired. Sign in again to refresh weather.';
        } else if (errors.length) {
            const failed = errors.length === 2 ? 'Weather' : errors[0].action === 'current' ? 'Current conditions' : 'The forecast';
            pageStatus.textContent = `${failed} could not be updated.${current || forecast ? ' Showing available data.' : ''} Use Refresh to try again.`;
        } else {
            pageStatus.textContent = current?.stale || forecast?.stale ? 'Showing the last available weather.' : '';
        }
        renderWeather();
        const selectedPlace = quickPlaces.querySelector('[aria-pressed="true"]');
        if (selectedPlace) selectedPlace.textContent = locationName();
    }

    function locationName() {
        if (!location) return '';
        const providerName = [current?.location_name || current?.location, forecast?.location_name]
            .find(name => name && name !== 'Unknown');
        return location.useProviderName && providerName ? providerName : location.name;
    }

    function upcomingHours() {
        const now = Date.now() / 1000;
        return forecast?.hourly.filter(hour => hour.timestamp >= now && hour.timestamp <= now + 86400) ?? [];
    }

    function metrics(values) {
        return `<dl class="wx-metrics">${values.map(([label, value]) => `<div><dt>${label}</dt><dd>${value}</dd></div>`).join('')}</dl>`;
    }

    function renderSky() {
        const title = locationName() || 'Current weather';
        const offset = current?.timezone ?? forecast?.timezone ?? location?.offset;
        const observed = current?.dt;
        heroTitle.textContent = title;
        heroLocationSource.textContent = location?.source ?? '';
        heroLocationSource.hidden = !location?.source;
        heroSummary.textContent = observed
            ? `${current.stale ? 'Last observation' : 'Observed'} at ${localTime(observed, offset)} local time` : '';
        heroSummary.hidden = !observed;
        refreshButton.hidden = !location;
        sky.update({
            current,
            offset,
            message: refreshButton.disabled ? 'Loading current weather…' : 'Current weather is unavailable'
        });
    }

    function renderDaylight(daylight) {
        daylightCard.hidden = !daylight;
        if (!daylight) return;
        const { sunrise, sunset, now, offset } = daylight;
        for (const [id, timestamp] of [['daylightSunrise', sunrise], ['daylightSunset', sunset]]) {
            const time = byId(id);
            const label = localTime(timestamp / 1000, offset);
            time.dateTime = label;
            if (time.textContent !== label) time.textContent = label;
        }
        const progress = Math.max(0, Math.min(1, (now - sunrise) / (sunset - sunrise)));
        daylightCard.style.setProperty('--wx-sun-progress', `${progress * 100}%`);
        let message = 'The sun has set for today.';
        if (now < sunset) {
            const beforeSunrise = now < sunrise;
            const remaining = Math.ceil(((beforeSunrise ? sunrise : sunset) - now) / 60000);
            const hours = Math.floor(remaining / 60);
            const minutes = remaining % 60;
            const duration = [hours && `${hours}h`, minutes && `${minutes}m`].filter(Boolean).join(' ');
            message = `${beforeSunrise ? 'Sunrise' : 'Sunset'} in ${duration}`;
        }
        byId('daylightNote').textContent = message;
    }

    function renderCurrent() {
        byId('currentWeather').hidden = !current;
        if (!current) return;
        const compass = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        const direction = Number.isFinite(current.wind_direction)
            ? compass[Math.round(current.wind_direction / 22.5 + 16) % 16] : '';
        const wind = measure(current.wind_speed, ' km/h');
        const values = [
            ['Humidity', measure(current.humidity, '%')],
            ['Wind', wind === '—' ? wind : [wind, direction].filter(Boolean).join(' ')],
            ['Gusts', measure(current.wind_gust, ' km/h')],
            ['Visibility', measure(current.visibility, ' km')],
            ['Pressure', measure(current.pressure, ' hPa')],
            ['Cloud cover', measure(current.clouds, '%')]
        ];
        if (Number.isFinite(current.rain_mm)) values.push(['Rain in the last hour', measure(current.rain_mm, ' mm')]);
        byId('currentMetrics').innerHTML = metrics(values);
    }

    function renderRainOutlook(hours) {
        const card = byId('weatherPlan');
        card.hidden = !hours.length;
        rainTime = null;
        if (!hours.length) return;

        const snow = hours.some(hour => hour.condition === 'Snow' || hour.snow_mm > 0);
        const kind = snow ? 'Precipitation' : 'Rain';
        byId('rainHeading').textContent = `${kind} outlook`;
        byId('precipitationMetric').textContent = snow ? 'Precip.' : 'Rain';

        const known = hours.filter(hour => Number.isFinite(hour.precipitation));
        const peak = known.reduce((highest, hour) => hour.precipitation > highest.precipitation ? hour : highest, known[0]);
        const firstWet = hours.find(hour => ['Rain', 'Drizzle', 'Thunderstorm', 'Snow'].includes(hour.condition) || hour.precipitation >= 50);
        const firstStorm = hours.find(hour => hour.condition === 'Thunderstorm');
        const slot = firstWet ?? peak;
        const time = hour => `${dayName(hour.date)} ${hour.time}`;
        let reading = `${kind} chance unavailable`;
        let note = 'Check the forecast intervals for conditions.';
        if (firstWet) {
            reading = `${kind} possible · ${time(firstWet)}`;
            note = Number.isFinite(firstWet.precipitation)
                ? `${firstWet.precipitation}% chance at that time` : firstWet.description;
        } else if (peak) {
            reading = `Up to ${peak.precipitation}% ${kind.toLowerCase()} chance`;
            note = peak.precipitation > 0 ? `Highest around ${time(peak)}` : 'Over the next 24 hours';
        }
        if (firstStorm) note = `${note} · Thunderstorms possible ${time(firstStorm)}`;
        byId('rainReading').textContent = reading;
        byId('rainNote').textContent = note;
        rainTime = (Number.isFinite(slot?.precipitation) ? slot : peak)?.timestamp ?? null;
        rainButton.hidden = rainTime === null;
    }

    function drawForecast() {
        if (!displayedHours.length) return;
        const { width, height } = forecastCanvas.getBoundingClientRect();
        const context = forecastCanvas.getContext('2d');
        if (!width || !height || !context) return;
        const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
        forecastCanvas.width = Math.round(width * pixelRatio);
        forecastCanvas.height = Math.round(height * pixelRatio);
        context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);

        const metric = chartMetrics[forecastMetric];
        const values = displayedHours.map(hour => hour[forecastMetric]);
        const known = values.filter(Number.isFinite);
        if (!known.length) return;
        const isRain = forecastMetric === 'precipitation';
        const min = forecastMetric === 'temperature' ? Math.min(...known) - 2 : 0;
        const max = isRain ? 100 : Math.max(min + 5, Math.max(...known) + 2);
        const graphTop = 11;
        const bottom = height - 8;
        const column = width / values.length;
        const points = values.map((value, index) => Number.isFinite(value) ? {
            x: (index + .5) * column,
            y: bottom - Math.max(0, Math.min(1, (value - min) / (max - min))) * (bottom - graphTop),
            selected: displayedHours[index].timestamp === selectedTime
        } : null);

        context.strokeStyle = '#d8ddd9';
        context.setLineDash([3, 4]);
        for (const ratio of [0, .33, .66, 1]) {
            const y = bottom - ratio * (bottom - graphTop);
            context.beginPath();
            context.moveTo(column * .25, y);
            context.lineTo(width - column * .25, y);
            context.stroke();
        }
        context.setLineDash([]);

        if (isRain) {
            const barWidth = Math.min(27, column * .45);
            for (const point of points.filter(Boolean)) {
                context.fillStyle = point.selected ? metric.color : metric.fill;
                context.fillRect(point.x - barWidth / 2, point.y, barWidth, Math.max(1, bottom - point.y));
            }
            return;
        }

        const runs = [[]];
        for (const point of points) {
            if (point) runs.at(-1).push(point);
            else if (runs.at(-1).length) runs.push([]);
        }
        for (const run of runs.filter(points => points.length)) {
            context.beginPath();
            run.forEach(({ x, y }, index) => index ? context.lineTo(x, y) : context.moveTo(x, y));
            context.strokeStyle = metric.color;
            context.lineWidth = 2.5;
            context.lineJoin = 'round';
            context.stroke();
            context.lineTo(run.at(-1).x, bottom);
            context.lineTo(run[0].x, bottom);
            context.closePath();
            context.fillStyle = metric.fill;
            context.fill();
        }
        for (const point of points.filter(Boolean)) {
            context.beginPath();
            context.arc(point.x, point.y, point.selected ? 5 : 3.4, 0, Math.PI * 2);
            context.fillStyle = metric.color;
            context.fill();
        }
    }

    function paintForecastSelection() {
        const selected = displayedHours.find(hour => hour.timestamp === selectedTime);
        if (!selected) return;
        for (const button of forecastSlots.children) {
            button.setAttribute('aria-pressed', String(Number(button.dataset.time) === selectedTime));
        }
        const metric = chartMetrics[forecastMetric];
        const chance = Number.isFinite(selected.precipitation)
            ? `${selected.precipitation}% precipitation chance` : 'Precipitation chance unavailable';
        const details = {
            temperature: chance,
            precipitation: temperature(selected.temperature),
            wind_speed: Number.isFinite(selected.wind_gust) ? `Gusts up to ${selected.wind_gust} km/h` : chance
        };
        const time = `${dayName(selected.date)} · ${selected.time}`;
        const availability = forecastCanvas.hidden ? `${metric.name} unavailable` : details[forecastMetric];
        const description = [selected.description, availability].filter(Boolean).join(' · ');
        const markup = `<time datetime="${new Date(selected.timestamp * 1000).toISOString()}">${escapeHTML(time)}</time>
            <strong>${metric.format(selected[forecastMetric])}</strong><span>${escapeHTML(description)}</span>`;
        if (forecastReading.dataset.markup !== markup) {
            forecastReading.innerHTML = markup;
            forecastReading.dataset.markup = markup;
        }
        drawForecast();
    }

    function renderForecastChart(hours) {
        chartCard.hidden = !hours.length;
        displayedHours = hours;
        if (!hours.length) return;
        if (!hours.some(hour => hour.timestamp === selectedTime)) selectedTime = hours[0].timestamp;
        const focusTime = Number(document.activeElement?.closest('#forecastSlots button')?.dataset.time);
        const metric = chartMetrics[forecastMetric];
        forecastCanvas.hidden = !hours.some(hour => Number.isFinite(hour[forecastMetric]));
        forecastTrack.style.setProperty('--wx-slots', hours.length);
        for (const button of chartModes) button.setAttribute('aria-pressed', String(forecastMetric === button.dataset.metric));
        forecastSlots.innerHTML = hours.map(hour => {
            const time = `${dayName(hour.date)} ${hour.time}`;
            const value = metric.format(hour[forecastMetric]);
            const label = `${time}, ${metric.name} ${value === '—' ? 'unavailable' : value}`;
            return `<button type="button" data-time="${hour.timestamp}" aria-label="${escapeHTML(label)}">
                <span>${escapeHTML(calendarDate(hour.date, weekday))}</span>
                <strong>${escapeHTML(hour.time)}</strong>
                <span>${value}</span>
            </button>`;
        }).join('');
        paintForecastSelection();
        if (focusTime) forecastSlots.querySelector(`[data-time="${focusTime}"]`)?.focus({ preventScroll: true });
    }

    function forecastDays() {
        if (!forecast) return [];
        const now = Date.now();
        const today = localDate(now, forecast.timezone ?? 0);
        const future = forecast.forecast.filter(day => day.date > today);
        const todayForecast = forecast.forecast.find(day => day.date === today);
        const remaining = forecast.hourly.filter(hour => hour.date === today && hour.timestamp * 1000 >= now);
        if (!todayForecast || !remaining.length) return future;

        const lows = remaining.map(hour => hour.temp_min ?? hour.temperature).filter(Number.isFinite);
        const highs = remaining.map(hour => hour.temp_max ?? hour.temperature).filter(Number.isFinite);
        const chances = remaining.map(hour => hour.precipitation).filter(Number.isFinite);
        return [{
            ...todayForecast,
            temp_min: lows.length ? Math.min(...lows) : null,
            temp_max: highs.length ? Math.max(...highs) : null,
            precipitation: chances.length ? Math.max(...chances) : null
        }, ...future];
    }

    function renderDaily() {
        const days = forecastDays();
        byId('dailySection').hidden = !days.length;
        if (!days.length) return;

        const low = Math.min(...days.map(day => day.temp_min).filter(Number.isFinite));
        const high = Math.max(...days.map(day => day.temp_max).filter(Number.isFinite));
        const scale = Math.max(1, high - low);
        const list = byId('weeklyForecast');
        const focusDate = document.activeElement?.closest('#weeklyForecast [data-date]')?.dataset.date;
        list.innerHTML = days.map(day => {
            const lowLabel = temperature(day.temp_min);
            const highLabel = temperature(day.temp_max);
            const bar = Number.isFinite(low) && Number.isFinite(high) && Number.isFinite(day.temp_min) && Number.isFinite(day.temp_max)
                ? `<i style="--wx-range-start:${(day.temp_min - low) / scale * 100}%;--wx-range-length:${(day.temp_max - day.temp_min) / scale * 100}%"></i>` : '';
            const probability = Number.isFinite(day.precipitation) ? `${day.precipitation}% precip.` : 'Chance unavailable';
            const name = dayName(day.date);
            const dateLabel = name === 'Today' ? 'Rest of day' : calendarDate(day.date, shortDate);
            return `<button type="button" class="wx-day" data-date="${escapeHTML(day.date)}" aria-haspopup="dialog">
                <span class="wx-day-name"><strong>${escapeHTML(name)}</strong><span class="wx-day-date">${dateLabel}</span></span>
                <span class="wx-day-conditions"><span class="wx-day-icon" aria-hidden="true">${weatherIcon(day)}</span>${escapeHTML(day.description)}</span>
                <span class="wx-day-chance">${probability}</span>
                <span class="wx-day-range" role="img" aria-label="Forecast low ${lowLabel}, high ${highLabel}">
                    <span>${lowLabel}</span><span class="wx-range-track">${bar}</span><span>${highLabel}</span>
                </span>
                <span class="wx-day-arrow" aria-hidden="true">›</span>
            </button>`;
        }).join('');
        if (focusDate) Array.from(list.children).find(button => button.dataset.date === focusDate)?.focus({ preventScroll: true });
    }

    function renderWeather() {
        const hours = upcomingHours();
        renderSky();
        byId('weatherShare').hidden = !current && !forecast;
        byId('weatherSource').hidden = !current && !forecast;
        renderCurrent();
        renderRainOutlook(hours);
        renderForecastChart(hours);
        renderDaily();
    }

    function renderHour(hour) {
        return `<article class="wx-hour">
            <span class="wx-hour-time">${escapeHTML(hour.time)}</span>
            <span class="wx-hour-icon" aria-hidden="true">${weatherIcon(hour)}</span>
            <strong>${temperature(hour.temperature)}</strong>
            <small>${escapeHTML(hour.description)} · ${measure(hour.precipitation, '%')} precipitation chance</small>
            ${metrics([
                ['Feels like', temperature(hour.feels_like)], ['Humidity', measure(hour.humidity, '%')],
                ['Wind', measure(hour.wind_speed, ' km/h')], ['Gusts', measure(hour.wind_gust, ' km/h')],
                ['Rain', measure(hour.rain_mm, ' mm')], ['Snow', measure(hour.snow_mm, ' mm')],
                ['Cloud cover', measure(hour.clouds, '%')], ['Visibility', measure(hour.visibility, ' km')],
                ['Pressure', measure(hour.pressure, ' hPa')], ['Dew point', temperature(hour.dew_point)]
            ])}
        </article>`;
    }

    function openDay(date) {
        const day = forecastDays().find(item => item.date === date);
        if (!day) return;
        byId('weatherDayTitle').textContent = calendarDate(date);
        const hours = forecast.hourly.filter(hour => hour.date === date && hour.timestamp * 1000 >= Date.now());
        byId('dayDetailContent').innerHTML = `
            <p>${escapeHTML(day.description)} · High ${temperature(day.temp_max)} · Low ${temperature(day.temp_min)} · Peak precipitation chance ${measure(day.precipitation, '%')}</p>
            <div class="wx-detail-grid">${hours.map(hour => renderHour(hour)).join('')}</div>`;
        dayDialog.showModal();
    }

    function setView(view) {
        byId('weeklyForecast').classList.toggle('is-list', view === 'list');
        app.querySelectorAll('[data-view]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.view === view));
        });
        preferences.view = view;
    }

    function reportLines() {
        const lines = [`Weather in ${locationName()}`];
        if (current) {
            lines.push('', `${temperature(current.temperature)} · ${current.description} · Feels like ${temperature(current.feels_like)}`,
                `Humidity ${measure(current.humidity, '%')} · Wind ${measure(current.wind_speed, ' km/h')}`);
        }
        if (forecast) {
            lines.push('', 'Daily forecast', ...forecastDays().map(day =>
                `${calendarDate(day.date)} · ${temperature(day.temp_max)} / ${temperature(day.temp_min)} · ${day.description} · Peak precipitation chance ${measure(day.precipitation, '%')}`));
        }
        lines.push('', 'Weather data from OpenWeather.');
        return lines;
    }

    function exportWeather(format) {
        shareDialog.close();
        const text = reportLines().join('\n');
        if (format === 'whatsapp') {
            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener,noreferrer');
            return;
        }
        if (format === 'print') {
            const printable = document.createElement('div');
            printable.className = 'wx-print-summary';
            printable.textContent = text;
            document.body.append(printable);
            try {
                window.print();
            } finally {
                printable.remove();
            }
            return;
        }
        const name = locationName();
        const unit = preferences.fahrenheit ? 'F' : 'C';
        const rows = [
            ['Date', 'Location', `Temp (°${unit})`, 'Description', 'Humidity (%)', 'Wind (km/h)', `High (°${unit})`, `Low (°${unit})`, 'Peak precipitation chance (%)']
        ];
        if (current) rows.push(['Current', name, tempNumber(current.temperature), current.description, current.humidity, current.wind_speed]);
        if (forecast) {
            rows.push(...forecastDays().map(day => [day.date, name, '', day.description, day.humidity, day.wind_speed,
                tempNumber(day.temp_max), tempNumber(day.temp_min), day.precipitation]));
        }
        const csv = rows.map(row => Array.from({ length: rows[0].length }, (_, index) => {
            const cell = row[index];
            if (typeof cell !== 'string') return cell ?? '';
            const safe = /^[\s]*[=+@-]|^[\t\r\n]/.test(cell) ? `'${cell}` : cell;
            return `"${safe.replaceAll('"', '""')}"`;
        }).join(',')).join('\r\n');
        const blob = new Blob([format === 'csv' ? csv : text], {
            type: format === 'csv' ? 'text/csv;charset=utf-8' : 'text/plain;charset=utf-8'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `weather-${name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.${format === 'csv' ? 'csv' : 'txt'}`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }

    searchInput.addEventListener('input', startSearch);
    searchInput.addEventListener('focus', startSearch);
    searchInput.addEventListener('blur', closeSearch);
    searchResults.addEventListener('mousedown', event => event.preventDefault());
    searchResults.addEventListener('click', event => {
        const option = event.target.closest('[data-result]');
        if (option) chooseResult(Number(option.dataset.result));
    });
    searchInput.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !searchPopover.hidden) {
            event.preventDefault();
            closeSearch();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (searchPopover.hidden) startSearch();
            if (!results.length) return;
            const delta = event.key === 'ArrowDown' ? 1 : -1;
            const index = activeResult < 0 ? (delta > 0 ? 0 : results.length - 1) : (activeResult + delta + results.length) % results.length;
            highlightResult(index);
            searchResults.children[index].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter' && !searchPopover.hidden && results.length) {
            event.preventDefault();
            chooseResult(Math.max(activeResult, 0));
        }
    });
    locationButton.hidden = false;
    locationButton.addEventListener('click', () => useDeviceLocation());
    refreshButton.addEventListener('click', () => loadWeather());
    unitButton.addEventListener('click', () => {
        preferences.fahrenheit = !preferences.fahrenheit;
        unitButton.setAttribute('aria-pressed', String(preferences.fahrenheit));
        savePreferences();
        renderWeather();
    });
    for (const button of chartModes) {
        button.addEventListener('click', () => {
            forecastMetric = button.dataset.metric;
            renderForecastChart(displayedHours);
        });
    }
    forecastSlots.addEventListener('click', event => {
        const button = event.target.closest('button[data-time]');
        if (!button) return;
        selectedTime = Number(button.dataset.time);
        paintForecastSelection();
    });
    forecastSlots.addEventListener('keydown', event => {
        const button = event.target.closest('button[data-time]');
        if (!button || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const neighbors = {
            ArrowLeft: button.previousElementSibling,
            ArrowRight: button.nextElementSibling,
            Home: forecastSlots.firstElementChild,
            End: forecastSlots.lastElementChild
        };
        event.preventDefault();
        const next = neighbors[event.key];
        if (!next) return;
        selectedTime = Number(next.dataset.time);
        paintForecastSelection();
        next.focus();
    });
    rainButton.addEventListener('click', () => {
        if (rainTime === null) return;
        selectedTime = rainTime;
        forecastMetric = 'precipitation';
        renderForecastChart(displayedHours);
        forecastSlots.querySelector(`[data-time="${selectedTime}"]`)?.focus();
    });
    new ResizeObserver(drawForecast).observe(forecastCanvas);
    byId('weatherShare').addEventListener('click', () => shareDialog.showModal());
    byId('weeklyForecast').addEventListener('click', event => {
        const button = event.target.closest('[data-date]');
        if (button) openDay(button.dataset.date);
    });
    app.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
        setView(button.dataset.view);
        savePreferences();
    }));
    shareDialog.querySelectorAll('[data-export]').forEach(button => button.addEventListener('click', () => exportWeather(button.dataset.export)));

    setView(preferences.view === 'cards' ? 'cards' : 'list');
    preferences.fahrenheit = preferences.fahrenheit === true;
    unitButton.hidden = false;
    unitButton.setAttribute('aria-pressed', String(Boolean(preferences.fahrenheit)));
    const tracked = settings.trackedLocation && { ...settings.trackedLocation,
        name: 'Last shared location', source: 'Last shared location', useProviderName: true };
    const query = new URLSearchParams(window.location.search);
    const linked = query.get('lat')?.trim() && query.get('lon')?.trim()
        ? { lat: Number(query.get('lat')), lon: Number(query.get('lon')), name: 'Your location', useProviderName: true }
        : null;
    if (validPlace(linked)) {
        selectPlace(linked);
    } else if (validPlace(preferences.recent)) {
        selectPlace(preferences.recent);
    } else if (validPlace(settings.savedCity)) {
        selectPlace({ ...settings.savedCity, source: 'Saved city' });
    } else if (validPlace(tracked)) {
        selectPlace(tracked);
    } else {
        const defaultPlace = { ...settings.defaultCity, source: 'Default city' };
        selectPlace(defaultPlace);
        weather.hasLocationPermission().then(granted => {
            if (granted && location?.source === 'Default city') useDeviceLocation(true);
        });
    }

    function refreshForecast(afterReconnect = false) {
        const due = Date.now() - lastWeatherAttempt >= 600000 || afterReconnect;
        if (due && location && navigator.onLine && !document.hidden && !weatherRequest && !locationButton.disabled
            && !dayDialog.open && !shareDialog.open) {
            loadWeather(true);
        }
    }

    setInterval(refreshForecast, 60000);
    document.addEventListener('visibilitychange', () => refreshForecast());
    window.addEventListener('online', () => refreshForecast(true));
})();
