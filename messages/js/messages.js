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
    lastMessageId: 0,
    changeCursor: null,
    initialLoadComplete: false,
    replyToMessageId: null,
    contextMessageId: null,
    mediaFiles: [],
    typingTimeout: null,
    typingInterval: null,
};

const MESSAGE_POLL_INTERVAL = 3000;
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
const visibleMessages = new Set();
const confirmedReads = new Set();
let nativeChatVisible = null;

document.addEventListener('DOMContentLoaded', () => {
    MessageSystem.currentUserId = document.getElementById('currentUserId').value;
    MessageSystem.currentUserName = document.getElementById('currentUserName').value;
    MessageSystem.familyId = document.getElementById('familyId').value;
    chatOutbox = new ChatOutbox(
        `${MessageSystem.familyId}:${MessageSystem.currentUserId}`,
        document.getElementById('sendBtn').dataset.csrf,
        renderPendingMessages,
        message => {
            if (message) displayMessages([message]);
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
    if (!list.firstElementChild) {
        list.innerHTML = '<div class="empty-state"><h3>No messages yet</h3></div>';
    }
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
    const snapshot = !MessageSystem.initialLoadComplete;
    let more = false;
    try {
        const focus = new URLSearchParams(location.search).get('message');
        const params = new URLSearchParams();
        if (snapshot) {
            if (/^\d+$/.test(focus)) params.set('message', focus);
        } else if (MessageSystem.changeCursor === null) {
            params.set('since', MessageSystem.lastMessageId);
        } else {
            params.set('cursor', MessageSystem.changeCursor);
        }
        const response = await fetch('/messages/api/fetch.php?' + params, {
            credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(8000)
        });
        if (response.status === 401 || response.status === 403) {
            sessionExpired = true;
            MessageSystem.initialLoadComplete = false;
            const link = document.createElement('a');
            link.className = 'chat-sign-in';
            link.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search);
            link.textContent = 'Sign in to continue';
            const list = document.getElementById('messagesList');
            list.replaceChildren(link);
            updateConnectionStatus('Session expired');
            return;
        }
        if (!response.ok) throw new Error('Chat sync failed');
        const data = await response.json();
        if (!data.success) throw new Error('Chat sync failed');
        if (data.reset) {
            MessageSystem.initialLoadComplete = false;
            more = true;
            return;
        }

        syncFailures = 0;
        MessageSystem.initialLoadComplete = true;
        MessageSystem.changeCursor = data.cursor ?? null;
        updateConnectionStatus('');
        const list = document.getElementById('messagesList');
        for (const id of data.removed ?? []) {
            const row = list.querySelector(`[data-message-id="${id}"]`);
            if (row) {
                unobserveMessage(row);
                row.remove();
            }
        }
        if (snapshot || data.messages.length || data.removed?.length) {
            displayMessages(data.messages, snapshot, true, Boolean(data.removed?.length));
        }
        if (snapshot) renderPendingMessages();
        showEmptyConversation();
        if (data.messages.length) {
            MessageSystem.lastMessageId = Math.max(MessageSystem.lastMessageId, ...data.messages.map(message => message.id));
        }
        if (snapshot && params.has('message')) scrollToMessage(focus, { navigate: false, behavior: 'auto' });
        more = data.more === true;
        scheduleRead();
    } catch {
        syncFailures++;
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

// ============================================
// DATE SEPARATOR
// ============================================
function formatDateSeparator(date) {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const weekAgo = new Date(today);
    weekAgo.setDate(weekAgo.getDate() - 7);

    const msgDate = new Date(date.replace(' ', 'T'));

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
            month: 'long'
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
function displayMessages(messages, clearFirst = false, applyEdits = false, removed = false) {
    const list = document.getElementById('messagesList');
    if (clearFirst) {
        readObserver.disconnect();
        visibleMessages.clear();
        list.replaceChildren();
        pendingMessageCount = 0;
    }
    if (messages.length) list.querySelector(':scope > .empty-state')?.remove();

    const follow = clearFirst || isScrolledToBottom();
    const rows = [...list.querySelectorAll(':scope > [data-message-id]')];
    const lastId = rows.at(-1)?.dataset.messageId || '0';
    const pending = list.querySelector('#pendingMessages');
    let changed = clearFirst || removed;
    let added = 0;

    for (const message of messages) {
        const existing = list.querySelector(`[data-message-id="${message.id}"]`);
        if (existing) {
            if (applyEdits && (existing.dataset.editedAt !== (message.edited_at || '')
                || existing.dataset.editCount !== String(message.edit_count ?? 0)
                || existing.dataset.pinned !== String(Boolean(Number(message.pinned))))) {
                const replacement = createMessageElement(message);
                unobserveMessage(existing);
                existing.replaceWith(replacement);
                readObserver.observe(replacement);
            } else if (applyEdits && existing.dataset.reactions !== reactionKey(message.reactions)) {
                updateReactionDOM(message.id, message.reactions);
            }
            continue;
        }

        const row = createMessageElement(message);
        const later = rows.find(item => BigInt(item.dataset.messageId) > BigInt(message.id));
        list.insertBefore(row, later || pending);
        readObserver.observe(row);
        changed = true;
        if (BigInt(message.id) > BigInt(lastId) && !row.classList.contains('own')) added++;
    }

    if (changed) {
        list.querySelectorAll(':scope > .date-separator').forEach(separator => separator.remove());
        let day = null;
        for (const row of list.querySelectorAll(':scope > [data-message-id]')) {
            if (row.dataset.day === day) continue;
            day = row.dataset.day;
            list.insertBefore(createDateSeparator(formatDateSeparator(row.dataset.createdAt)), row);
        }
    }
    if (follow) {
        scrollToBottom();
    } else if (added) {
        pendingMessageCount += added;
        document.getElementById('chatAnnouncement').textContent = added === 1 ? 'New message' : `${added} new messages`;
    }
    updateScrollControls();
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
    div.dataset.createdAt = msg.created_at;
    div.dataset.day = new Date(msg.created_at.replace(' ', 'T')).toDateString();
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
    
    let replyHtml = '';
    if (msg.reply_to_message_id) {
        replyHtml = `
            <div class="message-reply" onclick="scrollToMessage(${msg.reply_to_message_id})">
                ↩️ Replying to: ${escapeHtml(msg.reply_to_content || 'Message')}
            </div>
        `;
    }
    
    let mediaHtml = '';
    if (msg.media_path) {
        // Try to detect multi-file JSON (type 'file' with JSON array, or legacy 'multi')
        let isMulti = false;
        if (msg.message_type === 'file' || msg.message_type === 'multi') {
            try {
                const parsed = typeof msg.media_path === 'string' ? JSON.parse(msg.media_path) : msg.media_path;
                if (Array.isArray(parsed)) {
                    isMulti = true;
                    mediaHtml = '<div class="message-media message-media-multi">';
                    parsed.forEach(f => {
                        if (f.type && f.type.startsWith('image/')) {
                            mediaHtml += `<img src="${f.path}" alt="${escapeHtml(f.name).replace(/"/g, '&quot;')}" loading="lazy" onclick="openMediaViewer('${f.path}', 'image')">`;
                        } else if (f.type && f.type.startsWith('video/')) {
                            mediaHtml += `<video src="${f.path}" controls></video>`;
                        } else if (f.type && f.type.startsWith('audio/')) {
                            mediaHtml += `<audio src="${f.path}" controls></audio>`;
                        } else {
                            const ext = f.name ? f.name.split('.').pop().toUpperCase() : 'FILE';
                            const icon = (f.type === 'application/pdf') ? '📄' : '📎';
                            const size = f.size ? (f.size / 1024).toFixed(0) + 'KB' : '';
                            mediaHtml += `<a href="${f.path}" target="_blank" rel="noopener" class="message-doc">${icon} <span>${escapeHtml(f.name)}</span><small>${escapeHtml(ext)} ${size}</small></a>`;
                        }
                    });
                    mediaHtml += '</div>';
                }
            } catch (e) {
                // Not JSON - fall through to single file handling below
            }
        }

        if (!isMulti) {
            if (msg.message_type === 'image') {
                mediaHtml = `
                    <div class="message-media">
                        <img src="${msg.media_path}" alt="Image" loading="lazy" onclick="openMediaViewer('${msg.media_path}', 'image')">
                    </div>
                `;
            } else if (msg.message_type === 'video') {
                mediaHtml = `
                    <div class="message-media">
                        <video src="${msg.media_path}" controls onclick="openMediaViewer('${msg.media_path}', 'video')"></video>
                    </div>
                `;
            } else if (msg.message_type === 'voice' || msg.message_type === 'audio') {
                mediaHtml = `
                    <div class="message-media">
                        <audio src="${msg.media_path}" controls></audio>
                    </div>
                `;
            } else if (msg.message_type === 'file' || msg.message_type === 'document') {
                const fileName = msg.media_path.split('/').pop();
                const ext = fileName.split('.').pop().toUpperCase();
                const icon = ext === 'PDF' ? '📄' : '📎';
                mediaHtml = `
                    <div class="message-media">
                        <a href="${msg.media_path}" target="_blank" class="message-doc">${icon} <span>${escapeHtml(fileName)}</span><small>${ext}</small></a>
                    </div>
                `;
            } else {
                // Fallback: detect type from file extension so media always renders
                const path = msg.media_path.toLowerCase();
                if (path.match(/\.(jpg|jpeg|png|gif|webp)(\?|$)/)) {
                    mediaHtml = `
                        <div class="message-media">
                            <img src="${msg.media_path}" alt="Image" loading="lazy" onclick="openMediaViewer('${msg.media_path}', 'image')">
                        </div>
                    `;
                } else if (path.match(/\.(mp4|mov|webm)(\?|$)/)) {
                    mediaHtml = `
                        <div class="message-media">
                            <video src="${msg.media_path}" controls onclick="openMediaViewer('${msg.media_path}', 'video')"></video>
                        </div>
                    `;
                } else if (path.match(/\.(mp3|ogg|wav)(\?|$)/)) {
                    mediaHtml = `
                        <div class="message-media">
                            <audio src="${msg.media_path}" controls></audio>
                        </div>
                    `;
                } else {
                    const fileName = msg.media_path.split('/').pop();
                    const ext = fileName.split('.').pop().toUpperCase();
                    const icon = ext === 'PDF' ? '📄' : '📎';
                    mediaHtml = `
                        <div class="message-media">
                            <a href="${msg.media_path}" target="_blank" class="message-doc">${icon} <span>${escapeHtml(fileName)}</span><small>${ext}</small></a>
                        </div>
                    `;
                }
            }
        }
    }
    
    const actions = `
        <div class="message-actions">
            <button type="button" class="message-action-btn" data-reply-trigger aria-label="Reply" title="Reply">↩️</button>
            <button type="button" class="message-action-btn" data-reaction-trigger aria-label="React" aria-haspopup="dialog" title="React">😊</button>
            ${isOwn && window.enableEditMessage ? `<button class="message-action-btn" onclick="enableEditMessage(${msg.id})" title="Edit">✏️</button>` : ''}
            <button class="message-action-btn" onclick="showMessageOptions(${msg.id}, event)" title="More actions" aria-label="More actions" aria-controls="contextMenu" aria-expanded="false">⋮</button>
        </div>
    `;
    
    const msgDate = new Date(msg.created_at.replace(' ', 'T'));
    const time = msgDate.toLocaleTimeString([], {
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
                ${replyHtml}
                ${msg.content ? `<div class="message-text">${linkify(msg.content)}${editedIndicator}</div>` : ''}
                ${mediaHtml}
                ${actions}
                <span class="message-time">${time}${isOwn ? ' <span title="Sent" aria-label="Sent">✓</span>' : ''}</span>
            </div>
        </div>
        ${isOwn ? avatar : ''}
    `;
    div.querySelector('[data-reply-trigger]').addEventListener('click', () => replyToMessage(msg.id, msg.full_name, msg.content || ''));
    div.querySelector('[data-reaction-trigger]').addEventListener('click', () => showReactionPicker(msg.id));
    if (hasReactions) div.querySelector('.message-content').append(buildReactions(msg.id, msg.reactions));
    
    return div;
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
        scrollToBottom();
    } catch {
        showError('Could not queue this message. Try again.');
    } finally {
        button.disabled = false;
    }
}

function renderPendingMessages() {
    if (sessionExpired) return;
    const list = document.getElementById('messagesList');
    const follow = isScrolledToBottom();
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
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ typing }), signal: AbortSignal.timeout(3000)
    }).catch(() => {});
}

async function checkTypingStatus() {
    if (!MessageSystem.initialLoadComplete || document.hidden || !navigator.onLine) return;

    try {
        const url = window.location.origin + '/messages/api/typing.php?t=' + Date.now();
        
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: AbortSignal.timeout(3000)
        });
        const data = await response.json();
        
        const indicator = document.getElementById('typingIndicator');
        
        if (data.typing && data.typing.length > 0) {
            const names = data.typing.map(t => t.name).join(', ');
            indicator.querySelector('.typing-text').textContent = 
                `${names} ${data.typing.length === 1 ? 'is' : 'are'} typing...`;
            indicator.style.display = 'flex';
        } else {
            indicator.style.display = 'none';
        }
    } catch (error) {
        // Silently fail
    }
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
            item.innerHTML = `<div class="doc-preview">${icon}<span class="doc-name">${escapeHtml(file.name)}</span><span class="doc-size">${ext} · ${(file.size / 1024).toFixed(0)}KB</span></div>
                <button class="remove-file-btn" onclick="removeFile(${i})">✕</button>`;
        }
        previewContent.appendChild(item);
    });
}

function handleFileSelect(event) {
    const files = Array.from(event.target.files);
    if (!files.length) return;

    // Max 10 files
    const newFiles = files.slice(0, 10 - MessageSystem.mediaFiles.length);
    if (newFiles.length === 0) {
        showError('Maximum 10 files allowed');
        return;
    }
    MessageSystem.mediaFiles.push(...newFiles);

    renderFilePreviews();

    document.getElementById('mediaPreview').style.display = 'block';
    // Reset input so same file can be re-selected
    event.target.value = '';
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
    const container = media.closest('.message-media, .media-viewer');
    if (!container || !media.matches('img, video, audio')) return;

    const unavailable = document.createElement('p');
    unavailable.className = 'attachment-unavailable';
    unavailable.textContent = media.matches('img') ? 'Image unavailable' : 'Attachment unavailable';
    media.replaceWith(unavailable);
    container.querySelector('.media-viewer-actions')?.remove();
}

function openMediaViewer(mediaPath, type) {
    const viewer = document.createElement('div');
    viewer.className = 'media-viewer-overlay';
    viewer.addEventListener('error', showUnavailableAttachment, true);
    viewer.innerHTML = `
        <div class="media-viewer">
            <button onclick="this.parentElement.parentElement.remove()" class="media-viewer-close">✖️</button>
            ${type === 'image' ?
                `<img src="${mediaPath}" alt="Full size image">` :
                `<video src="${mediaPath}" controls autoplay></video>`
            }
            <div class="media-viewer-actions">
                <button onclick="downloadMedia('${mediaPath}')" class="media-action-btn">⬇️ Download</button>
            </div>
        </div>
    `;

    document.body.appendChild(viewer);

    viewer.addEventListener('click', function(e) {
        if (e.target === viewer) {
            viewer.remove();
        }
    });
}

// Download media function that works on mobile
async function downloadMedia(url) {
    try {
        // Show loading state
        const btn = document.querySelector('.media-viewer-actions .media-action-btn');
        if (btn) {
            btn.textContent = '⏳ Downloading...';
            btn.disabled = true;
        }

        const response = await fetch(url);
        if (!response.ok) throw new Error('Download failed');

        const blob = await response.blob();
        const blobUrl = window.URL.createObjectURL(blob);

        // Extract filename from URL
        const filename = url.split('/').pop() || 'download';

        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Cleanup blob URL after a delay
        setTimeout(() => window.URL.revokeObjectURL(blobUrl), 1000);

        // Reset button
        if (btn) {
            btn.textContent = '✅ Downloaded';
            setTimeout(() => {
                btn.textContent = '⬇️ Download';
                btn.disabled = false;
            }, 2000);
        }
    } catch (error) {
        console.error('Download error:', error);

        // Fallback: open in new tab (mobile can then long-press to save)
        window.open(url, '_blank');

        const btn = document.querySelector('.media-viewer-actions .media-action-btn');
        if (btn) {
            btn.textContent = '⬇️ Download';
            btn.disabled = false;
        }
    }
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
            var msgToRemove = document.querySelector('[data-message-id="' + MessageSystem.contextMessageId + '"]');
            if (msgToRemove) msgToRemove.parentNode.removeChild(msgToRemove);
        } else {
            showError(data.message || 'Failed to delete message');
        }
    } catch (error) {
        console.error('Error deleting message:', error);
        showError('Failed to delete message');
    }
}

function copyMessage() {
    var messageEl = document.querySelector('[data-message-id="' + MessageSystem.contextMessageId + '"]');
    var textEl = messageEl ? messageEl.querySelector('.message-text') : null;
    var text = textEl ? textEl.textContent : null;
    if (text) {
        // Try modern clipboard API first, then fallback to execCommand
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showNotification('Message copied!');
            }).catch(function() {
                copyTextFallback(text);
            });
        } else {
            copyTextFallback(text);
        }
    }
}

function copyTextFallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    ta.setAttribute('readonly', '');
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, 99999);
    var success = false;
    try {
        success = document.execCommand('copy');
    } catch (e) {
        success = false;
    }
    document.body.removeChild(ta);
    if (success) {
        showNotification('Message copied!');
    } else {
        showNotification('Could not copy message');
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

    list.addEventListener('scroll', updateScrollControls, { passive: true });
    document.getElementById('latestMessages').addEventListener('click', () => {
        list.focus({ preventScroll: true });
        scrollToBottom();
    });

    let followLayout = true;
    list.addEventListener('scroll', () => { followLayout = isScrolledToBottom(); }, { passive: true });
    new ResizeObserver(() => {
        if (followLayout) scrollToBottom();
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
            clearInterval(MessageSystem.typingInterval);
            MessageSystem.typingInterval = null;
            updateNativeChatVisibility();
        }
    });
    window.addEventListener('online', resumeChat);
    window.addEventListener('relativesappresume', resumeChat);
    window.addEventListener('offline', () => updateConnectionStatus('Offline'));
    window.addEventListener('pageshow', event => { if (event.persisted) resumeChat(); });
    window.addEventListener('relativesnotification', () => {
        loadNewMessages();
        updateNativeChatVisibility(true);
    });
}

function resumeChat() {
    if (document.hidden) return;
    loadNewMessages();
    chatOutbox.resume();
    scheduleRead();
    if (!MessageSystem.typingInterval) {
        MessageSystem.typingInterval = setInterval(checkTypingStatus, 4000);
    }
}

function scrollToBottom() {
    const list = document.getElementById('messagesList');
    list.scrollTop = list.scrollHeight;
    updateScrollControls();
}

function isScrolledToBottom() {
    const list = document.getElementById('messagesList');
    return list.scrollHeight - list.scrollTop - list.clientHeight < 48;
}

function updateScrollControls() {
    const button = document.getElementById('latestMessages');
    button.hidden = isScrolledToBottom();
    if (button.hidden) pendingMessageCount = 0;
    button.textContent = pendingMessageCount
        ? `${pendingMessageCount} new ${pendingMessageCount === 1 ? 'message' : 'messages'} ↓`
        : 'Latest ↓';
    updateNativeChatVisibility();
}

function scrollToMessage(messageId, { navigate = true, behavior = 'smooth' } = {}) {
    if (!/^\d+$/.test(String(messageId))) return;
    const element = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!element) {
        if (navigate) location.assign('/messages/?message=' + messageId);
        else showError('This message is no longer available.');
        return;
    }
    element.scrollIntoView({ behavior, block: 'center' });
    element.classList.add('highlight');
    setTimeout(() => element.classList.remove('highlight'), 2000);
}

function chatObscured() {
    return document.hidden || document.getElementById('familyMembers').classList.contains('is-open')
        || Boolean(document.querySelector('dialog[open], .modal-overlay.active'));
}

function updateNativeChatVisibility(force = false) {
    const visible = MessageSystem.initialLoadComplete && !chatObscured() && isScrolledToBottom()
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
            return bounds.bottom > viewport.top && bounds.top < viewport.bottom;
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
window.scrollToMessage = scrollToMessage;
window.openMediaViewer = openMediaViewer;
window.retryConnection = loadNewMessages;
window.updateConnectionStatus = updateConnectionStatus;
window.replyToMessage = replyToMessage;

console.log('✅ Core messaging system ready');
