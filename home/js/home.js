/**
 * ============================================
 * RELATIVES v4.1 - HOME (FAMILY COMMAND CENTER)
 * Day-ring clock · live countdowns · weather ·
 * cursor spotlight · count-ups
 * ============================================
 */

(function () {
    'use strict';

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    // Flag @property support so the rotating hero border only runs where it animates smoothly
    if (window.CSS && typeof CSS.registerProperty === 'function') {
        document.documentElement.classList.add('has-props');
    }

    // ============================================
    // DAY-RING CLOCK (fills as the day passes)
    // ============================================
    var hmEl = document.getElementById('clockHM');
    var secEl = document.getElementById('clockSec');
    var ringEl = document.getElementById('clockRing');
    var capEl = document.getElementById('dayCap');
    var dateEl = document.getElementById('heroDate');

    function tick() {
        var now = new Date();

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

    tick();
    tickDate();
    setInterval(tick, 1000);
    setInterval(tickDate, 60000);

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

    // ============================================
    // CURSOR SPOTLIGHT (desktop pointers only)
    // ============================================
    if (window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches) {
        document.querySelectorAll('.spot').forEach(function (el) {
            el.addEventListener('pointermove', function (e) {
                var r = el.getBoundingClientRect();
                el.style.setProperty('--mx', (e.clientX - r.left) + 'px');
                el.style.setProperty('--my', (e.clientY - r.top) + 'px');
            });
        });
    }

    // ============================================
    // WEATHER STRIP (inside the hero card)
    // ============================================
    var weather = {
        el: document.getElementById('heroWeather'),
        location: null,
        loadedAt: 0,

        init: function () {
            if (!this.el) return;

            if (window.USER_LOCATION && window.USER_LOCATION.lat && window.USER_LOCATION.lng) {
                this.location = { lat: window.USER_LOCATION.lat, lng: window.USER_LOCATION.lng };
                this.load();
            } else if ('geolocation' in navigator) {
                var self = this;
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        self.location = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                        self.load();
                    },
                    function () { self.renderFallback(); },
                    { timeout: 8000, maximumAge: 600000 }
                );
            } else {
                this.renderFallback();
            }
        },

        load: function () {
            var self = this;
            if (!this.location) return;

            var base = '/weather/api/api.php?lat=' + this.location.lat + '&lon=' + this.location.lng;

            Promise.all([
                fetch(base + '&action=current').then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                }),
                fetch(base + '&action=forecast').then(function (r) {
                    return r.ok ? r.json() : null;
                }).catch(function () { return null; })
            ]).then(function (results) {
                var current = results[0];
                var forecast = results[1];
                if (!current || current.error) throw new Error(current && current.error);

                if (forecast && forecast.forecast && forecast.forecast[0]) {
                    current.temp_high = forecast.forecast[0].temp_max;
                    current.temp_low = forecast.forecast[0].temp_min;
                }

                self.loadedAt = Date.now();
                self.render(current);
            }).catch(function (err) {
                console.warn('Weather load failed:', err);
                self.renderFallback();
            });
        },

        rainChance: function (w) {
            var condition = (w.condition || '').toLowerCase();
            if (condition.indexOf('rain') !== -1 || condition.indexOf('drizzle') !== -1) return 100;
            if (condition.indexOf('thunder') !== -1 || condition.indexOf('storm') !== -1) return 95;

            var p = Math.max(0, Math.min(100, w.clouds * 0.4 + (w.humidity - 50) * 0.6));
            if (condition.indexOf('overcast') !== -1) p += 15;
            else if (condition.indexOf('clear') !== -1 || condition.indexOf('sunny') !== -1) p = Math.min(p, 20);
            return Math.min(100, Math.round(p));
        },

        emoji: function (condition, day) {
            var c = (condition || '').toLowerCase();
            if (c.indexOf('clear') !== -1 || c.indexOf('sunny') !== -1) return day ? '☀️' : '🌙';
            if (c.indexOf('partly') !== -1 && c.indexOf('cloud') !== -1) return day ? '⛅' : '☁️';
            if (c.indexOf('overcast') !== -1 || c.indexOf('cloud') !== -1) return '☁️';
            if (c.indexOf('drizzle') !== -1) return '🌦️';
            if (c.indexOf('rain') !== -1) return '🌧️';
            if (c.indexOf('thunder') !== -1 || c.indexOf('storm') !== -1) return '⛈️';
            if (c.indexOf('snow') !== -1) return '❄️';
            if (c.indexOf('mist') !== -1 || c.indexOf('fog') !== -1 || c.indexOf('haze') !== -1) return '🌫️';
            if (c.indexOf('wind') !== -1) return '💨';
            return day ? '🌤️' : '🌙';
        },

        render: function (w) {
            var now = new Date();
            var day = true;
            if (w.sunrise && w.sunset) {
                day = now >= new Date(w.sunrise * 1000) && now <= new Date(w.sunset * 1000);
            }

            var rain = this.rainChance(w);
            var hilo = '';
            if (w.temp_high !== undefined) {
                hilo = '<span class="wx-hilo"><span class="hi">H ' + w.temp_high + '°</span> · <span class="lo">L ' + w.temp_low + '°</span></span>';
            }

            this.el.innerHTML =
                '<div class="wx-now">' +
                    '<span class="wx-emoji" aria-hidden="true">' + this.emoji(w.condition, day) + '</span>' +
                    '<span class="wx-temp">' + w.temperature + '°</span>' +
                    '<span class="wx-meta">' +
                        '<span class="wx-desc">' + w.description + '</span>' +
                        hilo +
                    '</span>' +
                '</div>' +
                '<div class="wx-stats">' +
                    '<span class="wx-stat' + (rain >= 60 ? ' rain-high' : '') + '">☔ ' + rain + '%</span>' +
                    '<span class="wx-stat">💨 ' + w.wind_speed + ' km/h</span>' +
                    '<span class="wx-stat">📍 ' + w.location + '</span>' +
                '</div>' +
                '<span class="wx-arrow" aria-hidden="true">→</span>';
        },

        renderFallback: function () {
            if (!this.el) return;
            this.el.innerHTML =
                '<span class="wx-fallback">' +
                    '<span aria-hidden="true">🌍</span>' +
                    '<span>Tap for the full weather forecast</span>' +
                '</span>' +
                '<span class="wx-arrow" aria-hidden="true">→</span>';
        }
    };

    weather.init();

    // Refresh weather when the app returns to the foreground (stale after 10 min)
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && weather.location && Date.now() - weather.loadedAt > 600000) {
            weather.load();
        }
    });

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
