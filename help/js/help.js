/**
 * ============================================
 * HELP OVERLAY SYSTEM - FRONTEND
 * ============================================ */

console.log('💡 Help system loading...');

class HelpSystem {
    static instance = null;
    
    constructor() {
        if (HelpSystem.instance) return HelpSystem.instance;
        
        this.isOpen = false;
        this.messages = [];
        this.isLoading = false;
        this.rateLimitRemaining = 10;
        
        HelpSystem.instance = this;
        this.init();
    }
    
    static getInstance() {
        if (!HelpSystem.instance) {
            HelpSystem.instance = new HelpSystem();
        }
        return HelpSystem.instance;
    }
    
    init() {
        // Create help panel HTML if it doesn't exist
        if (!document.getElementById('helpOverlay')) {
            this.injectHTML();
        }
        
        // Attach event listeners
        this.attachListeners();
        
        console.log('✅ Help system initialized');
    }
    
    injectHTML() {
        const helpHTML = `
            <div class="help-overlay" id="helpOverlay">
                <div class="help-panel" id="helpPanel">
                    <div class="help-header">
                        <div class="help-header-left">
                            <div class="help-icon">💡</div>
                            <div>
                                <div class="help-title">Help Center</div>
                                <div class="help-subtitle">Powered by Suzi AI</div>
                            </div>
                        </div>
                        <button class="help-close-btn" id="helpCloseBtn" aria-label="Close help">✕</button>
                    </div>
                    
                    <div class="help-chat" id="helpChat">
                        <div class="help-welcome">
                            <div class="help-welcome-icon">👋</div>
                            <h3>Hi! I'm Suzi</h3>
                            <p>I can help you with anything about the Relatives app. Ask me a question or choose one below:</p>
                            
                            <div class="help-quick-questions">
                                <button class="help-quick-btn" data-question="How do I add a shopping item?">
                                    <span class="help-quick-icon">🛒</span>
                                    <span>Add shopping item</span>
                                </button>
                                <button class="help-quick-btn" data-question="How do I enable location tracking?">
                                    <span class="help-quick-icon">📍</span>
                                    <span>Enable location tracking</span>
                                </button>
                                <button class="help-quick-btn" data-question="How do I use voice commands?">
                                    <span class="help-quick-icon">🎤</span>
                                    <span>Use voice commands</span>
                                </button>
                                <button class="help-quick-btn" data-question="Why can't I see weather data?">
                                    <span class="help-quick-icon">🌤️</span>
                                    <span>Weather not showing</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="help-input-wrapper">
                        <div class="help-input-container">
                            <textarea 
                                id="helpInput" 
                                class="help-input" 
                                placeholder="Ask me anything about the app..."
                                rows="1"
                                maxlength="500"
                            ></textarea>
                            <button id="helpSendBtn" class="help-send-btn" aria-label="Send message">
                                ➤
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', helpHTML);
    }
    
    attachListeners() {
        // Help button in footer
        const helpBtn = document.getElementById('helpBtn');
        if (helpBtn) {
            helpBtn.addEventListener('click', () => this.open());
        }
        
        // Close button
        const closeBtn = document.getElementById('helpCloseBtn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.close());
        }
        
        // Overlay click
        const overlay = document.getElementById('helpOverlay');
        if (overlay) {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) this.close();
            });
        }
        
        // ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
        
        // Send button
        const sendBtn = document.getElementById('helpSendBtn');
        if (sendBtn) {
            sendBtn.addEventListener('click', () => this.sendMessage());
        }
        
        // Input enter key
        const input = document.getElementById('helpInput');
        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            // Auto-resize textarea
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 120) + 'px';
            });
        }
        
        // Quick question buttons
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.help-quick-btn');
            if (btn) {
                const question = btn.dataset.question;
                if (question) this.askQuestion(question);
            }
        });
    }
    
    open() {
        this.isOpen = true;
        const overlay = document.getElementById('helpOverlay');
        const panel = document.getElementById('helpPanel');
        
        if (overlay) overlay.classList.add('active');
        if (panel) panel.classList.add('active');
        
        // Focus input
        setTimeout(() => {
            const input = document.getElementById('helpInput');
            if (input) input.focus();
        }, 300);
        
        console.log('💡 Help opened');
    }
    
    close() {
        this.isOpen = false;
        const overlay = document.getElementById('helpOverlay');
        const panel = document.getElementById('helpPanel');
        
        if (overlay) overlay.classList.remove('active');
        if (panel) panel.classList.remove('active');
        
        console.log('💡 Help closed');
    }
    
    askQuestion(question) {
        const input = document.getElementById('helpInput');
        if (input) {
            input.value = question;
            input.focus();
            this.sendMessage();
        }
    }
    
    async sendMessage() {
        if (this.isLoading) return;
        
        const input = document.getElementById('helpInput');
        if (!input) return;
        
        const message = input.value.trim();
        if (!message) return;
        
        // Clear input
        input.value = '';
        input.style.height = 'auto';
        
        // Add user message
        this.addMessage('user', message);
        
        // Show loading
        this.showLoading();
        this.isLoading = true;
        
        try {
            const response = await fetch('/help/api/ask.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ message })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            this.hideLoading();
            
            if (data.ok) {
                this.addMessage('assistant', data.answer);
                
                // Update rate limit info
                if (data.meta && data.meta.rate_limit_remaining !== undefined) {
                    this.rateLimitRemaining = data.meta.rate_limit_remaining;
                }
            } else {
                this.showError(data.error || 'Something went wrong. Please try again.');
            }
            
        } catch (error) {
            console.error('Help API error:', error);
            this.hideLoading();
            this.showError('Unable to connect. Please check your internet connection.');
        } finally {
            this.isLoading = false;
        }
    }
    
    addMessage(type, content) {
        const chat = document.getElementById('helpChat');
        if (!chat) return;
        
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
        
        const avatar = type === 'user' ? '👤' : '💡';
        
        const messageHTML = `
            <div class="help-message ${type}">
                <div class="help-message-avatar">${avatar}</div>
                <div class="help-message-content">
                    <div class="help-message-bubble">${this.sanitize(content)}</div>
                    <div class="help-message-time">${timeStr}</div>
                </div>
            </div>
        `;
        
        chat.insertAdjacentHTML('beforeend', messageHTML);
        this.scrollToBottom();
        
        this.messages.push({ type, content, timestamp: now });
    }
    
    showLoading() {
        const chat = document.getElementById('helpChat');
        if (!chat) return;
        
        const loadingHTML = `
            <div class="help-loading" id="helpLoading">
                <div class="help-message-avatar">💡</div>
                <div class="help-message-content">
                    <div class="help-message-bubble">
                        <div class="help-loading-dot"></div>
                        <div class="help-loading-dot"></div>
                        <div class="help-loading-dot"></div>
                    </div>
                </div>
            </div>
        `;
        
        chat.insertAdjacentHTML('beforeend', loadingHTML);
        this.scrollToBottom();
    }
    
    hideLoading() {
        const loading = document.getElementById('helpLoading');
        if (loading) loading.remove();
    }
    
    showError(message) {
        const chat = document.getElementById('helpChat');
        if (!chat) return;
        
        const errorHTML = `
            <div class="help-error">
                <div class="help-error-icon">⚠️</div>
                <div>${this.sanitize(message)}</div>
            </div>
        `;
        
        chat.insertAdjacentHTML('beforeend', errorHTML);
        this.scrollToBottom();
    }
    
    scrollToBottom() {
        const chat = document.getElementById('helpChat');
        if (!chat) return;
        
        setTimeout(() => {
            chat.scrollTo({
                top: chat.scrollHeight,
                behavior: 'smooth'
            });
        }, 100);
    }
    
    sanitize(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML.replace(/\n/g, '<br>');
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new HelpSystem();
    });
} else {
    new HelpSystem();
}

// Expose globally
window.HelpSystem = HelpSystem;

console.log('✅ Help system loaded');