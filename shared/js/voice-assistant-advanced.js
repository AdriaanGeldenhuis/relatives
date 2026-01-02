/**
 * ============================================
 * SUZI VOICE ASSISTANT v6.0 - COMPLETE SYSTEM
 * Full integration with all app areas
 * Fixed: race conditions, state management, restart logic
 * ============================================
 */

class AdvancedVoiceAssistant {
    static instance = null;

    static getInstance() {
        if (!AdvancedVoiceAssistant.instance) {
            AdvancedVoiceAssistant.instance = new AdvancedVoiceAssistant();
        }
        return AdvancedVoiceAssistant.instance;
    }

    constructor() {
        if (AdvancedVoiceAssistant.instance) {
            return AdvancedVoiceAssistant.instance;
        }

        console.log('🎤 Suzi Voice Assistant v6.0 Initializing...');

        // Detect native vs web
        this.isNativeApp = !!(window.AndroidVoice && typeof window.AndroidVoice.startListening === 'function');

        // Core state
        this.recognition = null;
        this.synthesis = window.speechSynthesis;
        this.isListening = false;
        this.isSpeaking = false;
        this.recognitionActive = false;
        this.processingCommand = false;
        this.modalOpen = false;
        this.initialized = false;

        // Timers
        this.restartTimeout = null;
        this.apiTimeout = null;
        this.speakTimeout = null;

        // Anti-duplicate
        this.lastTranscript = '';
        this.lastTranscriptTime = 0;
        this.commandCooldown = 2000;

        // Conversation history
        this.conversation = [];
        this.maxConversationHistory = 5;

        // DOM cache
        this.dom = {};

        AdvancedVoiceAssistant.instance = this;
    }

    init() {
        if (this.initialized) return;
        
        this.cacheDOMElements();
        
        if (this.isNativeApp) {
            console.log('📱 NATIVE APP MODE');
        } else {
            console.log('🌐 WEB BROWSER MODE');
            this.setupRecognition();
        }
        
        this.preloadVoices();
        
        this.initialized = true;
        console.log('✅ Suzi Voice Assistant v6.0 READY!');
    }

    cacheDOMElements() {
        this.dom.voiceModal = document.getElementById('voiceModal');
        this.dom.statusIcon = document.getElementById('statusIcon');
        this.dom.statusText = document.getElementById('statusText');
        this.dom.statusSubtext = document.getElementById('statusSubtext');
        this.dom.voiceTranscript = document.getElementById('voiceTranscript');
        this.dom.micBtn = document.getElementById('micBtn');
        this.dom.voiceStatus = document.getElementById('voiceStatus');
        this.dom.voiceSuggestions = document.getElementById('voiceSuggestions');
    }

    setupRecognition() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        if (!SpeechRecognition) {
            console.warn('⚠️ Speech Recognition not supported');
            return;
        }

        this.recognition = new SpeechRecognition();
        this.recognition.lang = navigator.language || 'en-US';
        this.recognition.continuous = false;
        this.recognition.interimResults = true;
        this.recognition.maxAlternatives = 1;

        this.recognition.onstart = () => {
            this.recognitionActive = true;
            this.isListening = true;
            this.updateMicState(true);
            this.updateStatus('🎤', 'Listening...', 'Speak now');
        };

        this.recognition.onresult = (event) => {
            const result = event.results[event.results.length - 1];
            const transcript = result[0].transcript.trim();
            
            // Show interim results
            this.updateTranscript(transcript + (result.isFinal ? '' : '...'));
            
            if (result.isFinal && transcript) {
                this.handleTranscript(transcript);
            }
        };

        this.recognition.onerror = (event) => {
            console.log('🎤 Recognition error:', event.error);
            this.recognitionActive = false;
            this.isListening = false;
            this.updateMicState(false);

            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                this.updateStatus('🚫', 'Microphone Blocked', 'Enable in browser settings');
            } else if (event.error === 'no-speech') {
                // Silent restart for no-speech
                this.scheduleRestart(800);
            } else if (event.error !== 'aborted') {
                this.scheduleRestart(1000);
            }
        };

        this.recognition.onend = () => {
            this.recognitionActive = false;
            this.isListening = false;
            this.updateMicState(false);

            // Only restart if modal is open and we're not busy
            if (this.modalOpen && !this.isSpeaking && !this.processingCommand) {
                this.scheduleRestart(600);
            }
        };
    }

    preloadVoices() {
        if (this.synthesis) {
            // Chrome needs this to load voices
            this.synthesis.getVoices();
            if (typeof speechSynthesis !== 'undefined' && speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = () => this.synthesis.getVoices();
            }
        }
    }

    updateMicState(listening) {
        if (this.dom.micBtn) {
            this.dom.micBtn.classList.toggle('listening', listening);
        }
        if (this.dom.voiceStatus) {
            this.dom.voiceStatus.classList.toggle('listening', listening);
        }
    }

    // ==================== NATIVE BRIDGE ====================
    
    static onNativeListeningStart() {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.isListening = true;
        instance.recognitionActive = true;
        instance.updateMicState(true);
        instance.updateStatus('🎤', 'Listening...', 'Speak now');
        instance.updateTranscript('Listening...');
    }

    static onNativeListeningStop() {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.isListening = false;
        instance.recognitionActive = false;
        instance.updateMicState(false);
        
        if (instance.modalOpen && !instance.processingCommand && !instance.isSpeaking) {
            instance.updateStatus('🎤', 'Ready', 'Tap mic to speak');
        }
    }

    static onNativeTranscript(text) {
        const instance = AdvancedVoiceAssistant.getInstance();
        const transcript = (text || '').trim();
        if (transcript) {
            instance.handleTranscript(transcript);
        }
    }

    static onNativeError(code, message) {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.isListening = false;
        instance.recognitionActive = false;
        instance.updateMicState(false);
        
        console.log('🎤 Native recognition issue:', code, message);
        
        if (code === 'not-allowed') {
            instance.updateStatus('🚫', 'Microphone blocked', 'Enable in settings');
            return;
        }
        
        // Restart listening after error
        setTimeout(() => {
            if (instance.modalOpen && !instance.processingCommand && !instance.isSpeaking) {
                instance.startListening();
            }
        }, 800);
    }

    static onNativeSpeakStart() {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.isSpeaking = true;
        instance.updateStatus('🔊', 'Speaking...', '');
    }

    static onNativeSpeakDone() {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.isSpeaking = false;
        
        // Start listening after TTS completes
        if (instance.modalOpen && !instance.processingCommand) {
            setTimeout(() => {
                if (instance.modalOpen && !instance.isSpeaking && !instance.processingCommand) {
                    instance.startListening();
                }
            }, 500);
        }
    }

    // ==================== LISTENING ====================
    
    startNativeListening() {
        if (window.AndroidVoice && typeof window.AndroidVoice.startListening === 'function') {
            try {
                window.AndroidVoice.startListening();
            } catch (error) {
                console.error('❌ Native startListening failed', error);
            }
        }
    }

    stopNativeListening() {
        if (window.AndroidVoice && typeof window.AndroidVoice.stopListening === 'function') {
            try {
                window.AndroidVoice.stopListening();
            } catch (error) {}
        }
        this.isListening = false;
        this.recognitionActive = false;
        this.updateMicState(false);
    }

    startListening() {
        // For native: don't start if speaking (can't do both)
        // For web: allow starting even while speaking (user can interrupt)
        if (this.isNativeApp) {
            if (this.recognitionActive || this.isListening || this.isSpeaking || this.processingCommand) {
                return;
            }
            this.startNativeListening();
            return;
        }

        // Web: allow listening while speaking for natural interruption
        if (this.recognitionActive || this.isListening || this.processingCommand) {
            return;
        }

        if (!this.recognition) {
            this.updateStatus('❌', 'Not supported', 'Use Chrome, Edge, or Safari');
            return;
        }

        this.clearAllTimeouts();

        try {
            this.recognition.start();
            this.updateStatus('🎤', 'Listening...', 'Speak now');
            this.updateTranscript('Listening...');
        } catch (error) {
            if (error.name === 'InvalidStateError') {
                // Already running, ignore
                console.log('🎤 Recognition already active');
            } else {
                console.error('🎤 Start failed:', error);
                this.scheduleRestart(1000);
            }
        }
    }

    stopListening() {
        this.clearAllTimeouts();
        
        if (this.isNativeApp) {
            this.stopNativeListening();
            return;
        }

        if (this.recognition) {
            try {
                this.recognition.abort();
            } catch (e) {}
        }

        this.isListening = false;
        this.recognitionActive = false;
        this.updateMicState(false);
    }

    scheduleRestart(delay = 600) {
        if (this.isNativeApp) return;
        
        // Clear existing timeout
        if (this.restartTimeout) {
            clearTimeout(this.restartTimeout);
            this.restartTimeout = null;
        }

        this.restartTimeout = setTimeout(() => {
            this.restartTimeout = null;
            
            // Double-check conditions before restarting
            if (this.modalOpen && 
                !this.isSpeaking && 
                !this.processingCommand && 
                !this.recognitionActive &&
                !this.isListening) {
                this.startListening();
            }
        }, delay);
    }

    clearAllTimeouts() {
        if (this.restartTimeout) {
            clearTimeout(this.restartTimeout);
            this.restartTimeout = null;
        }
        if (this.apiTimeout) {
            clearTimeout(this.apiTimeout);
            this.apiTimeout = null;
        }
        if (this.speakTimeout) {
            clearTimeout(this.speakTimeout);
            this.speakTimeout = null;
        }
    }

    // ==================== MODAL ====================
    
    static openModal() {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.openModal();
    }

    static closeModal() {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.closeModal();
    }

    openModal() {
        if (!this.initialized) {
            this.init();
        }
        
        if (!this.dom.voiceModal) {
            this.cacheDOMElements();
        }
        
        if (!this.dom.voiceModal) return;

        this.modalOpen = true;
        this.dom.voiceModal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reset state
        this.processingCommand = false;
        this.isSpeaking = false;
        this.isListening = false;
        this.recognitionActive = false;
        this.lastTranscript = '';
        
        // Show initial state
        this.updateStatus('🎤', 'Hi there!', 'How can I help?');
        this.updateTranscript('Listening...');
        this.showSuggestions(true);

        // For native: speak and let onNativeSpeakDone handle listening
        // For web: speak then start listening via callback
        if (this.isNativeApp) {
            this.speak('How can I help?');
            // Native will call onNativeSpeakDone which starts listening
        } else {
            // Web: start listening immediately, speak in background
            // This is more responsive - user can interrupt
            this.speak('How can I help?');
            
            // Start listening after a short delay for speech to begin
            setTimeout(() => {
                if (this.modalOpen && !this.isListening && !this.recognitionActive) {
                    this.startListening();
                }
            }, 800);
        }
    }

    closeModal() {
        // Stop everything first
        this.stopListening();
        this.stopSpeaking();
        this.clearAllTimeouts();

        if (this.dom.voiceModal) {
            this.dom.voiceModal.classList.remove('active');
        }
        
        document.body.style.overflow = '';
        this.modalOpen = false;
        this.processingCommand = false;
        this.isSpeaking = false;
        this.isListening = false;
        this.recognitionActive = false;
        this.lastTranscript = '';
        
        this.updateMicState(false);
    }

    showSuggestions(show) {
        if (this.dom.voiceSuggestions) {
            this.dom.voiceSuggestions.style.display = show ? 'block' : 'none';
        }
    }

    // ==================== TRANSCRIPT HANDLING ====================
    
    handleTranscript(transcript) {
        if (!transcript || !this.modalOpen) return;

        // Stop listening while processing
        this.stopListening();

        // Stop words close the conversation
        const stopWords = ['stop', 'bye', 'goodbye', 'cancel', 'exit', 'quit', 'close', 'nevermind', 'never mind'];
        const lower = transcript.toLowerCase().trim();
        
        if (stopWords.some(w => lower === w || lower === w + ' suzi' || lower === 'hey ' + w)) {
            this.updateTranscript(transcript);
            this.speak('Goodbye!', () => this.closeModal(), 1.3);
            return;
        }

        // Check for duplicates
        const now = Date.now();
        if (transcript === this.lastTranscript && (now - this.lastTranscriptTime) < this.commandCooldown) {
            this.scheduleRestart(500);
            return;
        }

        // Don't process if already processing
        if (this.processingCommand) {
            return;
        }

        this.lastTranscript = transcript;
        this.lastTranscriptTime = now;
        
        this.updateTranscript(transcript);
        this.showSuggestions(false);
        this.processVoiceCommand(transcript);
    }

    // ==================== COMMAND PROCESSING ====================
    
    async processVoiceCommand(command) {
        if (this.processingCommand || !command || command.trim().length === 0) return;

        this.processingCommand = true;
        this.stopListening();

        this.updateStatus('⚙️', 'Thinking...', 'Processing your request');

        // Set a timeout for the API call
        const controller = new AbortController();
        this.apiTimeout = setTimeout(() => {
            controller.abort();
        }, 12000); // 12 second timeout

        try {
            const response = await fetch('/api/voice-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transcript: command,
                    page: window.location.pathname,
                    conversation: this.conversation.slice(-4) // Only send last 4 messages
                }),
                signal: controller.signal
            });

            clearTimeout(this.apiTimeout);
            this.apiTimeout = null;

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (!data || !data.intent) {
                throw new Error('Invalid response');
            }

            // Add to conversation history
            this.conversation.push({ role: 'user', content: command });
            this.conversation.push({ role: 'assistant', content: data.response_text });
            
            // Keep only last N exchanges
            if (this.conversation.length > this.maxConversationHistory * 2) {
                this.conversation = this.conversation.slice(-this.maxConversationHistory * 2);
            }

            await this.executeIntent(data);

        } catch (error) {
            clearTimeout(this.apiTimeout);
            this.apiTimeout = null;

            console.error('Voice command error:', error);
            
            let fallback = "Sorry, I couldn't process that. Try again?";
            if (error.name === 'AbortError') {
                fallback = "That took too long. Please try again.";
            }
            
            this.updateStatus('❓', 'Oops', fallback);
            
            this.speak(fallback, () => {
                this.processingCommand = false;
                this.scheduleRestart(800);
            }, 1.1);
        }
    }

    async executeIntent(intentData) {
        const { intent, slots, response_text } = intentData;

        // Update UI
        const icons = {
            'add_shopping_item': '🛒',
            'create_note': '📝',
            'create_event': '📅',
            'create_schedule': '⏰',
            'get_weather_today': '🌤️',
            'get_weather_tomorrow': '🌤️',
            'get_weather_week': '🌤️',
            'send_message': '💬',
            'navigate': '🧭',
            'smalltalk': '💬',
            'error': '❌'
        };
        
        this.updateStatus(icons[intent] || '✅', 'Got it!', response_text.substring(0, 50) + (response_text.length > 50 ? '...' : ''));

        // Determine if this intent navigates away
        const navigationIntents = [
            'navigate', 
            'add_shopping_item', 
            'create_event', 
            'create_schedule', 
            'create_note',
            'send_message',
            'view_shopping',
            'show_calendar',
            'show_schedule',
            'read_messages',
            'show_location',
            'find_member',
            'check_notifications'
        ];
        
        const willNavigate = navigationIntents.includes(intent);

        // Speak the response
        this.speak(response_text, () => {
            this.processingCommand = false;

            if (!willNavigate && this.modalOpen) {
                this.scheduleRestart(800);
            }
        }, 1.05);

        // Execute the action
        try {
            switch (intent) {
                // ========== SHOPPING ==========
                case 'add_shopping_item':
                    await this.addToShopping(slots.item, slots.quantity, slots.category);
                    break;
                
                case 'view_shopping':
                    this.navigate('/shopping/');
                    break;
                
                case 'clear_bought':
                    await this.clearBoughtItems();
                    break;

                // ========== NOTES ==========
                case 'create_note':
                    this.navigateToCreateNote(slots);
                    break;
                
                case 'search_notes':
                    this.navigate('/notes/?search=' + encodeURIComponent(slots.query || ''));
                    break;

                // ========== CALENDAR ==========
                case 'create_event':
                    this.navigateToCreateEvent(slots);
                    break;
                
                case 'show_calendar':
                    const calendarDate = this.parseDate(slots.date || 'today');
                    this.navigate('/calendar/?date=' + calendarDate);
                    break;
                
                case 'next_event':
                    await this.showNextEvent();
                    break;

                // ========== SCHEDULE ==========
                case 'create_schedule':
                    this.navigateToCreateSchedule(slots);
                    break;
                
                case 'show_schedule':
                    const scheduleDate = this.parseDate(slots.date || 'today');
                    this.navigate('/schedule/?date=' + scheduleDate);
                    break;

                // ========== WEATHER ==========
                case 'get_weather_today':
                case 'get_weather_tomorrow':
                case 'get_weather_week':
                    await this.getWeather(intent);
                    break;

                // ========== MESSAGES ==========
                case 'send_message':
                    await this.sendMessage(slots.content);
                    break;
                
                case 'read_messages':
                    this.navigate('/messages/');
                    break;

                // ========== TRACKING ==========
                case 'show_location':
                    this.navigate('/tracking/');
                    break;
                
                case 'find_member':
                    this.navigate('/tracking/?search=' + encodeURIComponent(slots.member_name || ''));
                    break;

                // ========== NOTIFICATIONS ==========
                case 'check_notifications':
                    this.navigate('/notifications/');
                    break;
                
                case 'mark_all_read':
                    await this.markAllNotificationsRead();
                    break;

                // ========== NAVIGATION ==========
                case 'navigate':
                    this.navigate(this.getNavigationPath(slots.destination));
                    break;

                // ========== SMART FEATURES ==========
                case 'get_stats':
                    this.navigate('/home/#stats');
                    break;
                
                case 'get_suggestions':
                    await this.getAISuggestions();
                    break;

                // ========== SMALLTALK ==========
                case 'smalltalk':
                    // Just speak the response, no action needed
                    break;

                default:
                    console.log('Unknown intent:', intent);
            }
        } catch (error) {
            console.error('Intent execution error:', error);
        }
    }

    // ==================== INTENT ACTIONS ====================

    async addToShopping(item, quantity, category = 'other') {
        if (!item) {
            this.updateStatus('❌', 'Missing item', 'Please specify what to add');
            return;
        }

        try {
            const listId = window.currentListId || await this.getDefaultShoppingList();
            
            if (!listId) {
                throw new Error('No shopping list found');
            }

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('list_id', listId);
            formData.append('name', item);
            if (quantity) formData.append('qty', quantity);
            formData.append('category', category || 'other');

            const response = await fetch('/shopping/api/items.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to add item');

            // Navigate after speech finishes
            this.speakTimeout = setTimeout(() => {
                this.closeModal();
                if (window.location.pathname.includes('/shopping/')) {
                    location.reload();
                } else {
                    window.location.href = '/shopping/';
                }
            }, 2000);

        } catch (error) {
            console.error('Add to shopping failed:', error);
            this.updateStatus('❌', 'Failed', error.message || 'Could not add item');
        }
    }

    async getDefaultShoppingList() {
        try {
            const response = await fetch('/shopping/api/lists.php?action=get_all');
            const data = await response.json();
            return data.lists && data.lists[0] ? data.lists[0].id : null;
        } catch (error) {
            console.error('Failed to get shopping lists:', error);
            return null;
        }
    }

    async clearBoughtItems() {
        try {
            const listId = window.currentListId || await this.getDefaultShoppingList();
            
            const formData = new FormData();
            formData.append('action', 'clear_bought');
            formData.append('list_id', listId);

            await fetch('/shopping/api/items.php', {
                method: 'POST',
                body: formData
            });

            this.speakTimeout = setTimeout(() => {
                if (window.location.pathname.includes('/shopping/')) {
                    location.reload();
                }
            }, 2000);
        } catch (error) {
            console.error('Clear bought failed:', error);
        }
    }

    navigateToCreateNote(slots) {
        let url = '/notes/?new=1';
        if (slots.content) url += '&content=' + encodeURIComponent(slots.content);
        if (slots.title) url += '&title=' + encodeURIComponent(slots.title);
        
        this.speakTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 2000);
    }

    navigateToCreateEvent(slots) {
        let url = '/calendar/?new=1';
        if (slots.title) url += '&content=' + encodeURIComponent(slots.title);
        if (slots.date) url += '&date=' + this.parseDate(slots.date);
        if (slots.time) url += '&time=' + slots.time;
        
        this.speakTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 2000);
    }

    navigateToCreateSchedule(slots) {
        let url = '/schedule/?new=1';
        if (slots.title) url += '&content=' + encodeURIComponent(slots.title);
        if (slots.date) url += '&date=' + this.parseDate(slots.date);
        if (slots.time) url += '&time=' + slots.time;
        if (slots.type) url += '&type=' + slots.type;
        
        this.speakTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 2000);
    }

    async getWeather(intent) {
        // Weather details are handled by the API response_text
        // Navigate to weather page after speaking
        this.speakTimeout = setTimeout(() => {
            if (!window.location.pathname.includes('/weather/')) {
                this.navigate('/weather/');
            }
        }, 3000);
    }

    async sendMessage(content) {
        if (!content) return;
        
        try {
            const formData = new FormData();
            formData.append('content', content);
            formData.append('to_family', '1');

            await fetch('/messages/api/send.php', {
                method: 'POST',
                body: formData
            });

            this.speakTimeout = setTimeout(() => {
                if (window.location.pathname.includes('/messages/')) {
                    location.reload();
                }
            }, 2000);
        } catch (error) {
            console.error('Send message failed:', error);
        }
    }

    async showNextEvent() {
        this.speakTimeout = setTimeout(() => {
            this.navigate('/calendar/');
        }, 2500);
    }

    async markAllNotificationsRead() {
        try {
            const formData = new FormData();
            formData.append('action', 'mark_all_read');

            await fetch('/notifications/api/', {
                method: 'POST',
                body: formData
            });

            this.speakTimeout = setTimeout(() => {
                if (window.location.pathname.includes('/notifications/')) {
                    location.reload();
                } else {
                    // Update badge if available
                    if (window.HeaderMenu && typeof window.HeaderMenu.updateNotificationBadge === 'function') {
                        window.HeaderMenu.updateNotificationBadge(0);
                    }
                }
            }, 2000);
        } catch (error) {
            console.error('Mark all read failed:', error);
        }
    }

    async getAISuggestions() {
        this.speakTimeout = setTimeout(() => {
            this.navigate('/home/#suggestions');
        }, 2500);
    }

    navigate(url) {
        this.updateStatus('🧭', 'Navigating...', 'One moment');
        this.speakTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 1500);
    }

    getNavigationPath(destination) {
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
            help: '/help/'
        };
        return paths[destination] || '/home/';
    }

    parseDate(dateString) {
        if (!dateString) return new Date().toISOString().split('T')[0];
        
        const today = new Date();
        const lower = dateString.toLowerCase().trim();
        
        if (lower === 'today') {
            return today.toISOString().split('T')[0];
        }
        
        if (lower === 'tomorrow') {
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            return tomorrow.toISOString().split('T')[0];
        }
        
        // If already in YYYY-MM-DD format
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
            return dateString;
        }
        
        // Try to parse as date
        try {
            const parsed = new Date(dateString);
            if (!isNaN(parsed.getTime())) {
                return parsed.toISOString().split('T')[0];
            }
        } catch (e) {}
        
        return today.toISOString().split('T')[0];
    }

    // ==================== TEXT-TO-SPEECH ====================
    
    speak(text, onEndCallback = null, rate = 1.1) {
        if (!text) {
            if (onEndCallback) onEndCallback();
            return;
        }

        // Native app TTS
        if (window.AndroidVoice && typeof window.AndroidVoice.speak === 'function') {
            try {
                this.isSpeaking = true;
                this.updateStatus('🔊', 'Speaking...', '');
                window.AndroidVoice.speak(text);
                // Native callbacks will handle completion
                return;
            } catch (error) {
                console.error('❌ Native speak failed', error);
                this.isSpeaking = false;
            }
        }

        // Web Speech API
        if (!this.synthesis) {
            if (onEndCallback) onEndCallback();
            return;
        }

        // Stop any current speech
        this.stopSpeaking();
        
        // Also stop listening to prevent echo
        if (!this.isNativeApp && this.recognitionActive) {
            try {
                this.recognition.abort();
            } catch (e) {}
        }

        const utterance = new SpeechSynthesisUtterance(text);

        // Get best voice
        const voices = this.synthesis.getVoices();
        const preferredVoice =
            voices.find(v => v.lang.startsWith('en') && v.name.toLowerCase().includes('female')) ||
            voices.find(v => v.lang.startsWith('en') && !v.name.toLowerCase().includes('male')) ||
            voices.find(v => v.lang.startsWith('en'));

        if (preferredVoice) utterance.voice = preferredVoice;

        utterance.rate = rate;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        utterance.onstart = () => {
            this.isSpeaking = true;
        };

        utterance.onerror = (event) => {
            if (event.error !== 'interrupted' && event.error !== 'canceled') {
                console.error('Speech error:', event.error);
            }
            this.isSpeaking = false;
            if (onEndCallback) {
                setTimeout(onEndCallback, 200);
            }
        };

        // Fallback timeout in case onend doesn't fire (Chrome bug)
        const fallbackTimeout = setTimeout(() => {
            if (this.isSpeaking) {
                console.log('🔊 Speech fallback timeout triggered');
                this.isSpeaking = false;
                if (onEndCallback) onEndCallback();
            }
        }, (text.length * 100) + 3000);

        utterance.onend = () => {
            clearTimeout(fallbackTimeout);
            this.isSpeaking = false;
            if (onEndCallback) {
                setTimeout(onEndCallback, 200);
            }
        };

        this.synthesis.speak(utterance);
    }

    stopSpeaking() {
        if (this.synthesis) {
            this.synthesis.cancel();
        }
        this.isSpeaking = false;
    }

    // ==================== UI UPDATES ====================
    
    updateStatus(icon, text, subtext) {
        if (this.dom.statusIcon) this.dom.statusIcon.textContent = icon;
        if (this.dom.statusText) this.dom.statusText.textContent = text;
        if (this.dom.statusSubtext) this.dom.statusSubtext.textContent = subtext || '';
    }

    updateTranscript(text) {
        if (this.dom.voiceTranscript) {
            this.dom.voiceTranscript.textContent = text || 'Listening...';
        }
    }

    executeSuggestion(command) {
        if (this.processingCommand) return;
        
        this.updateTranscript(command);
        this.showSuggestions(false);
        this.processVoiceCommand(command);
    }
}

// ==================== AUTO-INITIALIZE ====================
document.addEventListener('DOMContentLoaded', () => {
    // Small delay to ensure all DOM is ready
    setTimeout(() => {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.init();
    }, 100);
});

// Expose to global scope
window.AdvancedVoiceAssistant = AdvancedVoiceAssistant;