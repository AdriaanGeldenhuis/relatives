'use strict';

const scheduleElement = id => document.getElementById(id);
const scheduleState = JSON.parse(scheduleElement('scheduleData').textContent);
const planner = window.RelativesPlanner;
const scheduleFilters = { scheduleSearch: '', scheduleStatus: 'all', scheduleAssignee: 'all' };
const selectedEvents = new Set();
const savingEvents = new Set();
let navigation = null;
let refreshQueued = false;
let bulkMode = false;
let bulkSaving = false;
let focusSession = null;
let focusTick = null;
let focusPaused = null;
let focusFinishing = false;
let focusRevision = 0;
let templateSaving = false;
let scheduleToastTimeout;

function scheduleTime(value) {
    return value ? Date.parse(value.replace(' ', 'T') + '+02:00') : NaN;
}

function scheduleEvent(id) {
    return scheduleState.allEvents.find(event => String(event.id) === String(id));
}

function scheduleApi(action, values = {}, method = 'POST') {
    return planner.request(scheduleState.csrf, action, values, method);
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

function scheduleUrl(date, view, ajax = false, event = null) {
    const params = new URLSearchParams({ date, view });
    if (ajax) params.set('ajax', '1');
    if (event) params.set('event', event);
    return '/schedule/?' + params;
}

async function loadScheduleData(date = scheduleState.selectedDate, options = {}) {
    const { view = scheduleState.viewMode, historyMode = 'push', focus = false, event = null } = options;
    navigation?.controller.abort();
    const controller = new AbortController();
    const request = { date, view, event, controller, focusRevision };
    navigation = request;
    const panel = scheduleElement('scheduleDatePanel');
    panel.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(scheduleUrl(date, view, true, event), { signal: controller.signal });
        if (response.status === 401) throw new Error('Sign in again to use Schedule.');
        if (!response.ok || response.redirected) throw new Error('The day could not be loaded.');
        const data = await response.json();
        if (navigation !== request) return false;
        const template = document.createElement('template');
        template.innerHTML = data.html;
        const nextPanel = template.content.firstElementChild;
        if (nextPanel?.id !== 'scheduleDatePanel' || !Array.isArray(data.events)) {
            throw new Error('The day could not be loaded.');
        }
        const changed = data.date !== scheduleState.selectedDate || data.view !== scheduleState.viewMode;
        if (changed) {
            scheduleFilters.scheduleSearch = '';
            bulkMode = false;
            selectedEvents.clear();
        }
        scheduleState.selectedDate = data.date;
        scheduleState.viewMode = data.view;
        scheduleState.allEvents = data.events;
        panel.replaceWith(nextPanel);
        applyScheduleFilters();
        renderBulkSelection();
        if (historyMode === 'push' && (changed || data.selectedEvent)) {
            history.pushState(null, '', scheduleUrl(data.date, data.view, false, data.selectedEvent));
        }
        if (request.focusRevision === focusRevision) syncFocus(data.activeFocusSession);
        if (focus) scheduleElement('scheduleDateHeading').focus({ preventScroll: true });
        if (data.selectedEvent) editEvent(data.selectedEvent);
        if (data.notice) showToast(data.notice, 'error');
        return true;
    } catch (error) {
        if (error.name !== 'AbortError') {
            if (historyMode === 'pop') {
                location.assign(scheduleUrl(date, view, false, event));
            } else {
                showToast(error.message || 'Could not refresh the agenda.', 'error');
            }
        }
        return false;
    } finally {
        if (navigation === request) {
            navigation = null;
            scheduleElement('scheduleDatePanel')?.removeAttribute('aria-busy');
            if (refreshQueued) {
                refreshQueued = false;
                refreshSchedule();
            }
        }
    }
}

function refreshSchedule() {
    return loadScheduleData(navigation?.date || scheduleState.selectedDate, {
        view: navigation?.view || scheduleState.viewMode, event: navigation?.event
    });
}

function changeDate(direction) {
    const date = navigation?.date || scheduleState.selectedDate;
    const view = navigation?.view || scheduleState.viewMode;
    const step = view === 'week' ? 7 : 1;
    return loadScheduleData(planner.shiftDate(date, direction * step), { view, focus: true });
}

function goToToday() {
    return loadScheduleData(planner.today().date, { view: navigation?.view || scheduleState.viewMode, focus: true });
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
    loadScheduleData(params.get('date') || planner.today().date, {
        view: params.get('view') === 'week' ? 'week' : 'day',
        historyMode: 'pop', focus: true, event: params.get('event')
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
    for (const id of selectedEvents) {
        const event = all.get(id);
        if (!event || (event.focus_mode && event.status === 'in_progress')) selectedEvents.delete(id);
    }
    let visible = 0;
    document.querySelectorAll('.planner-event').forEach(row => {
        const event = all.get(row.dataset.eventId);
        const text = [event.title, event.notes ?? event.description, event.location,
            event.assigned_to_name, scheduleState.types[event.kind]?.name]
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
    document.querySelectorAll('.planner-event:not([hidden]) .bulk-checkbox:enabled')
        .forEach(checkbox => selectedEvents.add(checkbox.value));
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
    if (confirm(`Delete ${selectedEvents.size} selected events from the family calendar and schedule?`)) bulkAction('bulk_delete');
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
    if (!event || savingEvents.has(String(id)) || !confirm(`Delete “${event.title}” from the family calendar and schedule?`)) return;
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
    if (!count || bulkSaving || !confirm(`Remove ${count} completed ${count === 1 ? 'event' : 'events'} from the family calendar and schedule?`)) return;
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

const eventEditor = planner.createEditor({
    types: scheduleState.types,
    getDate: () => scheduleState.selectedDate,
    save: scheduleApi,
    onConflict: refreshSchedule,
    show: showModal,
    async onSaved(result, isEdit) {
        const savedDate = result.event.starts_at.slice(0, 10);
        const loaded = planner.onDay(result.event, scheduleState.selectedDate) ? await refreshSchedule()
            : await loadScheduleData(savedDate, { view: scheduleState.viewMode, focus: true });
        if (loaded) showToast(isEdit ? 'Changes saved.' : 'Event added.', 'success');
    }
});

function editEvent(id) {
    const event = scheduleEvent(id);
    if (!event) return;
    if (event.focus_mode && event.status === 'in_progress') {
        if (focusSession?.id === String(event.id)) startFocusMode();
        else showToast('This focus session is still running.');
        return;
    }
    eventEditor.open(event);
}
function duplicateEvent(id) {
    const event = scheduleEvent(id);
    if (event) eventEditor.open(event, { duplicate: true });
}

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
    const start = scheduleTime(event.actual_start) || Date.now();
    focusRevision++;
    focusSession = { id: String(event.id), start, deadline: start + (duration > 0 ? duration : 0) };
    focusPaused = null;
    try {
        const saved = JSON.parse(sessionStorage.getItem('relatives.schedule.focus'));
        if (saved?.id === focusSession.id && saved.start === start && Number.isFinite(saved.deadline)) {
            focusSession.deadline = saved.deadline;
            focusPaused = Number.isFinite(saved.paused) && saved.paused > 0 ? saved.paused : null;
        }
    } catch {
        // The timer remains usable when storage is unavailable.
    }
    scheduleElement('focusPauseButton').textContent = focusPaused === null ? 'Pause' : 'Resume';
    scheduleElement('focusTask').textContent = event.title;
    scheduleElement('scheduleFocusButton').textContent = 'Resume focus';
    clearInterval(focusTick);
    updateFocusTimer();
    if (focusRemaining() > 0) focusTick = setInterval(updateFocusTimer, 500);
    if (open) startFocusMode();
}

function toggleFocusPause() {
    if (!focusSession || !focusRemaining()) return;
    if (focusPaused === null) {
        focusPaused = focusRemaining();
        scheduleElement('focusPauseButton').textContent = 'Resume';
    } else {
        focusSession.deadline = Date.now() + focusPaused;
        focusPaused = null;
        scheduleElement('focusPauseButton').textContent = 'Pause';
    }
    try {
        sessionStorage.setItem('relatives.schedule.focus', JSON.stringify({ ...focusSession, paused: focusPaused }));
    } catch {
        // Keep the current timer state in this page.
    }
    updateFocusTimer();
}

function clearFocus() {
    focusRevision++;
    clearInterval(focusTick);
    focusSession = null;
    focusPaused = null;
    scheduleElement('scheduleFocusButton').textContent = 'Start focus';
    closeModal('focusTimerModal');
    closeModal('ratingModal');
    try {
        sessionStorage.removeItem('relatives.schedule.focus');
    } catch {}
}

function syncFocus(event) {
    if (event && focusSession?.id === String(event.id)) return;
    if (focusSession) clearFocus();
    if (event) runFocus(event, false);
}

function finishFocus() {
    closeModal('focusTimerModal');
    showModal('ratingModal');
}

async function selectRating(rating) {
    if (!focusSession || focusFinishing) return;
    const session = focusSession;
    focusFinishing = true;
    try {
        await scheduleApi('end_focus', { event_id: session.id, rating });
        if (focusSession === session) clearFocus();
        if (await refreshSchedule()) showToast('Focus session completed.', 'success');
    } catch (error) {
        await refreshSchedule();
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
            start_date: planner.shiftDate(endDate, -29), end_date: endDate
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
            const date = planner.shiftDate(endDate, index - 29);
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

function showTemplates() { showModal('templatesModal'); }

async function applyTemplate(template, eventCount) {
    const date = scheduleState.selectedDate;
    if (templateSaving || !confirm(`Add ${eventCount} events from “${template}” on ${date}?`)) return;
    templateSaving = true;
    closeModal('templatesModal');
    try {
        const { count } = await scheduleApi('apply_template', { template, date });
        if (await refreshSchedule()) showToast(`Added ${count} events from the template.`, 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        templateSaving = false;
    }
}

function exportSchedule() { showModal('exportModal'); }

function getScheduleData() {
    const dayStart = scheduleTime(scheduleState.selectedDate + ' 00:00:00');
    const dayEnd = dayStart + 24 * 60 * 60 * 1000;
    return scheduleState.allEvents.map(event => {
        const minutes = Math.floor((Math.min(dayEnd, scheduleTime(event.ends_at))
            - Math.max(dayStart, scheduleTime(event.starts_at))) / 60000);
        return {
            name: event.title, time: planner.timeOnDay(event, scheduleState.selectedDate),
            duration: !event.all_day && minutes > 0 ? formatScheduleDuration(minutes) : '',
            type: scheduleState.types[event.kind]?.name || 'Other',
            status: event.status === 'done' ? 'Done' : event.status === 'in_progress' ? 'In progress' : 'Pending',
            notes: event.notes ?? event.description ?? ''
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
    if (event.target.closest('[data-schedule-add]')) eventEditor.open();
    const link = event.target.closest('[data-schedule-view]');
    if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
        event.preventDefault();
        const params = new URL(link.href).searchParams;
        loadScheduleData(params.get('date'), { view: link.dataset.scheduleView, focus: true, event: params.get('event') });
    }
    if (event.target.matches('dialog.planner-modal')) closeModal(event.target.id);
    document.querySelectorAll('.planner-tools[open], .planner-event-menu[open]').forEach(menu => {
        if (!menu.contains(event.target)) menu.open = false;
    });
});
scheduleElement('templatesContent').addEventListener('click', event => {
    const button = event.target.closest('[data-template]');
    if (button) applyTemplate(button.dataset.template, button.dataset.count);
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
syncFocus(scheduleState.activeFocusSession);
if (scheduleState.selectedEvent) editEvent(scheduleState.selectedEvent);
if (scheduleState.notice) showToast(scheduleState.notice, 'error');

window.addEventListener('storage', event => {
    if (event.key !== planner.eventSignal) return;
    if (navigation) refreshQueued = true;
    else refreshSchedule();
});
window.addEventListener('relatives:event-saved', refreshSchedule);
window.addEventListener('pageshow', event => {
    if (event.persisted) refreshSchedule();
});
