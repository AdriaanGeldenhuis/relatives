'use strict';

window.RelativesPlanner = (() => {
    const timeZone = 'Africa/Johannesburg';
    const zoneClock = new Intl.DateTimeFormat('en-GB', {
        timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
    });
    const eventSignal = 'relatives.planner.events';
    const noteSignal = 'relatives.planner.notes';

    function today() {
        const parts = Object.fromEntries(zoneClock.formatToParts(new Date()).map(({ type, value }) => [type, value]));
        return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
    }

    function shiftDate(date, days) {
        const midday = new Date(date + 'T12:00:00Z');
        midday.setUTCDate(midday.getUTCDate() + days);
        return midday.toISOString().slice(0, 10);
    }

    function onDay(event, date) {
        return event.starts_at < shiftDate(date, 1)
            && (event.starts_at.slice(0, 10) >= date || (event.ends_at || '') > date + ' 00:00:00');
    }

    function timeOnDay(event, date) {
        if (event.all_day) return 'All day';
        const starts = event.starts_at;
        const ends = event.ends_at;
        const start = starts.slice(0, 10) < date ? '00:00' : starts.slice(11, 16);
        if (!ends || ends <= starts) return start;
        const end = ends.slice(0, 10) > date ? '24:00' : ends.slice(11, 16);
        return start + '–' + end;
    }

    function broadcast(key) {
        try {
            localStorage.setItem(key, Date.now() + ':' + Math.random());
        } catch {
            // Other views can still refresh when reopened.
        }
    }

    async function request(csrf, action, values = {}, method = 'POST') {
        const payload = values instanceof FormData ? values : new URLSearchParams(values);
        payload.set('action', action);
        let url = '/schedule/api/events.php';
        const options = { method };
        if (method === 'GET') {
            url += '?' + payload;
        } else {
            payload.set('csrf_token', csrf);
            options.body = payload;
        }
        const response = await fetch(url, options);
        if (response.status === 401) throw new Error('Your session expired. Sign in again before saving.');
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.success) {
            const error = new Error(result?.message || result?.error || 'Could not reach the server. Try again.');
            error.status = response.status;
            throw error;
        }
        if (method !== 'GET') {
            if (!['link_note', 'unlink_note'].includes(action)) broadcast(eventSignal);
            if (result.note_linked || ['link_note', 'unlink_note'].includes(action)) broadcast(noteSignal);
        }
        return result;
    }

    return { today, shiftDate, onDay, timeOnDay, request, broadcast, eventSignal, noteSignal };
})();
