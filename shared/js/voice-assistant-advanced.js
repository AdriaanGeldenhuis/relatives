/**
 * ============================================
 * SUZI VOICE ASSISTANT v9.0
 * Full conversational AI assistant
 * Can answer questions, tell stories, AND control the app
 * ============================================
 */

class SuziVoice {
    constructor() {
        // Singleton
        if (SuziVoice._instance) {
            return SuziVoice._instance;
        }
        SuziVoice._instance = this;

        console.log('🎤 Suzi Voice v9.0 - Starting...');

        // State
        this.isOpen = false;
        this.isListening = false;
        this.isSpeaking = false;
        this.isProcessing = false;

        // Speech APIs
        this.recognition = null;
        this.synthesis = window.speechSynthesis;
        this.currentUtterance = null;

        // Conversation history
        this.conversation = [];
        this.maxHistory = 10;

        // Timeouts
        this.silenceTimer = null;
        this.SILENCE_TIMEOUT = 10000; // 10 seconds

        // Native app detection
        this.isNative = !!(window.AndroidVoice && typeof window.AndroidVoice.startListening === 'function');

        // DOM elements (cached on first use)
        this.dom = null;

        // Initialize
        this.init();
    }

    static getInstance() {
        if (!SuziVoice._instance) {
            new SuziVoice();
        }
        return SuziVoice._instance;
    }

    init() {
        // Setup speech recognition
        this.setupRecognition();

        // Setup native hooks
        this.setupNativeHooks();

        // Preload voices
        if (this.synthesis) {
            this.synthesis.getVoices();
            speechSynthesis.onvoiceschanged = () => this.synthesis.getVoices();
        }

        console.log('✅ Suzi Voice v9.0 Ready!', this.isNative ? '(Native Mode)' : '(Web Mode)');
    }

    cacheDom() {
        if (this.dom) return;

        this.dom = {
            modal: document.getElementById('voiceModal'),
            statusIcon: document.getElementById('statusIcon'),
            statusText: document.getElementById('statusText'),
            statusSubtext: document.getElementById('statusSubtext'),
            transcript: document.getElementById('voiceTranscript'),
            micBtn: document.getElementById('modalMicBtn'),
            suggestions: document.getElementById('voiceSuggestions'),
            conversationArea: document.getElementById('conversationArea')
        };
    }

    setupRecognition() {
        if (this.isNative) return;

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            console.warn('Speech Recognition not supported');
            return;
        }

        this.recognition = new SpeechRecognition();
        this.recognition.lang = navigator.language || 'en-US';
        this.recognition.continuous = false;
        this.recognition.interimResults = true;
        this.recognition.maxAlternatives = 1;

        this.recognition.onstart = () => {
            console.log('🎤 Listening...');
            this.isListening = true;
            this.updateUI('listening');
            this.startSilenceTimer();
        };

        this.recognition.onresult = (event) => {
            const result = event.results[event.results.length - 1];
            const text = result[0].transcript.trim();

            this.resetSilenceTimer();
            this.showTranscript(text + (result.isFinal ? '' : '...'));

            if (result.isFinal && text) {
                this.handleInput(text);
            }
        };

        this.recognition.onerror = (event) => {
            console.log('🎤 Error:', event.error);
            this.isListening = false;
            this.clearSilenceTimer();

            if (event.error === 'not-allowed') {
                this.showStatus('🚫', 'Microphone blocked', 'Enable in browser settings');
            } else if (event.error !== 'aborted') {
                this.updateUI('ready');
            }
        };

        this.recognition.onend = () => {
            console.log('🎤 Recognition ended');
            this.isListening = false;
            this.clearSilenceTimer();

            if (this.isOpen && !this.isProcessing && !this.isSpeaking) {
                this.updateUI('ready');
            }
        };
    }

    setupNativeHooks() {
        window.SuziVoice = {
            onSttResult: (text) => {
                if (text && text.trim()) {
                    this.handleInput(text.trim());
                }
            },
            onTtsEnd: () => {
                this.isSpeaking = false;
                if (this.isOpen && !this.isProcessing) {
                    this.updateUI('ready');
                }
            },
            onTtsStart: () => {
                this.isSpeaking = true;
                this.updateUI('speaking');
            },
            onSttStart: () => {
                this.isListening = true;
                this.updateUI('listening');
            },
            onSttStop: () => {
                this.isListening = false;
                if (this.isOpen && !this.isProcessing) {
                    this.updateUI('ready');
                }
            }
        };
    }

    // ==================== MODAL CONTROL ====================

    open() {
        this.cacheDom();
        if (!this.dom.modal) return;

        this.isOpen = true;
        this.conversation = [];

        this.dom.modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        this.updateUI('ready');
        this.showTranscript('Tap the mic and ask me anything!');

        // Short greeting
        setTimeout(() => {
            this.speak("Hi! I'm Suzi. What can I help you with?");
        }, 300);
    }

    close() {
        this.stopListening();
        this.stopSpeaking();
        this.clearSilenceTimer();

        if (this.dom?.modal) {
            this.dom.modal.classList.remove('active');
        }

        document.body.style.overflow = '';
        this.isOpen = false;
        this.isListening = false;
        this.isSpeaking = false;
        this.isProcessing = false;
    }

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    // ==================== LISTENING CONTROL ====================

    startListening() {
        if (this.isListening || this.isProcessing) return;

        // Stop any ongoing speech first
        this.stopSpeaking();

        if (this.isNative) {
            if (window.AndroidVoice?.startListening) {
                window.AndroidVoice.startListening();
            }
        } else if (this.recognition) {
            try {
                this.recognition.start();
            } catch (e) {
                if (e.name !== 'InvalidStateError') {
                    console.error('Start listening error:', e);
                }
            }
        }
    }

    stopListening() {
        this.clearSilenceTimer();

        if (this.isNative) {
            if (window.AndroidVoice?.stopListening) {
                window.AndroidVoice.stopListening();
            }
        } else if (this.recognition) {
            try {
                this.recognition.abort();
            } catch (e) {}
        }

        this.isListening = false;
    }

    toggleListening() {
        if (this.isSpeaking) {
            this.stopSpeaking();
            this.updateUI('ready');
        } else if (this.isListening) {
            this.stopListening();
            this.updateUI('ready');
        } else if (!this.isProcessing) {
            this.startListening();
        }
    }

    // ==================== SILENCE TIMER ====================

    startSilenceTimer() {
        this.clearSilenceTimer();
        this.silenceTimer = setTimeout(() => {
            if (this.isListening) {
                console.log('Silence timeout');
                this.stopListening();
                this.updateUI('ready');
            }
        }, this.SILENCE_TIMEOUT);
    }

    resetSilenceTimer() {
        if (this.isListening) {
            this.startSilenceTimer();
        }
    }

    clearSilenceTimer() {
        if (this.silenceTimer) {
            clearTimeout(this.silenceTimer);
            this.silenceTimer = null;
        }
    }

    // ==================== INPUT HANDLING ====================

    handleInput(text) {
        if (!text || this.isProcessing) return;

        console.log('📝 Input:', text);

        this.stopListening();
        this.showTranscript(text);

        // Check for close commands
        const lowerText = text.toLowerCase().trim();
        const closeCommands = ['bye', 'goodbye', 'stop', 'close', 'exit', 'cancel', 'never mind', 'nevermind'];
        if (closeCommands.some(cmd => lowerText === cmd || lowerText.startsWith(cmd + ' '))) {
            const goodbyes = ['Goodbye!', 'Bye! Talk soon!', 'See you later!', 'Take care!'];
            this.speak(goodbyes[Math.floor(Math.random() * goodbyes.length)], () => {
                setTimeout(() => this.close(), 500);
            });
            return;
        }

        // Process with AI
        this.processWithAI(text);
    }

    async processWithAI(text) {
        this.isProcessing = true;
        this.updateUI('thinking');

        // Add to conversation history
        this.conversation.push({ role: 'user', content: text });

        try {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);

            const response = await fetch('/api/voice-chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transcript: text,
                    conversation: this.conversation.slice(-this.maxHistory)
                }),
                signal: controller.signal,
                credentials: 'same-origin'
            });

            clearTimeout(timeout);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.response || 'Request failed');
            }

            // Add AI response to history
            this.conversation.push({ role: 'assistant', content: data.response });

            // Trim history
            if (this.conversation.length > this.maxHistory * 2) {
                this.conversation = this.conversation.slice(-this.maxHistory * 2);
            }

            // Handle response
            this.handleAIResponse(data.response, data.action);

        } catch (error) {
            console.error('AI Error:', error);

            let message = 'Sorry, I had trouble processing that. Please try again.';
            if (error.name === 'AbortError') {
                message = 'That took too long. Please try again.';
            }

            this.isProcessing = false;
            this.speak(message, () => {
                this.updateUI('ready');
            });
        }
    }

    handleAIResponse(response, action) {
        this.isProcessing = false;

        // Speak the response
        this.speak(response, () => {
            // After speaking, execute action if any
            if (action) {
                this.executeAction(action);
            } else {
                this.updateUI('ready');
            }
        });
    }

    // ==================== ACTION EXECUTION ====================

    executeAction(action) {
        console.log('🎯 Action:', action);

        const { type, data } = action;

        switch (type) {
            case 'navigate':
                this.navigate(data.to);
                break;

            case 'add_shopping':
                this.addToShopping(data.item, data.category);
                break;

            case 'create_note':
                this.createNote(data.content);
                break;

            case 'create_event':
                this.createEvent(data);
                break;

            case 'create_reminder':
                this.createReminder(data);
                break;

            case 'send_message':
                this.sendMessage(data.content);
                break;

            case 'find_member':
                this.findMember(data.name);
                break;

            default:
                console.log('Unknown action:', type);
                this.updateUI('ready');
        }
    }

    navigate(destination) {
        const paths = {
            home: '/home/',
            shopping: '/shopping/',
            notes: '/notes/',
            calendar: '/calendar/',
            schedule: '/schedule/',
            weather: '/weather/',
            messages: '/messages/',
            tracking: '/tracking/',
            notifications: '/notifications/',
            games: '/games/',
            help: '/help/'
        };

        const path = paths[destination] || '/home/';

        this.updateUI('navigating');

        setTimeout(() => {
            this.close();
            window.location.href = path;
        }, 800);
    }

    async addToShopping(item, category = 'other') {
        try {
            // Get or create shopping list
            let listId = window.currentListId;

            if (!listId) {
                const listResponse = await fetch('/shopping/api/lists.php?action=get_all', {
                    credentials: 'same-origin'
                });
                const listData = await listResponse.json();

                if (listData.success && listData.lists?.[0]) {
                    listId = listData.lists[0].id;
                } else {
                    // Create default list
                    const formData = new FormData();
                    formData.append('action', 'create');
                    formData.append('name', 'Main List');
                    formData.append('icon', '🛒');

                    const createResponse = await fetch('/shopping/api/lists.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });
                    const createData = await createResponse.json();
                    listId = createData.list_id;
                }
            }

            // Add item
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('list_id', listId);
            formData.append('name', item);
            formData.append('category', category);

            await fetch('/shopping/api/items.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            // Reload if on shopping page
            if (window.location.pathname.includes('/shopping/')) {
                setTimeout(() => location.reload(), 500);
            }

        } catch (error) {
            console.error('Add to shopping failed:', error);
        }

        this.updateUI('ready');
    }

    createNote(content) {
        let url = '/notes/?new=1';
        if (content) {
            url += '&content=' + encodeURIComponent(content);
        }
        setTimeout(() => {
            this.close();
            window.location.href = url;
        }, 800);
    }

    createEvent(data) {
        let url = '/calendar/?new=1';
        if (data.title) url += '&content=' + encodeURIComponent(data.title);
        if (data.date) url += '&date=' + data.date;
        if (data.time) url += '&time=' + data.time;

        setTimeout(() => {
            this.close();
            window.location.href = url;
        }, 800);
    }

    createReminder(data) {
        let url = '/schedule/?new=1';
        if (data.title) url += '&content=' + encodeURIComponent(data.title);
        if (data.date) url += '&date=' + data.date;
        if (data.time) url += '&time=' + data.time;

        setTimeout(() => {
            this.close();
            window.location.href = url;
        }, 800);
    }

    async sendMessage(content) {
        if (!content) {
            this.navigate('messages');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('content', content);
            formData.append('to_family', '1');

            await fetch('/messages/api/send.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            if (window.location.pathname.includes('/messages/')) {
                setTimeout(() => location.reload(), 500);
            }
        } catch (error) {
            console.error('Send message failed:', error);
        }

        this.updateUI('ready');
    }

    findMember(name) {
        const url = '/tracking/?search=' + encodeURIComponent(name);
        setTimeout(() => {
            this.close();
            window.location.href = url;
        }, 800);
    }

    // ==================== SPEECH SYNTHESIS ====================

    speak(text, onComplete = null) {
        if (!text) {
            if (onComplete) onComplete();
            return;
        }

        this.isSpeaking = true;
        this.updateUI('speaking');
        this.showTranscript(text);

        // Native TTS
        if (this.isNative && window.AndroidVoice?.speak) {
            window.AndroidVoice.speak(text);

            // Fallback timer
            const duration = Math.max(2000, text.split(' ').length * 400);
            setTimeout(() => {
                if (this.isSpeaking) {
                    this.isSpeaking = false;
                    if (onComplete) onComplete();
                }
            }, duration);
            return;
        }

        // Web TTS
        if (!this.synthesis) {
            this.isSpeaking = false;
            if (onComplete) onComplete();
            return;
        }

        this.synthesis.cancel();

        const utterance = new SpeechSynthesisUtterance(text);
        this.currentUtterance = utterance;

        // Get a good voice
        const voices = this.synthesis.getVoices();
        const voice = voices.find(v => v.lang.startsWith('en') && v.name.toLowerCase().includes('female'))
            || voices.find(v => v.lang.startsWith('en') && !v.name.toLowerCase().includes('male'))
            || voices.find(v => v.lang.startsWith('en'));

        if (voice) utterance.voice = voice;
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        // Fallback timeout
        const fallbackTime = (text.length * 80) + 2000;
        const fallbackTimer = setTimeout(() => {
            if (this.isSpeaking) {
                this.isSpeaking = false;
                if (onComplete) onComplete();
            }
        }, fallbackTime);

        utterance.onend = () => {
            clearTimeout(fallbackTimer);
            this.isSpeaking = false;
            if (onComplete) onComplete();
        };

        utterance.onerror = (e) => {
            clearTimeout(fallbackTimer);
            this.isSpeaking = false;
            if (e.error !== 'interrupted' && e.error !== 'canceled') {
                console.error('TTS Error:', e.error);
            }
            if (onComplete) onComplete();
        };

        this.synthesis.speak(utterance);
    }

    stopSpeaking() {
        if (this.synthesis) {
            this.synthesis.cancel();
        }
        this.isSpeaking = false;
        this.currentUtterance = null;
    }

    // ==================== UI UPDATES ====================

    updateUI(state) {
        this.cacheDom();
        if (!this.dom) return;

        // Update mic button
        if (this.dom.micBtn) {
            this.dom.micBtn.classList.remove('listening', 'speaking', 'thinking');
            if (state === 'listening') {
                this.dom.micBtn.classList.add('listening');
            } else if (state === 'speaking') {
                this.dom.micBtn.classList.add('speaking');
            } else if (state === 'thinking') {
                this.dom.micBtn.classList.add('thinking');
            }
        }

        // Update status
        const states = {
            ready: { icon: '🎤', text: 'Tap mic to speak', sub: 'Ask me anything' },
            listening: { icon: '🎤', text: 'Listening...', sub: 'Speak now' },
            thinking: { icon: '🧠', text: 'Thinking...', sub: 'Processing your request' },
            speaking: { icon: '🔊', text: 'Speaking...', sub: '' },
            navigating: { icon: '🧭', text: 'Opening...', sub: '' }
        };

        const s = states[state] || states.ready;
        this.showStatus(s.icon, s.text, s.sub);

        // Show/hide suggestions
        if (this.dom.suggestions) {
            this.dom.suggestions.style.display = (state === 'ready' && this.conversation.length === 0) ? 'block' : 'none';
        }
    }

    showStatus(icon, text, subtext) {
        this.cacheDom();
        if (this.dom.statusIcon) this.dom.statusIcon.textContent = icon;
        if (this.dom.statusText) this.dom.statusText.textContent = text;
        if (this.dom.statusSubtext) this.dom.statusSubtext.textContent = subtext || '';
    }

    showTranscript(text) {
        this.cacheDom();
        if (this.dom.transcript) {
            this.dom.transcript.textContent = text;
        }
    }

    // ==================== PUBLIC API ====================

    executeSuggestion(text) {
        if (this.isProcessing || this.isSpeaking) return;
        this.handleInput(text);
    }
}

// ==================== STATIC METHODS FOR HTML ONCLICK ====================

// For backwards compatibility
class AdvancedVoiceAssistant {
    static getInstance() {
        return SuziVoice.getInstance();
    }

    static openModal() {
        SuziVoice.getInstance().open();
    }

    static closeModal() {
        SuziVoice.getInstance().close();
    }
}

// ==================== AUTO-INITIALIZE ====================

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        SuziVoice.getInstance();
    }, 100);
});

// Expose globally
window.SuziVoice = SuziVoice;
window.AdvancedVoiceAssistant = AdvancedVoiceAssistant;
