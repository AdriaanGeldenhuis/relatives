'use strict';

(() => {
    const hero = document.querySelector('[data-public-weather-offset]');
    if (!hero) return;

    const weather = window.RelativesWeather;
    const city = {
        place: hero.querySelector('[data-sky-place]').textContent,
        offset: Number(hero.dataset.publicWeatherOffset)
    };
    const sky = weather.mountHero(hero, { initial: { ...city, message: 'Loading current weather…' } });
    let lastAttempt = 0;
    let reading;

    async function refresh() {
        if (document.hidden || !navigator.onLine || Date.now() - lastAttempt < 600000) return;
        lastAttempt = Date.now();
        try {
            const current = await weather.request('welcome');
            if (!Number.isFinite(current?.temperature)) throw new Error('Current conditions are missing.');
            reading = current;
            sky.update({ ...city, current });
        } catch {
            if (reading) sky.update({ ...city, current: { ...reading, stale: true } });
            else sky.update({ ...city, message: 'Current weather unavailable' });
        }
    }

    if (!navigator.onLine) sky.update({ ...city, message: 'Current weather unavailable' });
    refresh();
    setInterval(refresh, 600000);
    window.addEventListener('online', refresh);
    document.addEventListener('visibilitychange', refresh);
})();
