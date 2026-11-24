'use strict';

(() => {
    const { events, date } = JSON.parse(document.getElementById('timelineData').textContent);
    const details = new Map(events.map(event => [event.id, event]));
    const dialog = document.getElementById('timelineEventDialog');
    const clock = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Africa/Johannesburg', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
    });
    const nowLine = document.getElementById('timelineNow');

    function drawClock() {
        const values = Object.fromEntries(clock.formatToParts().map(({ type, value }) => [type, value]));
        const today = values.year + '-' + values.month + '-' + values.day;
        const minutes = Number(values.hour) * 60 + Number(values.minute);
        const { start, end } = nowLine.dataset;
        nowLine.hidden = today !== date || minutes < Number(start) || minutes >= Number(end);
        if (!nowLine.hidden) nowLine.style.top = ((minutes - start) / (end - start) * 100) + '%';
    }

    if (nowLine) {
        drawClock();
        setInterval(drawClock, 15000);
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
        dialog.showModal();
    });

    document.getElementById('timelineClose').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
})();
