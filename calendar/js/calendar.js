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
    const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    let navigation = null;
    let refreshQueued = false;
    let opened = null;
    let relatedRequest = 0;
    let savingDetail = false;
    let noticeTimer;

    const dateValue = date => new Date(date + 'T12:00:00Z');
    const findEvent = id => state.events.find(event => String(event.id) === String(id));
    const kind = event => types[event.kind] || types.other;
    const color = event => /^#[\da-f]{6}$/i.test(event.color) ? event.color : kind(event).color;
    const status = event => ({ done: 'Completed', in_progress: 'In progress' })[event.status] || 'Open';
    const eventUrl = id => '/calendar/?event=' + encodeURIComponent(id);
    const scheduleUrl = (date, id) => '/schedule/?' + new URLSearchParams(id ? { date, event: id } : { date });
    const noteUrl = id => '/notes/?note=' + encodeURIComponent(id);

    function escape(value) {
        return String(value).replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[character]);
    }

    function calendarUrl(date, view, ajax = false, event = null) {
        const params = new URLSearchParams({ date, view });
        if (ajax) params.set('ajax', '1');
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

    function filterEvents() {
        const query = $('calendarSearch').value.trim().toLocaleLowerCase();
        const kindValue = $('calendarKind').value;
        const assignee = $('calendarAssignee').value;
        const terms = query ? query.split(/\s+/) : [];
        return state.events.filter(event => {
            const text = [event.title, event.notes || event.description, event.location, event.assigned_to_name, kind(event).name]
                .join(' ').toLocaleLowerCase();
            return (!kindValue || event.kind === kindValue)
                && (!assignee || (assignee === 'unassigned' ? !event.assigned_to : String(event.assigned_to) === assignee))
                && terms.every(term => text.includes(term));
        });
    }

    function eventButton(event, date, compact = false) {
        const details = `data-cal-event="${escape(event.id)}" data-cal-date="${date}"`;
        const time = planner.timeOnDay(event, date);
        if (compact) return `<button type="button" class="calendar-mini-event${event.status === 'done' ? ' is-done' : ''}" ${details} style="--event-color: ${color(event)}">
            <span class="calendar-mini-time">${time.split('–', 1)[0]}</span><span>${escape(event.title)}</span>
        </button>`;
        const meta = [kind(event).name, event.assigned_to_name, event.location,
            event.status === 'pending' ? '' : status(event)].filter(Boolean).map(escape).join(' · ');
        return `<button type="button" class="calendar-agenda-event" ${details} style="--event-color: ${color(event)}">
            <strong>${escape(event.title)}</strong><span>${time} · ${meta}</span>
        </button>`;
    }

    function renderMonth(events, today) {
        const date = state.date;
        const weekdaysHtml = weekdays.map(day => `<span>${day}</span>`).join('');
        const cells = [];
        for (let day = state.first; day < state.end; day = planner.shiftDate(day, 1)) {
            const list = events.filter(event => planner.onDay(event, day));
            const selected = day === date;
            const eventLabel = `${list.length} ${list.length === 1 ? 'event' : 'events'}`;
            const label = escape(dateText.format(dateValue(day)) + ', ' + eventLabel);
            cells.push(`<div class="calendar-cell${selected ? ' is-selected' : ''}${day.slice(0, 7) !== date.slice(0, 7) ? ' is-outside' : ''}">
                <button type="button" class="calendar-cell-date${day === today ? ' is-today' : ''}" data-select-date="${day}" aria-label="${label}" aria-pressed="${selected}"${
                    day === today ? ' aria-current="date"' : ''
                }>${Number(day.slice(-2))}<span class="calendar-day-count" aria-hidden="true">${list.length || ''}</span></button>
                <div class="calendar-cell-events">${list.slice(0, 3).map(event => eventButton(event, day, true)).join('')}
                    ${list.length > 3 ? `<button type="button" class="calendar-more" data-select-date="${day}">+${list.length - 3} more</button>` : ''}
                </div>
                <span class="calendar-dots" aria-hidden="true">${list.slice(0, 3).map(event => `<i style="background: ${color(event)}"></i>`).join('')}</span>
            </div>`);
        }
        return `<section class="calendar-month" aria-label="Month grid"><div class="calendar-weekdays">${weekdaysHtml}</div>
            <div class="calendar-grid">${cells.join('')}</div></section>`;
    }

    function renderSelectedDay(events) {
        const date = state.date;
        const daily = events.filter(event => planner.onDay(event, date));
        const until = planner.shiftDate(date, 7);
        const upcoming = events.filter(event => {
            const start = event.starts_at.slice(0, 10);
            return start > date && start <= until && event.status !== 'done';
        }).sort((a, b) => a.starts_at.localeCompare(b.starts_at)).slice(0, 5);
        const filtered = filtersActive();
        return `<aside class="calendar-side" aria-label="Day agenda">
            <section class="calendar-day-panel">
                <header>
                    <h3>${escape(dateText.format(dateValue(date)))}</h3>
                    <button type="button" class="planner-control" data-calendar-add>Add event</button>
                </header>
                <div class="calendar-day-list">${daily.length ? daily.map(event => eventButton(event, date)).join('')
                    : `<p class="calendar-empty">${filtered ? 'No matching events for this day.' : 'Nothing scheduled for this day.'}</p>`}</div>
            </section>
            ${upcoming.length ? `<section class="calendar-day-panel">
                <header><h3>Next 7 days</h3></header>
                <div class="calendar-day-list">${upcoming.map(event => {
                    const day = event.starts_at.slice(0, 10);
                    return `<div class="calendar-upcoming"><span>${escape(shortDate.format(dateValue(day)))}</span>${eventButton(event, day)}</div>`;
                }).join('')}</div>
            </section>` : ''}
        </aside>`;
    }

    function filtersActive() {
        return Boolean($('calendarSearch').value.trim() || $('calendarKind').value || $('calendarAssignee').value);
    }

    function renderWeek(events, today) {
        const days = [];
        for (let date = state.first; date < state.end; date = planner.shiftDate(date, 1)) {
            const list = events.filter(event => planner.onDay(event, date));
            days.push(`<section class="calendar-week-day${today === date ? ' is-today' : ''}">
                <h3><a href="${calendarUrl(date, 'day')}" data-week-date="${date}">${escape(dateText.format(dateValue(date)))}</a></h3>
                <div class="calendar-day-list">${list.length ? list.map(event => eventButton(event, date)).join('')
                    : '<p class="calendar-empty">No events</p>'}</div>
            </section>`);
        }
        return `<div class="calendar-week">${days.join('')}</div>`;
    }

    function paint() {
        const today = planner.today().date;
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

        const events = filterEvents();
        if (state.view === 'month') {
            content.innerHTML = `<div class="calendar-layout">${renderMonth(events, today)}${renderSelectedDay(events)}</div>`;
        } else if (state.view === 'week') {
            content.innerHTML = renderWeek(events, today);
        } else {
            content.innerHTML = `<div class="calendar-single-day">${renderSelectedDay(events)}</div>`;
        }
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
            Object.assign(state, next);
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
        if (eventButton) openEvent(eventButton.dataset.calEvent, eventButton.dataset.calDate);
        const day = event.target.closest('[data-select-date]');
        if (day) loadCalendar(day.dataset.selectDate, 'month', { focus: 'date' });
        if (event.target.closest('[data-calendar-add]')) editor.open();
        const view = event.target.closest('[data-calendar-view], [data-week-date]');
        if (view && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            loadCalendar(view.dataset.weekDate || state.date, view.dataset.calendarView || 'day', { focus: 'period' });
        }
        if (event.target.closest('[data-calendar-close]')) event.target.closest('dialog').close();
        if (event.target === eventDialog || event.target === dateDialog) event.target.close();
    });
    content.addEventListener('keydown', event => {
        const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        const date = event.target.dataset.selectDate;
        if (date && steps[event.key]) {
            event.preventDefault();
            loadCalendar(planner.shiftDate(date, steps[event.key]), 'month', { focus: 'date' });
        }
    });

    for (const id of ['calendarSearch', 'calendarKind', 'calendarAssignee']) {
        $(id).addEventListener('input', paint);
    }
    $('calendarPrevious').addEventListener('click', () => shiftPeriod(-1));
    $('calendarNext').addEventListener('click', () => shiftPeriod(1));
    $('calendarToday').addEventListener('click', () => loadCalendar(planner.today().date, state.view, { focus: 'period' }));
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
        if (eventDialog.open) eventDialog.close();
        loadCalendar(params.get('date') || planner.today().date, params.get('view') || 'month', {
            historyMode: 'pop', event: params.get('event'), focus: 'period'
        });
    });
    window.addEventListener('storage', event => {
        if (event.key === planner.eventSignal) {
            if (navigation) refreshQueued = true;
            else reloadCalendar();
        }
        if (event.key === planner.noteSignal && eventDialog.open && opened) relatedNotes();
    });
    window.addEventListener('relatives:event-saved', reloadCalendar);
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            reloadCalendar();
            if (eventDialog.open && opened) relatedNotes();
        }
    });

    paint();
    if (initial.notice) message(initial.notice);
    if (initial.selectedEvent) openEvent(initial.selectedEvent, state.date);
    else if (prefill) {
        editor.open(null, prefill);
        history.replaceState(null, '', calendarUrl(state.date, state.view));
    }
})();
