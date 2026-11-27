(() => {
    'use strict';

    const $ = id => document.getElementById(id);
    const { notes: initialNotes, csrf, prefill, startNew, maxPhotos, uploadLimits,
        event: initialEvent, openNote: initialNote, linkNotice } = JSON.parse($('notesData').textContent);
    const planner = window.RelativesPlanner;
    const notes = new Map(initialNotes.map(note => [note.id, note]));
    const colors = {
        '#ffffff': 'paper', '#ffeb3b': 'honey', '#ff9800': 'peach', '#e91e63': 'rose',
        '#9c27b0': 'lilac', '#2196f3': 'sky', '#00bcd4': 'aqua', '#4caf50': 'mint', '#8bc34a': 'lime'
    };
    const taskPattern = /^([ \t]*[-*][ \t]+\[)([ xX]?)(\](?:[ \t]+|$))(.*)$/u;
    const headingPattern = /^(#{1,6}[ \t]+)(.*)$/u;
    const bulletPattern = /^([ \t]*[-*+•][ \t]+)(.*)$/u;
    const numberPattern = /^([ \t]*(\d{1,9})[.)][ \t]+)(.*)$/u;
    const eventMarker = /^\[Calendar event\]\(\/calendar\/\?event=[1-9][0-9]*\)$/;
    const blockFormats = {
        text: { label: 'Note text', placeholder: 'Write something to remember…' },
        heading: { label: 'Heading', placeholder: 'Section heading', prefix: '## ' },
        subheading: { label: 'Subheading', placeholder: 'Subheading', prefix: '### ' },
        bullet: { label: 'Bullet item', placeholder: 'Add a bullet item', prefix: '- ' },
        number: { label: 'Numbered item', placeholder: 'Add a step', prefix: '1. ' },
        task: { label: 'Checklist item', placeholder: 'Add a checklist item' }
    };
    const listFormats = new Set(['bullet', 'number', 'task']);
    const dateFormat = new Intl.DateTimeFormat('en-ZA', {
        day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Africa/Johannesburg'
    });
    const editor = $('notesEditor');
    const reader = $('notesReader');
    const gallery = $('notesGallery');
    const deleteDialog = $('notesDelete');
    const conflictDialog = $('notesConflict');
    const form = $('notesForm');
    const board = $('notesBoard');
    const search = $('notesSearch');
    const blocks = $('notesBlocks');
    const blockFormat = $('notesBlockFormat');
    const blockSources = new WeakMap();
    const moveUp = $('notesMoveUp');
    const moveDown = $('notesMoveDown');
    const photoInput = $('notesPhotoInput');
    const saveButton = $('notesSave');
    const deleteButton = $('notesConfirmDelete');
    const fileLimit = bytes => `${Math.floor(bytes / (1024 * 1024) * 10) / 10} MB`;
    const pending = new Set();
    let filter = 'all';
    let sourceEvent = null;
    let boardRevision = 0;
    let boardRefreshTimer;
    let layout = preference('layout') === 'list' ? 'list' : 'grid';
    let editing = null;
    let editorType = 'text';
    let voiceBody = '';
    let activeBlock = null;
    let initialDraft = '';
    let resolution = null;
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
        if (note.body.split('\n').some(line => taskPattern.exec(line)?.[4].trim())) return ['list', 'Checklist'];
        if (note.images.length) return ['photo', 'Photo note'];
        return ['note', 'Note'];
    }

    function noteTitle(note) {
        const kind = noteType(note)[1];
        return note.title || (kind === 'Note' ? 'Untitled note' : kind);
    }

    function checkbox(checked, attributes) {
        return `<input class="notes-checkbox" type="checkbox" ${attributes}${checked ? ' checked' : ''}>`;
    }

    function blockSyntax(line) {
        if (eventMarker.test(line)) return { kind: 'event', text: line };
        const task = taskPattern.exec(line);
        if (task) return { kind: 'task', text: task[4], checked: /x/i.test(task[2]), task };
        const heading = headingPattern.exec(line);
        if (heading) return { kind: heading[1].startsWith('###') ? 'subheading' : 'heading', text: heading[2], prefix: heading[1] };
        const bullet = bulletPattern.exec(line);
        if (bullet) return { kind: 'bullet', text: bullet[2], prefix: bullet[1] };
        const number = numberPattern.exec(line);
        if (number) return { kind: 'number', text: number[3], prefix: number[1], start: Number(number[2]) };
        return null;
    }

    function parseLine(line) {
        if (line.startsWith('\\') && blockSyntax(line.replace(/^\\+/, ''))) {
            return { kind: 'text', text: line.slice(1) };
        }
        return blockSyntax(line) || { kind: line.trim() ? 'text' : 'blank', text: line };
    }

    function noteBody(note, headingLevel = 4) {
        const parts = [];
        let previous = '';
        let group = [];
        let start = 1;
        let total = 0;
        let done = 0;

        function flush() {
            if (previous === 'text') parts.push(`<div class="notes-prose">${group.join('\n')}</div>`);
            if (previous === 'task') parts.push(`<ul class="notes-task-list">${group.join('')}</ul>`);
            if (previous === 'bullet') parts.push(`<ul class="notes-body-list">${group.join('')}</ul>`);
            if (previous === 'number') parts.push(`<ol class="notes-body-list" start="${start}">${group.join('')}</ol>`);
            group = [];
        }

        note.body.split('\n').forEach((line, index) => {
            const { kind, text, checked, start: number } = parseLine(line);
            if (!text.trim() && kind !== 'blank') return;
            if (previous !== kind) {
                flush();
                previous = kind;
                start = number ?? 1;
            }
            const value = escape(text);
            if (kind === 'heading' || kind === 'subheading') {
                const level = kind === 'subheading' ? headingLevel + 1 : headingLevel;
                parts.push(`<h${level} class="notes-body-${kind}">${value}</h${level}>`);
            } else if (kind === 'task') {
                total++;
                if (checked) done++;
                group.push(`<li><label>${checkbox(checked, `data-line="${index}"`)}<span>${value}</span></label></li>`);
            } else if (kind === 'bullet' || kind === 'number') {
                group.push(`<li>${value}</li>`);
            } else if (kind === 'text') {
                group.push(value);
            }
        });
        flush();
        const progress = total ? `<div class="notes-progress"><progress value="${done}" max="${total}" aria-label="Checklist progress"></progress><span>${done} / ${total} done</span></div>` : '';
        const content = parts.length ? `<div class="notes-rendered-body">${parts.join('')}</div>` : '';
        return { content, progress };
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
            ${photo ? photoButton(photo, 0, note.images.length) : ''}
            ${text?.content ? `<div class="notes-card-content">${text.content}</div>` : ''}
            ${text?.progress || ''}
            ${note.type === 'voice' ? `<div class="notes-audio">${audioPlayer(note)}</div>` : ''}
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
        const pinnedCount = [...notes.values()].filter(note => note.pinned).length;
        const pinnedTotal = $('notesPinnedTotal');
        pinnedTotal.textContent = pinnedCount;
        pinnedTotal.hidden = pinnedCount === 0;
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
        const text = voice ? null : noteBody(note, 3);
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
        const response = await fetch('/notes/', { method: 'POST', body: data }).catch(() => {
            throw new Error('Notes could not reach the server. Try again when you’re connected.');
        });
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error(response.status === 413
                ? 'The attachments are too large. Try fewer or smaller files.'
                : 'Notes could not reach the server. Try again.');
        }
        if (!response.ok || !result?.success) {
            const error = new Error(result?.error || 'Could not save this change.');
            error.note = result?.note;
            error.missing = response.status === 404;
            throw error;
        }
        // Cross-tab refresh is optional once the server has saved the change.
        planner?.broadcast(planner.noteSignal);
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

    function sameNote(previous, next) {
        return previous && previous.version === next.version && previous.pinned === next.pinned
            && previous.updated === next.updated && previous.author === next.author
            && previous.avatar === next.avatar && previous.audio === next.audio
            && JSON.stringify(previous.eventLinks) === JSON.stringify(next.eventLinks);
    }

    function refreshBoard() {
        clearTimeout(boardRefreshTimer);
        boardRefreshTimer = setTimeout(async () => {
            const revision = ++boardRevision;
            try {
                const response = await fetch('/notes/?ajax=1');
                if (!response.ok || response.redirected) return;
                const data = await response.json();
                if (!data.success || revision !== boardRevision) return;
                const next = new Map(data.notes.map(note => {
                    const previous = notes.get(note.id);
                    return [note.id, sameNote(previous, note) ? previous : note];
                }));
                if (next.size === notes.size && [...next].every(([id, note]) => notes.get(id) === note)) return;
                const displayed = notes.get(readerId);
                notes.clear();
                next.forEach((note, id) => notes.set(id, note));
                renderBoard();
                if (reader.open && readerId) {
                    const latest = notes.get(readerId);
                    if (!latest) reader.close();
                    else if (latest !== displayed) renderReader(latest);
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
        } else {
            data.set('pinned', note.pinned ? '0' : '1');
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
        let previousKind = '';
        const body = editorType === 'voice' ? voiceBody : [...blocks.children].flatMap(row => {
            if (row.hidden) {
                previousKind = '';
                return [row.value];
            }
            const { kind, prefix, task, raw, text: original } = blockSources.get(row);
            const text = row.querySelector('.notes-block-input').value;
            if (!text.trim() && (raw === undefined || original.trim())) return [];
            const separateParagraph = kind === 'text' && previousKind === 'text';
            previousKind = kind;
            if (!text.trim()) return [raw];
            if (kind === 'text') {
                // A paragraph can contain literal list or heading punctuation.
                const lines = text.split('\n').map(line => blockSyntax(line.replace(/^\\+/, '')) ? '\\' + line : line);
                return separateParagraph ? ['', ...lines] : lines;
            }
            if (kind === 'task') {
                const checked = row.querySelector('.notes-checkbox').checked;
                const marker = task && checked === /x/i.test(task[2]) ? task[2] : checked ? 'x' : ' ';
                const suffix = task?.[3].length > 1 ? task[3] : '] ';
                return [(task?.[1] || '- [') + marker + suffix + text];
            }
            const line = (prefix || blockFormats[kind].prefix) + text;
            return [kind === 'bullet' && taskPattern.test(line) ? '• ' + text : line];
        }).join('\n');
        return { title: $('notesTitle').value.trim(), body, color: form.elements.color.value };
    }

    function setEditorColor(color) {
        form.querySelector('[data-custom-color]')?.remove();
        if (colors[color]) {
            form.elements.color.value = color;
            return;
        }
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'color';
        input.value = color;
        input.dataset.customColor = '';
        input.hidden = true;
        input.checked = true;
        form.querySelector('.notes-colors').append(input);
    }

    function makeBlock(data) {
        const { kind, text, checked } = data;
        if (kind === 'blank' || kind === 'event') {
            // Calendar markers and blank separators retain their original position in the saved body.
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.hidden = true;
            hidden.value = text;
            return hidden;
        }
        const row = document.createElement('div');
        row.className = 'notes-block-row';
        row.dataset.format = kind;
        blockSources.set(row, data);
        if (kind === 'task') {
            row.innerHTML = checkbox(checked, 'aria-label="Mark item complete"');
        } else if (kind === 'bullet' || kind === 'number') {
            row.innerHTML = '<span class="notes-block-marker" aria-hidden="true"></span>';
        }
        const { label, placeholder } = blockFormats[kind];
        const field = document.createElement(kind === 'text' ? 'textarea' : 'input');
        field.className = kind === 'task' ? 'notes-block-input notes-task-text' : 'notes-block-input';
        field.setAttribute('aria-label', label);
        field.placeholder = placeholder;
        field.value = text;
        if (kind === 'text') field.rows = Math.min(5, Math.max(2, text.split('\n').length));
        else field.enterKeyHint = 'enter';
        row.append(field);
        row.insertAdjacentHTML('beforeend', `<button type="button" class="notes-icon-button" data-remove-block aria-label="Remove ${label.toLowerCase()}">${icon('close')}</button>`);
        return row;
    }

    function parseBlocks(body) {
        const entries = [];
        for (const raw of body === '' ? [] : body.split('\n')) {
            const entry = { ...parseLine(raw), raw };
            const previous = entries.at(-1);
            if (entry.kind === 'text' && previous?.kind === 'text') previous.text += '\n' + entry.text;
            else entries.push(entry);
        }
        return entries;
    }

    function adjacentBlock(row, direction) {
        const sibling = direction < 0 ? 'previousElementSibling' : 'nextElementSibling';
        let next = row?.[sibling];
        while (next?.hidden) next = next[sibling];
        return next;
    }

    function selectBlock(row) {
        activeBlock?.removeAttribute('data-active');
        activeBlock = row;
        activeBlock?.setAttribute('data-active', '');
        blockFormat.value = activeBlock?.dataset.format || 'text';
        moveUp.disabled = saveButton.disabled || !adjacentBlock(activeBlock, -1);
        moveDown.disabled = saveButton.disabled || !adjacentBlock(activeBlock, 1);
    }

    function updateTaskCount() {
        let total = 0;
        let done = 0;
        for (const row of blocks.querySelectorAll('[data-format="task"]')) {
            const text = row.querySelector('.notes-task-text').value.trim();
            const control = row.querySelector('.notes-checkbox');
            control.setAttribute('aria-label', text ? `Mark ${text} complete` : 'Mark item complete');
            if (!text) continue;
            total++;
            if (control.checked) done++;
        }
        const count = $('notesChecklistCount');
        const progress = total ? `${done} / ${total} done` : '';
        if (count.textContent !== progress) count.textContent = progress;
    }

    function updateBlockRows() {
        let number = null;
        for (const row of blocks.children) {
            if (row.dataset.format === 'number') {
                number = number === null ? (blockSources.get(row).start ?? 1) : number + 1;
                row.querySelector('.notes-block-marker').textContent = number + '.';
            } else {
                number = null;
                if (row.dataset.format === 'bullet') row.querySelector('.notes-block-marker').textContent = '•';
            }
        }
        updateTaskCount();
    }

    function setEditorBody(body, firstKind = 'text') {
        voiceBody = body;
        blocks.replaceChildren();
        if (editorType === 'voice') {
            selectBlock(null);
            return;
        }
        const entries = parseBlocks(body);
        const content = document.createDocumentFragment();
        for (const entry of entries) content.append(makeBlock(entry));
        if (!content.querySelector('[data-format]')) content.append(makeBlock({ kind: firstKind, text: '' }));
        blocks.append(content);
        const first = blocks.firstElementChild;
        selectBlock(first.hidden ? adjacentBlock(first, 1) : first);
        updateBlockRows();
    }

    function draftFingerprint() {
        return JSON.stringify([draft(), photoPaths]);
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
        $('notesEditorHeading').textContent = note ? (noteType(note)[0] === 'list' ? 'Edit checklist' : 'Edit note')
            : { text: 'New note', list: 'New checklist', voice: 'Record a voice note' }[type];
        $('notesTitle').value = note?.title || linkedEvent?.title.slice(0, 160) || '';
        const eventHint = $('notesSourceEvent');
        eventHint.hidden = !linkedEvent;
        if (linkedEvent) {
            const link = eventHint.querySelector('a');
            link.href = '/calendar/?event=' + encodeURIComponent(linkedEvent.id);
            link.textContent = linkedEvent.title;
        }
        setEditorBody(note?.body ?? text, type === 'list' ? 'task' : 'text');
        $('notesRecorder').hidden = !recording;
        $('notesBodyField').hidden = editorType === 'voice';
        $('notesAttachments').hidden = editorType === 'voice';
        if (photoInput) renderPhotoPreviews();
        setEditorColor(note?.color.toLowerCase() || '#ffffff');
        setNotice($('notesFormError'), '');
        initialDraft = draftFingerprint();
        editor.showModal();
        if (recording) $('notesStartRecord').focus();
        else if (type === 'list') focusBlock(activeBlock);
        else if (note && editorType === 'text') $('notesEditorHeading').focus();
        else if (text) {
            const last = blocks.lastElementChild;
            focusBlock(last.hidden ? adjacentBlock(last, -1) : last);
        } else {
            $('notesTitle').focus();
        }
    }

    function closeEditor() {
        if (saveButton.disabled) return;
        const changed = draftFingerprint() !== initialDraft || Boolean(photoFiles.length || recordedBlob || activeRecording?.started);
        if (!changed || window.confirm('Discard your unsaved changes?')) editor.close();
    }

    function setSaving(saving) {
        for (const input of form.elements) input.disabled = saving;
        saveButton.textContent = saving ? 'Saving…' : 'Save note';
        if (!saving) {
            selectBlock(activeBlock);
            updateRecordingControls();
            if (photoInput) $('notesAddPhotos').disabled = photoPaths.length + photoFiles.length >= maxPhotos;
        }
    }

    function changedLines(base, version) {
        let start = 0;
        let end = base.length;
        let versionEnd = version.length;
        while (start < end && start < versionEnd && base[start] === version[start]) start++;
        while (end > start && versionEnd > start && base[end - 1] === version[versionEnd - 1]) {
            end--;
            versionEnd--;
        }
        return { start, end, lines: version.slice(start, versionEnd) };
    }

    function mergeBody(base, mine, latest) {
        const lines = base.split('\n');
        const [first, second] = [mine, latest].map(body => changedLines(lines, body.split('\n')))
            .sort((a, b) => a.start - b.start);
        if (first.start === second.start || first.end > second.start) return null;
        return [...lines.slice(0, first.start), ...first.lines, ...lines.slice(first.end, second.start),
            ...second.lines, ...lines.slice(second.end)].join('\n');
    }

    function noteFields(note) {
        return { title: note.title.trim(), body: note.body, color: note.color.toLowerCase() };
    }

    function compareDraft(latest, mine) {
        const base = noteFields(editing);
        const current = noteFields(latest);
        const fields = { ...mine };
        const conflicts = [];
        for (const field of Object.keys(base)) {
            if (mine[field] === base[field]) {
                fields[field] = current[field];
            } else if (current[field] !== base[field] && current[field] !== mine[field]) {
                const merged = field === 'body' ? mergeBody(base.body, mine.body, current.body) : null;
                if (merged === null) conflicts.push(field);
                else fields[field] = merged;
            }
        }
        const removed = editing.images.filter(path => !photoPaths.includes(path));
        return { latest, current, fields, conflicts, photos: latest.images.filter(path => !removed.includes(path)) };
    }

    function applyRebase(rebase) {
        editing = rebase.latest;
        initialDraft = JSON.stringify([rebase.current, editing.images]);
        photoPaths = rebase.photos;
        $('notesTitle').value = rebase.fields.title;
        setEditorBody(rebase.fields.body, activeBlock?.dataset.format);
        setEditorColor(rebase.fields.color);
        if (photoInput) renderPhotoPreviews();
    }

    function showConflicts(rebase) {
        const labels = { title: 'Title', body: 'Note contents', color: 'Note color' };
        function preview(field, value) {
            if (field === 'color') return colors[value] || value;
            if (field !== 'body') return value;
            return value.split('\n').map(line => {
                const { kind, text, prefix, checked } = parseLine(line);
                if (kind === 'event') return 'Linked calendar event';
                if (kind === 'task') return `${checked ? '☑' : '☐'} ${text}`;
                return kind === 'bullet' || kind === 'number' ? prefix + text : text;
            }).join('\n');
        }
        resolution = rebase;
        $('notesConflictFields').innerHTML = rebase.conflicts.map(field => `<fieldset class="notes-conflict-field">
            <legend>${labels[field]}</legend>
            ${[['mine', 'Your draft', rebase.fields[field]], ['latest', 'Latest saved', rebase.current[field]]]
                .map(([id, label, value]) => `<label><input type="radio" name="${field}" value="${id}">
                    <span><strong>${label}</strong><span class="notes-conflict-preview">${escape(preview(field, value) || 'Empty')}</span></span>
                </label>`).join('')}
        </fieldset>`).join('');
        $('notesApplyResolution').disabled = true;
        conflictDialog.showModal();
    }

    $('notesConflictFields').addEventListener('change', () => {
        $('notesApplyResolution').disabled = resolution.conflicts.some(field => !conflictDialog.querySelector(`[name="${field}"]:checked`));
    });

    $('notesApplyResolution').addEventListener('click', () => {
        for (const field of resolution.conflicts) {
            if (conflictDialog.querySelector(`[name="${field}"]:checked`).value === 'latest') {
                resolution.fields[field] = resolution.current[field];
            }
        }
        applyRebase(resolution);
        setNotice($('notesFormError'), '');
        conflictDialog.close();
        form.requestSubmit();
    });

    function saveData(fields) {
        const data = new FormData();
        Object.entries(fields).forEach(([field, value]) => data.set(field, value));
        data.set('type', editorType);
        if (editing) {
            data.set('note_id', editing.id);
            data.set('version', editing.version);
            editing.images.filter(path => !photoPaths.includes(path)).forEach(path => data.append('remove_images[]', path));
        } else if (sourceEvent) {
            data.set('event_id', sourceEvent.id);
        }
        data.set('photo_count', String(photoFiles.length));
        photoFiles.forEach(({ file }) => data.append('images[]', file));
        if (editorType === 'voice' && !editing) {
            if (!recordedBlob) throw new Error('Record a voice note first.');
            if (recordedBlob.size > uploadLimits.fileBytes) throw new Error(`Record again to stay within the ${fileLimit(uploadLimits.fileBytes)} upload limit.`);
            const extension = recordedBlob.type.includes('mp4') ? 'm4a' : recordedBlob.type.includes('ogg') ? 'ogg' : 'webm';
            data.append('audio', recordedBlob, 'recording.' + extension);
        }
        if (photoPaths.length + photoFiles.length > maxPhotos) throw new Error(`Remove a photo before saving. A note can hold up to ${maxPhotos}.`);
        return data;
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saveButton.disabled || conflictDialog.open) return;
        setNotice($('notesFormError'), '');
        setSaving(true);
        let fields = draft();
        try {
            let result;
            for (let attempt = 0; attempt < 2; attempt++) {
                try {
                    result = await request(editing ? 'update_note' : 'create_note', saveData(fields));
                    break;
                } catch (error) {
                    if (editing) recordMutationError(error, editing.id);
                    if (!editing || !error.note) throw error;
                    const rebase = compareDraft(error.note, fields);
                    if (rebase.conflicts.length) {
                        setNotice($('notesFormError'), 'Your draft is still here. Save again to review the shared edits.');
                        showConflicts(rebase);
                        return;
                    }
                    applyRebase(rebase);
                    fields = rebase.fields;
                    if (attempt) throw new Error('The latest changes were merged. Review your note and save again.');
                    setSaving(true);
                }
            }
            const wasEditing = Boolean(editing);
            refreshNote(result.note, result.note.id);
            editor.close();
            if (!reader.open && previousFocus && !previousFocus.isConnected) {
                board.querySelector(`[data-note-id="${result.note.id}"] [data-action="edit"]`)?.focus();
            }
            notify(wasEditing ? 'Changes saved.' : 'Note added.');
        } catch (error) {
            setNotice($('notesFormError'), error.message);
        } finally {
            setSaving(false);
        }
    });

    function focusBlock(row) {
        const field = row.querySelector('.notes-block-input');
        field.focus();
        field.setSelectionRange(field.value.length, field.value.length);
    }

    function insertBlock(kind, after = activeBlock) {
        const row = makeBlock({ kind, text: '' });
        if (after) after.after(row);
        else blocks.append(row);
        updateBlockRows();
        focusBlock(row);
    }

    function replaceBlock(row, entries) {
        const replacement = entries.map(makeBlock);
        row.replaceWith(...replacement);
        updateBlockRows();
        focusBlock(replacement.find(element => !element.hidden));
    }

    function moveBlock(direction) {
        const neighbor = adjacentBlock(activeBlock, direction);
        if (!neighbor) return;
        const position = document.createComment('');
        activeBlock.before(position);
        neighbor.before(activeBlock);
        position.replaceWith(neighbor);
        updateBlockRows();
        selectBlock(activeBlock);
        if ((direction < 0 ? moveUp : moveDown).disabled) focusBlock(activeBlock);
        else activeBlock.scrollIntoView({ block: 'nearest' });
    }

    $('notesAddBlock').addEventListener('click', () => {
        const kind = activeBlock?.dataset.format;
        insertBlock(listFormats.has(kind) ? kind : 'text');
    });
    moveUp.addEventListener('click', () => moveBlock(-1));
    moveDown.addEventListener('click', () => moveBlock(1));
    blockFormat.addEventListener('change', () => {
        const kind = blockFormat.value;
        if (!activeBlock || kind === activeBlock.dataset.format) return;
        const { value } = activeBlock.querySelector('.notes-block-input');
        if (kind === 'text') {
            replaceBlock(activeBlock, [{ kind, text: value }]);
            return;
        }
        const [first, ...rest] = value.split('\n');
        const additional = kind === 'heading' || kind === 'subheading'
            ? (rest.length ? [{ kind: 'text', text: rest.join('\n') }] : [])
            : rest.map(text => ({ kind: text.trim() ? kind : 'blank', text }));
        replaceBlock(activeBlock, [{ kind, text: first }, ...additional]);
    });

    blocks.addEventListener('focusin', event => {
        const row = event.target.closest('[data-format]');
        if (row) selectBlock(row);
    });
    blocks.addEventListener('input', event => {
        if (event.target.closest('[data-format="task"]')) updateTaskCount();
    });
    blocks.addEventListener('keydown', event => {
        const field = event.target;
        if (!field.matches('input.notes-block-input') || event.key !== 'Enter' || event.ctrlKey || event.metaKey
            || event.isComposing || event.keyCode === 229) return;
        event.preventDefault();
        const row = field.closest('[data-format]');
        const kind = row.dataset.format;
        const list = listFormats.has(kind);
        if (!field.value.trim() && list) replaceBlock(row, [{ kind: 'text', text: '' }]);
        else insertBlock(list ? kind : 'text', row);
    });

    blocks.addEventListener('paste', event => {
        const field = event.target;
        if (!field.matches('.notes-block-input')) return;
        const text = event.clipboardData?.getData('text/plain').replace(/\r\n?/g, '\n');
        if (!text?.includes('\n')) return;
        const row = field.closest('[data-format]');
        const kind = row.dataset.format;
        const before = field.value.slice(0, field.selectionStart);
        const after = field.value.slice(field.selectionEnd);
        if (kind === 'text') {
            const entries = parseBlocks(text);
            if (!entries.some(entry => Object.hasOwn(blockFormats, entry.kind) && entry.kind !== 'text')) return;
            event.preventDefault();
            const parts = entries.map(entry => entry.kind === 'event' ? { kind: 'text', text: entry.text } : entry);
            if (before) parts.unshift({ kind: 'text', text: before });
            if (after) parts.push({ kind: 'text', text: after });
            replaceBlock(row, parts);
            return;
        }
        if (!listFormats.has(kind)) return;
        const lines = text.split('\n').filter(line => line.trim());
        if (lines.length < 2) return;
        event.preventDefault();
        const items = lines.map(line => {
            const parsed = parseLine(line);
            return { kind, text: listFormats.has(parsed.kind) ? parsed.text : line, checked: parsed.checked };
        });
        const first = items.shift();
        field.value = before + first.text;
        if (kind === 'task' && !before && !after && first.checked !== undefined) {
            row.querySelector('.notes-checkbox').checked = first.checked;
        }
        items.at(-1).text += after;
        const added = items.map(makeBlock);
        row.after(...added);
        updateBlockRows();
        focusBlock(added.at(-1));
    });

    blocks.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-block]');
        if (!button) return;
        const row = button.closest('[data-format]');
        const next = adjacentBlock(row, 1) || adjacentBlock(row, -1);
        if (next) {
            row.remove();
            updateBlockRows();
            focusBlock(next);
        } else {
            replaceBlock(row, [{ kind: row.dataset.format, text: '' }]);
        }
    });

    function clearPhotoFiles() {
        photoFiles.forEach(({ url }) => { if (url) URL.revokeObjectURL(url); });
        photoFiles = [];
    }

    function renderPhotoPreviews() {
        const photos = [...photoPaths.map(url => ({ url })), ...photoFiles];
        $('notesPhotoCount').textContent = `${photos.length} / ${maxPhotos}`;
        $('notesAddPhotos').disabled = saveButton.disabled || photos.length >= maxPhotos;
        $('notesPhotoPreviews').innerHTML = photos.map(({ url }, index) => `<div class="notes-photo-preview">
            ${url ? `<img alt="" src="${escape(url)}"><span class="notes-photo-missing" hidden>${icon('photo')}</span>`
                : `<span class="notes-photo-missing" aria-label="Preview after saving" title="Preview after saving">${icon('photo')}</span>`}
            <button type="button" data-remove-photo="${index}" class="notes-icon-button" aria-label="Remove photo ${index + 1}">${icon('close')}</button>
        </div>`).join('');
    }

    async function resizePhoto(image, name, limit, preferred) {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        if (!context) return null;
        let mime = 'image/webp';
        let best = null;
        const encode = () => new Promise(resolve => canvas.toBlob(resolve, mime, .84));
        for (let edge = 2400; edge >= 500; edge = Math.floor(edge * .7)) {
            const scale = Math.min(1, edge / Math.max(image.naturalWidth, image.naturalHeight));
            canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
            if (mime === 'image/jpeg') {
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
            }
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
            let blob = await encode();
            if (!blob || blob.type !== mime) {
                mime = 'image/jpeg';
                context.globalCompositeOperation = 'destination-over';
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                blob = await encode();
            }
            if (blob?.type === mime && blob.size <= limit) {
                if (!best || blob.size < best.size) best = blob;
                if (blob.size <= preferred) break;
            }
        }
        if (!best) return null;
        const extension = best.type === 'image/webp' ? '.webp' : '.jpg';
        return new File([best], name.replace(/\.[^.]+$/, '') + extension, { type: best.type });
    }

    async function preparePhoto(file, limit, preferred) {
        const heif = /^image\/hei[cf](?:-sequence)?$/i.test(file.type) || /\.hei[cf]$/i.test(file.name);
        if (!file.size || /^image\/svg/i.test(file.type) || /\.svgz?$/i.test(file.name)) {
            throw new Error('Choose a JPG, PNG, GIF, WebP or camera photo.');
        }
        const source = URL.createObjectURL(file);
        try {
            const image = new Image();
            image.src = source;
            try {
                await image.decode();
            } catch {
                if (heif && uploadLimits.heif && file.size <= limit) return { file, url: null };
                throw new Error(heif ? 'This HEIC photo could not be prepared. Choose a JPEG copy.'
                    : 'This photo could not be opened. Choose another image.');
            }
            const budget = Math.min(limit, preferred);
            const standard = /^image\/(?:jpeg|png|gif|webp)$/i.test(file.type);
            const original = standard && file.size <= budget && Math.max(image.naturalWidth, image.naturalHeight) <= 2400;
            const prepared = original ? file : await resizePhoto(image, file.name, limit, budget);
            if (!prepared) throw new Error(`This photo could not fit the ${fileLimit(limit)} upload limit.`);
            return { file: prepared, url: URL.createObjectURL(prepared) };
        } finally {
            URL.revokeObjectURL(source);
        }
    }

    async function addPhotos(files) {
        if (saveButton.disabled || !files.length) return;
        saveButton.disabled = true;
        $('notesAddPhotos').disabled = true;
        setNotice($('notesPhotoStatus'), files.length === 1 ? 'Preparing photo…' : 'Preparing photos…');
        setNotice($('notesFormError'), '');
        const messages = new Set();
        let total = photoFiles.reduce((sum, { file }) => sum + file.size, 0);
        const batchSize = Math.min(maxPhotos, uploadLimits.files);
        const preferred = Math.floor(uploadLimits.totalBytes / Math.max(1, batchSize));
        try {
            for (const file of files) {
                if (photoPaths.length + photoFiles.length >= maxPhotos) {
                    messages.add(`You can attach up to ${maxPhotos} photos.`);
                    break;
                }
                if (photoFiles.length >= batchSize) {
                    messages.add(batchSize ? `You can upload ${batchSize} photos at a time.` : 'Photo uploads are disabled on this server.');
                    break;
                }
                const limit = Math.min(uploadLimits.fileBytes, uploadLimits.totalBytes - total);
                if (limit <= 0) {
                    messages.add('Save these photos before adding more.');
                    break;
                }
                try {
                    const photo = await preparePhoto(file, limit, preferred);
                    photoFiles.push(photo);
                    total += photo.file.size;
                } catch (error) {
                    messages.add(error instanceof DOMException ? 'This photo could not be prepared. Try another image.' : error.message);
                }
            }
        } finally {
            saveButton.disabled = false;
            setNotice($('notesPhotoStatus'), '');
            renderPhotoPreviews();
            setNotice($('notesFormError'), [...messages].join(' '));
        }
    }

    if (photoInput) {
        const dropZone = $('notesAddPhotos');
        dropZone.addEventListener('click', () => photoInput.click());
        photoInput.addEventListener('change', () => {
            const files = Array.from(photoInput.files);
            photoInput.value = '';
            addPhotos(files);
        });
        dropZone.addEventListener('dragover', event => event.preventDefault());
        dropZone.addEventListener('drop', event => {
            event.preventDefault();
            addPhotos(Array.from(event.dataTransfer.files));
        });
        $('notesPhotoPreviews').addEventListener('click', event => {
            const button = event.target.closest('[data-remove-photo]');
            if (!button || saveButton.disabled) return;
            const index = Number(button.dataset.removePhoto);
            if (index < photoPaths.length) photoPaths.splice(index, 1);
            else {
                const [{ url }] = photoFiles.splice(index - photoPaths.length, 1);
                if (url) URL.revokeObjectURL(url);
            }
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
            // Reserve part of the limit for the audio container and variable bitrate.
            const bitrate = Math.max(24000, Math.min(64000, Math.floor(uploadLimits.fileBytes * 8 / 660)));
            const maxSeconds = Math.min(600, Math.floor(uploadLimits.fileBytes * 8 / bitrate * .9));
            const recorder = new MediaRecorder(recording.stream, { audioBitsPerSecond: bitrate });
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
                if (seconds >= maxSeconds && recorder.state === 'recording') recorder.stop();
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
            setNotice($('notesDeleteError'), error.note ? 'This note changed. Close this confirmation to review the latest version.' : error.message);
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
            if (button.id === 'notesPinnedShortcut') $('notesBoardHeading').focus();
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

    for (const dialog of [editor, reader, gallery, deleteDialog, conflictDialog]) {
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
    conflictDialog.addEventListener('close', () => {
        if (conflictDialog.open) return;
        resolution = null;
        $('notesConflictFields').replaceChildren();
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
        if (event.key === planner?.noteSignal || event.key === planner?.eventSignal) refreshBoard();
    });
    window.addEventListener('relatives:event-saved', refreshBoard);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshBoard();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) refreshBoard();
    });
    updateLayout();
    if (initialNote) {
        const note = notes.get(initialNote);
        if (note) openNote(note);
        else notify('This note is no longer available.');
    } else if (startNew && !linkNotice) {
        openEditor('text', null, prefill, initialEvent);
        history.replaceState(null, '', '/notes/');
    }
    if (linkNotice) notify(linkNotice);
})();
