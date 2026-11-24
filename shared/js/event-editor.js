'use strict';

(() => {
    const lookup = id => document.getElementById(id);
    const { today, shiftDate } = window.RelativesPlanner;

    function selectValue(id, value, fallback) {
        const input = lookup(id);
        input.querySelector('[data-previous-value]')?.remove();
        const desired = String(value ?? fallback);
        if (desired && ![...input.options].some(option => option.value === desired)) {
            const option = new Option(fallback, desired);
            option.dataset.previousValue = '';
            input.add(option);
        }
        input.value = desired;
    }

    function createEditor({ types, getDate, save, onSaved, onConflict, show }) {
        const dialog = lookup('eventEditor');
        const form = lookup('eventForm');
        let original = null;
        let sourceNote = null;
        let previousDate = '';
        let defaultColor = '';

        function syncTimeFields() {
            const allDay = lookup('eventAllDay').checked;
            lookup('eventTimeFields').hidden = allDay;
            for (const id of ['eventStart', 'eventEnd']) lookup(id).required = !allDay;
            lookup('eventFocus').disabled = allDay;
        }

        function setError(message) {
            const error = lookup('eventFormError');
            error.textContent = message;
            error.hidden = !message;
            if (message) error.scrollIntoView({ block: 'nearest' });
        }

        function open(event = null, { duplicate = false, note = null, title = '' } = {}) {
            form.reset();
            original = duplicate ? null : event;
            sourceNote = note;
            const { date, time } = today();
            const color = types[event?.kind]?.color || types.event.color;
            defaultColor = color;
            const startDate = event?.starts_at.slice(0, 10) || getDate();
            const startTime = event?.starts_at.slice(11, 16) || (date === startDate ? time : '09:00');
            const startHour = Number(startTime.slice(0, 2));
            const keepEnd = event && (!duplicate || event.all_day || event.ends_at?.slice(0, 16) > event.starts_at.slice(0, 16));
            let endDate = (keepEnd && event.ends_at?.slice(0, 10)) || startDate;
            if (!keepEnd && startHour === 23) endDate = shiftDate(startDate, 1);
            if (event?.all_day && event.ends_at?.slice(11, 19) === '00:00:00' && endDate > startDate) {
                endDate = shiftDate(endDate, -1);
            }

            lookup('eventEditorHeading').textContent = duplicate ? 'Duplicate event' : event ? 'Edit event' : 'Add event';
            const initialTitle = event?.title || title || note?.title || '';
            lookup('eventTitle').value = Array.from(initialTitle).slice(0, duplicate ? 193 : 200).join('') + (duplicate ? ' (copy)' : '');
            lookup('eventDate').value = startDate;
            previousDate = startDate;
            lookup('eventEndDate').value = endDate;
            lookup('eventEndDate').min = startDate;
            lookup('eventStart').value = startTime;
            lookup('eventEnd').value = keepEnd ? (event.ends_at || event.starts_at).slice(11, 16) :
                String((startHour + 1) % 24).padStart(2, '0') + startTime.slice(2);
            lookup('eventAllDay').checked = Boolean(event?.all_day);
            lookup('eventFocus').checked = Boolean(event?.focus_mode);
            lookup('eventPriority').value = event?.priority || 'medium';
            lookup('eventLocation').value = event?.location || '';
            lookup('eventNotes').value = event?.notes ?? event?.description ?? '';
            selectValue('eventType', event?.kind || 'event', 'Other');
            selectValue('eventAssign', event?.assigned_to || '', event?.assigned_to_name || 'Previous assignee');
            selectValue('eventRepeat', duplicate ? '' : event?.repeat_rule || '', 'Existing repeat interval');
            lookup('eventReminder').value = event?.reminder_minutes || '';
            lookup('eventColor').value = /^#[\da-f]{6}$/i.test(event?.color) ? event.color : color;
            lookup('eventRepeat').disabled = Boolean(original?.recurrence_parent_id);
            const hint = lookup('eventRepeatHint');
            hint.textContent = original?.recurrence_parent_id
                ? 'Changes affect only this occurrence. Edit the original event to change the repeat.'
                : 'Changes to the original event also update its future pending occurrences.';
            hint.hidden = !(original?.recurrence_parent_id || original?.repeat_rule);
            lookup('eventOptions').open = Boolean(event?.repeat_rule || event?.focus_mode || event?.reminder_minutes);
            const source = lookup('eventSourceNote');
            source.hidden = !note;
            if (note) {
                const link = source.querySelector('a');
                link.textContent = note.title;
                link.href = '/notes/?note=' + encodeURIComponent(note.id);
            }
            setError('');
            syncTimeFields();
            show(dialog.id);
        }

        lookup('eventDate').addEventListener('change', event => {
            const end = lookup('eventEndDate');
            const date = event.target.value;
            if (!date) return;
            const days = (Date.parse(date) - Date.parse(previousDate)) / 86400000;
            end.value = Number.isFinite(days) && end.value && end.value >= previousDate ? shiftDate(end.value, days) : date;
            end.min = date;
            previousDate = date;
        });
        lookup('eventAllDay').addEventListener('change', syncTimeFields);
        lookup('eventType').addEventListener('change', event => {
            const type = event.target.value;
            const color = lookup('eventColor');
            if (color.value.toLowerCase() === defaultColor.toLowerCase()) color.value = types[type]?.color || types.event.color;
            defaultColor = types[type]?.color || types.event.color;
            if (!original && ['birthday', 'anniversary', 'holiday'].includes(type)) {
                lookup('eventAllDay').checked = true;
                if (type !== 'holiday' && !lookup('eventRepeat').value) lookup('eventRepeat').value = 'yearly';
                syncTimeFields();
            }
        });
        dialog.addEventListener('click', event => {
            if (dialog.dataset.saving !== '1' && (event.target === dialog || event.target.closest('[data-close-event-editor]'))) dialog.close();
        });
        dialog.addEventListener('cancel', event => {
            if (dialog.dataset.saving === '1') event.preventDefault();
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (dialog.dataset.saving === '1') return;
            const data = new FormData(form);
            const isEdit = Boolean(original);
            const submit = event.submitter || form.querySelector('[type="submit"]');
            if (isEdit) {
                data.set('event_id', original.id);
                data.set('version', original.version);
            } else if (sourceNote) {
                data.set('note_id', sourceNote.id);
            }
            dialog.dataset.saving = '1';
            submit.disabled = true;
            setError('');
            try {
                const result = await save(isEdit ? 'update' : 'add', data);
                dialog.close();
                await onSaved(result, isEdit);
            } catch (error) {
                setError(error.message);
                if (error.status === 409) await onConflict();
            } finally {
                dialog.dataset.saving = '';
                submit.disabled = false;
            }
        });
        return { open };
    }

    window.RelativesPlanner.createEditor = createEditor;
})();
