(() => {
    'use strict';

    const $ = id => document.getElementById(id);
    const { notes: initialNotes, csrf, prefill, maxPhotos, event: initialEvent, openNote: initialNote, linkNotice } = JSON.parse($('notesData').textContent);
    const planner = window.RelativesPlanner;
    const notes = new Map(initialNotes.map(note => [note.id, note]));
    const colors = {
        '#ffffff': 'paper', '#ffeb3b': 'honey', '#ff9800': 'peach', '#e91e63': 'rose',
        '#9c27b0': 'lilac', '#2196f3': 'sky', '#00bcd4': 'aqua', '#4caf50': 'mint', '#8bc34a': 'lime'
    };
    const taskPattern = /^[ \t]*[-*] \[([ xX])\][ \t]+(\S.*)$/u;
    const taskPrefix = /^([ \t]*[-*] )\[[ xX]\](?:[ \t]+|$)/;
    const bulletPrefix = /^([ \t]*)[-*•][ \t]+/;
    const eventMarker = /^\[Calendar event\]\(\/calendar\/\?event=[1-9][0-9]*\)$/;
    const dateFormat = new Intl.DateTimeFormat('en-ZA', {
        day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Africa/Johannesburg'
    });
    const editor = $('notesEditor');
    const reader = $('notesReader');
    const gallery = $('notesGallery');
    const deleteDialog = $('notesDelete');
    const form = $('notesForm');
    const board = $('notesBoard');
    const search = $('notesSearch');
    const bodyInput = $('notesBody');
    const photoInput = $('notesPhotoInput');
    const saveButton = $('notesSave');
    const deleteButton = $('notesConfirmDelete');
    const pending = new Set();
    let filter = 'all';
    let sourceEvent = null;
    let boardRevision = 0;
    let boardRefreshTimer;
    let layout = preference('layout') === 'list' ? 'list' : 'grid';
    let editing = null;
    let editorType = 'text';
    let initialDraft = '';
    let previousFocus = null;
    let readerId = null;
    let deleting = null;
    let photoPaths = [];
    let photoFiles = [];
    let galleryImages = [];
    let galleryIndex = 0;
    let galleryTouch = null;
    let toastTimer;

    function preference(name, value) {
        try {
            const key = 'relatives.notes.' + name;
            if (value === undefined) return localStorage.getItem(key);
            localStorage.setItem(key, value);
        } catch {
            // The board still works when browser storage is unavailable.
        }
    }

    function escape(value) {
        return String(value).replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[character]);
    }

    function icon(name) {
        return `<svg class="notes-icon" aria-hidden="true"><use href="#notes-icon-${name}"></use></svg>`;
    }

    function iconButton(action, glyph, label, pressed) {
        return `<button type="button" class="notes-icon-button" data-action="${action}" aria-label="${label}" title="${label}" ${
            pressed === undefined ? '' : `aria-pressed="${pressed}"`
        }>${icon(glyph)}</button>`;
    }

    function noteType(note) {
        if (note.type === 'voice') return ['mic', 'Voice note'];
        if (note.body.split('\n').some(line => taskPattern.test(line))) return ['list', 'Checklist'];
        if (note.images.length) return ['photo', 'Photo note'];
        return ['note', 'Note'];
    }

    function noteTitle(note) {
        const kind = noteType(note)[1];
        return note.title || (kind === 'Note' ? 'Untitled note' : kind);
    }

    function noteBody(note) {
        const parts = [];
        let prose = [];
        let tasks = [];
        let total = 0;
        let done = 0;

        function flushProse() {
            if (prose.length && prose.some(line => line.trim())) {
                parts.push(`<div class="notes-prose">${escape(prose.join('\n'))}</div>`);
            }
            prose = [];
        }

        function flushTasks() {
            if (tasks.length) parts.push(`<ul class="notes-task-list">${tasks.join('')}</ul>`);
            tasks = [];
        }

        note.body.split('\n').forEach((line, index) => {
            if (eventMarker.test(line)) return;
            const match = taskPattern.exec(line);
            if (!match) {
                flushTasks();
                prose.push(line);
                return;
            }
            flushProse();
            const checked = match[1] !== ' ';
            total++;
            if (checked) done++;
            tasks.push(`<li><label><input type="checkbox" data-line="${index}" ${checked ? 'checked' : ''}><span>${escape(match[2])}</span></label></li>`);
        });
        flushProse();
        flushTasks();

        const progress = total ? `<div class="notes-progress"><progress value="${done}" max="${total}" aria-label="Checklist progress"></progress><span>${done} / ${total} done</span></div>` : '';
        return { content: parts.join(''), progress };
    }

    function audioPlayer(note) {
        if (!note.audio) return '<p class="notes-media-missing">Recording unavailable</p>';
        return `<audio controls preload="metadata" src="${escape(note.audio)}" aria-label="${escape(noteTitle(note))}"></audio>`;
    }

    function photoButton(path, index, count = 1) {
        return `<button type="button" class="notes-photo" data-photo="${index}" aria-label="${
            count > 1 ? `Open ${count} photos` : `Open photo ${index + 1}`
        }"><img src="${escape(path)}" alt="" loading="lazy"><span class="notes-photo-missing" hidden>${icon('photo')} Photo unavailable</span>${
            count > 1 ? `<span class="notes-photo-count">${icon('photo')} ${count}</span>` : ''
        }</button>`;
    }

    function authorLine(note) {
        const date = note.updated || note.created;
        const initial = Array.from(note.author)[0]?.toLocaleUpperCase() || '?';
        return `<span class="notes-author"><span class="notes-avatar" aria-hidden="true">${escape(initial)}${
            note.avatar ? `<img src="${escape(note.avatar)}" alt="" loading="lazy">` : ''
        }</span><span>${escape(note.author)}</span></span>${
            date ? `<time title="${note.updated ? 'Last edited' : 'Created'}" datetime="${escape(date)}">${dateFormat.format(new Date(date))}</time>` : ''
        }`;
    }

    function makeCard(note) {
        const [glyph, kind] = noteType(note);
        const text = note.type === 'text' ? noteBody(note) : null;
        const photo = note.images[0];
        return `<article class="notes-card" data-note-id="${escape(note.id)}" data-tone="${colors[note.color.toLowerCase()] || 'paper'}">
            <div class="notes-card-top">
                <span class="notes-card-kind">${icon(glyph)} ${kind}</span>
                <div class="notes-card-actions">
                    ${iconButton('pin', 'pin', note.pinned ? 'Unpin note' : 'Pin note', note.pinned)}
                    ${iconButton('edit', 'pencil', 'Edit note')}
                </div>
            </div>
            <h3><button type="button" class="notes-open" data-action="open">${escape(noteTitle(note))} ${icon('arrow')}</button></h3>
            ${text?.content ? `<div class="notes-card-content">${text.content}</div>` : ''}
            ${text?.progress || ''}
            ${note.type === 'voice' ? `<div class="notes-audio">${audioPlayer(note)}</div>` : ''}
            ${photo ? photoButton(photo, 0, note.images.length) : ''}
            <footer class="notes-card-footer">${authorLine(note)}</footer>
        </article>`;
    }

    function renderBoard() {
        const query = search.value.trim().toLocaleLowerCase();
        const terms = query ? query.split(/\s+/) : [];
        const relevant = [...notes.values()].filter(note => {
            if (filter === 'pinned' && !note.pinned) return false;
            if (filter === 'list' && noteType(note)[0] !== 'list') return false;
            if (filter === 'text' && note.type !== 'text') return false;
            if (filter === 'photos' && !note.images.length) return false;
            if (filter === 'voice' && note.type !== 'voice') return false;
            const eventTitles = (note.eventLinks || []).map(event => event.title).join(' ');
            const searchable = `${noteTitle(note)} ${note.body} ${note.author} ${eventTitles}`.toLocaleLowerCase();
            return terms.every(word => searchable.includes(word));
        });
        const sort = $('notesSort').value;
        relevant.sort((a, b) => {
            if (sort === 'title') return noteTitle(a).localeCompare(noteTitle(b));
            const aDate = sort === 'updated' ? a.updated || a.created : a.created;
            const bDate = sort === 'updated' ? b.updated || b.created : b.created;
            const difference = (Date.parse(bDate) || 0) - (Date.parse(aDate) || 0);
            return sort === 'old' ? -difference : difference;
        });
        $('notesTotal').textContent = notes.size;
        $('notesResultCount').textContent = `${relevant.length} ${relevant.length === 1 ? 'note' : 'notes'} shown`;
        board.dataset.layout = layout;
        if (!relevant.length) {
            const hasFilter = Boolean(query) || filter !== 'all';
            board.innerHTML = `<div class="notes-empty"><span class="notes-empty-icon">${icon(hasFilter ? 'search' : 'note')}</span>
                <h3>${hasFilter ? 'No matching notes' : 'No notes yet'}</h3>
                <p>${hasFilter ? 'Try another search or filter.' : 'Start with a note, a checklist or a voice recording.'}</p>
                ${hasFilter
                    ? '<button type="button" class="notes-button" data-action="clear">Clear filters</button>'
                    : `<button type="button" class="notes-button notes-button-primary" data-create="text">${icon('plus')} New note</button>`}
            </div>`;
            return;
        }
        const pinned = relevant.filter(note => note.pinned);
        const others = relevant.filter(note => !note.pinned);
        const group = (title, items) => items.length ? `<section class="notes-group" aria-label="${title}"><h3>${title}</h3><div class="notes-grid">${items.map(makeCard).join('')}</div></section>` : '';
        board.innerHTML = group('Pinned', pinned) + group(pinned.length ? 'Other notes' : 'Notes', others);
    }

    function renderReader(note) {
        const [glyph, kind] = noteType(note);
        const voice = note.type === 'voice';
        const text = voice ? null : noteBody(note);
        const events = note.eventLinks || [];
        $('notesReaderSurface').dataset.tone = colors[note.color.toLowerCase()] || 'paper';
        $('notesReaderKind').innerHTML = `${icon(glyph)} ${kind}`;
        $('notesReaderHeading').textContent = noteTitle(note);
        $('notesReaderContent').innerHTML = `<div class="notes-reader-meta">${authorLine(note)}</div>
            ${voice ? `<div class="notes-audio">${audioPlayer(note)}</div>` : `${text.progress}${text.content}`}
            ${note.images.length ? `<div class="notes-reader-photos">${note.images.map((path, index) => photoButton(path, index)).join('')}</div>` : ''}
            ${events.length ? `<div class="notes-linked-events"><h3>Calendar events</h3>${events.map(event => event.title
                ? `<a href="/calendar/?event=${encodeURIComponent(event.id)}">${escape(event.title)} ${icon('arrow')}</a>`
                : '<span>Event unavailable</span>').join('')}</div>` : ''}`;
        $('notesReaderActions').innerHTML = `<button type="button" class="notes-button notes-reader-delete" data-action="delete">${icon('trash')} Delete</button>
            ${voice ? '' : `<button type="button" class="notes-button" data-action="copy">${icon('copy')} Copy</button>`}
            <a class="notes-button notes-calendar-action" href="/calendar/?new=1&amp;note=${encodeURIComponent(note.id)}">${icon('plus')} Plan event</a>
            <button type="button" class="notes-button" data-action="pin" aria-pressed="${note.pinned}">${icon('pin')} ${note.pinned ? 'Unpin' : 'Pin'}</button>
            <button type="button" class="notes-button notes-button-primary" data-action="edit">${icon('pencil')} Edit</button>`;
    }

    function openNote(note) {
        readerId = note.id;
        setNotice($('notesReaderFeedback'), '');
        renderReader(note);
        reader.showModal();
        $('notesReaderHeading').focus();
    }

    async function request(action, data = new FormData()) {
        data.set('action', action);
        data.set('csrf_token', csrf);
        const response = await fetch('/notes/', { method: 'POST', body: data });
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error(response.status === 413
                ? 'The attachments are too large. Try fewer or smaller files.'
                : 'Notes could not reach the server. Try again.');
        }
        if (!response.ok || !result.success) {
            const error = new Error(result.error || 'Could not save this change.');
            error.note = result.note;
            error.missing = response.status === 404;
            throw error;
        }
        planner.broadcast(planner.noteSignal);
        return result;
    }

    function setNotice(element, message) {
        element.textContent = message;
        element.hidden = !message;
    }

    function notify(message) {
        const toast = reader.open && !editor.open && !deleteDialog.open ? $('notesReaderFeedback') : $('notesToast');
        clearTimeout(toastTimer);
        for (const id of ['notesToast', 'notesReaderFeedback']) setNotice($(id), '');
        setNotice(toast, message);
        toastTimer = setTimeout(() => setNotice(toast, ''), 4500);
    }

    function refreshNote(note, id) {
        boardRevision++;
        if (note) notes.set(note.id, note);
        else notes.delete(id);
        renderBoard();
        if (reader.open && readerId === id) {
            if (note) renderReader(note);
            else reader.close();
        }
    }

    function refreshLinkedNotes() {
        clearTimeout(boardRefreshTimer);
        boardRefreshTimer = setTimeout(async () => {
            const revision = ++boardRevision;
            try {
                const response = await fetch('/notes/?ajax=1');
                if (!response.ok || response.redirected) return;
                const data = await response.json();
                if (!data.success || revision !== boardRevision) return;
                notes.clear();
                data.notes.forEach(note => notes.set(note.id, note));
                renderBoard();
                if (reader.open && readerId) {
                    const displayed = notes.get(readerId);
                    if (displayed) renderReader(displayed);
                    else reader.close();
                }
            } catch {
                // The current board remains usable if a background refresh fails.
            }
        }, 160);
    }

    function recordMutationError(error, id) {
        if (error.note || error.missing) refreshNote(error.note, id);
    }

    async function changeNote(note, action, control) {
        if (pending.has(note.id)) {
            if (action === 'set_task') control.checked = !control.checked;
            return;
        }
        const data = new FormData();
        data.set('note_id', note.id);
        data.set('version', note.version);
        const focusWithinReader = reader.contains(control);
        const selector = action === 'set_task' ? `[data-line="${control.dataset.line}"]` : '[data-action="pin"]';
        if (action === 'set_task') {
            data.set('line', control.dataset.line);
            data.set('checked', control.checked ? '1' : '0');
        }
        pending.add(note.id);
        control.disabled = true;
        try {
            const result = await request(action, data);
            refreshNote(result.note, note.id);
            if (action === 'toggle_pin') notify(result.note.pinned ? 'Note pinned.' : 'Note unpinned.');
        } catch (error) {
            recordMutationError(error, note.id);
            if (control.isConnected && action === 'set_task') control.checked = !control.checked;
            notify(error.message);
        } finally {
            pending.delete(note.id);
            control.disabled = false;
            const area = focusWithinReader && reader.open ? reader : board.querySelector(`[data-note-id="${note.id}"]`);
            if (!control.isConnected && (!document.activeElement || document.activeElement === document.body || reader === document.activeElement || area?.contains(document.activeElement))) {
                (area?.querySelector(selector) || (focusWithinReader && reader.open ? $('notesReaderHeading') : search)).focus({ preventScroll: true });
            }
        }
    }

    function draft() {
        return JSON.stringify([$('notesTitle').value, bodyInput.value, form.elements.color.value, photoPaths]);
    }

    function openEditor(type, note = null, text = '', linkedEvent = null) {
        editing = note;
        sourceEvent = linkedEvent;
        editorType = note?.type || (type === 'voice' ? 'voice' : 'text');
        previousFocus = document.activeElement;
        const recording = editorType === 'voice' && !note;
        form.reset();
        resetRecording();
        clearPhotoFiles();
        photoPaths = note ? [...note.images] : [];
        $('notesEditorHeading').textContent = note ? 'Edit note' : { text: 'New note', list: 'New checklist', voice: 'Record a voice note' }[type];
        $('notesTitle').value = note?.title || linkedEvent?.title.slice(0, 160) || '';
        const eventHint = $('notesSourceEvent');
        eventHint.hidden = !linkedEvent;
        if (linkedEvent) {
            const link = eventHint.querySelector('a');
            link.href = '/calendar/?event=' + encodeURIComponent(linkedEvent.id);
            link.textContent = linkedEvent.title;
        }
        bodyInput.value = note?.body ?? (type === 'list' ? '- [ ] ' : text);
        bodyInput.placeholder = type === 'list' ? 'Add a checklist item…' : 'Write something to remember…';
        $('notesRecorder').hidden = !recording;
        $('notesBodyField').hidden = editorType === 'voice';
        if (photoInput) {
            $('notesAttachments').hidden = editorType === 'voice';
            renderPhotoPreviews();
        }
        const custom = form.querySelector('[data-custom-color]');
        custom?.remove();
        const color = note?.color.toLowerCase() || '#ffffff';
        if (colors[color]) {
            form.elements.color.value = color;
        } else {
            const input = document.createElement('input');
            input.type = 'radio';
            input.name = 'color';
            input.value = color;
            input.dataset.customColor = '';
            input.hidden = true;
            input.checked = true;
            form.querySelector('.notes-colors').append(input);
        }
        setNotice($('notesFormError'), '');
        initialDraft = draft();
        updateChecklistHint();
        editor.showModal();
        if (recording) $('notesStartRecord').focus();
        else if (type === 'list' || text) {
            bodyInput.focus();
            bodyInput.setSelectionRange(bodyInput.value.length, bodyInput.value.length);
        } else {
            $('notesTitle').focus();
        }
    }

    function closeEditor() {
        if (saveButton.disabled) return;
        const changed = draft() !== initialDraft || Boolean(photoFiles.length || recordedBlob || activeRecording?.started);
        if (!changed || window.confirm('Discard your unsaved changes?')) editor.close();
    }

    function setSaving(saving) {
        for (const input of form.elements) input.disabled = saving;
        saveButton.textContent = saving ? 'Saving…' : 'Save note';
        if (!saving) {
            updateRecordingControls();
            if (photoInput) $('notesAddPhotos').disabled = photoPaths.length + photoFiles.length >= maxPhotos;
        }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saveButton.disabled) return;
        const data = new FormData(form);
        data.set('type', editorType);
        if (editing) {
            data.set('note_id', editing.id);
            data.set('version', editing.version);
            editing.images.filter(path => !photoPaths.includes(path)).forEach(path => data.append('remove_images[]', path));
        } else if (sourceEvent) {
            data.set('event_id', sourceEvent.id);
        }
        photoFiles.forEach(({ file }) => data.append('images[]', file));
        if (editorType === 'voice' && !editing) {
            if (!recordedBlob) {
                setNotice($('notesFormError'), 'Record a voice note first.');
                return;
            }
            const extension = recordedBlob.type.includes('mp4') ? 'm4a' : recordedBlob.type.includes('ogg') ? 'ogg' : 'webm';
            data.append('audio', recordedBlob, 'recording.' + extension);
        }
        // An unfinished last checkbox is a prompt, not a saved item.
        if (editorType === 'text' && bodyInput.value !== editing?.body) {
            data.set('body', bodyInput.value.replace(/(?:^|\n)[ \t]*[-*] \[ \][ \t]*$/, ''));
        }
        setNotice($('notesFormError'), '');
        setSaving(true);
        try {
            const result = await request(editing ? 'update_note' : 'create_note', data);
            const wasEditing = Boolean(editing);
            refreshNote(result.note, result.note.id);
            editor.close();
            if (!reader.open && previousFocus && !previousFocus.isConnected) {
                board.querySelector(`[data-note-id="${result.note.id}"] [data-action="edit"]`)?.focus();
            }
            notify(wasEditing ? 'Changes saved.' : 'Note added.');
        } catch (error) {
            if (editing) recordMutationError(error, editing.id);
            setNotice($('notesFormError'), error.message + (error.note ? ' Copy your changes before reopening the editor.' : ''));
        } finally {
            setSaving(false);
        }
    });

    function updateChecklistHint() {
        $('notesChecklistHint').hidden = !bodyInput.value.split('\n').some(line => taskPrefix.test(line));
    }

    function addCheckboxes() {
        const text = bodyInput.value;
        const { selectionStart, selectionEnd } = bodyInput;
        const start = selectionStart ? text.lastIndexOf('\n', selectionStart - 1) + 1 : 0;
        const lastSelected = selectionEnd > selectionStart && text[selectionEnd - 1] === '\n' ? selectionEnd - 1 : selectionEnd;
        const nextNewline = text.indexOf('\n', lastSelected);
        const end = nextNewline < 0 ? text.length : nextNewline;
        const toTask = line => taskPrefix.test(line) ? line : '- [ ] ' + line.replace(bulletPrefix, '$1').trimStart();
        if (selectionStart !== selectionEnd) {
            const lines = text.slice(start, end).split('\n').map(line => line.trim() ? toTask(line) : line);
            bodyInput.setRangeText(lines.join('\n'), start, end, 'select');
        } else if (text.split('\n').some(line => bulletPrefix.test(line) && !taskPrefix.test(line))) {
            const lines = text.split('\n').map(line => bulletPrefix.test(line) ? toTask(line) : line);
            bodyInput.setRangeText(lines.join('\n'), 0, text.length, 'end');
        } else if (text.slice(start, end).trim()) {
            const line = text.slice(start, end);
            if (!taskPrefix.test(line)) bodyInput.setRangeText('\n- [ ] ', end, end, 'end');
        } else {
            bodyInput.setRangeText('- [ ] ', start, end, 'end');
        }
        bodyInput.focus();
        updateChecklistHint();
    }

    $('notesInsertTasks').addEventListener('click', addCheckboxes);
    bodyInput.addEventListener('input', updateChecklistHint);
    bodyInput.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.isComposing
            || bodyInput.selectionStart !== bodyInput.selectionEnd) return;
        const position = bodyInput.selectionStart;
        const start = position ? bodyInput.value.lastIndexOf('\n', position - 1) + 1 : 0;
        const end = bodyInput.value.indexOf('\n', position);
        const line = bodyInput.value.slice(start, end < 0 ? undefined : end);
        const prefix = taskPrefix.exec(line);
        if (!prefix || position < start + prefix[0].length) return;
        event.preventDefault();
        if (!line.slice(prefix[0].length).trim()) {
            bodyInput.setRangeText('', start, end < 0 ? bodyInput.value.length : end, 'end');
        } else {
            bodyInput.setRangeText('\n' + prefix[1] + '[ ] ', position, position, 'end');
        }
        updateChecklistHint();
    });

    function clearPhotoFiles() {
        photoFiles.forEach(({ url }) => URL.revokeObjectURL(url));
        photoFiles = [];
    }

    function renderPhotoPreviews() {
        const paths = [...photoPaths, ...photoFiles.map(({ url }) => url)];
        $('notesPhotoCount').textContent = `${paths.length} / ${maxPhotos}`;
        $('notesAddPhotos').disabled = paths.length >= maxPhotos;
        $('notesPhotoPreviews').innerHTML = paths.map((path, index) => `<div class="notes-photo-preview">
            <img alt="" src="${escape(path)}"><span class="notes-photo-missing" hidden>${icon('photo')}</span>
            <button type="button" data-remove-photo="${index}" class="notes-icon-button" aria-label="Remove photo ${index + 1}">${icon('close')}</button>
        </div>`).join('');
    }

    function addPhotos(files) {
        const message = [];
        for (const file of files) {
            if (photoPaths.length + photoFiles.length >= maxPhotos) {
                message.push('You can attach up to seven photos.');
                break;
            }
            if (/^image\/(?:jpeg|png|gif|webp)$/.test(file.type) && file.size > 0 && file.size <= 10 * 1024 * 1024) {
                photoFiles.push({ file, url: URL.createObjectURL(file) });
            } else if (!message.length) {
                message.push('Choose JPG, PNG, GIF or WebP photos up to 10 MB each.');
            }
        }
        renderPhotoPreviews();
        setNotice($('notesFormError'), message.join(' '));
    }

    if (photoInput) {
        const dropZone = $('notesAddPhotos');
        dropZone.addEventListener('click', () => photoInput.click());
        photoInput.addEventListener('change', () => {
            addPhotos(photoInput.files);
            photoInput.value = '';
        });
        dropZone.addEventListener('dragover', event => event.preventDefault());
        dropZone.addEventListener('drop', event => {
            event.preventDefault();
            if (!saveButton.disabled) addPhotos(event.dataTransfer.files);
        });
        $('notesPhotoPreviews').addEventListener('click', event => {
            const button = event.target.closest('[data-remove-photo]');
            if (!button || saveButton.disabled) return;
            const index = Number(button.dataset.removePhoto);
            if (index < photoPaths.length) photoPaths.splice(index, 1);
            else URL.revokeObjectURL(photoFiles.splice(index - photoPaths.length, 1)[0].url);
            renderPhotoPreviews();
            ($('notesPhotoPreviews').querySelector(`[data-remove-photo="${index}"]`) || dropZone).focus();
        });
    }

    function showPhoto(index) {
        galleryIndex = (index + galleryImages.length) % galleryImages.length;
        const image = $('notesGalleryImage');
        image.hidden = false;
        $('notesGalleryError').hidden = true;
        image.alt = `Note photo ${galleryIndex + 1}`;
        image.src = galleryImages[galleryIndex];
        $('notesGalleryCount').textContent = `Photo ${galleryIndex + 1} of ${galleryImages.length}`;
        $('notesPrevPhoto').hidden = galleryImages.length === 1;
        $('notesNextPhoto').hidden = galleryImages.length === 1;
    }

    function openGallery(note, index) {
        galleryImages = note.images;
        showPhoto(index);
        gallery.showModal();
    }

    $('notesPrevPhoto').addEventListener('click', () => showPhoto(galleryIndex - 1));
    $('notesNextPhoto').addEventListener('click', () => showPhoto(galleryIndex + 1));
    gallery.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            showPhoto(galleryIndex + (event.key === 'ArrowLeft' ? -1 : 1));
        }
    });
    gallery.addEventListener('touchstart', event => {
        galleryTouch = event.changedTouches[0].clientX;
    }, { passive: true });
    gallery.addEventListener('touchend', event => {
        if (galleryTouch === null) return;
        const offset = galleryTouch - event.changedTouches[0].clientX;
        galleryTouch = null;
        if (Math.abs(offset) > 55) showPhoto(galleryIndex + Math.sign(offset));
    }, { passive: true });

    document.addEventListener('error', event => {
        const element = event.target;
        if (element.id === 'notesGalleryImage') {
            element.hidden = true;
            $('notesGalleryError').hidden = false;
        } else if (element instanceof HTMLImageElement
            && element.closest('.notes-photo, .notes-photo-preview, .notes-avatar')) {
            element.hidden = true;
            if (element.nextElementSibling?.classList.contains('notes-photo-missing')) {
                element.nextElementSibling.hidden = false;
            }
        } else if (element instanceof HTMLAudioElement && element.closest('.notes-audio')) {
            const message = document.createElement('p');
            message.className = 'notes-media-missing';
            message.textContent = 'Recording unavailable';
            element.replaceWith(message);
        }
    }, true);

    let activeRecording = null;
    let recordedBlob = null;
    let recordingUrl = null;
    let timer = null;

    function updateRecordingControls() {
        const waiting = Boolean(activeRecording && !activeRecording.started);
        $('notesStartRecord').hidden = Boolean(activeRecording?.started);
        $('notesStartRecord').disabled = waiting;
        $('notesStartRecord').textContent = recordedBlob ? 'Record again' : 'Start recording';
        $('notesStopRecord').hidden = !activeRecording?.started;
    }

    function releaseRecording() {
        const recording = activeRecording;
        activeRecording = null;
        clearInterval(timer);
        if (recording?.recorder?.state === 'recording') recording.recorder.stop();
        recording?.stream?.getTracks().forEach(track => track.stop());
    }

    function resetRecording() {
        releaseRecording();
        recordedBlob = null;
        if (recordingUrl) URL.revokeObjectURL(recordingUrl);
        recordingUrl = null;
        const preview = $('notesRecordingPreview');
        preview.pause();
        preview.removeAttribute('src');
        preview.hidden = true;
        $('notesTimer').value = '00:00';
        $('notesRecordStatus').textContent = 'Ready to record';
        updateRecordingControls();
    }

    function recordingError(message) {
        resetRecording();
        $('notesRecordStatus').textContent = message;
    }

    $('notesStartRecord').addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            recordingError('Audio recording is unavailable in this browser.');
            return;
        }
        if (recordedBlob && !window.confirm('Replace this recording?')) return;
        setNotice($('notesFormError'), '');
        resetRecording();
        const recording = { started: false };
        activeRecording = recording;
        $('notesRecordStatus').textContent = 'Waiting for microphone access…';
        updateRecordingControls();
        try {
            recording.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            if (activeRecording !== recording || !editor.open) {
                recording.stream.getTracks().forEach(track => track.stop());
                return;
            }
            const recorder = new MediaRecorder(recording.stream);
            recording.recorder = recorder;
            const chunks = [];
            recorder.addEventListener('dataavailable', event => {
                if (event.data.size) chunks.push(event.data);
            });
            recorder.addEventListener('error', () => {
                if (activeRecording === recording) recordingError('Recording failed. Try again.');
            });
            recorder.addEventListener('stop', () => {
                if (activeRecording !== recording) return;
                releaseRecording();
                const blob = new Blob(chunks, { type: recorder.mimeType || chunks[0]?.type });
                if (!blob.size) {
                    recordingError('No audio was captured. Try recording again.');
                    return;
                }
                recordedBlob = blob;
                recordingUrl = URL.createObjectURL(blob);
                const preview = $('notesRecordingPreview');
                preview.src = recordingUrl;
                preview.hidden = false;
                $('notesRecordStatus').textContent = 'Recording ready to preview';
                updateRecordingControls();
            });
            recorder.start(1000);
            recording.started = true;
            const start = Date.now();
            timer = setInterval(() => {
                const seconds = Math.floor((Date.now() - start) / 1000);
                $('notesTimer').value = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
                if (seconds >= 600 && recorder.state === 'recording') recorder.stop();
            }, 250);
            $('notesRecordStatus').textContent = 'Recording…';
            updateRecordingControls();
        } catch (error) {
            if (activeRecording === recording) recordingError(error.name === 'NotAllowedError'
                ? 'Microphone access was denied. Allow it in your device or browser settings.'
                : 'Could not start the microphone. Try again.');
        }
    });

    $('notesStopRecord').addEventListener('click', () => {
        if (activeRecording?.recorder?.state === 'recording') activeRecording.recorder.stop();
    });

    function updateFilters() {
        document.querySelectorAll('[data-filter]').forEach(button => {
            button.setAttribute('aria-pressed', button.dataset.filter === filter);
        });
        renderBoard();
    }

    function updateLayout() {
        document.querySelectorAll('[data-layout]:is(button)').forEach(button => {
            button.setAttribute('aria-pressed', button.dataset.layout === layout);
        });
        renderBoard();
    }

    async function copyNote(note) {
        try {
            await navigator.clipboard.writeText([note.title, note.body].filter(Boolean).join('\n\n'));
            notify('Note copied.');
        } catch {
            notify('Clipboard access is unavailable.');
        }
    }

    function openDelete(note) {
        deleting = note;
        setNotice($('notesDeleteError'), '');
        deleteDialog.showModal();
    }

    deleteButton.addEventListener('click', async () => {
        const note = deleting;
        const data = new FormData();
        data.set('note_id', note.id);
        data.set('version', note.version);
        deleteButton.disabled = true;
        setNotice($('notesDeleteError'), '');
        try {
            await request('delete_note', data);
            deleteDialog.close();
            refreshNote(null, note.id);
            search.focus({ preventScroll: true });
            notify('Note deleted.');
        } catch (error) {
            recordMutationError(error, note.id);
            setNotice($('notesDeleteError'), error.message);
        } finally {
            deleteButton.disabled = false;
        }
    });

    function handleNoteClick(event) {
        const button = event.target.closest('button');
        if (!button) return;
        const note = notes.get(reader.contains(button) ? readerId : button.closest('[data-note-id]')?.dataset.noteId);
        if (!note) return;
        if (button.hasAttribute('data-photo')) {
            openGallery(note, Number(button.dataset.photo));
            return;
        }
        switch (button.dataset.action) {
            case 'open': openNote(note); break;
            case 'edit': openEditor('text', note); break;
            case 'pin': changeNote(note, 'toggle_pin', button); break;
            case 'copy': copyNote(note); break;
            case 'delete': openDelete(note); break;
        }
    }

    function handleTaskChange(event) {
        const control = event.target;
        if (!control.matches('input[data-line]')) return;
        const note = notes.get(reader.contains(control) ? readerId : control.closest('[data-note-id]')?.dataset.noteId);
        if (note) changeNote(note, 'set_task', control);
    }

    for (const container of [board, reader]) {
        container.addEventListener('click', handleNoteClick);
        container.addEventListener('change', handleTaskChange);
    }

    $('notesApp').addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.create) openEditor(button.dataset.create);
        if (button.dataset.filter) {
            filter = button.dataset.filter;
            updateFilters();
        }
        if (button.dataset.layout) {
            layout = button.dataset.layout;
            preference('layout', layout);
            updateLayout();
        }
        if (button.dataset.action === 'clear') {
            filter = 'all';
            search.value = '';
            updateFilters();
            search.focus();
        }
    });

    for (const dialog of [editor, reader, gallery, deleteDialog]) {
        const close = () => {
            if (dialog === editor) closeEditor();
            else if (dialog !== deleteDialog || !deleteButton.disabled) dialog.close();
        };
        dialog.addEventListener('click', event => {
            if (event.target === dialog || event.target.closest('[data-close]')) close();
        });
        dialog.addEventListener('cancel', event => {
            event.preventDefault();
            close();
        });
    }

    editor.addEventListener('close', () => {
        resetRecording();
        clearPhotoFiles();
    });
    reader.addEventListener('close', () => {
        readerId = null;
        $('notesReaderContent').replaceChildren();
    });
    document.addEventListener('keydown', event => {
        if (editor.open && !saveButton.disabled && event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            form.requestSubmit();
        }
        if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey
            && !document.querySelector('dialog[open]') && !event.target.closest('input, textarea, select, [contenteditable]')) {
            event.preventDefault();
            search.focus();
        }
    });

    search.addEventListener('input', renderBoard);
    const savedSort = preference('sort');
    if (Array.from($('notesSort').options).some(option => option.value === savedSort)) $('notesSort').value = savedSort;
    $('notesSort').addEventListener('change', () => {
        preference('sort', $('notesSort').value);
        renderBoard();
    });
    window.addEventListener('relatives:note-created', event => refreshNote(event.detail, event.detail.id));
    window.addEventListener('storage', event => {
        if (event.key === planner.noteSignal || event.key === planner.eventSignal) refreshLinkedNotes();
    });
    window.addEventListener('relatives:event-saved', refreshLinkedNotes);
    window.addEventListener('pageshow', event => {
        if (event.persisted) refreshLinkedNotes();
    });
    updateLayout();
    if (initialNote) {
        const note = notes.get(initialNote);
        if (note) openNote(note);
        else notify('This note is no longer available.');
    } else if (initialEvent || prefill) {
        openEditor('text', null, prefill, initialEvent);
        history.replaceState(null, '', '/notes/');
    }
    if (linkNotice) notify(linkNotice);
})();
