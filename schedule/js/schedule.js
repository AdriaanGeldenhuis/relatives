'use strict';

const scheduleElement = id => document.getElementById(id);
const scheduleState = window.ScheduleApp = JSON.parse(scheduleElement('scheduleData').textContent);
const scheduleEditor = scheduleElement('eventForm');
const scheduleFilters = { scheduleSearch: '', scheduleStatus: 'all', scheduleAssignee: 'all' };
const selectedEvents = new Set();
const savingEvents = new Set();
const scheduleZone = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Africa/Johannesburg', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
});
let navigation = null;
let bulkMode = false;
let bulkSaving = false;
let editingEvent = null;
let editorStartDate = '';
let focusSession = null;
let focusTick = null;
let focusPaused = null;
let focusFinishing = false;
let templateSaving = false;
let scheduleToastTimeout;

function scheduleToday() {
    const parts = Object.fromEntries(scheduleZone.formatToParts(new Date()).map(({ type, value }) => [type, value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

function shiftScheduleDate(date, days) {
    const midday = new Date(date + 'T12:00:00Z');
    midday.setUTCDate(midday.getUTCDate() + days);
    return midday.toISOString().slice(0, 10);
}

function scheduleTime(value) {
    return value ? Date.parse(value.replace(' ', 'T') + '+02:00') : NaN;
}

function scheduleEvent(id) {
    return scheduleState.allEvents.find(event => String(event.id) === String(id));
}

async function scheduleApi(action, values = {}, method = 'POST') {
    const data = values instanceof FormData ? values : new URLSearchParams(values);
    data.set('action', action);
    let url = '/schedule/api/events.php';
    const request = { method };
    if (method === 'GET') {
        url += '?' + data;
    } else {
        data.set('csrf_token', scheduleState.csrf);
        request.body = data;
    }
    const response = await fetch(url, request);
    if (response.status === 401) throw new Error('Your session expired. Sign in again before saving.');
    const result = await response.json().catch(() => null);
    if (!result?.success || !response.ok) {
        const error = new Error(result?.message || result?.error || 'Schedule could not reach the server. Try again.');
        error.status = response.status;
        throw error;
    }
    return result;
}

function showToast(text, type = 'info') {
    let toast = scheduleElement('scheduleToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'scheduleToast';
        toast.className = 'schedule-toast';
        toast.setAttribute('role', 'status');
    }
    const activeDialog = [...document.querySelectorAll('dialog[open]')].at(-1);
    (activeDialog || document.body).append(toast);
    toast.dataset.type = type;
    toast.textContent = text;
    toast.hidden = false;
    clearTimeout(scheduleToastTimeout);
    scheduleToastTimeout = setTimeout(() => { toast.hidden = true; }, 5000);
}

function showModal(id) {
    const modal = scheduleElement(id);
    document.querySelectorAll('.planner-tools[open], .planner-event-menu[open]').forEach(menu => { menu.open = false; });
    if (!modal.hasAttribute('aria-labelledby')) {
        const heading = modal.querySelector('h2');
        heading.id = id + 'Heading';
        modal.setAttribute('aria-labelledby', heading.id);
    }
    if (!modal.open) modal.showModal();
}

function closeModal(id) {
    const modal = scheduleElement(id);
    if (modal.dataset.saving !== '1') modal.close();
}

function scheduleUrl(date, view, ajax = false) {
    const params = new URLSearchParams({ date, view });
    if (ajax) params.set('ajax', '1');
    return '/schedule/?' + params;
}

async function loadScheduleData(date = scheduleState.selectedDate, options = {}) {
    const { view = scheduleState.viewMode, historyMode = 'push', focus = false } = options;
    navigation?.controller.abort();
    const controller = new AbortController();
    const request = { date, view, controller };
    navigation = request;
    const panel = scheduleElement('scheduleDatePanel');
    panel.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(scheduleUrl(date, view, true), { signal: controller.signal });
        if (!response.ok || response.redirected) throw new Error('The day could not be loaded.');
        const data = await response.json();
        if (navigation !== request) return false;
        const changed = data.date !== scheduleState.selectedDate || data.view !== scheduleState.viewMode;
        if (changed) {
            scheduleFilters.scheduleSearch = '';
            bulkMode = false;
            selectedEvents.clear();
        }
        scheduleState.selectedDate = data.date;
        scheduleState.viewMode = data.view;
        scheduleState.allEvents = data.events;
        panel.outerHTML = data.html;
        applyScheduleFilters();
        renderBulkSelection();
        if (changed && historyMode === 'push') {
            history.pushState(null, '', scheduleUrl(data.date, data.view));
        }
        if (focus) scheduleElement('scheduleDateHeading').focus({ preventScroll: true });
        return true;
    } catch (error) {
        if (error.name !== 'AbortError') {
            if (historyMode === 'pop') {
                location.assign(scheduleUrl(date, view));
            } else {
                showToast('Could not refresh the agenda. Reload the page to see the latest changes.', 'error');
            }
        }
        return false;
    } finally {
        if (navigation === request) {
            navigation = null;
            scheduleElement('scheduleDatePanel').removeAttribute('aria-busy');
        }
    }
}

function refreshSchedule() {
    return loadScheduleData(navigation?.date || scheduleState.selectedDate, {
        view: navigation?.view || scheduleState.viewMode
    });
}

function changeDate(direction) {
    const date = navigation?.date || scheduleState.selectedDate;
    const view = navigation?.view || scheduleState.viewMode;
    const step = view === 'week' ? 7 : 1;
    return loadScheduleData(shiftScheduleDate(date, direction * step), { view, focus: true });
}

function goToToday() {
    return loadScheduleData(scheduleToday().date, { view: navigation?.view || scheduleState.viewMode, focus: true });
}

function showDatePicker() {
    scheduleElement('datePickerInput').value = scheduleState.selectedDate;
    showModal('datePickerModal');
}

function goToPickedDate(event) {
    event.preventDefault();
    const date = scheduleElement('datePickerInput').value;
    closeModal('datePickerModal');
    loadScheduleData(date, { focus: true });
}

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(location.search);
    loadScheduleData(params.get('date') || scheduleToday().date, {
        view: params.get('view') === 'week' ? 'week' : 'day',
        historyMode: 'pop', focus: true
    });
});

function applyScheduleFilters() {
    const search = scheduleFilters.scheduleSearch.trim().toLocaleLowerCase();
    const terms = search ? search.split(/\s+/) : [];
    const status = scheduleFilters.scheduleStatus;
    const assignee = scheduleFilters.scheduleAssignee;
    for (const [id, value] of Object.entries(scheduleFilters)) {
        if (scheduleElement(id)) scheduleElement(id).value = value;
    }
    const all = new Map(scheduleState.allEvents.map(event => [String(event.id), event]));
    let visible = 0;
    document.querySelectorAll('.planner-event').forEach(row => {
        const event = all.get(row.dataset.eventId);
        const text = [event.title, event.notes || event.description, event.assigned_to_name, scheduleState.types[event.kind]?.name]
            .join(' ').toLocaleLowerCase();
        const matchesStatus = status === 'all' || ((status === 'done') === (event.status === 'done'));
        const matchesAssignee = assignee === 'all' || (assignee === 'unassigned' ? !event.assigned_to : String(event.assigned_to) === assignee);
        row.hidden = !matchesStatus || !matchesAssignee || !terms.every(term => text.includes(term));
        if (row.hidden) selectedEvents.delete(row.dataset.eventId);
        else visible++;
    });
    if (scheduleElement('scheduleNoResults')) scheduleElement('scheduleNoResults').hidden = visible !== 0;
}

function renderBulkSelection() {
    const bar = scheduleElement('bulkActionsBar');
    if (!bar) return;
    bar.hidden = !bulkMode;
    scheduleElement('bulkModeBtn')?.setAttribute('aria-pressed', String(bulkMode));
    scheduleElement('bulkSelectedCount').textContent = selectedEvents.size;
    document.querySelectorAll('.planner-event').forEach(row => {
        row.querySelector('.planner-select').hidden = !bulkMode;
        row.querySelector('.planner-complete').hidden = bulkMode;
        row.querySelector('.bulk-checkbox').checked = selectedEvents.has(row.dataset.eventId);
    });
    bar.querySelectorAll('[data-bulk-action]').forEach(button => { button.disabled = !selectedEvents.size || bulkSaving; });
}

function toggleBulkMode() {
    if (bulkSaving || !scheduleElement('bulkModeBtn')) return;
    bulkMode = !bulkMode;
    selectedEvents.clear();
    renderBulkSelection();
}

function selectVisibleEvents() {
    document.querySelectorAll('.planner-event:not([hidden])').forEach(row => selectedEvents.add(row.dataset.eventId));
    renderBulkSelection();
}

async function bulkAction(action, extra = {}, dialog = null) {
    if (!selectedEvents.size || bulkSaving) return;
    bulkSaving = true;
    renderBulkSelection();
    try {
        const result = await scheduleApi(action, { event_ids: JSON.stringify([...selectedEvents]), ...extra });
        if (dialog) closeModal(dialog);
        bulkMode = false;
        selectedEvents.clear();
        const refreshed = await refreshSchedule();
        if (refreshed) showToast(`${result.count} ${result.count === 1 ? 'event' : 'events'} updated.`, 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        bulkSaving = false;
        renderBulkSelection();
    }
}

function bulkMarkDone() { bulkAction('bulk_mark_done'); }
function showBulkTypeModal() { showModal('bulkTypeModal'); }
function showBulkAssignModal() { showModal('bulkAssignModal'); }
function applyBulkType(kind) { bulkAction('bulk_change_type', { kind }, 'bulkTypeModal'); }
function applyBulkAssign(assign_to) { bulkAction('bulk_assign', { assign_to }, 'bulkAssignModal'); }
function bulkDelete() {
    if (confirm(`Delete ${selectedEvents.size} selected events from the family schedule?`)) bulkAction('bulk_delete');
}

async function toggleEvent(id, checkbox) {
    const event = scheduleEvent(id);
    const previous = event?.status === 'done';
    if (savingEvents.has(String(id))) {
        checkbox.checked = previous;
        return;
    }
    checkbox.disabled = true;
    savingEvents.add(String(id));
    try {
        await scheduleApi('toggle', { event_id: id, status: checkbox.checked ? 'done' : 'pending' });
        const hadFocus = document.activeElement === checkbox || document.activeElement === document.body;
        if (await refreshSchedule() && hadFocus) {
            document.querySelector(`.planner-event[data-event-id="${id}"]:not([hidden]) .planner-complete input`)?.focus();
        }
    } catch (error) {
        checkbox.checked = previous;
        showToast(error.message, 'error');
    } finally {
        checkbox.disabled = false;
        savingEvents.delete(String(id));
    }
}

async function deleteEvent(id) {
    const event = scheduleEvent(id);
    if (!event || savingEvents.has(String(id)) || !confirm(`Delete “${event.title}” from the family schedule?`)) return;
    savingEvents.add(String(id));
    try {
        await scheduleApi('delete', { event_id: id });
        if (await refreshSchedule()) showToast('Event deleted.', 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        savingEvents.delete(String(id));
    }
}

async function clearDone() {
    const count = scheduleState.allEvents.filter(event => event.status === 'done').length;
    if (!count || bulkSaving || !confirm(`Remove ${count} completed ${count === 1 ? 'event' : 'events'} from the family schedule?`)) return;
    bulkSaving = true;
    try {
        const result = await scheduleApi('clear_done', { date: scheduleState.selectedDate });
        if (await refreshSchedule()) showToast(`${result.count} completed ${result.count === 1 ? 'event cleared' : 'events cleared'}.`, 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        bulkSaving = false;
    }
}

function showQuickAdd() { openEventEditor(); }
function editEvent(id) { openEventEditor(scheduleEvent(id)); }
function duplicateEvent(id) {
    const original = scheduleEvent(id);
    if (original) openEventEditor(original, true);
}

function selectEditorValue(id, value, fallback) {
    const input = scheduleElement(id);
    const missing = input.querySelector('[data-previous-value]');
    missing?.remove();
    const desired = String(value ?? fallback);
    if (desired && ![...input.options].some(option => option.value === desired)) {
        const option = new Option(fallback, desired);
        option.dataset.previousValue = '';
        input.add(option);
    }
    input.value = desired;
}

function openEventEditor(event = null, duplicate = false) {
    scheduleEditor.reset();
    editingEvent = duplicate ? null : event;
    const { date, time } = scheduleToday();
    const dateField = scheduleElement('eventDate');
    const startField = scheduleElement('eventStart');
    const endField = scheduleElement('eventEnd');
    const endDateField = scheduleElement('eventEndDate');
    const color = scheduleState.types[event?.kind]?.color || scheduleState.types.event.color;
    const startDate = event?.starts_at.slice(0, 10) || scheduleState.selectedDate;
    const startTime = event?.starts_at.slice(11, 16) || (date === startDate ? time : '09:00');
    const startHour = Number(startTime.slice(0, 2));
    let endDay = event?.ends_at?.slice(0, 10) || startDate;
    if (!event && startHour === 23) endDay = shiftScheduleDate(startDate, 1);
    if (event?.all_day && event.ends_at?.slice(11, 19) === '00:00:00' && endDay > startDate) {
        endDay = shiftScheduleDate(endDay, -1);
    }

    scheduleElement('eventEditorHeading').textContent = duplicate ? 'Duplicate event' : event ? 'Edit event' : 'Add event';
    scheduleElement('eventTitle').value = duplicate ? Array.from(event.title).slice(0, 193).join('') + ' (copy)' : event?.title || '';
    dateField.value = startDate;
    editorStartDate = startDate;
    endDateField.value = endDay;
    startField.value = startTime;
    endField.value = event ? (event.ends_at || event.starts_at).slice(11, 16) :
        String((startHour + 1) % 24).padStart(2, '0') + startTime.slice(2);
    scheduleElement('eventAllDay').checked = Boolean(event?.all_day);
    scheduleElement('eventFocus').checked = Boolean(event?.focus_mode);
    scheduleElement('eventPriority').value = event?.priority || 'medium';
    scheduleElement('eventNotes').value = event?.notes || '';
    selectEditorValue('eventType', event?.kind || 'event', 'Other');
    selectEditorValue('eventAssign', event?.assigned_to || '', event?.assigned_to_name || 'Previous assignee');
    selectEditorValue('eventRepeat', duplicate ? '' : event?.repeat_rule || '', 'Existing repeat interval');
    scheduleElement('eventReminder').value = event?.reminder_minutes || '';
    scheduleElement('eventColor').value = /^#[\da-f]{6}$/i.test(event?.color) ? event.color : color;
    scheduleElement('eventRepeat').disabled = Boolean(editingEvent?.recurrence_parent_id);
    const hint = scheduleElement('eventRepeatHint');
    hint.textContent = editingEvent?.recurrence_parent_id
        ? 'Changes affect only this occurrence. Edit the original event to change the repeat.'
        : 'Changes to the original event also update its future pending occurrences.';
    hint.hidden = !(editingEvent?.recurrence_parent_id || editingEvent?.repeat_rule);
    scheduleElement('eventOptions').open = Boolean(event?.repeat_rule || event?.focus_mode || event?.reminder_minutes);
    setEventEditorError('');
    syncEventTimeFields();
    showModal('eventEditor');
}

function syncEventTimeFields() {
    const allDay = scheduleElement('eventAllDay').checked;
    scheduleElement('eventTimeFields').hidden = allDay;
    for (const id of ['eventStart', 'eventEnd']) scheduleElement(id).required = !allDay;
    scheduleElement('eventFocus').disabled = allDay;
}

function setEventEditorError(message) {
    const error = scheduleElement('eventFormError');
    error.textContent = message;
    error.hidden = !message;
    if (message) error.scrollIntoView({ block: 'nearest' });
}

scheduleElement('eventDate').addEventListener('change', event => {
    const end = scheduleElement('eventEndDate');
    if (end.value === editorStartDate || end.value < event.target.value) end.value = event.target.value;
    editorStartDate = event.target.value;
});
scheduleElement('eventAllDay').addEventListener('change', syncEventTimeFields);
scheduleEditor.addEventListener('submit', async event => {
    event.preventDefault();
    const dialog = scheduleElement('eventEditor');
    if (dialog.dataset.saving === '1') return;
    const data = new FormData(scheduleEditor);
    const isEdit = Boolean(editingEvent);
    const submit = event.submitter || scheduleEditor.querySelector('[type="submit"]');
    if (isEdit) {
        data.set('event_id', editingEvent.id);
        data.set('version', editingEvent.version);
    }
    dialog.dataset.saving = '1';
    submit.disabled = true;
    setEventEditorError('');
    try {
        const result = await scheduleApi(isEdit ? 'update' : 'add', data);
        dialog.close();
        const savedDate = result.event.starts_at.slice(0, 10);
        const selected = scheduleState.selectedDate;
        const overlapsCurrent = result.event.starts_at < shiftScheduleDate(selected, 1)
            && (savedDate === selected || (result.event.ends_at || '') > selected + ' 00:00:00');
        const loaded = overlapsCurrent ? await refreshSchedule()
            : await loadScheduleData(savedDate, { view: scheduleState.viewMode, focus: true });
        if (loaded) showToast(isEdit ? 'Changes saved.' : 'Event added.', 'success');
    } catch (error) {
        setEventEditorError(error.message);
        if (error.status === 409) await refreshSchedule();
    } finally {
        dialog.dataset.saving = '';
        submit.disabled = false;
    }
});

function startFocusMode() {
    if (focusSession) {
        if (focusRemaining() > 0) showModal('focusTimerModal');
        else finishFocus();
    } else {
        showModal('focusSessionModal');
    }
}

async function createFocusSession(event) {
    event.preventDefault();
    if (event.submitter.disabled) return;
    event.submitter.disabled = true;
    try {
        const result = await scheduleApi('add', {
            title: scheduleElement('focusTitle').value,
            kind: 'focus',
            focus_duration: scheduleElement('focusDuration').value
        });
        event.target.reset();
        closeModal('focusSessionModal');
        runFocus(result.event, true);
        await refreshSchedule();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        event.submitter.disabled = false;
    }
}

async function startFocusSession(id) {
    if (savingEvents.has(String(id))) return;
    savingEvents.add(String(id));
    try {
        const result = await scheduleApi('start_focus', { event_id: id });
        runFocus(result.event, true);
        await refreshSchedule();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        savingEvents.delete(String(id));
    }
}

function focusRemaining() {
    return focusPaused ?? Math.max(0, focusSession.deadline - Date.now());
}

function updateFocusTimer() {
    const seconds = Math.ceil(focusRemaining() / 1000);
    scheduleElement('focusTimer').value = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    if (!seconds) {
        clearInterval(focusTick);
        if (scheduleElement('focusTimerModal').open) finishFocus();
    }
}

function runFocus(event, open) {
    const duration = scheduleTime(event.ends_at) - scheduleTime(event.starts_at);
    focusSession = { id: event.id, deadline: (scheduleTime(event.actual_start) || Date.now()) + Math.max(0, duration) };
    focusPaused = null;
    scheduleElement('focusPauseButton').textContent = 'Pause';
    scheduleElement('focusTask').textContent = event.title;
    scheduleElement('scheduleFocusButton').textContent = 'Resume focus';
    clearInterval(focusTick);
    updateFocusTimer();
    if (focusRemaining() > 0) focusTick = setInterval(updateFocusTimer, 500);
    if (open) startFocusMode();
}

function toggleFocusPause() {
    if (focusPaused === null) {
        focusPaused = focusRemaining();
        scheduleElement('focusPauseButton').textContent = 'Resume';
    } else {
        focusSession.deadline = Date.now() + focusPaused;
        focusPaused = null;
        scheduleElement('focusPauseButton').textContent = 'Pause';
    }
    updateFocusTimer();
}

function finishFocus() {
    closeModal('focusTimerModal');
    showModal('ratingModal');
}

async function selectRating(rating) {
    if (!focusSession || focusFinishing) return;
    focusFinishing = true;
    try {
        await scheduleApi('end_focus', { event_id: focusSession.id, rating });
        clearInterval(focusTick);
        focusSession = null;
        focusPaused = null;
        scheduleElement('scheduleFocusButton').textContent = 'Start focus';
        closeModal('ratingModal');
        if (await refreshSchedule()) showToast('Focus session completed.', 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        focusFinishing = false;
    }
}

function formatScheduleDuration(minutes) {
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return hours ? hours + 'h' + (remainder ? ' ' + remainder + 'm' : '') : remainder + 'm';
}

async function showAnalytics() {
    showModal('analyticsModal');
    const output = scheduleElement('analyticsContent');
    output.textContent = 'Loading analytics…';
    const endDate = scheduleState.selectedDate;
    try {
        const { productivity } = await scheduleApi('get_productivity', {
            start_date: shiftScheduleDate(endDate, -29), end_date: endDate
        }, 'GET');
        const totals = Object.fromEntries(['study_minutes', 'work_minutes', 'focus_minutes', 'completed_tasks', 'total_tasks']
            .map(key => [key, productivity.reduce((sum, row) => sum + Number(row[key]), 0)]));
        if (!totals.total_tasks) {
            output.textContent = 'No scheduled events in this 30-day period.';
            return;
        }
        const percent = Math.round(totals.completed_tasks / totals.total_tasks * 100);
        const days = new Map(productivity.map(row => [row.date, row]));
        const activity = Array.from({ length: 30 }, (_, index) => {
            const date = shiftScheduleDate(endDate, index - 29);
            const row = days.get(date);
            const completed = Number(row?.completed_tasks || 0);
            const count = Number(row?.total_tasks || 0);
            return { date, completed, count };
        });
        const maxEvents = Math.max(1, ...activity.map(day => day.count));
        output.innerHTML = `<p class="planner-form-hint">Your events over the 30 days ending ${endDate}</p>
            <dl class="planner-analytics">
                <div><dt>Study</dt><dd>${formatScheduleDuration(totals.study_minutes)}</dd></div>
                <div><dt>Work</dt><dd>${formatScheduleDuration(totals.work_minutes)}</dd></div>
                <div><dt>Focus</dt><dd>${formatScheduleDuration(totals.focus_minutes)}</dd></div>
                <div><dt>Completion</dt><dd>${percent}%</dd></div>
            </dl>
            <p>${totals.completed_tasks} of ${totals.total_tasks} ${totals.total_tasks === 1 ? 'event' : 'events'} complete.</p>
            <progress value="${percent}" max="100" aria-label="Events complete"></progress>
            <h3 class="planner-activity-heading">Daily completion</h3>
            <div class="planner-activity" aria-hidden="true">${activity.map(({ date, count, completed }) =>
                `<span title="${date}: ${completed} of ${count} complete" style="--planned: ${100 * count / maxEvents}%; --completed: ${100 * completed / maxEvents}%"></span>`
            ).join('')}</div>
            <details class="planner-form-more planner-activity-details">
                <summary>Daily totals</summary>
                <ul>${activity.filter(day => day.count).map(({ date, count, completed }) =>
                    `<li><time datetime="${date}">${date}</time><span>${completed} of ${count} complete</span></li>`
                ).join('')}</ul>
            </details>`;
    } catch (error) {
        output.textContent = error.message;
    }
}

const scheduleTemplates = {
    'Productive morning': [
        ['Morning study session', '08:00', '10:00', 'study'], ['Coffee break', '10:00', '10:15', 'break'],
        ['Work block', '10:15', '12:00', 'work'], ['Lunch break', '12:00', '13:00', 'break']
    ],
    'Deep work day': [
        ['Deep work session', '09:00', '11:00', 'focus'], ['Break', '11:00', '11:15', 'break'],
        ['Deep work session', '11:15', '13:00', 'focus'], ['Lunch', '13:00', '14:00', 'break'],
        ['Deep work session', '14:00', '16:00', 'focus']
    ],
    'Study intensive': [
        ['Study block', '08:00', '10:00', 'study'], ['Break', '10:00', '10:15', 'break'],
        ['Study block', '10:15', '12:15', 'study'], ['Lunch', '12:15', '13:00', 'break'],
        ['Study block', '13:00', '15:00', 'study'], ['Break', '15:00', '15:15', 'break'],
        ['Study block', '15:15', '17:00', 'study']
    ],
    'Balanced day': [
        ['Morning work', '09:00', '11:00', 'work'], ['Break', '11:00', '11:15', 'break'],
        ['Study session', '11:15', '13:00', 'study'], ['Lunch', '13:00', '14:00', 'break'],
        ['Afternoon work', '14:00', '16:00', 'work'], ['Break', '16:00', '16:15', 'break'],
        ['Personal time', '16:15', '17:00', 'todo']
    ]
};

function showTemplates() {
    const content = scheduleElement('templatesContent');
    content.replaceChildren(...Object.entries(scheduleTemplates).map(([name, slots]) => {
        const button = document.createElement('button');
        button.className = 'planner-control';
        button.textContent = `${name} · ${slots.length} events`;
        button.addEventListener('click', () => applyTemplate(name, slots));
        return button;
    }));
    showModal('templatesModal');
}

async function applyTemplate(name, slots) {
    const date = scheduleState.selectedDate;
    if (templateSaving || !confirm(`Add ${slots.length} events from “${name}” on ${date}?`)) return;
    templateSaving = true;
    closeModal('templatesModal');
    let count = 0;
    try {
        for (const [title, start_time, end_time, kind] of slots) {
            await scheduleApi('add', { title, date, start_time, end_time, kind, focus_mode: kind === 'focus' ? 1 : 0 });
            count++;
        }
        if (await refreshSchedule()) showToast(`Added ${count} events from the template.`, 'success');
    } catch (error) {
        await refreshSchedule();
        showToast(`Added ${count} of ${slots.length} events. ${error.message}`, 'error');
    } finally {
        templateSaving = false;
    }
}

function exportSchedule() { showModal('exportModal'); }

function getScheduleData() {
    const dayStart = scheduleTime(scheduleState.selectedDate + ' 00:00:00');
    const dayEnd = dayStart + 24 * 60 * 60 * 1000;
    return scheduleState.allEvents.map(event => {
        const start = event.starts_at.slice(0, 10) === scheduleState.selectedDate ? event.starts_at.slice(11, 16) : '00:00';
        const end = event.ends_at?.slice(0, 10) > scheduleState.selectedDate ? '24:00' : event.ends_at?.slice(11, 16);
        const time = event.all_day ? 'All day' : end && end !== start ? start + '–' + end : start;
        const minutes = Math.floor((Math.min(dayEnd, scheduleTime(event.ends_at))
            - Math.max(dayStart, scheduleTime(event.starts_at))) / 60000);
        return {
            name: event.title, time,
            duration: !event.all_day && minutes > 0 ? formatScheduleDuration(minutes) : '',
            type: scheduleState.types[event.kind]?.name || 'Other',
            status: event.status === 'done' ? 'Done' : event.status === 'in_progress' ? 'In progress' : 'Pending',
            notes: event.notes || event.description || ''
        };
    });
}

function schedulePlainText() {
    const lines = getScheduleData().map(row => `${row.status === 'Done' ? '[x]' : '[ ]'} ${row.time} · ${row.name}
${[row.type, row.status, row.duration].filter(Boolean).join(' · ')}${row.notes ? '\n' + row.notes : ''}`);
    return `Schedule for ${scheduleState.selectedDate}\n\n${lines.join('\n\n') || 'No events scheduled.'}`;
}

function exportAsPDF() {
    closeModal('exportModal');
    const print = window.open('', '_blank');
    if (!print) {
        showToast('Allow pop-ups to print your schedule.', 'error');
        return;
    }
    print.document.title = 'Schedule · ' + scheduleState.selectedDate;
    const content = print.document.createElement('pre');
    content.style.cssText = 'white-space: pre-wrap; font: 14px/1.55 system-ui; margin: 24px';
    content.textContent = schedulePlainText();
    print.document.body.append(content);
    print.print();
}

function downloadSchedule(content, extension, mime) {
    closeModal('exportModal');
    const url = URL.createObjectURL(new Blob([content], { type: mime + ';charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `schedule-${scheduleState.selectedDate}.${extension}`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function exportAsCSV() {
    const escape = value => {
        const text = /^[\s]*[=+@-]/.test(value) ? "'" + value : value;
        return '"' + text.replaceAll('"', '""') + '"';
    };
    const rows = getScheduleData().map(row => Object.values(row).map(escape).join(','));
    downloadSchedule(['Name,Time,Duration,Type,Status,Notes', ...rows].join('\r\n'), 'csv', 'text/csv');
}

function exportAsText() { downloadSchedule(schedulePlainText(), 'txt', 'text/plain'); }

function shareToWhatsApp() {
    closeModal('exportModal');
    window.open('https://wa.me/?text=' + encodeURIComponent(schedulePlainText()), '_blank', 'noopener');
}

document.addEventListener('click', event => {
    const link = event.target.closest('[data-schedule-view]');
    if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
        event.preventDefault();
        const date = new URL(link.href).searchParams.get('date');
        loadScheduleData(date, { view: link.dataset.scheduleView, focus: true });
    }
    if (event.target.matches('dialog.planner-modal')) closeModal(event.target.id);
    document.querySelectorAll('.planner-tools[open], .planner-event-menu[open]').forEach(menu => {
        if (!menu.contains(event.target)) menu.open = false;
    });
});
document.addEventListener('cancel', event => {
    if (event.target.dataset.saving === '1') event.preventDefault();
}, true);
document.addEventListener('input', event => {
    if (Object.hasOwn(scheduleFilters, event.target.id)) {
        scheduleFilters[event.target.id] = event.target.value;
        applyScheduleFilters();
        renderBulkSelection();
    }
});
document.addEventListener('change', event => {
    if (event.target.classList.contains('bulk-checkbox')) {
        if (event.target.checked) selectedEvents.add(event.target.value);
        else selectedEvents.delete(event.target.value);
        renderBulkSelection();
    }
});
document.addEventListener('keydown', event => {
    if (event.defaultPrevented || document.querySelector('dialog[open]') || event.target.closest('input, textarea, select, [contenteditable]')) return;
    if (event.key === '/' && scheduleElement('scheduleSearch') && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        scheduleElement('scheduleSearch').focus();
    }
    if (event.key === 'Escape' && bulkMode) toggleBulkMode();
});
if (scheduleState.activeFocusSession) runFocus(scheduleState.activeFocusSession, false);
const scheduleInitialUrl = new URL(location.href);
const scheduleInitialEvent = scheduleInitialUrl.searchParams.get('event');
if (scheduleInitialEvent && scheduleState.viewMode === 'day') {
    const event = scheduleEvent(scheduleInitialEvent);
    if (event) openEventEditor(event);
    scheduleInitialUrl.searchParams.delete('event');
    history.replaceState(null, '', scheduleInitialUrl);
}
