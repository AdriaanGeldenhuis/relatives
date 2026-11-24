'use strict';

(() => {
    const dateFormat = new Intl.DateTimeFormat('en-ZA', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC'
    });
    const clockFormat = new Intl.DateTimeFormat('en-ZA', {
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC'
    });
    const symbols = { rain: '🌧️', storm: '⛈️', cloud: '☁️', snow: '❄️', fog: '🌫️' };

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

    function mount(hero, { formatTemperature = value => `${Math.round(value)}°C`, onTime } = {}) {
        if (!hero) return null;
        const panel = hero.querySelector('[data-weather-panel]');
        const field = name => panel.querySelector(`[data-sky-${name}]`);
        const fields = Object.fromEntries(['readout', 'place', 'source', 'clock', 'time', 'period', 'date', 'temp',
            'condition', 'feels', 'wind', 'icon', 'sunrise', 'sunset', 'sun-note'].map(name => [name, field(name)]));
        const views = panel.querySelectorAll('[data-sky-view]');
        const viewContents = panel.querySelectorAll('[data-sky-content]');
        const switcher = panel.querySelector('.sky-switch');
        const deviceClock = panel.hasAttribute('data-device-clock');
        let data = {};
        let selectedView = 'now';

        function showView(view) {
            selectedView = view;
            views.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.skyView === view)));
            viewContents.forEach(content => { content.hidden = content.dataset.skyContent !== view; });
        }
        views.forEach(button => button.addEventListener('click', () => showView(button.dataset.skyView)));

        function text(element, value, hideWhenEmpty = false) {
            if (!element) return;
            if (element.textContent !== value) element.textContent = value;
            if (hideWhenEmpty) element.hidden = !value;
        }

        function paint() {
            const { place, source, current, offset, message } = data;
            const now = Date.now();
            const offsetSeconds = Number.isFinite(offset) ? offset : deviceClock ? -new Date(now).getTimezoneOffset() * 60 : null;
            const shifted = instant => new Date(instant + offsetSeconds * 1000);
            const local = offsetSeconds === null ? null : shifted(now);
            const hour = local?.getUTCHours();
            const period = hour === undefined ? '' : hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
            const time = local ? clockFormat.format(local) : '';
            const date = local ? dateFormat.format(local) : '';
            const sunrise = Number.isFinite(current?.sunrise) && current.sunrise > 0 ? current.sunrise * 1000 : null;
            const sunset = Number.isFinite(current?.sunset) && current.sunset > 0 ? current.sunset * 1000 : null;
            const hasSunTimes = Boolean(local && sunrise && sunset && sunset > sunrise
                && shifted(sunrise).toISOString().slice(0, 10) === local.toISOString().slice(0, 10)
                && shifted(sunset).toISOString().slice(0, 10) === local.toISOString().slice(0, 10));
            let light = 'unknown';
            if (hasSunTimes) {
                const nearSunrise = Math.abs(now - sunrise) <= 1800000;
                const nearSunset = Math.abs(now - sunset) <= 1800000;
                light = nearSunrise || nearSunset ? 'twilight' : now > sunrise && now < sunset ? 'day' : 'night';
            } else if (current && /[dn]$/.test(current.icon ?? '') && (!current.dt || Math.abs(now - current.dt * 1000) < 5400000)) {
                light = current.icon.endsWith('n') ? 'night' : 'day';
            } else if (local) {
                light = hour >= 6 && hour < 18 ? 'day' : 'night';
            }
            const sky = skyFor(current);
            hero.dataset.time = period;
            hero.dataset.light = light;
            hero.dataset.sky = sky;
            const overnight = light === 'night' && (hour < 5 || hour >= 22);
            const periodLabel = period ? period[0].toUpperCase() + period.slice(1) : '';
            const lightLabel = { day: 'Daylight', twilight: 'Twilight', night: 'Night' }[light];
            text(fields.period, overnight ? 'Overnight' : [periodLabel, lightLabel].filter(Boolean).join(' · '), true);
            text(fields.date, date, true);
            text(fields.time, time);
            if (time) fields.time.dateTime = time;
            else fields.time.removeAttribute('datetime');
            fields.clock.hidden = !time;
            fields.readout.hidden = !time && !deviceClock;
            if (onTime && local) onTime({ period, date });

            text(fields.place, place || 'On this device');
            text(fields.source, source || '', true);
            const temp = Number.isFinite(current?.temperature) ? formatTemperature(current.temperature) : '';
            text(fields.temp, temp, true);
            text(fields.condition, current?.description || current?.condition || message
                || (deviceClock ? 'Use your location for current weather' : 'Choose a place to see current conditions'));
            text(fields.feels, Number.isFinite(current?.feels_like)
                ? `Feels like ${formatTemperature(current.feels_like)}` : '', true);
            text(fields.wind, Number.isFinite(current?.wind_speed) ? `Wind ${current.wind_speed} km/h` : '', true);
            text(fields.icon, current ? symbols[sky] || (light === 'night' ? '🌙' : '☀️') : '', true);

            switcher.hidden = !hasSunTimes;
            if (!hasSunTimes) {
                if (selectedView !== 'now') showView('now');
                return;
            }
            const sunTimes = [[fields.sunrise, sunrise], [fields.sunset, sunset]];
            for (const [element, instant] of sunTimes) {
                const localTime = clockFormat.format(shifted(instant));
                text(element, localTime);
                element.dateTime = localTime;
            }
            const nextSunEvent = now < sunrise ? sunrise : now < sunset ? sunset : null;
            let sunNote = 'The sun has set for today.';
            if (nextSunEvent) {
                const minutes = Math.ceil((nextSunEvent - now) / 60000);
                const hours = Math.floor(minutes / 60);
                const remaining = minutes % 60;
                const countdown = [hours && `${hours}h`, remaining && `${remaining}m`].filter(Boolean).join(' ');
                sunNote = `${nextSunEvent === sunrise ? 'Sunrise' : 'Sunset'} in ${countdown}`;
            }
            text(fields['sun-note'], sunNote);
        }

        paint();
        setInterval(paint, 15000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) paint(); });
        return { update: next => { data = next; paint(); } };
    }

    window.RelativesWeatherHero = { mount };
})();
