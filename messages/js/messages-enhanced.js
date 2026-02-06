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
    editingMessageId: null,
    
    // Search
    searchResults: [],
    
    // Cache & Queue
    messageCache: new Map(),
    offlineQueue: [],
    statusUpdateQueue: [],
    
    // Feature Flags
    features: {
        editing: true,
        search: true,
        bulkOps: true,
        statusTracking: true
    }
};

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Enhanced Messaging System initializing...');
    
    setupEnhancedEventListeners();
    addEnhancedToolbar();
    initOfflineSupport();
    checkMessageLimit();
    
    console.log('✅ Enhanced features loaded');
});

// ============================================
// ENHANCED TOOLBAR (EXPORT REMOVED)
// ============================================
function addEnhancedToolbar() {
    const toolbar = document.createElement('div');
    toolbar.className = 'messages-toolbar';
    toolbar.innerHTML = `
        <button onclick="openSearchDialog()" class="toolbar-btn" title="Search (Ctrl+F)">
            🔍 Search
        </button>
        <button onclick="toggleBulkMode()" id="bulkModeBtn" class="toolbar-btn" title="Bulk Operations (Ctrl+B)">
            ☑️ Select
        </button>
    `;
    
    const chatMain = document.querySelector('.chat-main');
    if (chatMain) {
        chatMain.insertBefore(toolbar, chatMain.firstChild);
    }
    
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
    
    toolbar.insertAdjacentElement('afterend', bulkBar);
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
                <button onclick="saveEditedMessage(${messageId})" class="btn-save">💾 Save</button>
                <button onclick="cancelEdit(${messageId})" class="btn-cancel">✖️ Cancel</button>
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
    const dialogHtml = `
        <div class="modal-overlay" id="searchModal">
            <div class="modal-dialog search-dialog">
                <div class="modal-header">
                    <h3>🔍 Search Messages</h3>
                    <button onclick="closeSearchDialog()" class="modal-close">✖️</button>
                </div>
                <div class="modal-body">
                    <div class="search-form">
                        <input type="text" id="searchQuery" placeholder="Search messages..." class="search-input">
                        
                        <div class="search-filters">
                            <h4>Filters</h4>
                            
                            <div class="filter-group">
                                <label>From User:</label>
                                <select id="filterUserId" class="filter-select">
                                    <option value="">All Members</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>Message Type:</label>
                                <select id="filterType" class="filter-select">
                                    <option value="">All Types</option>
                                    <option value="text">Text</option>
                                    <option value="image">Images</option>
                                    <option value="video">Videos</option>
                                    <option value="audio">Audio</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>Date From:</label>
                                <input type="date" id="filterDateFrom" class="filter-input">
                            </div>
                            
                            <div class="filter-group">
                                <label>Date To:</label>
                                <input type="date" id="filterDateTo" class="filter-input">
                            </div>
                            
                            <div class="filter-group">
                                <label>
                                    <input type="checkbox" id="filterHasMedia">
                                    Only messages with media
                                </label>
                            </div>
                        </div>
                        
                        <button onclick="performSearch()" class="btn-search">Search</button>
                    </div>
                    
                    <div id="searchResults" class="search-results"></div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', dialogHtml);
    
    // Load family members for filter
    loadFamilyMembersForFilter();
    
    // Focus search input
    document.getElementById('searchQuery').focus();
    
    // Enter to search
    document.getElementById('searchQuery').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            performSearch();
        }
    });
}

function closeSearchDialog() {
    document.getElementById('searchModal')?.remove();
}

function loadFamilyMembersForFilter() {
    const members = document.querySelectorAll('.member-item');
    const select = document.getElementById('filterUserId');
    
    members.forEach(member => {
        const name = member.querySelector('.member-name')?.textContent;
        const userId = member.dataset.userId;
        if (name && userId && name !== 'You') {
            const option = document.createElement('option');
            option.value = userId;
            option.textContent = name;
            select.appendChild(option);
        }
    });
}

async function performSearch() {
    const query = document.getElementById('searchQuery').value.trim();
    const userId = document.getElementById('filterUserId').value;
    const type = document.getElementById('filterType').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const hasMedia = document.getElementById('filterHasMedia').checked;
    
    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = '<div class="loading">Searching...</div>';
    
    try {
        const params = new URLSearchParams();
        if (query) params.append('query', query);
        if (userId) params.append('user_id', userId);
        if (type) params.append('type', type);
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);
        if (hasMedia) params.append('has_media', '1');
        
        const response = await fetch(`/messages/api/search.php?${params.toString()}`, {
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            displaySearchResults(data.results || [], data.total || 0);
        } else {
            resultsContainer.innerHTML = '<div class="empty-results">Search failed: ' + data.message + '</div>';
        }
    } catch (error) {
        console.error('Search error:', error);
        resultsContainer.innerHTML = '<div class="empty-results">Search failed</div>';
    }
}

function displaySearchResults(results, total) {
    const container = document.getElementById('searchResults');
    
    if (results.length === 0) {
        container.innerHTML = '<div class="empty-results">No messages found</div>';
        return;
    }
    
    let html = `<div class="search-results-header">Found ${total} message${total !== 1 ? 's' : ''}</div>`;
    
    results.forEach(msg => {
        const date = new Date(msg.created_at).toLocaleString();
        const content = msg.content_highlighted || msg.content || '[No text content]';
        
        html += `
            <div class="search-result-item" onclick="scrollToMessage(${msg.id}); closeSearchDialog();">
                <div class="result-header">
                    <strong>${escapeHtml(msg.full_name)}</strong>
                    <span class="result-date">${date}</span>
                </div>
                <div class="result-content">${content}</div>
                ${msg.media_path ? '<div class="result-media-indicator">📎 Has media</div>' : ''}
                ${msg.edited_at ? '<div class="result-edited-indicator">(edited)</div>' : ''}
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// ============================================
// BULK OPERATIONS
// ============================================
function toggleBulkMode() {
    EnhancedMessageApp.bulkMode = !EnhancedMessageApp.bulkMode;
    
    const btn = document.getElementById('bulkModeBtn');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    
    if (EnhancedMessageApp.bulkMode) {
        btn.textContent = '✖️ Cancel';
        btn.classList.add('active');
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
        btn.textContent = '☑️ Select';
        btn.classList.remove('active');
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
            showSuccess(`Successfully ${action.replace('_', ' ')}d ${data.affected_count || EnhancedMessageApp.selectedMessages.size} message(s)`);
            
            if (action === 'delete') {
                // Remove deleted messages from UI
                EnhancedMessageApp.selectedMessages.forEach(id => {
                    document.querySelector(`[data-message-id="${id}"]`)?.remove();
                });
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
        <div class="limit-header">💾 Message Storage</div>
        <div class="limit-bar">
            <div class="limit-fill" style="width: ${percent}%"></div>
        </div>
        <div class="limit-text">${stats.current_count || 0} / ${stats.max_messages || 10000} messages</div>
        ${stats.archived_count > 0 ? `<div class="limit-archived">${stats.archived_count} archived</div>` : ''}
    `;
}

// ============================================
// OFFLINE SUPPORT
// ============================================
function initOfflineSupport() {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    
    // Load offline queue from localStorage
    const savedQueue = localStorage.getItem('messageQueue');
    if (savedQueue) {
        try {
            EnhancedMessageApp.offlineQueue = JSON.parse(savedQueue);
            console.log('📦 Loaded offline queue:', EnhancedMessageApp.offlineQueue.length, 'messages');
        } catch (e) {
            localStorage.removeItem('messageQueue');
        }
    }
    
    // Process queue if online
    if (navigator.onLine && EnhancedMessageApp.offlineQueue.length > 0) {
        processOfflineQueue();
    }
}

function handleOnline() {
    console.log('🟢 Online');
    showSuccess('Back online! Syncing messages...');
    processOfflineQueue();
    window.loadNewMessages();
}

function handleOffline() {
    console.log('🔴 Offline');
    showWarning('You are offline. Messages will be queued.');
}

function queueMessageForOffline(messageData) {
    const queueItem = {
        id: 'temp_' + Date.now(),
        ...messageData,
        queuedAt: Date.now()
    };
    
    EnhancedMessageApp.offlineQueue.push(queueItem);
    localStorage.setItem('messageQueue', JSON.stringify(EnhancedMessageApp.offlineQueue));
    
    // Show queued message in UI
    showQueuedMessage(queueItem);
}

async function processOfflineQueue() {
    if (EnhancedMessageApp.offlineQueue.length === 0) return;
    
    console.log('📤 Processing offline queue:', EnhancedMessageApp.offlineQueue.length, 'messages');
    
    const queue = [...EnhancedMessageApp.offlineQueue];
    EnhancedMessageApp.offlineQueue = [];
    
    for (const item of queue) {
        try {
            const formData = new FormData();
            formData.append('content', item.content);
            if (item.reply_to_message_id) {
                formData.append('reply_to_message_id', item.reply_to_message_id);
            }
            
            const response = await fetch('/messages/api/send.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Remove queued message from UI
                removeQueuedMessage(item.id);
                console.log('✅ Sent queued message:', item.id);
            } else {
                // Re-queue failed message
                EnhancedMessageApp.offlineQueue.push(item);
            }
        } catch (error) {
            console.error('Failed to send queued message:', error);
            EnhancedMessageApp.offlineQueue.push(item);
        }
    }
    
    // Save updated queue
    localStorage.setItem('messageQueue', JSON.stringify(EnhancedMessageApp.offlineQueue));
    
    // Refresh messages
    if (EnhancedMessageApp.offlineQueue.length === 0) {
        showSuccess('All queued messages sent!');
        setTimeout(() => window.loadNewMessages(), 500);
    }
}

function showQueuedMessage(queueItem) {
    const container = document.getElementById('messagesList');
    
    const messageEl = document.createElement('div');
    messageEl.className = 'message own queued';
    messageEl.dataset.tempId = queueItem.id;
    
    messageEl.innerHTML = `
        <div class="message-content">
            <div class="message-bubble">
                <div class="message-text">${escapeHtml(queueItem.content)}</div>
                <div class="queued-indicator">
                    <span class="spinner-small"></span> Queued for sending...
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(messageEl);
    window.scrollToBottom && window.scrollToBottom();
}

function removeQueuedMessage(tempId) {
    document.querySelector(`[data-temp-id="${tempId}"]`)?.remove();
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
        if (e.key === 'Escape') {
            closeSearchDialog();
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
    
    // Auto-save draft
    let draftTimeout;
    input.addEventListener('input', function() {
        clearTimeout(draftTimeout);
        draftTimeout = setTimeout(() => {
            saveDraft(input.value);
        }, 1000);
        
        updateCharacterCount(input.value.length);
    });
    
    // Load draft on init
    const draft = loadDraft();
    if (draft) {
        input.value = draft;
        updateCharacterCount(draft.length);
    }
}

function saveDraft(content) {
    if (content.trim()) {
        localStorage.setItem('messageDraft_' + window.MessageSystem.familyId, content);
    } else {
        localStorage.removeItem('messageDraft_' + window.MessageSystem.familyId);
    }
}

function loadDraft() {
    return localStorage.getItem('messageDraft_' + window.MessageSystem.familyId);
}

function clearDraft() {
    localStorage.removeItem('messageDraft_' + window.MessageSystem.familyId);
}

function updateCharacterCount(count) {
    let counter = document.getElementById('charCounter');
    
    if (!counter) {
        counter = document.createElement('div');
        counter.id = 'charCounter';
        counter.className = 'char-counter';
        document.querySelector('.message-input-wrapper')?.appendChild(counter);
    }
    
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
// ENHANCED sendMessage WITH OFFLINE SUPPORT
// ============================================
if (typeof window.sendMessage === 'function') {
    const originalSendMessage = window.sendMessage;
    window.sendMessage = async function() {
        const input = document.getElementById('messageInput');
        const content = input.value.trim();
        
        if (!content && window.MessageSystem.mediaFiles.length === 0) return;
        
        // Check if offline
        if (!navigator.onLine) {
            queueMessageForOffline({
                content: content,
                reply_to_message_id: window.MessageSystem.replyToMessageId,
                mediaFiles: window.MessageSystem.mediaFiles
            });
            
            input.value = '';
            clearDraft();
            window.cancelReply && window.cancelReply();
            return;
        }
        
        // Call original function
        await originalSendMessage();
        
        // Clear draft after successful send
        clearDraft();
        updateCharacterCount(0);
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
window.closeSearchDialog = closeSearchDialog;
window.performSearch = performSearch;
window.toggleBulkMode = toggleBulkMode;
window.selectAllMessages = selectAllMessages;
window.deselectAllMessages = deselectAllMessages;
window.performBulkAction = performBulkAction;
window.checkMessageLimit = checkMessageLimit;

console.log('✅ Enhanced messaging features ready');