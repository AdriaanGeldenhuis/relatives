'use strict';

(() => {
    const inbox = () => document.getElementById('notificationsContent');
    const preferences = document.getElementById('preferencesModal');
    const preferencesContent = document.getElementById('preferencesContent');
    const clearDialog = document.getElementById('clearConfirmModal');
    const preferencesUrl = '/notifications/api/preferences.php';
    let navigation;
    let toast;

    function showToast(message, error = false) {
        toast?.remove();
        const status = document.createElement('div');
        status.className = error ? 'notif-toast toast-error' : 'notif-toast';
        status.setAttribute('role', 'status');
        status.textContent = message;
        const dialog = document.querySelector('.notifications-dialog[open]');
        (dialog || document.body).append(status);
        toast = status;
        setTimeout(() => status.remove(), 3500);
    }

    async function loadInbox(url = location.href, { push = false, focusFilter = false, historyNavigation = false, quiet = false } = {}) {
        const target = new URL(url, location.href);
        const fragment = new URL(target);
        fragment.searchParams.set('ajax', '1');
        navigation?.controller.abort();
        const controller = new AbortController();
        const request = { controller, target, push, focusFilter, historyNavigation };
        navigation = request;
        inbox().setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(fragment, { signal: controller.signal });
            if (!response.ok || response.redirected) throw new Error('Inbox unavailable');
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            const next = template.content.firstElementChild;
            if (navigation !== request) return;
            if (next?.id !== 'notificationsContent') throw new Error('Invalid inbox response');
            if (quiet && inbox().innerHTML === next.innerHTML && inbox().dataset.unread === next.dataset.unread) return;
            const active = document.activeElement;
            const action = active?.dataset.notificationAction;
            const card = active?.closest('[data-notification-id]');
            inbox().replaceWith(next);
            if (push) history.pushState(null, '', target);
            window.HeaderMenu?.updateNotificationBadge(next.dataset.unread);

            if (focusFilter || active?.dataset.notificationFilter) {
                const filter = active?.dataset.notificationFilter;
                const filterLink = !focusFilter && [...next.querySelectorAll('.notifications-filters [data-notification-filter]')]
                    .find(link => link.dataset.notificationFilter === filter);
                (filterLink || next.querySelector('.notifications-filters [aria-current="page"]'))?.focus({ preventScroll: true });
            } else if (action && !active.isConnected) {
                const replacement = card
                    ? next.querySelector(`[data-notification-id="${card.dataset.notificationId}"]`)
                    : next;
                (replacement?.querySelector(`[data-notification-action="${action}"]`)
                    || replacement?.querySelector('.notification-actions button')
                    || next.querySelector('.notifications-filters [aria-current="page"]'))?.focus({ preventScroll: true });
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                if (push || historyNavigation) location.assign(target);
                else if (!quiet) showToast('Could not refresh notifications.', true);
            }
        } finally {
            if (navigation === request) {
                navigation = null;
                inbox().removeAttribute('aria-busy');
            }
        }
    }

    function refreshInbox() {
        return navigation ? loadInbox(navigation.target, navigation) : loadInbox();
    }

    async function post(url, action, values = {}, keepalive = false) {
        const response = await fetch(url, { method: 'POST', keepalive, headers: { 'X-CSRF-Token': preferences.dataset.csrf }, body: new URLSearchParams({ action, ...values }) });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Request failed');
    }

    async function updateInbox(control, action, card) {
        control.disabled = true;
        try {
            const values = card ? { notification_id: card.dataset.notificationId } : {};
            await post('/notifications/', action, values);
            if (action === 'clear_all') clearDialog.close();
            await refreshInbox();
        } catch {
            showToast('Could not update notifications. Try again.', true);
        } finally {
            control.disabled = false;
        }
    }

    const categories = [
        ['message', 'Messages'], ['shopping', 'Shopping'], ['calendar', 'Calendar'], ['schedule', 'Schedule'],
        ['tracking', 'Location'], ['weather', 'Weather'], ['note', 'Notes'], ['system', 'System']
    ];
    const settings = [['enabled', 'Enable'], ['push_enabled', 'Push'], ['sound_enabled', 'Sound'], ['vibrate_enabled', 'Vibrate']];

    function renderPreferences(data) {
        const values = new Map(data.preferences.map(pref => [pref.category, pref]));
        const weather = data.weather_schedule || { enabled: 1, notification_time: '07:00:00', voice_enabled: 1, include_forecast: 1 };
        const time = String(weather.notification_time).slice(0, 5);
        const clock = (value, fallback) => /^\d{2}:\d{2}/.test(String(value)) ? value.slice(0, 5) : fallback;
        const toggle = (name, label, checked, category = '') => `
            <label class="pref-toggle">
                <input type="checkbox" name="${name}" ${category ? `data-category="${category}"` : ''} ${Number(checked) ? 'checked' : ''}>
                <span>${label}</span>
            </label>`;

        preferencesContent.innerHTML = `
            <div class="preferences-grid">
                ${categories.map(([category, title]) => `
                    <fieldset class="pref-card">
                        <legend>${title}</legend>
                        <div class="pref-controls">
                            ${settings.map(([name, label]) => toggle(name, label, values.get(category)?.[name] ?? 1, category)).join('')}
                            ${toggle('quiet_hours_enabled', 'Quiet hours', values.get(category)?.quiet_hours_enabled ?? 0, category)}
                            <label>From <input type="time" data-category="${category}" name="quiet_hours_start" value="${clock(values.get(category)?.quiet_hours_start, '22:00')}"></label>
                            <label>To <input type="time" data-category="${category}" name="quiet_hours_end" value="${clock(values.get(category)?.quiet_hours_end, '07:00')}"></label>
                        </div>
                    </fieldset>`).join('')}
            </div>
            <form id="notificationWeatherForm">
                <fieldset class="weather-schedule-section">
                    <legend>Daily weather updates</legend>
                    <div class="weather-schedule-form">
                        ${toggle('enabled', 'Enable daily weather', weather.enabled)}
                        <label class="form-group">Time <input type="time" name="time" value="${/^\d{2}:\d{2}$/.test(time) ? time : '07:00'}" required></label>
                        ${toggle('voice_enabled', 'Voice prompt', weather.voice_enabled)}
                        ${toggle('include_forecast', 'Include forecast', weather.include_forecast)}
                    </div>
                </fieldset>
                <div class="preferences-actions">
                    <button type="submit" class="btn btn-primary">Save preferences</button>
                    <button type="button" class="btn" data-notification-action="test_weather">Test weather</button>
                    <button type="button" class="btn" data-notification-action="test">Test notification</button>
                </div>
            </form>`;
    }

    async function showPreferences() {
        if (preferences.open) return;
        preferencesContent.textContent = 'Loading preferences…';
        preferences.showModal();
        try {
            const response = await fetch(preferencesUrl + '?action=get');
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error('Settings unavailable');
            renderPreferences(data);
        } catch {
            preferencesContent.textContent = 'Could not load preferences. Close settings and try again.';
        }
    }

    preferences.addEventListener('change', async event => {
        const control = event.target;
        if (!control.matches('input[data-category]')) return;
        const checkbox = control.type === 'checkbox';
        const setting = checkbox ? Number(control.checked) : control.value;
        if (!checkbox && !setting) return;
        control.disabled = true;
        try {
            await post(preferencesUrl, 'update', {
                category: control.dataset.category,
                settings: JSON.stringify({ [control.name]: setting })
            });
            if (!checkbox) control.defaultValue = setting;
            showToast('Preference saved.');
        } catch {
            if (checkbox) control.checked = !Boolean(setting);
            else control.value = control.defaultValue;
            showToast('Could not save the preference.', true);
        } finally {
            control.disabled = false;
        }
    });

    preferences.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.target;
        const submit = event.submitter || form.querySelector('[type="submit"]');
        if (submit.disabled) return;
        submit.disabled = true;
        try {
            await post(preferencesUrl, 'update_weather_schedule', {
                time: form.elements.time.value,
                enabled: Number(form.elements.enabled.checked),
                voice_enabled: Number(form.elements.voice_enabled.checked),
                include_forecast: Number(form.elements.include_forecast.checked)
            });
            preferences.close();
            showToast('Preferences saved.');
        } catch {
            showToast('Could not save weather preferences.', true);
        } finally {
            submit.disabled = false;
        }
    });

    async function sendTest(button, action) {
        button.disabled = true;
        try {
            await post(preferencesUrl, action);
            showToast('Test notification sent.');
            await refreshInbox();
        } catch (error) {
            showToast(error.message || 'Could not send the test notification.', true);
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener('click', async event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const filter = event.target.closest('[data-notification-filter]');
        if (filter) {
            event.preventDefault();
            if (filter.href !== location.href) loadInbox(filter.href, { push: true, focusFilter: true });
            return;
        }
        const control = event.target.closest('[data-notification-action]');
        if (!control) return;
        const action = control.dataset.notificationAction;
        const card = control.closest('[data-notification-id]');
        switch (action) {
            case 'preferences': showPreferences(); break;
            case 'confirm_clear': clearDialog.showModal(); break;
            case 'close': control.closest('dialog').close(); break;
            case 'open':
                if (!card.hasAttribute('data-unread')) break;
                post('/notifications/', 'mark_read', { notification_id: card.dataset.notificationId }, true).catch(() => {});
                break;
            case 'test':
            case 'test_weather': sendTest(control, action); break;
            case 'mark_all_read':
            case 'delete_notification':
                if (action === 'delete_notification' && !confirm('Delete this notification?')) break;
                updateInbox(control, action, card);
                break;
            case 'mark_read':
            case 'clear_all': updateInbox(control, action, card); break;
        }
    });

    window.addEventListener('popstate', () => loadInbox(location.href, { focusFilter: true, historyNavigation: true }));

    function refreshIfVisible() {
        if (!document.hidden && !navigation && !document.querySelector('.notifications-dialog[open]')) {
            loadInbox(location.href, { quiet: true });
        }
    }
    window.addEventListener('relativesnotification', refreshIfVisible);
    window.addEventListener('online', refreshIfVisible);
    document.addEventListener('visibilitychange', refreshIfVisible);
    setInterval(refreshIfVisible, 5000);
})();
