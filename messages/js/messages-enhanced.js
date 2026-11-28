// FILE: messages/js/messages-enhanced.js

/**
 * ============================================
 * ENHANCED MESSAGING SYSTEM
 * Advanced features: Search, Edit, Bulk Operations
 * Export functionality REMOVED
 * Modular and conflict-free
 * ============================================
 */

// ============================================
// EXTENDED STATE MANAGEMENT
// ============================================
const EnhancedMessageApp = {
    // Selection & Bulk Operations
    selectedMessages: new Set(),
    bulkMode: false,
    
    // Editing
    editingMessageId: null
};

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Enhanced Messaging System initializing...');
    
    setupEnhancedEventListeners();
    addEnhancedToolbar();
    checkMessageLimit();
    
    console.log('✅ Enhanced features loaded');
});

// ============================================
// ENHANCED TOOLBAR (EXPORT REMOVED)
// ============================================
function addEnhancedToolbar() {
    const chat = document.getElementById('chatMain');
    if (!chat) return;

    // Add bulk actions bar (hidden by default)
    const bulkBar = document.createElement('div');
    bulkBar.id = 'bulkActionsBar';
    bulkBar.className = 'bulk-actions-bar';
    bulkBar.style.display = 'none';
    bulkBar.innerHTML = `
        <div class="bulk-info">
            <span id="bulkSelectionCount">0 selected</span>
            <button onclick="selectAllMessages()" class="bulk-action-btn">Select All</button>
            <button onclick="deselectAllMessages()" class="bulk-action-btn">Deselect All</button>
        </div>
        <div class="bulk-actions">
            <button onclick="performBulkAction('mark_read')" class="bulk-action-btn">
                ✓ Mark Read
            </button>
            <button onclick="performBulkAction('delete')" class="bulk-action-btn danger">
                🗑️ Delete
            </button>
        </div>
    `;
    
    chat.querySelector('.messages-toolbar').after(bulkBar);
}

// ============================================
// MESSAGE EDITING
// ============================================
function enableEditMessage(messageId) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageEl) return;
    
    const textEl = messageEl.querySelector('.message-text');
    if (!textEl) return;
    
    const currentText = textEl.textContent.replace('(edited)', '').trim();
    
    // Check ownership
    const messageUserId = messageEl.dataset.userId;
    if (messageUserId != window.MessageSystem.currentUserId) {
        showError('You can only edit your own messages');
        return;
    }
    
    // Create edit interface
    const editHtml = `
        <div class="message-edit-container">
            <textarea class="message-edit-input" maxlength="5000">${escapeHtml(currentText)}</textarea>
            <div class="message-edit-actions">
                <button onclick="saveEditedMessage(${messageId})" class="btn btn-primary btn-sm">💾 Save</button>
                <button onclick="cancelEdit(${messageId})" class="btn btn-sm">✖️ Cancel</button>
            </div>
            <div class="edit-info">Press Ctrl+Enter to save, Esc to cancel</div>
        </div>
    `;
    
    textEl.style.display = 'none';
    textEl.insertAdjacentHTML('afterend', editHtml);
    
    const textarea = messageEl.querySelector('.message-edit-input');
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    
    // Keyboard shortcuts
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            saveEditedMessage(messageId);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelEdit(messageId);
        }
    });
    
    EnhancedMessageApp.editingMessageId = messageId;
}

async function saveEditedMessage(messageId) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    const textarea = messageEl.querySelector('.message-edit-input');
    const newContent = textarea.value.trim();
    
    if (!newContent) {
        showError('Message cannot be empty');
        return;
    }
    
    try {
        const response = await fetch('/messages/api/edit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message_id: messageId,
                content: newContent
            }),
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update UI
            const textEl = messageEl.querySelector('.message-text');
            textEl.textContent = newContent;
            textEl.style.display = 'block';
            
            // Add edited indicator
            if (!messageEl.querySelector('.edited-indicator')) {
                const indicator = document.createElement('span');
                indicator.className = 'edited-indicator';
                indicator.textContent = '(edited)';
                indicator.title = 'Edited at ' + (data.edited_at || new Date().toISOString());
                textEl.appendChild(indicator);
            }
            
            // Remove edit interface
            messageEl.querySelector('.message-edit-container')?.remove();
            
            EnhancedMessageApp.editingMessageId = null;
            showSuccess('Message updated successfully');
            
        } else {
            showError(data.message || 'Failed to edit message');
        }
    } catch (error) {
        console.error('Error editing message:', error);
        showError('Failed to edit message');
    }
}

function cancelEdit(messageId) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    const textEl = messageEl.querySelector('.message-text');
    
    textEl.style.display = 'block';
    messageEl.querySelector('.message-edit-container')?.remove();
    
    EnhancedMessageApp.editingMessageId = null;
}

// ============================================
// SEARCH DIALOG
// ============================================
function openSearchDialog() {
    const open = document.getElementById('searchModal');
    if (open) {
        open.querySelector('#searchQuery').focus();
        return;
    }
    const dialog = document.createElement('dialog');
    dialog.id = 'searchModal';
    dialog.className = 'search-dialog';
    dialog.setAttribute('aria-labelledby', 'chatSearchTitle');
    dialog.innerHTML = `
        <div class="modal-header">
            <h2 id="chatSearchTitle">Search messages</h2>
            <button type="button" class="modal-close" aria-label="Close search">×</button>
        </div>
        <div class="modal-body">
            <form class="search-form">
                <input type="search" name="query" id="searchQuery" maxlength="200"
                       placeholder="Search messages or filenames" aria-label="Search messages or filenames" class="search-input">
                <details class="search-filters">
                    <summary>Filters</summary>
                    <label class="filter-group">From
                        <select name="user_id" class="filter-select"><option value="">Everyone</option></select>
                    </label>
                    <label class="filter-group">Type
                        <select name="type" class="filter-select">
                            <option value="">All types</option>
                            <option value="text">Text</option>
                            <option value="image">Photos</option>
                            <option value="video">Videos</option>
                            <option value="audio">Audio</option>
                            <option value="file">Files</option>
                        </select>
                    </label>
                    <label class="filter-group">From date
                        <input type="date" name="date_from" class="filter-input">
                    </label>
                    <label class="filter-group">To date
                        <input type="date" name="date_to" class="filter-input">
                    </label>
                    <label><input type="checkbox" name="has_media" value="1"> Only with attachments</label>
                </details>
                <button type="submit" class="btn btn-primary">Search</button>
            </form>
            <p id="chatSearchStatus" class="search-results-header" role="status"></p>
            <div id="searchResults" class="search-results"></div>
            <button type="button" class="search-more" hidden>More results</button>
        </div>`;

    const form = dialog.querySelector('form');
    const member = form.elements.user_id;
    member.add(new Option('You', MessageSystem.currentUserId));
    document.querySelectorAll('.member-item[data-user-id]').forEach(row => {
        member.add(new Option(row.querySelector('.member-name').textContent, row.dataset.userId));
    });

    const results = dialog.querySelector('#searchResults');
    const status = dialog.querySelector('#chatSearchStatus');
    const more = dialog.querySelector('.search-more');
    let request;
    let filters;
    let next;
    async function load(append = false) {
        request?.abort();
        const active = new AbortController();
        request = active;
        if (!append) {
            filters = new URLSearchParams();
            for (const [key, value] of new FormData(form)) if (value) filters.set(key, value);
            filters.set('account', `${MessageSystem.familyId}:${MessageSystem.currentUserId}`);
            results.replaceChildren();
        }
        more.hidden = true;
        status.textContent = 'Searching…';
        const params = new URLSearchParams(filters);
        if (append && next) params.set('before', next);
        const timeout = setTimeout(() => active.abort(), 8000);
        try {
            const response = await fetch('/messages/api/search.php?' + params, { cache: 'no-store', signal: active.signal });
            const data = await response.json();
            if (request !== active || !dialog.open) return;
            if (!response.ok || !data.success) throw new Error(data.error || 'Could not search messages.');
            const query = filters.get('query')?.trim();
            results.append(...data.results.map(message => searchResult(message, query, dialog)));
            status.textContent = data.total ? `${data.total} ${data.total === 1 ? 'match' : 'matches'}` : 'No messages found.';
            next = data.next_before;
            more.hidden = !next;
        } catch (error) {
            if (request !== active || !dialog.open) return;
            status.textContent = active.signal.aborted ? 'Search timed out. Try again.' : (error.message || 'Search unavailable.');
            more.hidden = !append;
        } finally {
            clearTimeout(timeout);
        }
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        load();
    });
    more.addEventListener('click', () => load(true));
    dialog.querySelector('.modal-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        request?.abort();
        dialog.remove();
        updateNativeChatVisibility();
        scheduleRead();
    }, { once: true });
    document.body.append(dialog);
    dialog.showModal();
    form.elements.query.focus();
    updateNativeChatVisibility();
}

function searchResult(message, query, dialog) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'search-result-item';
    const header = document.createElement('span');
    header.className = 'result-header';
    const name = document.createElement('strong');
    name.textContent = message.full_name;
    const date = document.createElement('span');
    date.className = 'result-date';
    date.textContent = messageDate(message).toLocaleString();
    header.append(name, date);
    button.append(header);
    const content = document.createElement('span');
    content.className = 'result-content';
    const text = message.excerpt || message.attachments.map(file => file.name).join(', ') || 'Message';
    if (query) {
        const literal = [...query].map(character => '\\^$.*+?()[]{}|'.includes(character) ? '\\' + character : character).join('');
        text.split(new RegExp(`(${literal})`, 'giu')).forEach((part, index) => {
            if (index % 2 === 0) content.append(part);
            else {
                const mark = document.createElement('mark');
                mark.textContent = part;
                content.append(mark);
            }
        });
    } else {
        content.textContent = text;
    }
    button.append(content);
    if (message.excerpt && message.attachments.length) {
        const attachments = document.createElement('span');
        attachments.className = 'result-media-indicator';
        attachments.textContent = message.attachments.map(file => file.name).join(', ');
        button.append(attachments);
    }
    button.addEventListener('click', () => {
        dialog.close();
        scrollToMessage(message.id);
    });
    return button;
}

// ============================================
// BULK OPERATIONS
// ============================================
function toggleBulkMode() {
    EnhancedMessageApp.bulkMode = !EnhancedMessageApp.bulkMode;
    
    const btn = document.getElementById('bulkModeBtn');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    
    if (EnhancedMessageApp.bulkMode) {
        btn.textContent = 'Cancel';
        btn.setAttribute('aria-pressed', 'true');
        bulkActionsBar.style.display = 'flex';
        
        // Add checkboxes to messages
        document.querySelectorAll('.message').forEach(msg => {
            if (!msg.querySelector('.bulk-select-checkbox')) {
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'bulk-select-checkbox';
                checkbox.dataset.messageId = msg.dataset.messageId;
                checkbox.addEventListener('change', handleBulkSelection);
                msg.insertBefore(checkbox, msg.firstChild);
            }
        });
    } else {
        btn.textContent = 'Select';
        btn.setAttribute('aria-pressed', 'false');
        bulkActionsBar.style.display = 'none';
        
        // Remove checkboxes
        document.querySelectorAll('.bulk-select-checkbox').forEach(cb => cb.remove());
        
        EnhancedMessageApp.selectedMessages.clear();
        updateBulkSelectionCount();
    }
}

function handleBulkSelection(e) {
    const messageId = parseInt(e.target.dataset.messageId);
    
    if (e.target.checked) {
        EnhancedMessageApp.selectedMessages.add(messageId);
    } else {
        EnhancedMessageApp.selectedMessages.delete(messageId);
    }
    
    updateBulkSelectionCount();
}

function selectAllMessages() {
    document.querySelectorAll('.bulk-select-checkbox').forEach(cb => {
        cb.checked = true;
        EnhancedMessageApp.selectedMessages.add(parseInt(cb.dataset.messageId));
    });
    updateBulkSelectionCount();
}

function deselectAllMessages() {
    document.querySelectorAll('.bulk-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    EnhancedMessageApp.selectedMessages.clear();
    updateBulkSelectionCount();
}

function updateBulkSelectionCount() {
    const countEl = document.getElementById('bulkSelectionCount');
    if (countEl) {
        countEl.textContent = `${EnhancedMessageApp.selectedMessages.size} selected`;
    }
}

async function performBulkAction(action) {
    if (EnhancedMessageApp.selectedMessages.size === 0) {
        showError('No messages selected');
        return;
    }
    
    const confirmMsg = `${action.replace('_', ' ')} ${EnhancedMessageApp.selectedMessages.size} message(s)?`;
    if (!confirm(confirmMsg)) return;
    
    try {
        const response = await fetch('/messages/api/bulk.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                message_ids: Array.from(EnhancedMessageApp.selectedMessages)
            }),
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (action === 'mark_read') {
                dismissChatAlerts(data.message_ids || [], data.notifications_read);
                showSuccess('Messages marked read.');
            } else {
                showSuccess(`${data.deleted_count} messages deleted.`);
            }

            toggleBulkMode(); // Exit bulk mode
            setTimeout(() => window.loadNewMessages(), 500);
        } else {
            showError(data.message || 'Bulk operation failed');
        }
    } catch (error) {
        console.error('Bulk operation error:', error);
        showError('Bulk operation failed');
    }
}

// ============================================
// MESSAGE LIMIT CHECKER
// ============================================
async function checkMessageLimit() {
    try {
        const response = await fetch('/messages/api/limit-status.php', {
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success && data.stats) {
            updateLimitIndicator(data.stats);
        }
    } catch (error) {
        console.error('Error checking message limit:', error);
    }
}

function updateLimitIndicator(stats) {
    let indicator = document.getElementById('messageLimitIndicator');
    
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'messageLimitIndicator';
        indicator.className = 'limit-indicator';
        document.querySelector('.messages-sidebar')?.appendChild(indicator);
    }
    
    const percent = stats.usage_percent || 0;
    let statusClass = 'normal';
    
    if (percent >= 90) statusClass = 'critical';
    else if (percent >= 75) statusClass = 'warning';
    
    indicator.className = `limit-indicator ${statusClass}`;
    indicator.innerHTML = `
        <div class="limit-header">Message storage</div>
        <div class="limit-bar">
            <div class="limit-fill" style="width: ${percent}%"></div>
        </div>
        <div class="limit-text">${stats.current_count || 0} / ${stats.max_messages || 10000} messages</div>
        ${stats.archived_count > 0 ? `<div class="limit-archived">${stats.archived_count} archived</div>` : ''}
    `;
}

// ============================================
// ENHANCED EVENT LISTENERS
// ============================================
function setupEnhancedEventListeners() {
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+F: Search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            openSearchDialog();
        }
        
        // Ctrl+B: Bulk mode
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            toggleBulkMode();
        }
        
        // Escape: Close modals
        if (e.key === 'Escape' && !document.getElementById('searchModal')) {
            if (EnhancedMessageApp.editingMessageId) {
                cancelEdit(EnhancedMessageApp.editingMessageId);
            }
        }
    });
    
    // Message input enhancements
    enhanceMessageInput();
}

function enhanceMessageInput() {
    const input = document.getElementById('messageInput');
    if (!input) return;
    
    const key = `messageDraft_${window.MessageSystem.familyId}:${window.MessageSystem.currentUserId}`;
    window.saveChatDraft = () => {
        try {
            if (input.value.trim()) localStorage.setItem(key, input.value);
            else localStorage.removeItem(key);
        } catch {
            // The composer still works with browser storage disabled.
        }
        updateCharacterCount(input.value.length);
    };
    input.addEventListener('input', window.saveChatDraft);
    try {
        input.value = localStorage.getItem(key) || '';
    } catch {
        return;
    }
    if (input.value) {
        input.style.height = input.scrollHeight + 'px';
        updateCharacterCount(input.value.length);
    }
}

function updateCharacterCount(count) {
    let counter = document.getElementById('charCounter');
    
    if (!counter) {
        counter = document.createElement('div');
        counter.id = 'charCounter';
        counter.className = 'char-counter';
        document.querySelector('.message-input-wrapper')?.appendChild(counter);
    }
    
    counter.hidden = count <= 4500;
    counter.textContent = `${count}/5000`;
    
    if (count > 4500) {
        counter.classList.add('warning');
    } else {
        counter.classList.remove('warning');
    }
}

// ============================================
// ENHANCED MESSAGE CREATION
// ============================================
if (typeof window.createMessageElement === 'function') {
    const originalCreateMessageElement = window.createMessageElement;
    window.createMessageElement = function(msg) {
        const element = originalCreateMessageElement(msg);
        
        // Add edit button for own messages
        if (msg.user_id == window.MessageSystem.currentUserId) {
            const actions = element.querySelector('.message-actions');
            if (actions && !actions.querySelector('[onclick*="enableEditMessage"]')) {
                const editBtn = document.createElement('button');
                editBtn.className = 'message-action-btn';
                editBtn.title = 'Edit';
                editBtn.innerHTML = '✏️';
                editBtn.onclick = () => enableEditMessage(msg.id);
                actions.insertBefore(editBtn, actions.lastElementChild);
            }
        }
        
        return element;
    };
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

function showSuccess(message) {
    showToast(message, 'success');
}

function showError(message) {
    showToast(message, 'error');
}

function showWarning(message) {
    showToast(message, 'warning');
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// EXPOSE PUBLIC API
// ============================================
window.EnhancedMessageApp = EnhancedMessageApp;
window.enableEditMessage = enableEditMessage;
window.saveEditedMessage = saveEditedMessage;
window.cancelEdit = cancelEdit;
window.openSearchDialog = openSearchDialog;
window.toggleBulkMode = toggleBulkMode;
window.selectAllMessages = selectAllMessages;
window.deselectAllMessages = deselectAllMessages;
window.performBulkAction = performBulkAction;
window.checkMessageLimit = checkMessageLimit;

console.log('✅ Enhanced messaging features ready');
