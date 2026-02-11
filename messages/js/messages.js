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
    // User Info
    currentUserId: null,
    currentUserName: null,
    familyId: null,
    
    // Message State
    lastMessageId: 0,
    replyToMessageId: null,
    contextMessageId: null,
    
    // UI State
    isLoadingMessages: false,
    isLoadingInitial: false,
    initialLoadComplete: false,
    sessionWarmedUp: false,
    
    // Media
    mediaFiles: [],
    
    // Retry Logic
    loadRetryCount: 0,
    MAX_RETRIES: 5,
    RETRY_DELAYS: [1000, 1500, 2000, 3000, 5000],
    
    // Typing
    typingTimeout: null,
    
    // Polling
    pollingInterval: null,
    typingInterval: null
};

// ============================================
// DETECT NATIVE APP
// ============================================
const isNativeApp = navigator.userAgent.includes('RelativesAndroidApp') ||
                    navigator.userAgent.includes('wv') ||
                    navigator.userAgent.includes('relatives-native') ||
                    window.AndroidInterface !== undefined;

if (isNativeApp) {
    console.log('📱 Running in native app mode');
    MessageSystem.MAX_RETRIES = 3;
    MessageSystem.RETRY_DELAYS = [2000, 3000, 5000];
}

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('🎬 Messages app starting...');
    
    initializeChat();
    warmupSession();
    setupEventListeners();
    initParticles();
    setupEmojiPicker();
});

function initializeChat() {
    var userIdEl = document.getElementById('currentUserId');
    var userNameEl = document.getElementById('currentUserName');
    var familyIdEl = document.getElementById('familyId');
    MessageSystem.currentUserId = userIdEl ? userIdEl.value : null;
    MessageSystem.currentUserName = userNameEl ? userNameEl.value : null;
    MessageSystem.familyId = familyIdEl ? familyIdEl.value : null;

    console.log('💬 Chat initialized:', {
        userId: MessageSystem.currentUserId,
        userName: MessageSystem.currentUserName,
        familyId: MessageSystem.familyId
    });
}

// ============================================
// CONNECTION STATUS INDICATOR
// ============================================
function updateConnectionStatus(status, message) {
    const indicator = document.getElementById('connectionStatus');
    if (!indicator) return;
    
    const statusText = indicator.querySelector('.status-text');
    
    indicator.className = 'connection-status ' + status;
    statusText.textContent = message;
    
    if (status === 'connected') {
        indicator.style.display = 'flex';
        setTimeout(() => {
            indicator.style.display = 'none';
        }, 3000);
    } else {
        indicator.style.display = 'flex';
    }
}

// ============================================
// SESSION WARMUP
// ============================================
async function warmupSession() {
    console.log('🔥 Warming up session...');
    
    const messagesList = document.getElementById('messagesList');
    messagesList.innerHTML = `
        <div class="loading-messages">
            <div class="spinner"></div>
            <p>Initializing...</p>
        </div>
    `;
    
    updateConnectionStatus('connecting', 'Initializing connection...');
    
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);
        
        const response = await fetch(window.location.origin + '/messages/api/test.php?t=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        console.log('✅ Session warmup complete, status:', response.status);
        MessageSystem.sessionWarmedUp = true;
        
    } catch (error) {
        console.warn('⚠️ Session warmup failed:', error.name);
        MessageSystem.sessionWarmedUp = false;
    }
    
    loadInitialMessages();
    startPolling();
}

// ============================================
// PARTICLE ANIMATION - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS
// ============================================
function initParticles() {
    // Disabled - canvas hidden via CSS for performance
}

// ============================================
// LOAD INITIAL MESSAGES
// ============================================
async function loadInitialMessages() {
    const messagesList = document.getElementById('messagesList');
    
    if (MessageSystem.isLoadingInitial) {
        console.log('⏸️ Already loading initial messages, skipping...');
        return;
    }
    
    MessageSystem.isLoadingInitial = true;
    MessageSystem.isLoadingMessages = true;
    
    console.log(`📥 Loading initial messages (attempt ${MessageSystem.loadRetryCount + 1}/${MessageSystem.MAX_RETRIES})...`);
    
    messagesList.innerHTML = `
        <div class="loading-messages">
            <div class="spinner"></div>
            <p>${MessageSystem.loadRetryCount === 0 ? 'Loading messages...' : `Connecting... (${MessageSystem.loadRetryCount}/${MessageSystem.MAX_RETRIES})`}</p>
        </div>
    `;
    
    updateConnectionStatus('connecting', `Connecting... (${MessageSystem.loadRetryCount + 1}/${MessageSystem.MAX_RETRIES})`);
    
    try {
        const baseUrl = window.location.origin;
        const timestamp = Date.now();
        const fullUrl = `${baseUrl}/messages/api/fetch.php?t=${timestamp}`;
        
        console.log('🌐 Fetching:', fullUrl);
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 15000);
        
        const response = await fetch(fullUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache, no-store, must-revalidate'
            },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        console.log('📡 Response status:', response.status);
        
        if (!response.ok) {
            console.error('❌ Server error, status:', response.status);
            
            if (response.status === 401 || response.status === 403) {
                throw new Error('AUTH_ERROR');
            }
            
            if (response.status === 429) {
                throw new Error('TOO_MANY_REQUESTS');
            }
            
            throw new Error(`HTTP_${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        
        if (!contentType || !contentType.includes('application/json')) {
            console.error('❌ Invalid content type:', contentType);
            throw new Error('INVALID_RESPONSE');
        }
        
        const data = await response.json();
        console.log('✅ Data received:', {
            success: data.success,
            messageCount: data.messages ? data.messages.length : 0
        });
        
        if (data.success) {
            MessageSystem.loadRetryCount = 0;
            MessageSystem.initialLoadComplete = true;
            MessageSystem.isLoadingInitial = false;
            MessageSystem.isLoadingMessages = false;
            
            updateConnectionStatus('connected', 'Connected successfully');
            
            if (data.messages.length === 0) {
                messagesList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">💬</div>
                        <h3>No messages yet</h3>
                        <p>Start the conversation with your family!</p>
                    </div>
                `;
            } else {
                displayMessages(data.messages, true);
                MessageSystem.lastMessageId = Math.max(...data.messages.map(m => m.id));
                console.log('✅ Last message ID:', MessageSystem.lastMessageId);
            }
        } else {
            throw new Error(data.message || 'UNKNOWN_ERROR');
        }
        
    } catch (error) {
        console.error('❌ Error loading messages:', error.name, error.message);
        
        MessageSystem.isLoadingInitial = false;
        MessageSystem.isLoadingMessages = false;
        
        if (error.message === 'TOO_MANY_REQUESTS') {
            console.log('⏸️ Too many requests, waiting longer...');
            setTimeout(() => {
                MessageSystem.loadRetryCount = 0;
                loadInitialMessages();
            }, 3000);
            return;
        }
        
        if (MessageSystem.loadRetryCount < MessageSystem.MAX_RETRIES) {
            const delay = MessageSystem.RETRY_DELAYS[MessageSystem.loadRetryCount] || 5000;
            MessageSystem.loadRetryCount++;
            
            console.log(`🔄 Retrying in ${delay}ms... (${MessageSystem.loadRetryCount}/${MessageSystem.MAX_RETRIES})`);
            
            updateConnectionStatus('error', `Retrying... (${MessageSystem.loadRetryCount}/${MessageSystem.MAX_RETRIES})`);
            
            setTimeout(() => loadInitialMessages(), delay);
        } else {
            console.error('❌ Max retries reached, stopping...');
            
            MessageSystem.initialLoadComplete = true;
            MessageSystem.loadRetryCount = 0;
            
            updateConnectionStatus('error', 'Connection failed');
            
            if (error.message === 'AUTH_ERROR') {
                messagesList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">🔒</div>
                        <h3>Session expired</h3>
                        <p style="margin-top: 15px;">
                            <button onclick="window.location.reload()" 
                                    style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                           color: white; 
                                           border: none; 
                                           padding: 12px 24px; 
                                           border-radius: 12px; 
                                           cursor: pointer; 
                                           font-weight: 600;
                                           font-size: 16px;">
                                Refresh Page
                            </button>
                        </p>
                    </div>
                `;
            } else {
                messagesList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">⚠️</div>
                        <h3>Connection failed</h3>
                        <p>Unable to load messages</p>
                        <p style="margin-top: 15px;">
                            <button onclick="retryConnection()" 
                                    style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                           color: white; 
                                           border: none; 
                                           padding: 12px 24px; 
                                           border-radius: 12px; 
                                           cursor: pointer; 
                                           font-weight: 600;
                                           font-size: 16px;">
                                Try Again
                            </button>
                        </p>
                    </div>
                `;
            }
        }
    }
}

function retryConnection() {
    MessageSystem.loadRetryCount = 0;
    MessageSystem.initialLoadComplete = false;
    MessageSystem.isLoadingInitial = false;
    MessageSystem.isLoadingMessages = false;
    loadInitialMessages();
}

// ============================================
// LOAD NEW MESSAGES (POLLING)
// ============================================
async function loadNewMessages() {
    if (MessageSystem.isLoadingMessages || !MessageSystem.initialLoadComplete) {
        return;
    }
    
    MessageSystem.isLoadingMessages = true;
    
    try {
        const baseUrl = window.location.origin;
        const fullUrl = `${baseUrl}/messages/api/fetch.php?since=${MessageSystem.lastMessageId}&t=${Date.now()}`;
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);
        
        const response = await fetch(fullUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache'
            },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Invalid response type');
        }
        
        const data = await response.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            console.log('📨 New messages:', data.messages.length);
            displayMessages(data.messages, false);
            MessageSystem.lastMessageId = Math.max(...data.messages.map(m => m.id));
            playNotificationSound();
        }
        
    } catch (error) {
        if (error.name !== 'AbortError') {
            console.error('Error fetching new messages:', error.name);
        }
    } finally {
        MessageSystem.isLoadingMessages = false;
    }
}

// ============================================
// DATE SEPARATOR
// ============================================
let lastDisplayedDate = null;

function formatDateSeparator(date) {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const weekAgo = new Date(today);
    weekAgo.setDate(weekAgo.getDate() - 7);

    const msgDate = new Date(date);

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
    div.innerHTML = `<span>${dateText}</span>`;
    return div;
}

// ============================================
// DISPLAY MESSAGES
// ============================================
function displayMessages(messages, clearFirst = false) {
    const container = document.getElementById('messagesList');

    if (clearFirst) {
        container.innerHTML = '';
        lastDisplayedDate = null;
    }

    const shouldScroll = isScrolledToBottom();

    messages.forEach(msg => {
        const existing = container.querySelector(`[data-message-id="${msg.id}"]`);
        if (existing) return;

        // Check if we need a date separator
        const msgDate = new Date(msg.created_at);
        const msgDateStr = msgDate.toDateString();

        if (lastDisplayedDate !== msgDateStr) {
            const dateSeparator = createDateSeparator(formatDateSeparator(msg.created_at));
            container.appendChild(dateSeparator);
            lastDisplayedDate = msgDateStr;
        }

        const messageEl = createMessageElement(msg);
        container.appendChild(messageEl);
    });

    if (shouldScroll) {
        scrollToBottom();
    }
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

    // Avatar with image support
    const avatarContent = msg.has_avatar
        ? `<img src="/saves/${msg.user_id}/avatar/avatar.webp" alt="${escapeHtml(msg.full_name)}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
           <span class="avatar-fallback" style="display:none; background: ${msg.avatar_color}; width:100%; height:100%; align-items:center; justify-content:center; border-radius:50%;">${msg.full_name.substring(0, 2).toUpperCase()}</span>`
        : msg.full_name.substring(0, 2).toUpperCase();

    const avatar = `
        <div class="message-avatar" style="background: ${msg.has_avatar ? 'transparent' : msg.avatar_color}">
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
                            mediaHtml += `<img src="${f.path}" alt="${escapeHtml(f.name)}" loading="lazy" onclick="openMediaViewer('${f.path}', 'image')">`;
                        } else if (f.type && f.type.startsWith('video/')) {
                            mediaHtml += `<video src="${f.path}" controls></video>`;
                        } else if (f.type && f.type.startsWith('audio/')) {
                            mediaHtml += `<audio src="${f.path}" controls></audio>`;
                        } else {
                            const ext = f.name ? f.name.split('.').pop().toUpperCase() : 'FILE';
                            const icon = (f.type === 'application/pdf') ? '📄' : '📎';
                            const size = f.size ? (f.size / 1024).toFixed(0) + 'KB' : '';
                            mediaHtml += `<a href="${f.path}" target="_blank" class="message-doc">${icon} <span>${escapeHtml(f.name)}</span><small>${ext} ${size}</small></a>`;
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
    
    let reactionsHtml = '';
    if (msg.reactions && msg.reactions.length > 0) {
        reactionsHtml = '<div class="message-reactions">';

        const grouped = {};
        msg.reactions.forEach(r => {
            if (!grouped[r.emoji]) grouped[r.emoji] = [];
            grouped[r.emoji].push(r.user_id);
        });

        Object.entries(grouped).forEach(([emoji, users]) => {
            const hasOwn = users.includes(parseInt(MessageSystem.currentUserId));
            const countDisplay = users.length > 1 ? `<span class="reaction-count">${users.length}</span>` : '';
            reactionsHtml += `
                <div class="reaction ${hasOwn ? 'own' : ''}"
                     onclick="toggleReaction(${msg.id}, '${emoji}')"
                     title="${users.length} reaction(s)">
                    ${emoji}${countDisplay}
                </div>
            `;
        });

        reactionsHtml += `<div class="reaction reaction-add" onclick="showReactionPicker(${msg.id})" title="Add reaction">+</div>`;
        reactionsHtml += '</div>';
    }
    
    const actions = `
        <div class="message-actions">
            <button class="message-action-btn" onclick="replyToMessage(${msg.id}, '${escapeForAttr(msg.full_name)}', '${escapeForAttr(msg.content || '')}')" title="Reply">↩️</button>
            <button class="message-action-btn" onclick="showReactionPicker(${msg.id})" title="React">😊</button>
            ${isOwn && window.enableEditMessage ? `<button class="message-action-btn" onclick="enableEditMessage(${msg.id})" title="Edit">✏️</button>` : ''}
            <button class="message-action-btn" onclick="showMessageOptions(${msg.id}, event)" title="More">⋮</button>
        </div>
    `;
    
    const msgDate = new Date(msg.created_at);
    const time = msgDate.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit'
    });
    
    const editedIndicator = msg.edited_at ? `<span class="edited-indicator" title="Edited at ${msg.edited_at}">(edited)</span>` : '';
    
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
                ${msg.content ? `<div class="message-text">${linkify(escapeHtml(msg.content))}${editedIndicator}</div>` : ''}
                ${mediaHtml}
                ${actions}
                <span class="message-time">${time}</span>
            </div>
            ${reactionsHtml}
        </div>
        ${isOwn ? avatar : ''}
    `;
    
    return div;
}

// ============================================
// SEND MESSAGE
// ============================================
async function sendMessage() {
    const input = document.getElementById('messageInput');
    const content = input.value.trim();
    
    if (!content && MessageSystem.mediaFiles.length === 0) return;
    
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    sendBtn.textContent = '⏳';
    
    try {
        const formData = new FormData();
        formData.append('content', content);
        
        if (MessageSystem.replyToMessageId) {
            formData.append('reply_to_message_id', MessageSystem.replyToMessageId);
        }
        
        MessageSystem.mediaFiles.forEach((file, i) => {
            formData.append('media[]', file);
        });
        
        const url = window.location.origin + '/messages/api/send.php';
        
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            input.value = '';
            input.style.height = 'auto';
            cancelReply();
            cancelMedia();

            // Instantly display the sent message from server response
            if (data.sent_message) {
                displayMessages([data.sent_message], false);
                if (data.sent_message.id > MessageSystem.lastMessageId) {
                    MessageSystem.lastMessageId = data.sent_message.id;
                }
                scrollToBottom();
            }
        } else {
            showError(data.message || 'Failed to send message');
        }
    } catch (error) {
        console.error('Error sending message:', error);
        showError('Failed to send message');
    } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = '➤';
    }
}

// ============================================
// TYPING INDICATOR
// ============================================
async function handleTyping() {
    clearTimeout(MessageSystem.typingTimeout);
    
    try {
        const url = window.location.origin + '/messages/api/typing.php';
        
        await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ typing: true }),
            credentials: 'same-origin'
        });
        
        MessageSystem.typingTimeout = setTimeout(async () => {
            await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ typing: false }),
                credentials: 'same-origin'
            });
        }, 2000);
    } catch (error) {
        // Silently fail
    }
}

async function checkTypingStatus() {
    if (!MessageSystem.initialLoadComplete) return;
    
    try {
        const url = window.location.origin + '/messages/api/typing.php?t=' + Date.now();
        
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-cache'
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
function buildReactionsHtml(messageId, reactions) {
    if (!reactions || reactions.length === 0) return '';

    const grouped = {};
    reactions.forEach(r => {
        if (!grouped[r.emoji]) grouped[r.emoji] = [];
        grouped[r.emoji].push(parseInt(r.user_id));
    });

    let html = '<div class="message-reactions">';

    Object.entries(grouped).forEach(([emoji, users]) => {
        const hasOwn = users.includes(parseInt(MessageSystem.currentUserId));
        const countDisplay = users.length > 1 ? `<span class="reaction-count">${users.length}</span>` : '';
        html += `
            <div class="reaction ${hasOwn ? 'own' : ''}"
                 onclick="toggleReaction(${messageId}, '${emoji}')"
                 title="${users.length} reaction(s)">
                ${emoji}${countDisplay}
            </div>
        `;
    });

    html += `<div class="reaction reaction-add" onclick="showReactionPicker(${messageId})" title="Add reaction">+</div>`;
    html += '</div>';
    return html;
}

function updateReactionDOM(messageId, reactions) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageEl) return;

    const contentEl = messageEl.querySelector('.message-content');
    if (!contentEl) return;

    // Remove old reactions
    const oldReactions = messageEl.querySelector('.message-reactions');
    if (oldReactions) oldReactions.remove();

    if (reactions && reactions.length > 0) {
        const html = buildReactionsHtml(messageId, reactions);
        const temp = document.createElement('div');
        temp.innerHTML = html;
        contentEl.appendChild(temp.firstElementChild);
        messageEl.classList.add('has-reactions');
    } else {
        messageEl.classList.remove('has-reactions');
    }
}

async function toggleReaction(messageId, emoji) {
    try {
        const url = window.location.origin + '/messages/api/react.php';

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: messageId, emoji: emoji }),
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            updateReactionDOM(messageId, data.reactions || []);
        }
    } catch (error) {
        console.error('Error toggling reaction:', error);
    }
}

function showReactionPicker(messageId, event) {
    // Remove any existing reaction picker
    const existingPicker = document.querySelector('.reaction-picker');
    if (existingPicker) existingPicker.remove();

    const picker = document.createElement('div');
    picker.className = 'reaction-picker';

    const quickEmojis = ['👍', '❤️', '😂', '🔥'];
    const moreEmojis = ['🎉', '👏', '💯', '🤔', '😍', '🥳', '😭', '💀', '😮', '😢', '😡', '🙏'];

    picker.innerHTML = `
        <div class="reaction-picker-row">
            ${quickEmojis.map(e => `<button class="reaction-picker-item" onclick="toggleReaction(${messageId}, '${e}'); document.querySelector('.reaction-picker').remove();">${e}</button>`).join('')}
            <button class="reaction-picker-item reaction-picker-more" onclick="document.querySelector('.reaction-picker-expanded').style.display='grid'">+</button>
        </div>
        <div class="reaction-picker-expanded" style="display:none;">
            ${moreEmojis.map(e => `<button class="reaction-picker-item" onclick="toggleReaction(${messageId}, '${e}'); document.querySelector('.reaction-picker').remove();">${e}</button>`).join('')}
        </div>
    `;

    document.body.appendChild(picker);

    // Position near the message
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const pickerRect = picker.getBoundingClientRect();

    picker.style.left = Math.max(10, (viewportWidth - pickerRect.width) / 2) + 'px';
    picker.style.top = Math.max(10, (viewportHeight - pickerRect.height) / 2) + 'px';

    setTimeout(() => {
        document.addEventListener('click', function removePickerClick(e) {
            if (!picker.contains(e.target)) {
                picker.remove();
                document.removeEventListener('click', removePickerClick);
            }
        });
    }, 100);
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

function openMediaViewer(mediaPath, type) {
    const viewer = document.createElement('div');
    viewer.className = 'media-viewer-overlay';
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
    event.stopPropagation();
    MessageSystem.contextMessageId = messageId;
    const menu = document.getElementById('contextMenu');
    menu.style.display = 'block';
    menu.style.left = event.pageX + 'px';
    menu.style.top = event.pageY + 'px';
    
    setTimeout(() => {
        document.addEventListener('click', function hideMenu() {
            menu.style.display = 'none';
            document.removeEventListener('click', hideMenu);
        });
    }, 100);
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
    
    // Auto-resize textarea
    input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
        handleTyping();
    });
    
    // Enter to send
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // Visibility-based polling pause/resume to save battery
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            // Stop polling when page is hidden (tab switched, app minimized)
            console.log('👁️ Page hidden, stopping polling...');
            stopPolling();
        } else if (MessageSystem.initialLoadComplete) {
            // Resume polling when page becomes visible
            console.log('👀 Page visible, resuming polling...');
            loadNewMessages(); // Immediate refresh
            startPolling();    // Resume interval polling
        }
    });
    
    // Handle page unload
    window.addEventListener('beforeunload', function() {
        stopPolling();
    });
}

// ============================================
// POLLING
// ============================================

// Reduced polling intervals to save battery
// Push notifications handle real-time alerts; polling is just for thread refresh
const MESSAGE_POLL_INTERVAL = isNativeApp ? 15000 : 10000;  // 15s native, 10s web
const TYPING_POLL_INTERVAL = isNativeApp ? 12000 : 8000;    // 12s native, 8s web

function startPolling() {
    // Don't start if already running
    if (MessageSystem.pollingInterval) {
        clearInterval(MessageSystem.pollingInterval);
    }
    if (MessageSystem.typingInterval) {
        clearInterval(MessageSystem.typingInterval);
    }

    MessageSystem.pollingInterval = setInterval(loadNewMessages, MESSAGE_POLL_INTERVAL);
    MessageSystem.typingInterval = setInterval(checkTypingStatus, TYPING_POLL_INTERVAL);

    console.log(`⏱️ Polling started: messages every ${MESSAGE_POLL_INTERVAL}ms, typing every ${TYPING_POLL_INTERVAL}ms`);
}

function stopPolling() {
    if (MessageSystem.pollingInterval) {
        clearInterval(MessageSystem.pollingInterval);
        MessageSystem.pollingInterval = null;
    }
    if (MessageSystem.typingInterval) {
        clearInterval(MessageSystem.typingInterval);
        MessageSystem.typingInterval = null;
    }
    console.log('⏹️ Polling stopped');
}

// ============================================
// SCROLL UTILITIES
// ============================================
function scrollToBottom() {
    const container = document.getElementById('messagesList');
    container.scrollTop = container.scrollHeight;
}

function isScrolledToBottom() {
    const container = document.getElementById('messagesList');
    return container.scrollHeight - container.scrollTop - container.clientHeight < 100;
}

function scrollToMessage(messageId) {
    const element = document.querySelector(`[data-message-id="${messageId}"]`);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        element.classList.add('highlight');
        setTimeout(() => element.classList.remove('highlight'), 2000);
    }
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

function escapeForAttr(text) {
    if (!text) return '';
    return text.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, ' ');
}

function linkify(text) {
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return text.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener">$1</a>');
}

function showError(message) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
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
window.loadInitialMessages = loadInitialMessages;
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
window.retryConnection = retryConnection;
window.updateConnectionStatus = updateConnectionStatus;
window.replyToMessage = replyToMessage;
window.startPolling = startPolling;
window.stopPolling = stopPolling;

console.log('✅ Core messaging system ready');