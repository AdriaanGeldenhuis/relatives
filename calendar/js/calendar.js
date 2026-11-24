'use strict';

(() => {
    const $ = id => document.getElementById(id);
    const planner = window.RelativesPlanner;
    const initial = JSON.parse($('calendarData').textContent);
    const { csrf, types, prefill } = initial;
    const state = { ...initial };
    const content = $('calendarContent');
    const eventDialog = $('calendarEventDialog');
    const dateDialog = $('calendarDateDialog');
    const dateText = new Intl.DateTimeFormat('en-ZA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
    const shortDate = new Intl.DateTimeFormat('en-ZA', { day: 'numeric', month: 'short', timeZone: 'UTC' });
    const monthText = new Intl.DateTimeFormat('en-ZA', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    let navigation = null;
    let refreshQueued = false;
    let opened = null;
    let relatedRequest = 0;
    let savingDetail = false;
    let noticeTimer;
    let filterTimer;

    const dateValue = date => new Date(date + 'T12:00:00Z');
    const findEvent = id => state.events.find(event => String(event.id) === String(id));
    const kind = event => types[event.kind] || types.other;
    const status = event => ({ done: 'Completed', in_progress: 'In progress' })[event.status] || 'Open';
    const eventUrl = id => '/calendar/?event=' + encodeURIComponent(id);
    const scheduleUrl = (date, id) => '/schedule/?' + new URLSearchParams(id ? { date, event: id } : { date });
    const noteUrl = id => '/notes/?note=' + encodeURIComponent(id);

    function calendarUrl(date, view, ajax = false, event = null) {
        const params = new URLSearchParams({ date, view });
        if (ajax) params.set('ajax', '1');
        for (const [key, id] of Object.entries({ q: 'calendarSearch', kind: 'calendarKind', assignee: 'calendarAssignee' })) {
            if ($(id).value) params.set(key, $(id).value);
        }
        if (event) params.set('event', event);
        return '/calendar/?' + params;
    }

    function message(text) {
        const el = $('calendarNotice');
        clearTimeout(noticeTimer);
        el.textContent = text;
        el.hidden = !text;
        if (text) noticeTimer = setTimeout(() => { el.hidden = true; }, 7000);
    }

    function detailMessage(text) {
        const el = $('calendarEventNotice');
        el.textContent = text;
        el.hidden = !text;
    }

    function api(action, values = {}, method = 'POST') {
        const payload = values instanceof FormData ? values : new URLSearchParams(values);
        if (method === 'POST') payload.set('surface', 'calendar');
        return planner.request(csrf, action, payload, method);
    }

    const editor = planner.createEditor({
        types, getDate: () => state.date, save: api,
        onConflict: () => reloadCalendar(),
        show: id => $(id).showModal(),
        async onSaved(result, editing) {
            const date = planner.onDay(result.event, state.date) ? state.date : result.event.starts_at.slice(0, 10);
            if (await loadCalendar(date, state.view)) message(editing ? 'Changes saved.' : 'Event added.');
        }
    });

    function paint() {
        const date = state.date;
        const options = [...document.querySelectorAll('[data-calendar-view]')];
        options.forEach(link => {
            const view = link.dataset.calendarView;
            link.href = calendarUrl(date, view);
            if (view === state.view) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        $('calendarPrevious').setAttribute('aria-label', 'Previous ' + state.view);
        $('calendarNext').setAttribute('aria-label', 'Next ' + state.view);
        $('calendarScheduleLink').href = scheduleUrl(date);
        $('calendarPeriod').textContent = state.view === 'month' ? monthText.format(dateValue(date)) :
            state.view === 'week' ? shortDate.format(dateValue(state.first)) + ' – ' + shortDate.format(dateValue(planner.shiftDate(state.end, -1))) :
                dateText.format(dateValue(date));

    }

    async function loadCalendar(date, view, options = {}) {
        const { historyMode = 'push', focus = null, event = null } = options;
        navigation?.controller.abort();
        const controller = new AbortController();
        const request = { date, view, controller };
        navigation = request;
        content.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(calendarUrl(date, view, true, event), { signal: controller.signal });
            if (!response.ok || response.redirected) throw new Error('Could not load the calendar.');
            const next = await response.json();
            if (navigation !== request) return false;
            const changed = next.date !== state.date || next.view !== state.view;
            if (typeof next.html !== 'string' || !next.html.trim()) throw new Error('Could not update the calendar.');
            Object.assign(state, next);
            content.innerHTML = next.html;
            paint();
            if (historyMode === 'push' && changed) history.pushState(null, '', calendarUrl(state.date, state.view));
            if (focus === 'period') $('calendarPeriod').focus({ preventScroll: true });
            if (focus === 'date') content.querySelector(`.calendar-cell-date[data-select-date="${state.date}"]`)?.focus({ preventScroll: true });
            if (next.notice) message(next.notice);
            if (event && next.selectedEvent) openEvent(next.selectedEvent, state.date);
            else if (opened && eventDialog.open) {
                const current = findEvent(opened.id);
                if (current) {
                    if (!planner.onDay(current, opened.date)) opened.date = current.starts_at.slice(0, 10);
                    renderEvent(current, opened.date);
                }
                else {
                    eventDialog.close();
                    message('This event has moved or is no longer available in this view.');
                }
            }
            return true;
        } catch (error) {
            if (error.name !== 'AbortError') {
                if (historyMode === 'pop') location.assign(calendarUrl(date, view, false, event));
                else message(error.message || 'Could not update the calendar.');
            }
            return false;
        } finally {
            if (navigation === request) {
                navigation = null;
                content.removeAttribute('aria-busy');
                if (refreshQueued) {
                    refreshQueued = false;
                    reloadCalendar();
                }
            }
        }
    }

    function reloadCalendar() {
        return loadCalendar(navigation?.date || state.date, navigation?.view || state.view, { historyMode: 'none' });
    }

    function queueCalendarRefresh() {
        if (navigation) refreshQueued = true;
        else reloadCalendar();
    }

    function shiftPeriod(direction) {
        const { date, view } = navigation || state;
        if (view === 'month') {
            const first = dateValue(date.slice(0, 7) + '-01');
            first.setUTCMonth(first.getUTCMonth() + direction);
            const year = first.getUTCFullYear();
            const month = first.getUTCMonth();
            const lastDay = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
            first.setUTCDate(Math.min(lastDay, Number(date.slice(-2))));
            loadCalendar(first.toISOString().slice(0, 10), view, { focus: 'period' });
        } else {
            loadCalendar(planner.shiftDate(date, direction * (view === 'week' ? 7 : 1)), view, { focus: 'period' });
        }
    }

    function renderEvent(event, date) {
        $('calendarEventTitle').textContent = event.title;
        const startDate = event.starts_at.slice(0, 10);
        let lastDate = event.ends_at?.slice(0, 10) || startDate;
        if (lastDate > startDate && event.ends_at?.endsWith('00:00:00')) lastDate = planner.shiftDate(lastDate, -1);
        const fields = {
            Date: dateText.format(dateValue(date)),
            Time: planner.timeOnDay(event, date),
            Type: kind(event).name,
            Status: status(event),
            'Assigned to': event.assigned_to_name,
            Priority: event.priority && event.priority !== 'medium' ? event.priority : '',
            Location: event.location,
            Reminder: event.reminder_minutes ? event.reminder_minutes + ' minutes before' : '',
            Repeat: event.repeat_rule,
            'Starts on': startDate < date ? shortDate.format(dateValue(startDate)) : '',
            'Continues through': lastDate > date ? shortDate.format(dateValue(lastDate)) : ''
        };
        const detail = Object.entries(fields).filter(([, value]) => value).flatMap(([name, value]) => {
            const label = document.createElement('dt');
            const text = document.createElement('dd');
            label.textContent = name;
            text.textContent = value;
            return [label, text];
        });
        $('calendarEventDetails').replaceChildren(...detail);
        const notes = event.notes || event.description || '';
        $('calendarEventNotes').textContent = notes;
        $('calendarEventNotes').hidden = !notes;
        $('calendarEventSchedule').href = scheduleUrl(date, event.id);
        $('calendarNewNote').href = '/notes/?' + new URLSearchParams({ event: event.id, new: '1' });
        eventDialog.querySelector('[data-event-action="copy"]').textContent = navigator.share ? 'Share event' : 'Copy link';
        const complete = $('calendarEventComplete');
        complete.textContent = event.status === 'done' ? 'Reopen' : 'Complete';
        complete.hidden = Boolean(event.focus_mode && event.status === 'in_progress');
        eventDialog.querySelector('[data-event-action="delete"]').hidden = complete.hidden;
        eventDialog.querySelector('[data-event-action="edit"]').hidden = complete.hidden;
    }

    async function relatedNotes() {
        if (!opened || !eventDialog.open) return;
        const event = opened;
        const request = ++relatedRequest;
        const output = $('calendarNoteLinks');
        const linkForm = $('calendarLinkNoteForm');
        try {
            const { linked, available } = await api('get_event_notes', { event_id: event.id }, 'GET');
            if (relatedRequest !== request || opened !== event || !eventDialog.open) return;
            output.replaceChildren();
            if (!linked.length) {
                const empty = document.createElement('p');
                empty.className = 'calendar-empty';
                empty.textContent = 'No linked notes yet.';
                output.append(empty);
            }
            for (const note of linked) {
                const line = document.createElement('div');
                line.className = 'calendar-linked-note';
                const link = document.createElement('a');
                link.href = noteUrl(note.id);
                link.textContent = note.title;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'planner-control';
                remove.textContent = 'Unlink';
                remove.setAttribute('aria-label', 'Unlink ' + note.title);
                remove.addEventListener('click', () => changeLink(note.id, false));
                line.append(link, remove);
                output.append(line);
            }
            $('calendarNoteChoice').replaceChildren(new Option('Choose a note…', ''),
                ...available.map(note => new Option(note.title, note.id)));
            linkForm.hidden = available.length === 0;
        } catch (error) {
            if (relatedRequest === request && opened === event) output.textContent = error.message;
        }
    }

    function openEvent(id, date) {
        const event = findEvent(id);
        if (!event) return;
        opened = { id: String(id), date };
        detailMessage('');
        $('calendarNoteLinks').textContent = 'Loading linked notes…';
        $('calendarLinkNoteForm').hidden = true;
        renderEvent(event, date);
        if (!eventDialog.open) eventDialog.showModal();
        relatedNotes();
    }

    async function changeLink(note, link) {
        if (!opened || savingDetail) return;
        savingDetail = true;
        try {
            await api(link ? 'link_note' : 'unlink_note', { event_id: opened.id, note_id: note });
            detailMessage(link ? 'Note linked.' : 'Note link removed.');
            await relatedNotes();
        } catch (error) {
            detailMessage(error.message);
        } finally {
            savingDetail = false;
        }
    }

    async function eventAction(action) {
        const event = opened && findEvent(opened.id);
        if (!event || savingDetail) return;
        if (action === 'edit') {
            eventDialog.close();
            editor.open(event);
            return;
        }
        if (action === 'copy') {
            try {
                const url = new URL(eventUrl(event.id), location.origin).href;
                if (navigator.share) {
                    const time = planner.timeOnDay(event, opened.date);
                    const text = [dateText.format(dateValue(opened.date)) + ' · ' + time, event.location, event.notes].filter(Boolean).join('\n');
                    await navigator.share({ title: event.title, text, url });
                    detailMessage('Event shared.');
                } else {
                    await navigator.clipboard.writeText(url);
                    detailMessage('Event link copied.');
                }
            } catch (error) {
                if (error.name !== 'AbortError') detailMessage('Could not share the event.');
            }
            return;
        }
        if (action === 'delete' && !confirm(`Remove “${event.title}” from the family calendar and schedule?`)) return;
        savingDetail = true;
        try {
            if (action === 'delete') {
                await api('delete', { event_id: event.id });
                eventDialog.close();
                if (await reloadCalendar()) message('Event deleted.');
            } else {
                await api('toggle', { event_id: event.id, status: event.status === 'done' ? 'pending' : 'done' });
                await reloadCalendar();
            }
        } catch (error) {
            detailMessage(error.message);
        } finally {
            savingDetail = false;
        }
    }

    document.addEventListener('click', event => {
        const action = event.target.closest('[data-event-action]');
        if (action) eventAction(action.dataset.eventAction);
        const eventButton = event.target.closest('[data-cal-event]');
        const standardClick = event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
        if (eventButton && standardClick) {
            event.preventDefault();
            openEvent(eventButton.dataset.calEvent, eventButton.dataset.calDate);
        }
        const day = event.target.closest('[data-select-date]');
        if (day && standardClick) {
            event.preventDefault();
            loadCalendar(day.dataset.selectDate, 'month', { focus: 'date' });
        }
        if (event.target.closest('[data-calendar-add]')) editor.open();
        const view = event.target.closest('[data-calendar-view], [data-week-date]');
        if (view && standardClick) {
            event.preventDefault();
            loadCalendar(view.dataset.weekDate || state.date, view.dataset.calendarView || 'day', { focus: 'period' });
        }
        if (event.target.closest('[data-calendar-close]')) event.target.closest('dialog').close();
        if (event.target === eventDialog || event.target === dateDialog) event.target.close();
    });
    content.addEventListener('keydown', event => {
        const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        const date = event.target.dataset.selectDate;
        if (event.key === ' ' && event.target.matches('a[role="button"]')) {
            event.preventDefault();
            event.target.click();
        }
        if (date && steps[event.key]) {
            event.preventDefault();
            loadCalendar(planner.shiftDate(date, steps[event.key]), 'month', { focus: 'date' });
        }
    });

    for (const id of ['calendarSearch', 'calendarKind', 'calendarAssignee']) {
        $(id).addEventListener('input', () => {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(queueCalendarRefresh, id === 'calendarSearch' ? 180 : 0);
        });
    }
    $('calendarPrevious').addEventListener('click', event => { event.preventDefault(); shiftPeriod(-1); });
    $('calendarNext').addEventListener('click', event => { event.preventDefault(); shiftPeriod(1); });
    $('calendarToday').addEventListener('click', event => {
        event.preventDefault();
        loadCalendar(planner.today().date, state.view, { focus: 'period' });
    });
    $('calendarPick').addEventListener('click', () => {
        $('calendarDateInput').value = state.date;
        dateDialog.showModal();
    });
    $('calendarDateForm').addEventListener('submit', event => {
        event.preventDefault();
        dateDialog.close();
        loadCalendar($('calendarDateInput').value, state.view, { focus: 'period' });
    });
    $('calendarLinkNoteForm').addEventListener('submit', event => {
        event.preventDefault();
        changeLink($('calendarNoteChoice').value, true);
    });
    eventDialog.addEventListener('close', () => { opened = null; });

    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(location.search);
        clearTimeout(filterTimer);
        $('calendarSearch').value = params.get('q') || '';
        $('calendarKind').value = params.get('kind') || '';
        $('calendarAssignee').value = params.get('assignee') || '';
        if (eventDialog.open) eventDialog.close();
        loadCalendar(params.get('date') || planner.today().date, params.get('view') || 'month', {
            historyMode: 'pop', event: params.get('event'), focus: 'period'
        });
    });
    window.addEventListener('storage', event => {
        if (event.key === planner.eventSignal) queueCalendarRefresh();
        if (event.key === planner.noteSignal && eventDialog.open && opened) relatedNotes();
    });
    window.addEventListener('relatives:event-saved', queueCalendarRefresh);
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            reloadCalendar();
            if (eventDialog.open && opened) relatedNotes();
        }
    });

    paint();
    if (initial.notice) message(initial.notice);
    if (initial.selectedEvent && findEvent(initial.selectedEvent)) {
        openEvent(initial.selectedEvent, state.date);
        if ($('calendarEventFallback')) $('calendarEventFallback').hidden = eventDialog.open;
    }
    else if (prefill) {
        editor.open(null, prefill);
        history.replaceState(null, '', calendarUrl(state.date, state.view));
    }
    content.closest('.calendar-main').dataset.enhanced = 'true';
})();
