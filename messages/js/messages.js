/**
 * ============================================
 * RELATIVES v3.0 - CORE MESSAGING SYSTEM
 * Clean, maintainable core functionality
 * Optimized for web and native Android app
 * ============================================
 */

// ============================================
// GLOBAL STATE MANAGEMENT
// ============================================
const MessageSystem = {
    currentUserId: null,
    currentUserName: null,
    familyId: null,
    changeCursor: null,
    initialLoadComplete: false,
    replyToMessageId: null,
    contextMessageId: null,
    mediaFiles: [],
    typingTimeout: null,
};

const MESSAGE_POLL_INTERVAL = 1800;
const timeline = { hasOlder: false, hasNewer: false, latestId: 0n, jump: null, page: null };
const messageRows = new Map();
let chatOutbox;
let syncTimer;
let syncing = false;
let syncAgain = false;
let syncFailures = 0;
let sessionExpired = false;
let pendingMessageCount = 0;
let readObserver;
let readTimer;
let reportingReads = false;
let lastTypingPing = -Infinity;
let followViewport = true;
const visibleMessages = new Set();
const confirmedReads = new Set();
let nativeChatVisible = null;

document.addEventListener('DOMContentLoaded', () => {
    MessageSystem.currentUserId = document.getElementById('currentUserId').value;
    MessageSystem.currentUserName = document.getElementById('currentUserName').value;
    MessageSystem.familyId = document.getElementById('familyId').value;
    timeline.jump = { id: linkedMessage(), push: false };
    chatOutbox = new ChatOutbox(
        `${MessageSystem.familyId}:${MessageSystem.currentUserId}`,
        document.getElementById('sendBtn').dataset.csrf,
        renderPendingMessages,
        message => {
            if (message && MessageSystem.initialLoadComplete && !timeline.hasNewer && !timeline.jump && !timeline.page) {
                displayMessages([message], { follow: isScrolledToBottom() });
            }
            loadNewMessages();
        }
    );

    setupEventListeners();
    setupEmojiPicker();
    resumeChat();
});

function updateConnectionStatus(message) {
    const indicator = document.getElementById('connectionStatus');
    indicator.querySelector('.status-text').textContent = message;
    indicator.style.display = message ? 'flex' : 'none';
    updateNativeChatVisibility();
}

function showEmptyConversation() {
    const list = document.getElementById('messagesList');
    if (MessageSystem.initialLoadComplete && !messageRows.size
        && !list.querySelector('#pendingMessages, .empty-state')) {
        document.getElementById('newerMessages').insertAdjacentHTML('beforebegin', '<div class="empty-state"><h3>No messages yet</h3></div>');
    }
}

function linkedMessage() {
    const id = new URLSearchParams(location.search).get('message');
    return /^[1-9][0-9]*$/.test(id) ? id : null;
}

async function loadNewMessages() {
    clearTimeout(syncTimer);
    if (document.hidden || sessionExpired) return;
    if (!navigator.onLine) {
        updateConnectionStatus('Offline');
        return;
    }
    if (syncing) {
        syncAgain = true;
        return;
    }

    syncing = true;
    const jump = timeline.jump;
    const page = jump ? null : timeline.page;
    let more = false;
    try {
        const params = new URLSearchParams({ account: chatOutbox.account });
        if (page) {
            params.set(page.direction, page.id);
        } else {
            params.set('typing', '1');
            const pending = chatOutbox.pendingIds();
            if (pending.length) params.set('pending', pending.join(','));
            if (jump) {
                if (jump.id) params.set('message', jump.id);
            } else if (MessageSystem.changeCursor === null) {
                params.set('since', timeline.latestId);
            } else {
                params.set('cursor', MessageSystem.changeCursor);
            }
        }
        const response = await fetch('/messages/api/fetch.php?' + params, {
            credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(8000)
        });
        if ([401, 403, 409].includes(response.status)) {
            closeExpiredConversation(response.status);
            return;
        }
        if (!response.ok) throw new Error('Chat sync failed');
        const data = await response.json();
        if (!data.success) throw new Error('Chat sync failed');
        if (jump !== timeline.jump || (page && page !== timeline.page)) return;

        syncFailures = 0;
        updateConnectionStatus('');
        if (page) {
            if (page.direction === 'before') timeline.hasOlder = data.has_older;
            else timeline.hasNewer = data.has_newer;
            displayMessages(data.messages);
            timeline.page = null;
            more = true;
        } else {
            chatOutbox.confirm(data.acknowledged || []);
            updateTypingIndicator(data.typing || []);
            if (data.reset) {
                timeline.jump = { id: currentScrollAnchor()?.id || linkedMessage(), push: false };
                more = true;
                return;
            }
            if (jump) {
                timeline.latestId = BigInt(data.latest_message_id ?? 0);
                timeline.hasOlder = data.has_older;
                timeline.hasNewer = data.has_newer;
                timeline.jump = null;
                pendingMessageCount = 0;
                MessageSystem.initialLoadComplete = true;
                displayMessages(data.messages, { replace: true, follow: !data.focused });
                renderPendingMessages();
                if (data.focused) revealMessage(jump.id, 'auto');
                else if (jump.id) showError('This message is no longer available.');
                if (jump.push) {
                    const url = jump.id && data.focused ? '/messages/?message=' + jump.id : '/messages/';
                    if (location.pathname + location.search !== url) history.pushState(null, '', url);
                }
            } else {
                applyLiveMessages(data.messages, data.removed || []);
            }
            MessageSystem.changeCursor = data.cursor ?? null;
            more = data.more === true;
        }
        showEmptyConversation();
        updateScrollControls();
        scheduleRead();
    } catch {
        syncFailures++;
        timeline.page = null;
        updateScrollControls();
        updateConnectionStatus(navigator.onLine ? 'Reconnecting…' : 'Offline');
    } finally {
        syncing = false;
        if (!sessionExpired && !document.hidden && navigator.onLine) {
            const delay = more || (syncAgain && !syncFailures) ? 0 : Math.min(MESSAGE_POLL_INTERVAL * 2 ** syncFailures, 30000);
            syncTimer = setTimeout(loadNewMessages, delay);
        }
        syncAgain = false;
    }
}

function closeExpiredConversation(status) {
    sessionExpired = true;
    chatOutbox.pause();
    MessageSystem.initialLoadComplete = false;
    timeline.jump = timeline.page = null;
    document.getElementById('searchModal')?.close();
    document.querySelector('.media-viewer[open]')?.close();
    const input = document.getElementById('messageInput');
    input.value = '';
    input.disabled = true;
    document.getElementById('sendBtn').disabled = true;
    cancelReply();
    cancelMedia();
    readObserver.disconnect();
    visibleMessages.clear();
    messageRows.clear();
    const link = document.createElement('a');
    link.className = 'chat-sign-in';
    link.href = status === 409 ? '/messages/' : '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search);
    link.textContent = status === 409 ? 'Reopen Messages' : 'Sign in to continue';
    const list = document.getElementById('messagesList');
    list.replaceChildren(document.getElementById('earlierMessages'), link, document.getElementById('newerMessages'));
    timeline.hasOlder = timeline.hasNewer = false;
    updateScrollControls();
    updateConnectionStatus(status === 409 ? 'Account changed' : 'Session expired');
}

function loadHistory(direction) {
    if (timeline.jump || timeline.page || sessionExpired || !navigator.onLine) return;
    const before = direction === 'before';
    if (before ? !timeline.hasOlder : !timeline.hasNewer) return;
    const rows = document.getElementById('messagesList').querySelectorAll(':scope > [data-message-id]');
    const row = before ? rows[0] : rows[rows.length - 1];
    if (!row) return;
    timeline.page = { direction, id: row.dataset.messageId };
    updateScrollControls();
    loadNewMessages();
}

function openChatWindow(id, push = true) {
    timeline.jump = { id, push };
    timeline.page = null;
    if (!navigator.onLine) showError('Chat will open when you reconnect.');
    updateNativeChatVisibility();
    loadNewMessages();
}

function applyLiveMessages(messages, removed) {
    if (!messages.length && !removed.length) return;
    const list = document.getElementById('messagesList');
    const first = list.querySelector(':scope > [data-message-id]')?.dataset.messageId;
    const newest = previousMessage(document.getElementById('newerMessages'))?.dataset.messageId;
    const following = !timeline.hasNewer && isScrolledToBottom();
    let added = 0;
    const inWindow = messages.filter(message => {
        const id = BigInt(message.id);
        const key = String(message.id);
        if (id > timeline.latestId && String(message.user_id) !== String(MessageSystem.currentUserId)) added++;
        if (messageRows.has(key)) return true;
        if (timeline.hasNewer) {
            return first && newest && id >= BigInt(first) && id <= BigInt(newest);
        }
        return !first || id >= BigInt(first) || !timeline.hasOlder;
    });
    if (messages.length) {
        const mostRecent = BigInt(messages.at(-1).id);
        if (mostRecent > timeline.latestId) timeline.latestId = mostRecent;
    }
    if (!following && added) {
        pendingMessageCount += added;
        document.getElementById('chatAnnouncement').textContent = added === 1 ? 'New message' : `${added} new messages`;
    }
    displayMessages(inWindow, { removed, follow: following });
    if (!messageRows.size && (timeline.hasOlder || timeline.hasNewer)) openChatWindow(null, false);
}

// ============================================
// DATE SEPARATOR
// ============================================
function formatDateSeparator(date) {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const weekAgo = new Date(today);
    weekAgo.setDate(weekAgo.getDate() - 7);

    const msgDate = date;
    if (Number.isNaN(msgDate.getTime())) return 'Date unavailable';

    // Reset time parts for comparison
    const todayStr = today.toDateString();
    const yesterdayStr = yesterday.toDateString();
    const msgDateStr = msgDate.toDateString();

    if (msgDateStr === todayStr) {
        return 'Today';
    } else if (msgDateStr === yesterdayStr) {
        return 'Yesterday';
    } else if (msgDate > weekAgo) {
        // Within last week - show just the day name
        return msgDate.toLocaleDateString([], {
            weekday: 'long'
        });
    } else {
        // Older than a week - show full date
        return msgDate.toLocaleDateString([], {
            day: 'numeric',
            month: 'long',
            ...(msgDate.getFullYear() === today.getFullYear() ? {} : { year: 'numeric' })
        });
    }
}

function createDateSeparator(dateText) {
    const div = document.createElement('div');
    div.className = 'date-separator';
    const label = document.createElement('span');
    label.textContent = dateText;
    div.append(label);
    return div;
}

// ============================================
// DISPLAY MESSAGES
// ============================================
function displayMessages(messages, { replace = false, follow = false, removed = [] } = {}) {
    const list = document.getElementById('messagesList');
    const earlier = document.getElementById('earlierMessages');
    const newer = document.getElementById('newerMessages');
    const anchor = replace || follow ? null : currentScrollAnchor();
    if (replace) {
        readObserver.disconnect();
        visibleMessages.clear();
        messageRows.clear();
        list.replaceChildren(earlier, newer);
    }
    removed.forEach(removeMessageRow);
    for (const id of removed) {
        list.querySelectorAll(`[data-quote="${id}"]`).forEach(quote => {
            quote.disabled = true;
            quote.textContent = 'Message unavailable';
        });
    }
    if (messages.length) list.querySelector(':scope > .empty-state')?.remove();

    const ordered = [...list.querySelectorAll(':scope > [data-message-id]')];
    for (const message of messages) {
        const key = String(message.id);
        const existing = messageRows.get(key);
        if (existing) {
            if (existing.dataset.editCount !== String(message.edit_count ?? 0)
                || existing.dataset.editedAt !== (message.edited_at || '')
                || existing.dataset.pinned !== String(Boolean(Number(message.pinned)))) {
                const replacement = createMessageElement(message);
                unobserveMessage(existing);
                existing.replaceWith(replacement);
                observeMessage(replacement);
            } else if (existing.dataset.reactions !== reactionKey(message.reactions)) {
                updateReactionDOM(message.id, message.reactions);
                if (existing.classList.contains('own')) confirmedReads.delete(key);
            }
            continue;
        }

        const id = BigInt(key);
        let low = 0;
        let high = ordered.length;
        while (low < high) {
            const middle = (low + high) >>> 1;
            if (BigInt(ordered[middle].dataset.messageId) < id) low = middle + 1;
            else high = middle;
        }
        const next = ordered[low] ? messageRows.get(ordered[low].dataset.messageId) : null;
        const row = createMessageElement(message);
        const separator = next?.previousElementSibling;
        list.insertBefore(row, separator?.classList.contains('date-separator') ? separator : next || newer);
        observeMessage(row);
        updateDateSeparator(row);
        if (next) updateDateSeparator(next);
    }

    if (follow) scrollToBottom();
    else if (anchor && messageRows.has(anchor.id)) {
        list.scrollTop += messageRows.get(anchor.id).getBoundingClientRect().top - anchor.top;
    }
    updateScrollControls();
}

function previousMessage(element) {
    const previous = element.previousElementSibling;
    const candidate = previous?.classList.contains('date-separator') ? previous.previousElementSibling : previous;
    return candidate?.hasAttribute('data-message-id') ? candidate : null;
}

function updateDateSeparator(row) {
    const previous = row.previousElementSibling;
    const separator = previous?.classList.contains('date-separator') ? previous : null;
    if (previousMessage(row)?.dataset.day === row.dataset.day) {
        separator?.remove();
    } else if (!separator) {
        row.before(createDateSeparator(row.dataset.dayLabel));
    }
}

function observeMessage(row) {
    messageRows.set(row.dataset.messageId, row);
    readObserver.observe(row);
}

function removeMessageRow(id) {
    const row = messageRows.get(String(id));
    if (!row) return;
    const previous = row.previousElementSibling;
    const next = row.nextElementSibling;
    const following = next?.classList.contains('date-separator') ? next.nextElementSibling : next;
    if (previous?.classList.contains('date-separator')) previous.remove();
    unobserveMessage(row);
    row.remove();
    messageRows.delete(String(id));
    if (following?.hasAttribute('data-message-id')) updateDateSeparator(following);
}

function currentScrollAnchor() {
    const list = document.getElementById('messagesList');
    const viewport = list.getBoundingClientRect();
    const rows = list.querySelectorAll(':scope > [data-message-id]');
    let low = 0;
    let high = rows.length;
    while (low < high) {
        const middle = (low + high) >>> 1;
        if (rows[middle].getBoundingClientRect().bottom <= viewport.top) low = middle + 1;
        else high = middle;
    }
    const row = rows[low];
    const top = row?.getBoundingClientRect().top;
    return row && top < viewport.bottom ? { id: row.dataset.messageId, top } : null;
}

function messageDate(message) {
    return message.sent_timestamp == null
        ? new Date(message.created_at?.replace(' ', 'T') + '+02:00')
        : new Date(Number(message.sent_timestamp) * 1000);
}

// ============================================
// CREATE MESSAGE ELEMENT
// ============================================
function createMessageElement(msg) {
    const isOwn = msg.user_id == MessageSystem.currentUserId;
    const hasReactions = msg.reactions && msg.reactions.length > 0;
    const div = document.createElement('div');
    div.className = `message ${isOwn ? 'own' : ''} ${hasReactions ? 'has-reactions' : ''}`;
    div.dataset.messageId = msg.id;
    div.dataset.userId = msg.user_id;
    const msgDate = messageDate(msg);
    div.dataset.day = msgDate.toDateString();
    div.dataset.dayLabel = formatDateSeparator(msgDate);
    div.dataset.editedAt = msg.edited_at || '';
    div.dataset.editCount = String(msg.edit_count ?? 0);
    div.dataset.pinned = String(Boolean(Number(msg.pinned)));
    div.dataset.reactions = reactionKey(msg.reactions);

    // Avatar with image support
    const initials = escapeHtml(Array.from(msg.full_name).slice(0, 2).join('').toUpperCase());
    const avatarColor = /^#[a-f\d]{6}$/i.test(msg.avatar_color) ? msg.avatar_color : '#667eea';
    const avatarContent = msg.has_avatar
        ? `<img src="/saves/${msg.user_id}/avatar/avatar.webp?v=${msg.avatar_version ?? 0}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
           <span class="avatar-fallback" style="display:none; width:100%; height:100%; align-items:center; justify-content:center">${initials}</span>`
        : initials;

    const avatar = `
        <div class="message-avatar" aria-hidden="true" style="background: ${avatarColor}">
            ${avatarContent}
        </div>
    `;
    
    const quote = msg.reply_to_message_id
        ? `<button type="button" class="message-reply" data-quote="${msg.reply_to_message_id}" ${msg.reply_to_name ? '' : 'disabled'}>
                <strong>${escapeHtml(msg.reply_to_name || 'Message unavailable')}</strong>
                ${msg.reply_to_name ? escapeHtml(msg.reply_to_content || attachmentLabel(msg.reply_to_type)) : ''}
            </button>`
        : '';

    const actions = `
        <div class="message-actions">
            <button type="button" class="message-action-btn" data-reply-trigger aria-label="Reply" title="Reply">↩️</button>
            <button type="button" class="message-action-btn" data-reaction-trigger aria-label="React" aria-haspopup="dialog" title="React">😊</button>
            ${isOwn && window.enableEditMessage ? `<button class="message-action-btn" onclick="enableEditMessage(${msg.id})" title="Edit">✏️</button>` : ''}
            <button class="message-action-btn" onclick="showMessageOptions(${msg.id}, event)" title="More actions" aria-label="More actions" aria-controls="contextMenu" aria-expanded="false">⋮</button>
        </div>
    `;
    
    const time = Number.isNaN(msgDate.getTime()) ? '' : msgDate.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit'
    });
    
    const editedIndicator = msg.edited_at ? `<span class="edited-indicator" title="Edited at ${msg.edited_at}">(edited)</span>` : '';
    
    div.addEventListener('error', showUnavailableAttachment, true);
    div.innerHTML = `
        ${!isOwn ? avatar : ''}
        <div class="message-content">
            ${!isOwn ? `
                <div class="message-header">
                    <span class="message-author">${escapeHtml(msg.full_name)}</span>
                </div>
            ` : ''}
            <div class="message-bubble">
                ${quote}
                ${msg.content ? `<div class="message-text">${linkify(msg.content)}${editedIndicator}</div>` : ''}
                ${actions}
                <span class="message-time">${time}${isOwn ? ' <span title="Sent" aria-label="Sent">✓</span>' : ''}</span>
            </div>
        </div>
        ${isOwn ? avatar : ''}
    `;
    div.querySelector('[data-quote]')?.addEventListener('click', () => scrollToMessage(msg.reply_to_message_id));
    div.querySelector('[data-reply-trigger]').addEventListener('click', () => replyToMessage(
        msg.id, msg.full_name, msg.content || attachmentLabel(msg.message_type)
    ));
    if (msg.attachments?.length) {
        div.querySelector('.message-actions').before(renderAttachments(msg.attachments));
    }
    div.querySelector('[data-reaction-trigger]').addEventListener('click', () => showReactionPicker(msg.id));
    if (hasReactions) div.querySelector('.message-content').append(buildReactions(msg.id, msg.reactions));
    
    return div;
}

function attachmentLabel(type) {
    return { image: 'Photo', video: 'Video', voice: 'Voice message', audio: 'Audio', file: 'Attachment' }[type] || 'Message';
}

function attachmentUrl(path) {
    if (typeof path !== 'string' || !path.startsWith('/messages/api/media.php?')) return null;
    const url = new URL(path, location.origin);
    return url.pathname === '/messages/api/media.php' && url.origin === location.origin ? url : null;
}

function formatBytes(bytes) {
    if (bytes >= 1024 * 1024) return `${Number((bytes / (1024 * 1024)).toFixed(1))} MB`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${bytes} B`;
}

function attachmentDownload(path, name, label) {
    const link = document.createElement('a');
    const url = new URL(path);
    url.searchParams.set('download', '1');
    link.href = url.href;
    link.download = name;
    if (label) link.textContent = label;
    return link;
}

function renderAttachments(files) {
    const gallery = document.createElement('div');
    gallery.className = 'message-media' + (files.length > 1 ? ' message-media-multi' : '');
    for (const file of files) {
        const path = attachmentUrl(file.path);
        if (!path) continue;
        const kind = file.type.split('/')[0];
        if (kind === 'image') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'attachment-image';
            button.setAttribute('aria-label', 'View ' + file.name);
            const image = document.createElement('img');
            const thumbnail = attachmentUrl(file.thumbnail);
            image.src = thumbnail || path;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            if (thumbnail) image.dataset.fullImage = path.href;
            if (file.width && file.height) {
                image.width = file.width;
                image.height = file.height;
            }
            button.append(image);
            button.addEventListener('click', () => openMediaViewer(path, file.name));
            gallery.append(button);
        } else if (kind === 'video' || kind === 'audio') {
            const player = document.createElement(kind);
            player.src = path.href;
            player.controls = true;
            player.preload = 'none';
            if (kind === 'video') player.playsInline = true;
            player.setAttribute('aria-label', file.name);
            gallery.append(player);
        } else {
            const link = attachmentDownload(path, file.name);
            link.className = 'message-doc';
            const name = document.createElement('span');
            name.textContent = file.name;
            const size = document.createElement('small');
            size.textContent = file.size ? formatBytes(file.size) : '';
            link.append(name, size);
            gallery.append(link);
        }
    }
    return gallery;
}

// ============================================
// SEND MESSAGE
// ============================================
async function sendMessage() {
    const input = document.getElementById('messageInput');
    const content = input.value.trim();
    const reply = MessageSystem.replyToMessageId;
    const media = MessageSystem.mediaFiles.slice();
    const button = document.getElementById('sendBtn');
    if (button.disabled || (!content && !media.length) || sessionExpired) return;

    button.disabled = true;
    try {
        const saved = await chatOutbox.add(content, reply, media);
        if (!saved && !navigator.onLine) showError('Keep chat open until this message sends.');
        if (input.value.trim() === content) {
            input.value = '';
            input.style.height = '';
            window.saveChatDraft?.();
        }
        if (MessageSystem.replyToMessageId === reply) cancelReply();
        if (MessageSystem.mediaFiles.length === media.length
            && MessageSystem.mediaFiles.every((file, index) => file === media[index])) cancelMedia();
        clearTimeout(MessageSystem.typingTimeout);
        lastTypingPing = -Infinity;
        if (navigator.onLine) setTyping(false);
        if (timeline.hasNewer && navigator.onLine) openChatWindow(null);
        else scrollToBottom();
    } catch {
        showError('Could not queue this message. Try again.');
    } finally {
        button.disabled = sessionExpired;
    }
}

function renderPendingMessages() {
    if (sessionExpired) return;
    const list = document.getElementById('messagesList');
    const follow = !timeline.hasNewer && isScrolledToBottom();
    list.querySelector('#pendingMessages')?.remove();
    if (chatOutbox.items.size) {
        list.querySelector(':scope > .empty-state')?.remove();
        const queue = document.createElement('div');
        queue.id = 'pendingMessages';
        queue.className = 'pending-messages';

        for (const item of chatOutbox.items.values()) {
            const row = document.createElement('div');
            row.className = 'pending-message';
            const body = document.createElement('div');
            body.className = 'message-bubble';
            const message = document.createElement('div');
            message.className = 'message-text';
            message.textContent = [item.content, ...item.media.map(file => file.name)].filter(Boolean).join('\n');
            const status = document.createElement('div');
            status.className = 'pending-status';
            const sending = chatOutbox.sending === item.id;
            status.textContent = item.error || (sending ? 'Sending…' : 'Waiting to send');
            if (item.error) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.textContent = 'Retry';
                retry.addEventListener('click', () => chatOutbox.retry(item.id));
                status.append(retry);
            }
            if (!sending) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = 'Cancel';
                remove.addEventListener('click', () => chatOutbox.remove(item.id));
                status.append(remove);
            }
            body.append(message, status);
            row.append(body);
            queue.append(row);
        }
        list.append(queue);
        updateScrollControls();
    } else if (MessageSystem.initialLoadComplete) {
        showEmptyConversation();
    }
    if (follow) scrollToBottom();
}

// ============================================
// TYPING INDICATOR
// ============================================
function handleTyping() {
    if (!navigator.onLine || sessionExpired) return;
    clearTimeout(MessageSystem.typingTimeout);
    const now = performance.now();
    if (now - lastTypingPing > 2000) {
        lastTypingPing = now;
        setTyping(true);
    }
    MessageSystem.typingTimeout = setTimeout(() => {
        lastTypingPing = -Infinity;
        setTyping(false);
    }, 3500);
}

function setTyping(typing) {
    fetch('/messages/api/typing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.getElementById('sendBtn').dataset.csrf
        },
        body: JSON.stringify({ typing }), signal: AbortSignal.timeout(3000)
    }).catch(() => {});
}

function updateTypingIndicator(members) {
    const indicator = document.getElementById('typingIndicator');
    if (!members.length) {
        indicator.style.display = 'none';
        return;
    }
    const names = members.map(member => member.name).join(', ');
    const message = `${names} ${members.length === 1 ? 'is' : 'are'} typing…`;
    const label = indicator.querySelector('.typing-text');
    if (label.textContent !== message) label.textContent = message;
    indicator.style.display = 'flex';
}

// ============================================
// REACTIONS
// ============================================
function reactionKey(reactions = []) {
    return JSON.stringify(reactions.map(reaction => [String(reaction.user_id), reaction.emoji]));
}

function buildReactions(messageId, reactions) {
    const grouped = new Map();
    for (const { emoji, user_id } of reactions) {
        if (!grouped.has(emoji)) grouped.set(emoji, []);
        grouped.get(emoji).push(String(user_id));
    }
    const row = document.createElement('div');
    row.className = 'message-reactions';
    for (const [emoji, users] of grouped) {
        const own = users.includes(String(MessageSystem.currentUserId));
        const button = document.createElement('button');
        button.type = 'button';
        button.className = own ? 'reaction own' : 'reaction';
        button.dataset.emoji = emoji;
        button.textContent = emoji;
        button.setAttribute('aria-pressed', String(own));
        button.setAttribute('aria-label', `${emoji}, ${users.length} ${users.length === 1 ? 'reaction' : 'reactions'}`);
        if (users.length > 1) {
            const count = document.createElement('span');
            count.className = 'reaction-count';
            count.textContent = users.length;
            button.append(count);
        }
        button.addEventListener('click', () => toggleReaction(messageId, emoji));
        row.append(button);
    }
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'reaction reaction-add';
    more.textContent = '+';
    more.setAttribute('aria-label', 'Add reaction');
    more.setAttribute('aria-haspopup', 'dialog');
    more.addEventListener('click', () => showReactionPicker(messageId));
    row.append(more);
    return row;
}

function updateReactionDOM(messageId, reactions) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageEl) return;

    const contentEl = messageEl.querySelector('.message-content');
    if (!contentEl) return;
    messageEl.dataset.reactions = reactionKey(reactions);

    // Remove old reactions
    const oldReactions = messageEl.querySelector('.message-reactions');
    const active = oldReactions?.contains(document.activeElement) ? document.activeElement : null;
    if (oldReactions) oldReactions.remove();

    if (reactions && reactions.length > 0) {
        contentEl.append(buildReactions(messageId, reactions));
        messageEl.classList.add('has-reactions');
    } else {
        messageEl.classList.remove('has-reactions');
    }
    if (active) {
        const replacement = [...contentEl.querySelectorAll('.reaction')].find(button => button.dataset.emoji === active.dataset.emoji);
        (replacement || messageEl.querySelector('[data-reaction-trigger]'))?.focus({ preventScroll: true });
    }
}

const pendingReactions = new Set();
async function toggleReaction(messageId, emoji) {
    if (pendingReactions.has(messageId)) return;
    pendingReactions.add(messageId);
    try {
        const url = window.location.origin + '/messages/api/react.php';

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.getElementById('sendBtn').dataset.csrf },
            body: JSON.stringify({ message_id: messageId, emoji: emoji }),
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!response.ok || !data.success) throw new Error(data.message || 'Could not update the reaction.');
        updateReactionDOM(messageId, data.reactions || []);
    } catch (error) {
        showError(error.message || 'Could not update the reaction.');
    } finally {
        pendingReactions.delete(messageId);
    }
}

function showReactionPicker(messageId) {
    if (document.querySelector('.reaction-picker[open]')) return;
    const picker = document.createElement('dialog');
    picker.className = 'reaction-picker';
    picker.setAttribute('aria-label', 'Choose a reaction');

    const quickEmojis = ['👍', '❤️', '😂', '🔥'];
    const moreEmojis = ['🎉', '👏', '💯', '🤔', '😍', '🥳', '😭', '💀', '😮', '😢', '😡', '🙏'];

    const buttonFor = emoji => `<button type="button" class="reaction-picker-item" data-emoji="${emoji}">${emoji}</button>`;
    picker.innerHTML = `<div class="reaction-picker-row">${quickEmojis.map(buttonFor).join('')}
            <button type="button" class="reaction-picker-item reaction-picker-more" data-more aria-label="More reactions" aria-expanded="false" aria-controls="moreReactions">+</button>
            <button type="button" class="reaction-picker-item reaction-picker-more" data-close-reactions aria-label="Close reactions">×</button>
        </div><div id="moreReactions" class="reaction-picker-expanded" hidden>${moreEmojis.map(buttonFor).join('')}</div>`;
    picker.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (button?.hasAttribute('data-more')) {
            const more = picker.querySelector('#moreReactions');
            more.hidden = !more.hidden;
            button.setAttribute('aria-expanded', String(!more.hidden));
        } else if (button?.dataset.emoji) {
            toggleReaction(messageId, button.dataset.emoji);
            picker.close();
        } else if (event.target === picker || button?.hasAttribute('data-close-reactions')) {
            picker.close();
        }
    });
    picker.addEventListener('close', () => picker.remove(), { once: true });
    document.body.append(picker);
    picker.showModal();
}

// ============================================
// REPLY FUNCTIONALITY
// ============================================
function replyToMessage(messageId, name, text) {
    MessageSystem.replyToMessageId = messageId;
    showReplyPreview(name, text);
}

function showReplyPreview(name, text) {
    const preview = document.getElementById('replyPreview');
    document.getElementById('replyToName').textContent = name;
    document.getElementById('replyToText').textContent = text.substring(0, 100);
    preview.style.display = 'block';
    document.getElementById('messageInput').focus();
}

function cancelReply() {
    MessageSystem.replyToMessageId = null;
    document.getElementById('replyPreview').style.display = 'none';
}

// ============================================
// MEDIA HANDLING
// ============================================
// Helper: render file previews, revoking old Object URLs to prevent memory leaks
function renderFilePreviews() {
    const previewContent = document.getElementById('previewContent');

    // Revoke old Object URLs before clearing
    previewContent.querySelectorAll('.preview-item img, .preview-item video').forEach(el => {
        if (el.src && el.src.startsWith('blob:')) {
            URL.revokeObjectURL(el.src);
        }
    });
    previewContent.querySelectorAll('.preview-item').forEach(el => el.remove());

    MessageSystem.mediaFiles.forEach((file, i) => {
        const item = document.createElement('div');
        item.className = 'preview-item';

        if (file.type.startsWith('image/')) {
            item.innerHTML = `<img src="${URL.createObjectURL(file)}" alt="Preview">
                <button class="remove-file-btn" onclick="removeFile(${i})">✕</button>`;
        } else if (file.type.startsWith('video/')) {
            item.innerHTML = `<video src="${URL.createObjectURL(file)}" controls></video>
                <button class="remove-file-btn" onclick="removeFile(${i})">✕</button>`;
        } else {
            const ext = file.name.split('.').pop().toUpperCase();
            const icon = file.type === 'application/pdf' ? '📄' : '📎';
            item.innerHTML = `<div class="doc-preview">${icon}<span class="doc-name">${escapeHtml(file.name)}</span><span class="doc-size">${escapeHtml(ext)} · ${formatBytes(file.size)}</span></div>
                <button class="remove-file-btn" onclick="removeFile(${i})">✕</button>`;
        }
        previewContent.appendChild(item);
    });
}

function handleFileSelect(event) {
    const input = event.target;
    const added = Array.from(input.files);
    input.value = '';
    if (!added.length) return;

    const files = [...MessageSystem.mediaFiles, ...added];
    const fileLimit = Number(input.dataset.maxFileBytes);
    const requestLimit = Number(input.dataset.maxPostBytes);
    if (files.length > 10) {
        showError('Attach up to 10 files per message.');
        return;
    }
    const tooLarge = added.find(file => file.size > fileLimit);
    if (tooLarge) {
        showError(`${tooLarge.name} exceeds the ${formatBytes(fileLimit)} file limit.`);
        return;
    }
    if (requestLimit && files.reduce((total, file) => total + file.size, 65536) > requestLimit) {
        showError('These attachments are too large to send together.');
        return;
    }
    MessageSystem.mediaFiles = files;
    renderFilePreviews();
    document.getElementById('mediaPreview').style.display = 'block';
}

function removeFile(index) {
    MessageSystem.mediaFiles.splice(index, 1);
    if (MessageSystem.mediaFiles.length === 0) {
        cancelMedia();
    } else {
        renderFilePreviews();
    }
}

function cancelMedia() {
    // Revoke any remaining Object URLs
    const previewContent = document.getElementById('previewContent');
    previewContent.querySelectorAll('.preview-item img, .preview-item video').forEach(el => {
        if (el.src && el.src.startsWith('blob:')) {
            URL.revokeObjectURL(el.src);
        }
    });
    MessageSystem.mediaFiles = [];
    document.getElementById('mediaPreview').style.display = 'none';
    document.getElementById('fileInput').value = '';
}

function showUnavailableAttachment(event) {
    const media = event.target;
    if (!media.matches?.('img, video, audio')) return;
    const container = media.closest('.message-media, .media-viewer');
    if (!container) return;
    if (media.dataset.fullImage) {
        media.src = media.dataset.fullImage;
        delete media.dataset.fullImage;
        return;
    }

    const unavailable = document.createElement('p');
    unavailable.className = 'attachment-unavailable';
    unavailable.textContent = media.matches('img') ? 'Image unavailable' : 'Attachment unavailable';
    (media.closest('.attachment-image') || media).replaceWith(unavailable);
    container.querySelector('.media-viewer-actions')?.remove();
}

function openMediaViewer(path, name) {
    const viewer = document.createElement('dialog');
    viewer.className = 'media-viewer media-viewer-dialog';
    viewer.setAttribute('aria-label', name);
    viewer.addEventListener('error', showUnavailableAttachment, true);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'media-viewer-close';
    close.textContent = '×';
    close.setAttribute('aria-label', 'Close photo');
    close.addEventListener('click', () => viewer.close());

    const image = document.createElement('img');
    image.src = path.href;
    image.alt = name;
    const actions = document.createElement('div');
    actions.className = 'media-viewer-actions';
    const download = attachmentDownload(path, name, 'Download photo');
    download.className = 'media-action-btn';
    actions.append(download);
    viewer.append(close, image, actions);
    document.body.append(viewer);
    viewer.addEventListener('close', () => {
        viewer.remove();
        updateNativeChatVisibility();
        scheduleRead();
    }, { once: true });
    viewer.showModal();
    updateNativeChatVisibility();
}

// ============================================
// CONTEXT MENU
// ============================================
function showMessageOptions(messageId, event) {
    MessageSystem.contextMessageId = messageId;
    ActionPopup.toggle(document.getElementById('contextMenu'), event);
}

function contextReplyMessage() {
    var messageEl = document.querySelector('[data-message-id="' + MessageSystem.contextMessageId + '"]');
    var textEl = messageEl ? messageEl.querySelector('.message-text') : null;
    var nameEl = messageEl ? messageEl.querySelector('.message-author') : null;
    var text = textEl ? textEl.textContent : '';
    var name = nameEl ? nameEl.textContent : 'Someone';
    replyToMessage(MessageSystem.contextMessageId, name, text);
}

async function deleteMessage() {
    if (!MessageSystem.contextMessageId) return;
    
    if (!confirm('Delete this message?')) return;
    
    try {
        const url = window.location.origin + '/messages/api/delete.php';
        
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: MessageSystem.contextMessageId }),
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            loadNewMessages();
        } else {
            showError(data.message || 'Failed to delete message');
        }
    } catch (error) {
        console.error('Error deleting message:', error);
        showError('Failed to delete message');
    }
}

function copyMessage() {
    const row = messageRows.get(String(MessageSystem.contextMessageId));
    const text = row?.querySelector('.message-text')?.textContent;
    if (text) copyChatText(text, 'Message copied.');
}

function copyMessageLink() {
    const id = String(MessageSystem.contextMessageId);
    if (/^[1-9][0-9]*$/.test(id)) {
        copyChatText(new URL('/messages/?message=' + id, location.origin).href, 'Link copied.');
    }
}

async function copyChatText(text, success) {
    try {
        await navigator.clipboard.writeText(text);
        showNotification(success);
    } catch {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.cssText = 'position:fixed;left:-9999px';
        document.body.append(field);
        field.select();
        try {
            if (!document.execCommand('copy')) throw new Error('Copy failed');
            showNotification(success);
        } catch {
            showError('Could not copy to the clipboard.');
        } finally {
            field.remove();
        }
    }
}

// ============================================
// EMOJI PICKER - FIXED
// ============================================
function setupEmojiPicker() {
    var pickerBtn = document.getElementById('emojiPickerBtn');
    var picker = document.getElementById('emojiPicker');
    var input = document.getElementById('messageInput');
    var scrollArea = document.getElementById('emojiScrollArea');
    var searchInput = document.getElementById('emojiSearch');

    if (!pickerBtn || !picker || !input) {
        console.error('Emoji picker elements not found');
        return;
    }

    // Ensure picker starts closed
    picker.classList.remove('active');

    // Toggle picker on button click
    pickerBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        var isVisible = picker.classList.contains('active');
        if (isVisible) {
            picker.classList.remove('active');
        } else {
            picker.classList.add('active');
            if (searchInput) {
                searchInput.value = '';
                filterEmojis('');
            }
        }
    });

    // Handle emoji selection
    picker.addEventListener('click', function(e) {
        if (e.target.classList.contains('emoji-item')) {
            var emoji = e.target.dataset.emoji || e.target.textContent;
            insertEmoji(emoji);
        }
    });

    // Handle category navigation - scroll to section
    var catButtons = picker.querySelectorAll('.emoji-cat-btn');

    for (var i = 0; i < catButtons.length; i++) {
        (function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var category = btn.getAttribute('data-category');
                var section = document.getElementById('emoji-section-' + category);

                // Update active button
                for (var j = 0; j < catButtons.length; j++) {
                    catButtons[j].classList.remove('active');
                }
                btn.classList.add('active');

                // Scroll to section using scrollIntoView
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        })(catButtons[i]);
    }

    // Update active category on scroll
    if (scrollArea) {
        var scrollTimeout;
        scrollArea.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function() {
                var sections = scrollArea.querySelectorAll('.emoji-section');
                var scrollTop = scrollArea.scrollTop;
                var activeCategory = 'smileys';

                for (var k = 0; k < sections.length; k++) {
                    var sect = sections[k];
                    if (sect.offsetTop <= scrollTop + 60) {
                        activeCategory = sect.id.replace('emoji-section-', '');
                    }
                }

                for (var m = 0; m < catButtons.length; m++) {
                    if (catButtons[m].getAttribute('data-category') === activeCategory) {
                        catButtons[m].classList.add('active');
                    } else {
                        catButtons[m].classList.remove('active');
                    }
                }
            }, 100);
        });
    }

    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterEmojis(searchInput.value.toLowerCase());
        });
    }

    // Close picker when clicking outside
    document.addEventListener('click', function(e) {
        if (!picker.contains(e.target) && e.target !== pickerBtn) {
            picker.classList.remove('active');
        }
    });
}

function filterEmojis(query) {
    var sections = document.querySelectorAll('.emoji-section');
    var items = document.querySelectorAll('.emoji-item');

    if (!query) {
        // Show all sections and items
        sections.forEach(function(s) {
            s.style.display = 'block';
            var title = s.querySelector('.emoji-section-title');
            if (title) title.style.display = 'block';
        });
        items.forEach(function(i) { i.style.display = 'flex'; });
        return;
    }

    // Hide section titles when searching, show matching emojis
    sections.forEach(function(s) {
        var title = s.querySelector('.emoji-section-title');
        if (title) title.style.display = 'none';
        s.style.display = 'block';
    });

    // For now, show all emojis (proper search would need emoji keywords database)
    items.forEach(function(item) {
        item.style.display = 'flex';
    });
}

function insertEmoji(emoji) {
    var input = document.getElementById('messageInput');
    var cursorPos = input.selectionStart;
    var textBefore = input.value.substring(0, cursorPos);
    var textAfter = input.value.substring(cursorPos);

    input.value = textBefore + emoji + textAfter;
    input.focus();

    // Set cursor position after emoji
    var newPos = cursorPos + emoji.length;
    input.setSelectionRange(newPos, newPos);

    // Close picker
    document.getElementById('emojiPicker').classList.remove('active');
}

// ============================================
// EVENT LISTENERS
// ============================================
function setupEventListeners() {
    const input = document.getElementById('messageInput');
    const list = document.getElementById('messagesList');
    const familyButton = document.getElementById('familyMembersButton');
    const family = document.getElementById('familyMembers');
    const showFamily = open => {
        family.classList.toggle('is-open', open);
        familyButton.setAttribute('aria-expanded', String(open));
        updateNativeChatVisibility();
        scheduleRead();
    };
    familyButton.addEventListener('click', () => showFamily(!family.classList.contains('is-open')));
    document.addEventListener('click', event => {
        if (!family.contains(event.target) && !familyButton.contains(event.target)) showFamily(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && family.classList.contains('is-open')) {
            showFamily(false);
            familyButton.focus();
        }
    });
    matchMedia('(min-width: 900px)').addEventListener('change', () => showFamily(false));

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = input.scrollHeight + 'px';
        handleTyping();
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            sendMessage();
        }
    });

    readObserver = new IntersectionObserver(entries => {
        for (const entry of entries) {
            if (entry.isIntersecting) visibleMessages.add(entry.target);
            else visibleMessages.delete(entry.target);
        }
        scheduleRead();
    }, { root: list, threshold: 0.01 });

    let previousTop = list.scrollTop;
    list.addEventListener('scroll', () => {
        const top = list.scrollTop;
        const movedUp = top < previousTop;
        previousTop = top;
        followViewport = !timeline.hasNewer && isScrolledToBottom();
        updateScrollControls();
        if (movedUp && top < 140) loadHistory('before');
    }, { passive: true });

    document.getElementById('earlierMessages').addEventListener('click', () => loadHistory('before'));
    document.getElementById('newerMessages').addEventListener('click', () => loadHistory('after'));
    document.getElementById('latestMessages').addEventListener('click', () => {
        list.focus({ preventScroll: true });
        if (timeline.hasNewer) openChatWindow(null);
        else scrollToBottom();
    });

    new ResizeObserver(() => {
        if (followViewport && !timeline.hasNewer && !timeline.jump) scrollToBottom();
        updateScrollControls();
    }).observe(list);

    const viewport = window.visualViewport;
    const resize = () => {
        if (viewport.scale === 1) {
            document.body.style.setProperty('--chat-viewport-height', Math.round(viewport.height) + 'px');
        } else {
            document.body.style.removeProperty('--chat-viewport-height');
        }
    };
    if (viewport) {
        viewport.addEventListener('resize', resize);
        resize();
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            resumeChat();
        } else {
            clearTimeout(syncTimer);
            updateTypingIndicator([]);
            updateNativeChatVisibility();
        }
    });
    window.addEventListener('popstate', () => openChatWindow(linkedMessage(), false));
    window.addEventListener('online', resumeChat);
    window.addEventListener('relativesappresume', resumeChat);
    window.addEventListener('offline', () => updateConnectionStatus('Offline'));
    window.addEventListener('pageshow', event => { if (event.persisted) resumeChat(); });
    window.addEventListener('relativesnotification', () => {
        loadNewMessages();
        updateNativeChatVisibility(true);
    });
    navigator.serviceWorker?.addEventListener('message', event => {
        if (event.data?.type === 'relatives-open-message' && openChatAlert(event.data.id)) {
            event.ports[0]?.postMessage(true);
        }
    });
}

function resumeChat() {
    if (document.hidden) return;
    loadNewMessages();
    chatOutbox.resume();
    scheduleRead();
}

function scrollToBottom() {
    const list = document.getElementById('messagesList');
    list.scrollTop = list.scrollHeight;
    followViewport = !timeline.hasNewer;
    updateScrollControls();
}

function isScrolledToBottom() {
    const list = document.getElementById('messagesList');
    return list.scrollHeight - list.scrollTop - list.clientHeight < 48;
}

function updateScrollControls() {
    const earlier = document.getElementById('earlierMessages');
    const newer = document.getElementById('newerMessages');
    earlier.hidden = !timeline.hasOlder;
    newer.hidden = !timeline.hasNewer;
    earlier.disabled = Boolean(timeline.page || timeline.jump);
    newer.disabled = earlier.disabled;

    const button = document.getElementById('latestMessages');
    button.hidden = !timeline.hasNewer && isScrolledToBottom();
    if (button.hidden) pendingMessageCount = 0;
    button.textContent = pendingMessageCount
        ? `${pendingMessageCount} new ${pendingMessageCount === 1 ? 'message' : 'messages'} ↓`
        : 'Latest ↓';
    updateNativeChatVisibility();
}

function scrollToMessage(messageId) {
    const id = String(messageId);
    if (!/^[1-9][0-9]*$/.test(id)) return;
    if (!revealMessage(id, matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth')) {
        openChatWindow(id);
    }
}

function openChatAlert(id) {
    if (typeof id !== 'string' || !/^[1-9][0-9]{0,18}$/.test(id) || !MessageSystem.initialLoadComplete || sessionExpired) {
        return false;
    }
    document.getElementById('searchModal')?.close();
    document.querySelector('.media-viewer[open]')?.close();
    scrollToMessage(id);
    return true;
}

function revealMessage(id, behavior) {
    const row = messageRows.get(String(id));
    if (!row) return false;
    followViewport = false;
    row.scrollIntoView({ behavior, block: 'center' });
    row.classList.add('highlight');
    setTimeout(() => row.classList.remove('highlight'), 2000);
    return true;
}

function chatObscured() {
    return document.hidden || document.getElementById('familyMembers').classList.contains('is-open')
        || Boolean(document.querySelector('dialog[open], #searchModal, .modal-overlay.active'));
}

function updateNativeChatVisibility(force = false) {
    const visible = MessageSystem.initialLoadComplete && !timeline.jump && !timeline.hasNewer && !chatObscured() && isScrolledToBottom()
        && document.getElementById('connectionStatus').style.display === 'none';
    if (force || visible !== nativeChatVisible) {
        window.RelativesNative?.postMessage(visible ? 'messages_visible' : 'messages_hidden');
        nativeChatVisible = visible;
    }
}

function unobserveMessage(row) {
    readObserver.unobserve(row);
    visibleMessages.delete(row);
}

function scheduleRead() {
    if (readTimer || reportingReads || chatObscured() || !navigator.onLine || sessionExpired) return;
    readTimer = setTimeout(reportVisibleMessages, 250);
}

async function reportVisibleMessages() {
    readTimer = null;
    if (chatObscured() || !navigator.onLine || sessionExpired) return;
    const viewport = document.getElementById('messagesList').getBoundingClientRect();
    const ids = [...visibleMessages]
        .filter(row => {
            if (!row.isConnected || confirmedReads.has(row.dataset.messageId)) return false;
            const bounds = row.getBoundingClientRect();
            const visibleHeight = Math.min(bounds.bottom, viewport.bottom) - Math.max(bounds.top, viewport.top);
            return visibleHeight >= Math.min(24, bounds.height * .45);
        })
        .slice(0, 100).map(row => row.dataset.messageId);
    if (!ids.length) return;

    reportingReads = true;
    let recorded = false;
    try {
        const response = await fetch('/messages/api/read.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.getElementById('sendBtn').dataset.csrf },
            body: JSON.stringify({ message_ids: ids }), signal: AbortSignal.timeout(8000)
        });
        if (!response.ok) return;
        const { success, message_ids = [], notifications_read = 0 } = await response.json();
        if (!success) return;
        ids.forEach(id => confirmedReads.add(id));
        recorded = true;
        dismissChatAlerts(message_ids, notifications_read);
    } catch {
        // The next successful sync or connection change retries the read.
    } finally {
        reportingReads = false;
        if (recorded && ids.length === 100) scheduleRead();
    }
}

function dismissChatAlerts(ids, count) {
    if (count) window.HeaderMenu?.refreshNotifications();
    if (!ids.length) return;
    window.RelativesNative?.postMessage('messages_read:' + ids.join(','));
    navigator.serviceWorker?.getRegistration('/').then(registration => {
        registration?.active?.postMessage({ type: 'relatives-messages-read', ids });
    }).catch(() => {});
}

// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function linkify(text) {
    return text.split(/(https?:\/\/[^\s<>"']+)/g).map((part, index) => {
        if (index % 2 === 0) return escapeHtml(part);
        const link = document.createElement('a');
        link.href = part;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = part;
        return link.outerHTML;
    }).join('');
}

function showError(message) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.setAttribute('role', 'alert');
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 100);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showNotification(message) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 100);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 2000);
}

// Reuse a single AudioContext to avoid resource leaks
let _notificationAudioCtx = null;

function playNotificationSound() {
    try {
        if (!_notificationAudioCtx) {
            _notificationAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }

        if (_notificationAudioCtx.state === 'suspended') {
            _notificationAudioCtx.resume();
        }

        const oscillator = _notificationAudioCtx.createOscillator();
        const gainNode = _notificationAudioCtx.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(_notificationAudioCtx.destination);

        oscillator.frequency.value = 800;
        oscillator.type = 'sine';

        gainNode.gain.setValueAtTime(0.1, _notificationAudioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, _notificationAudioCtx.currentTime + 0.1);

        oscillator.start(_notificationAudioCtx.currentTime);
        oscillator.stop(_notificationAudioCtx.currentTime + 0.1);
    } catch (error) {
        console.warn('Audio playback failed:', error);
    }
}

// ============================================
// EXPOSE PUBLIC API
// ============================================
window.MessageSystem = MessageSystem;
window.loadNewMessages = loadNewMessages;
window.sendMessage = sendMessage;
window.toggleReaction = toggleReaction;
window.showReplyPreview = showReplyPreview;
window.cancelReply = cancelReply;
window.handleFileSelect = handleFileSelect;
window.cancelMedia = cancelMedia;
window.removeFile = removeFile;
window.insertEmoji = insertEmoji;
window.showMessageOptions = showMessageOptions;
window.contextReplyMessage = contextReplyMessage;
window.deleteMessage = deleteMessage;
window.copyMessage = copyMessage;
window.copyMessageLink = copyMessageLink;
window.scrollToMessage = scrollToMessage;
window.openChatAlert = openChatAlert;
window.openMediaViewer = openMediaViewer;
window.retryConnection = loadNewMessages;
window.updateConnectionStatus = updateConnectionStatus;
window.replyToMessage = replyToMessage;

console.log('✅ Core messaging system ready');
