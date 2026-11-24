'use strict';

(() => {
    const { events, date } = JSON.parse(document.getElementById('timelineData').textContent);
    const details = new Map(events.map(event => [event.id, event]));
    const dialog = document.getElementById('timelineEventDialog');
    const planner = window.RelativesPlanner;
    const nowLine = document.getElementById('timelineNow');

    function drawClock() {
        if (document.hidden) return;
        const { date: today, time } = planner.today();
        const [hour, minute] = time.split(':').map(Number);
        const minutes = hour * 60 + minute;
        const { start, end } = nowLine.dataset;
        nowLine.hidden = today !== date || minutes < Number(start) || minutes >= Number(end);
        if (!nowLine.hidden) nowLine.style.top = ((minutes - start) / (end - start) * 100) + '%';
    }

    if (nowLine) {
        drawClock();
        setInterval(drawClock, 15000);
        document.addEventListener('visibilitychange', drawClock);
    }

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-timeline-event]');
        if (!trigger) return;
        const item = details.get(trigger.dataset.timelineEvent);
        if (!item) return;
        document.getElementById('timelineEventTitle').textContent = item.title;
        const status = item.status === 'done' ? 'Completed' : item.status === 'in_progress' ? 'In progress' : 'Open';
        const fields = { Time: item.time, Type: item.kind, Status: status, 'Assigned to': item.assigned };
        const values = document.createDocumentFragment();
        for (const [name, text] of Object.entries(fields)) {
            if (!text) continue;
            const label = document.createElement('dt');
            const value = document.createElement('dd');
            label.textContent = name;
            value.textContent = text;
            values.append(label, value);
        }
        document.getElementById('timelineEventData').replaceChildren(values);
        document.getElementById('timelineEventNotes').textContent = item.notes || '';
        document.getElementById('timelineEditLink').href = '/schedule/?' + new URLSearchParams({ date, event: item.id });
        document.getElementById('timelineCalendarLink').href = '/calendar/?' + new URLSearchParams({ date, event: item.id });
        dialog.showModal();
    });

    document.getElementById('timelineClose').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    let refreshOnClose = false;
    function refreshTimeline() {
        if (dialog.open) refreshOnClose = true;
        else location.reload();
    }
    window.addEventListener('storage', event => {
        if (event.key === planner.eventSignal) refreshTimeline();
    });
    window.addEventListener('relatives:event-saved', refreshTimeline);
    window.addEventListener('pageshow', event => { if (event.persisted) refreshTimeline(); });
    dialog.addEventListener('close', () => {
        if (refreshOnClose) location.reload();
    });
})();
