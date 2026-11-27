'use strict';

(() => {
    const finePointer = matchMedia('(any-hover: hover) and (any-pointer: fine)');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');

    function mountScene(hero) {
        const controls = hero.querySelector('[data-sky-controls]');
        const buttons = controls.querySelectorAll('button');
        controls.hidden = false;
        controls.addEventListener('click', event => {
            const button = event.target.closest('button[data-sky-preview]');
            if (!button) return;
            hero.dataset.skyPreview = button.dataset.skyPreview;
            buttons.forEach(choice => choice.setAttribute('aria-pressed', String(choice === button)));
        });

        let frame;
        hero.addEventListener('pointermove', event => {
            if (!finePointer.matches || reducedMotion.matches || event.pointerType === 'touch' || frame) return;
            frame = requestAnimationFrame(() => {
                frame = null;
                const bounds = hero.getBoundingClientRect();
                const x = (event.clientX - bounds.left) / bounds.width - .5;
                const y = (event.clientY - bounds.top) / bounds.height - .5;
                hero.style.setProperty('--welcome-x', `${x * 16}px`);
                hero.style.setProperty('--welcome-y', `${y * 12}px`);
            });
        });
        hero.addEventListener('pointerleave', () => {
            cancelAnimationFrame(frame);
            frame = null;
            hero.style.removeProperty('--welcome-x');
            hero.style.removeProperty('--welcome-y');
        });
    }

    function mountPublicWeather(hero) {
        const weather = window.RelativesWeather;
        const city = {
            place: hero.querySelector('[data-sky-place]').textContent,
            offset: Number(hero.dataset.publicWeatherOffset)
        };
        const sky = weather.mountHero(hero, { initial: { ...city, message: 'Loading current conditions…' } });
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
                else sky.update({ ...city, message: 'Current weather is unavailable' });
            }
        }

        if (!navigator.onLine) sky.update({ ...city, message: 'Current weather is unavailable' });
        refresh();
        setInterval(refresh, 600000);
        window.addEventListener('online', refresh);
        document.addEventListener('visibilitychange', refresh);
    }

    document.querySelectorAll('[data-welcome-sky]').forEach(hero => {
        mountScene(hero);
        if (hero.hasAttribute('data-public-weather-offset')) mountPublicWeather(hero);
    });
})();
