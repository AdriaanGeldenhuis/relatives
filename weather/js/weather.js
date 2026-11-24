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
    let locating = 0;
    let searchRequest;
    let searchTimer;
    let results = [];
    let activeResult = -1;
    const sky = weather.mountHero(app.querySelector('.sky-hero'), { formatTemperature: temperature });

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

    function dayName(day) {
        const localNow = Date.now() + (forecast.timezone || 0) * 1000;
        const today = new Date(localNow).toISOString().slice(0, 10);
        if (day.date === today) return 'Today';
        const tomorrow = new Date(localNow + 86400000).toISOString().slice(0, 10);
        return day.date === tomorrow ? 'Tomorrow' : day.day_name;
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
        const popular = places.slice(0, 4);
        const recent = validPlace(preferences.recent) ? preferences.recent : null;
        const choices = recent ? [recent, ...popular.filter(place => !placeMatches(place, recent))] : popular;
        const focused = quickPlaces.contains(document.activeElement) ? document.activeElement.textContent : '';
        quickPlaces.innerHTML = '';
        for (const place of choices.slice(0, 5)) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = place.name;
            if (place.state || place.country) button.title = [place.state, place.country].filter(Boolean).join(', ');
            button.setAttribute('aria-pressed', String(placeMatches(place, location)));
            button.addEventListener('click', () => selectPlace(place, true));
            quickPlaces.append(button);
            if (focused === place.name) button.focus();
        }
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
            if (dayDialog.open) dayDialog.close();
            if (shareDialog.open) shareDialog.close();
        }
        location = { ...place };
        if (remember) {
            preferences.recent = location;
            savePreferences();
        }
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
            if (attempt === locating) selectPlace({ ...coordinates, name: 'My location', useProviderName: true });
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
                if (!controller.signal.aborted) errors.push({ action, status: error.status });
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
            pageStatus.textContent = '';
        }
        renderSky();
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
        const offset = current?.timezone ?? forecast?.timezone;
        const timestamp = current?.dt ?? forecast?.fetched_at;
        const detail = timestamp ? `${current?.dt ? 'Observed' : 'Forecast retrieved'} at ${localTime(timestamp, offset)} local time.`
            : refreshButton.disabled ? 'Loading the local forecast…' : 'Refresh or choose another city.';
        heroTitle.textContent = locationName() || 'Weather';
        heroSummary.textContent = location ? [location.source, detail].filter(Boolean).join(' · ')
            : 'Search for a city or use your location to see its weather.';
        refreshButton.hidden = !location;
        sky.update({
            current,
            offset,
            message: location && (refreshButton.disabled ? 'Loading current weather…' : 'Current weather is unavailable')
        });
    }

    function renderWeather() {
        const hours = upcomingHours();
        renderSky();
        byId('weatherShare').hidden = !current && !forecast;
        byId('weatherOverview').hidden = !current && !forecast;
        byId('weatherSource').hidden = !current && !forecast;
        renderCurrent();
        renderPlan(hours);
        byId('hourlySection').hidden = !hours.length;
        byId('hourlyForecast').innerHTML = hours.map(hour => renderHour(hour)).join('');
        byId('dailySection').hidden = !forecast?.forecast.length;
        byId('weeklyForecast').innerHTML = (forecast?.forecast ?? []).map(day => `
            <button type="button" class="wx-day" data-date="${escapeHTML(day.date)}" aria-haspopup="dialog">
                <span><strong>${escapeHTML(dayName(day))}</strong><span class="wx-day-date">${calendarDate(day.date, shortDate)}</span></span>
                <span class="wx-day-icon" aria-hidden="true">${weatherIcon(day)}</span>
                <span class="wx-day-temps">${temperature(day.temp_max)} / ${temperature(day.temp_min)}</span>
                <small>${escapeHTML(day.description)} · ${measure(day.precipitation, '%')} peak rain</small>
            </button>`).join('');
    }

    function renderCurrent() {
        const element = byId('currentWeather');
        if (!current) {
            element.innerHTML = '<h3>Right now</h3><p>Current conditions are unavailable.</p>';
            return;
        }
        const wind = measure(current.wind_speed, ' km/h');
        element.innerHTML = `
            <h3>Right now</h3>
            <div class="wx-now">
                <span class="wx-now-icon" aria-hidden="true">${weatherIcon(current)}</span>
                <span class="wx-now-temp">${temperature(current.temperature)}</span>
                <span class="wx-now-condition"><strong>${escapeHTML(current.description)}</strong><small>Feels like ${temperature(current.feels_like)}</small></span>
            </div>
            ${metrics([
                ['Humidity', measure(current.humidity, '%')], ['Wind', wind],
                ['Visibility', measure(current.visibility, ' km')], ['Pressure', measure(current.pressure, ' hPa')],
                ['Sunrise', localTime(current.sunrise, current.timezone)], ['Sunset', localTime(current.sunset, current.timezone)]
            ])}`;
    }

    function renderPlan(hours) {
        const element = byId('weatherPlan');
        if (!hours.length) {
            element.innerHTML = '<h3>Plan your day</h3><p>The next 24 hours are unavailable.</p>';
            return;
        }
        const temps = hours.map(hour => hour.temperature).filter(Number.isFinite);
        const rain = hours.reduce((peak, hour) => (hour.precipitation ?? -1) > (peak?.precipitation ?? -1) ? hour : peak, null);
        const peakWind = Math.max(...hours.map(hour => hour.wind_gust ?? hour.wind_speed).filter(Number.isFinite));
        let note = 'Check the forecast below for the conditions at your planned time.';
        if (hours.some(hour => hour.condition === 'Thunderstorm')) {
            note = 'Thunderstorms are possible in the next 24 hours. Check the forecast before heading out.';
        } else if (rain?.precipitation >= 60) {
            note = `Rain is most likely around ${escapeHTML(rain.time)} on ${escapeHTML(rain.day)}. Pack a rain jacket if you’ll be out then.`;
        } else if (peakWind >= 40) {
            note = 'Wind could pick up. Check the forecast before outdoor plans.';
        } else if (Math.max(...temps) >= 32) {
            note = 'A hot stretch is forecast. Take water if you’re heading out.';
        }
        element.innerHTML = `
            <h3>Plan your day</h3><p>Next 24 hours</p>
            ${metrics([
                ['High / low', `${temperature(Math.max(...temps))} / ${temperature(Math.min(...temps))}`],
                ['Peak rain chance', measure(rain?.precipitation, '%')], ['Peak wind or gust', measure(peakWind, ' km/h')]
            ])}
            <p class="wx-plan-note">${note}</p>`;
    }

    function renderHour(hour, detailed = false) {
        return `<article class="wx-hour">
            <span class="wx-hour-time">${escapeHTML(hour.day)} · ${escapeHTML(hour.time)}</span>
            <span class="wx-hour-icon" aria-hidden="true">${weatherIcon(hour)}</span>
            <strong>${temperature(hour.temperature)}</strong>
            <small>${escapeHTML(hour.description)} · ${measure(hour.precipitation, '%')} rain</small>
            ${detailed ? metrics([
                ['Feels like', temperature(hour.feels_like)], ['Humidity', measure(hour.humidity, '%')],
                ['Wind', measure(hour.wind_speed, ' km/h')], ['Gusts', measure(hour.wind_gust, ' km/h')],
                ['Rain', measure(hour.rain_mm, ' mm')], ['Snow', measure(hour.snow_mm, ' mm')],
                ['Cloud cover', measure(hour.clouds, '%')], ['Visibility', measure(hour.visibility, ' km')],
                ['Pressure', measure(hour.pressure, ' hPa')], ['Dew point', temperature(hour.dew_point)]
            ]) : ''}
        </article>`;
    }

    function openDay(date) {
        const day = forecast?.forecast.find(item => item.date === date);
        if (!day) return;
        byId('weatherDayTitle').textContent = calendarDate(date);
        const hours = forecast.hourly.filter(hour => hour.date === date);
        byId('dayDetailContent').innerHTML = `
            <p>${escapeHTML(day.description)} · High ${temperature(day.temp_max)} · Low ${temperature(day.temp_min)} · Peak rain chance ${measure(day.precipitation, '%')}</p>
            <div class="wx-detail-grid">${hours.map(hour => renderHour(hour, true)).join('')}</div>`;
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
            lines.push('', 'Daily forecast', ...forecast.forecast.map(day =>
                `${calendarDate(day.date)} · ${temperature(day.temp_max)} / ${temperature(day.temp_min)} · ${day.description} · Peak rain chance ${measure(day.precipitation, '%')}`));
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
            ['Date', 'Location', `Temp (°${unit})`, 'Description', 'Humidity (%)', 'Wind (km/h)', `High (°${unit})`, `Low (°${unit})`, 'Peak rain chance (%)']
        ];
        if (current) rows.push(['Current', name, tempNumber(current.temperature), current.description, current.humidity, current.wind_speed]);
        if (forecast) {
            rows.push(...forecast.forecast.map(day => [day.date, name, '', day.description, day.humidity, day.wind_speed,
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

    setView(preferences.view === 'list' ? 'list' : 'cards');
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
    } else if (validPlace(tracked)) {
        selectPlace(tracked);
    } else {
        renderQuickPlaces();
        pageStatus.textContent = 'Choose a place to see its weather.';
        weather.hasLocationPermission().then(granted => { if (granted && !location) useDeviceLocation(true); });
    }
    setInterval(() => {
        if (location && navigator.onLine && !document.hidden && !refreshButton.disabled && !locationButton.disabled
            && !dayDialog.open && !shareDialog.open && !byId('weeklyForecast').contains(document.activeElement)) {
            loadWeather(true);
        }
    }, 600000);
})();
