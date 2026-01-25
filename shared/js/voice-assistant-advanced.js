/**
 * ============================================
 * SUZI VOICE ASSISTANT v7.1 - CONVERSATIONAL
 * Typing animation + continuous conversation
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

        console.log('🎤 Suzi Voice Assistant v7.0 Initializing...');

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
        this.typingTimeout = null;
        this.typingIntervals = [];

        // Anti-duplicate
        this.lastTranscript = '';
        this.lastTranscriptTime = 0;
        this.commandCooldown = 1500; // Reduced from 2000

        // Conversation history
        this.conversation = [];
        this.maxConversationHistory = 3; // Reduced from 5

        // DOM cache
        this.dom = {};

        // Local intent patterns (fast matching, no API needed)
        this.localIntents = this.buildLocalIntents();

        // Setup global hooks for native app callbacks
        this.setupNativeHooks();

        AdvancedVoiceAssistant.instance = this;
    }

    /**
     * Setup global SuziVoice hooks for native app communication
     * Native app should call these when TTS finishes or STT has a result
     */
    setupNativeHooks() {
        window.SuziVoice = {
            // Called when native STT has a transcript result
            onSttResult: (text) => {
                const v = AdvancedVoiceAssistant.getInstance();
                if (text && text.trim()) {
                    v.handleTranscript(text.trim());
                }
            },
            // Called when native TTS finishes speaking
            onTtsEnd: () => {
                const v = AdvancedVoiceAssistant.getInstance();
                v.isSpeaking = false;
                // Ask follow-up if modal still open and not processing
                if (v.modalOpen && !v.processingCommand) {
                    v.askFollowUp();
                }
            },
            // Called when native TTS starts speaking
            onTtsStart: () => {
                const v = AdvancedVoiceAssistant.getInstance();
                v.isSpeaking = true;
                v.updateStatus('🔊', 'Speaking...', '');
            },
            // Called when native STT starts listening
            onSttStart: () => {
                const v = AdvancedVoiceAssistant.getInstance();
                v.isListening = true;
                v.recognitionActive = true;
                v.updateMicState(true);
                v.updateStatus('🎤', 'Listening...', 'Speak now');
            },
            // Called when native STT stops listening
            onSttStop: () => {
                const v = AdvancedVoiceAssistant.getInstance();
                v.isListening = false;
                v.recognitionActive = false;
                v.updateMicState(false);
            }
        };
    }

    // ==================== LOCAL INTENT MATCHING ====================

    buildLocalIntents() {
        return [
            // SHOPPING - very common
            {
                patterns: [
                    // "add sugar" / "add sugar to my shopping list" / "add sugar to the list"
                    /^add\s+(.+?)(?:\s+(?:to|on)\s+(?:my|the)?\s*(?:shopping\s*)?list)?\s*$/i,
                    // "put sugar on my shopping list"
                    /^put\s+(.+?)(?:\s+(?:to|on)\s+(?:my|the)?\s*(?:shopping\s*)?list)?\s*$/i,
                    // "shopping add sugar"
                    /^shopping\s+add\s+(.+?)\s*$/i,
                    // "buy sugar"
                    /^buy\s+(.+?)\s*$/i
                ],
                handler: (match) => {
                    let item = (match[1] || '').trim();

                    // Extra safety: strip trailing "to my shopping list" if it sneaks in
                    item = item.replace(/\s+(?:to|on)\s+(?:my|the)\s+(?:shopping\s*)?list\s*$/i, '').trim();
                    // Also strip just "to my list" or "on the list"
                    item = item.replace(/\s+(?:to|on)\s+(?:my|the)\s+list\s*$/i, '').trim();

                    const category = this.guessCategory(item);
                    return {
                        intent: 'add_shopping_item',
                        slots: { item, category },
                        response_text: `Added ${item} to your shopping list.`
                    };
                }
            },
            {
                patterns: [/^(?:show|open|go to) (?:the )?shopping/i, /^shopping list/i],
                handler: () => ({
                    intent: 'view_shopping',
                    slots: {},
                    response_text: 'Opening your shopping list!'
                })
            },

            // NAVIGATION - instant
            {
                patterns: [/^(?:go to|open|show) (?:the )?home/i, /^home$/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'home' }, response_text: 'Going home!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?notes?/i, /^notes?$/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'notes' }, response_text: 'Opening notes!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?calendar/i, /^calendar$/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'calendar' }, response_text: 'Opening calendar!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?messages?/i, /^messages?$/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'messages' }, response_text: 'Opening messages!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?tracking/i, /^(?:where is everyone|family location)/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'tracking' }, response_text: 'Opening tracking!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?weather/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'weather' }, response_text: 'Opening weather!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?schedule/i, /^schedule$/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'schedule' }, response_text: 'Opening schedule!' })
            },
            {
                patterns: [/^(?:go to|open|show) (?:the )?notifications?/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'notifications' }, response_text: 'Opening notifications!' })
            },

            // WEATHER - common, can handle locally
            {
                patterns: [/^(?:what'?s? the )?weather(?: today)?$/i, /^how'?s? the weather/i, /^weather today/i],
                handler: () => ({ intent: 'get_weather_today', slots: {}, response_text: 'Let me check the weather for you.' })
            },
            {
                patterns: [/^weather tomorrow/i, /^tomorrow'?s? weather/i, /^what'?s? the weather tomorrow/i],
                handler: () => ({ intent: 'get_weather_tomorrow', slots: {}, response_text: 'Checking tomorrow\'s forecast.' })
            },

            // TRACKING - find family
            {
                patterns: [/^(?:where'?s?|find|locate) (?:my )?(mom|dad|mum|mother|father|wife|husband|son|daughter|brother|sister|grandma|grandpa|grandmother|grandfather)/i],
                handler: (match) => ({
                    intent: 'find_member',
                    slots: { member_name: match[1] },
                    response_text: `Looking for ${match[1]}!`
                })
            },
            {
                patterns: [/^(?:where'?s?|find|locate) (.+)/i],
                handler: (match) => ({
                    intent: 'find_member',
                    slots: { member_name: match[1].trim() },
                    response_text: `Looking for ${match[1].trim()}!`
                })
            },

            // TIME - instant local response
            {
                patterns: [/^what time is it/i, /^what'?s? the time/i, /^time$/i],
                handler: () => {
                    const now = new Date();
                    const time = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                    return { intent: 'smalltalk', slots: {}, response_text: `It's ${time}.` };
                }
            },
            {
                patterns: [/^what'?s? (?:today'?s? )?date/i, /^what day is it/i],
                handler: () => {
                    const now = new Date();
                    const date = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
                    return { intent: 'smalltalk', slots: {}, response_text: `Today is ${date}.` };
                }
            },

            // GREETINGS - instant
            {
                patterns: [/^(?:hi|hello|hey)(?: suzi)?$/i, /^good (?:morning|afternoon|evening)/i],
                handler: () => {
                    const greetings = ['Hey there!', 'Hi! How can I help?', 'Hello! What can I do for you?'];
                    return { intent: 'smalltalk', slots: {}, response_text: greetings[Math.floor(Math.random() * greetings.length)] };
                }
            },
            {
                patterns: [/^(?:thanks?|thank you)/i],
                handler: () => ({ intent: 'smalltalk', slots: {}, response_text: 'You\'re welcome!' })
            },
            {
                patterns: [/^(?:what can you do|help|commands)/i],
                handler: () => ({
                    intent: 'smalltalk',
                    slots: {},
                    response_text: 'I can help with shopping lists, notes, calendar, messages, weather, and finding family. Just ask!'
                })
            },

            // CREATE NOTE - capture content
            {
                patterns: [/^(?:create|make|new|add) (?:a )?note(?::? (.+))?/i, /^note(?::? (.+))?/i],
                handler: (match) => ({
                    intent: 'create_note',
                    slots: { content: match[1]?.trim() || '' },
                    response_text: match[1] ? 'Creating your note!' : 'Opening notes for you!'
                })
            },

            // MESSAGES
            {
                patterns: [/^(?:send|tell) (?:a )?message(?::? (.+))?/i],
                handler: (match) => ({
                    intent: 'send_message',
                    slots: { content: match[1]?.trim() || '' },
                    response_text: match[1] ? 'Sending your message!' : 'What would you like to say?'
                })
            }
        ];
    }

    guessCategory(item) {
        const categories = {
            dairy: ['milk', 'cheese', 'yogurt', 'butter', 'cream', 'eggs', 'yoghurt'],
            meat: ['chicken', 'beef', 'pork', 'lamb', 'fish', 'bacon', 'sausage', 'mince', 'steak', 'chops'],
            produce: ['apple', 'banana', 'orange', 'tomato', 'potato', 'onion', 'carrot', 'lettuce', 'spinach', 'fruit', 'vegetable', 'avocado', 'lemon', 'garlic'],
            bakery: ['bread', 'rolls', 'buns', 'cake', 'pastry', 'croissant', 'muffin'],
            pantry: ['rice', 'pasta', 'flour', 'sugar', 'salt', 'oil', 'sauce', 'spice', 'cereal', 'coffee', 'tea'],
            frozen: ['ice cream', 'frozen', 'pizza'],
            snacks: ['chips', 'chocolate', 'candy', 'cookies', 'biscuits', 'nuts', 'crisps'],
            beverages: ['juice', 'soda', 'water', 'wine', 'beer', 'coke', 'sprite', 'drink'],
            household: ['soap', 'detergent', 'toilet paper', 'paper towel', 'cleaning', 'shampoo', 'toothpaste']
        };

        const lower = item.toLowerCase();
        for (const [category, keywords] of Object.entries(categories)) {
            if (keywords.some(kw => lower.includes(kw))) {
                return category;
            }
        }
        return 'other';
    }

    tryLocalIntent(transcript) {
        const text = transcript.trim();

        for (const intent of this.localIntents) {
            for (const pattern of intent.patterns) {
                const match = text.match(pattern);
                if (match) {
                    console.log('⚡ Local intent match:', pattern);
                    return intent.handler(match);
                }
            }
        }

        return null; // No local match, need API
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
                this.scheduleRestart(400); // Reduced from 800
            } else if (event.error !== 'aborted') {
                this.scheduleRestart(500); // Reduced from 1000
            }
        };

        this.recognition.onend = () => {
            this.recognitionActive = false;
            this.isListening = false;
            this.updateMicState(false);

            // Only restart if modal is open and we're not busy
            if (this.modalOpen && !this.isSpeaking && !this.processingCommand) {
                this.scheduleRestart(300); // Reduced from 600
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

    scheduleRestart(delay = 300) { // Reduced from 600
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
        if (this.typingTimeout) {
            clearTimeout(this.typingTimeout);
            this.typingTimeout = null;
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

        // Start listening immediately - don't wait for greeting
        if (this.isNativeApp) {
            this.speak('How can I help?');
        } else {
            // Web: start listening RIGHT AWAY, speak greeting in background
            // User can interrupt immediately
            this.startListening();
            this.speak('How can I help?');
        }
    }

    closeModal() {
        // Stop everything first
        this.stopListening();
        this.stopSpeaking();
        this.stopTyping();
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

        // Stop words and closing phrases - these close the conversation
        const closeWords = [
            'stop', 'bye', 'goodbye', 'cancel', 'exit', 'quit', 'close',
            'nevermind', 'never mind', 'no', 'nope', 'nothing', 'no thanks',
            "that's all", "that's it", "all done", "i'm good", "i'm done",
            "no thank you", "nothing else", "that'll be all"
        ];
        const lower = transcript.toLowerCase().trim();

        // Check for closing phrases
        const isClosingPhrase = closeWords.some(w =>
            lower === w ||
            lower === w + ' suzi' ||
            lower === 'hey ' + w ||
            lower.startsWith(w + ' ') ||
            lower.endsWith(' ' + w)
        );

        if (isClosingPhrase) {
            this.updateTranscript(transcript);
            const farewells = ['Goodbye!', 'Bye for now!', 'Talk soon!', 'See you later!'];
            const farewell = farewells[Math.floor(Math.random() * farewells.length)];
            this.speak(farewell, () => this.closeModal(), 1.3);
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

        // TRY LOCAL INTENT FIRST (instant, no API)
        const localResult = this.tryLocalIntent(command);

        if (localResult) {
            console.log('⚡ Using local intent - skipping API');
            this.updateStatus('✅', 'Got it!', '');

            // Add to conversation history
            this.conversation.push({ role: 'user', content: command });
            this.conversation.push({ role: 'assistant', content: localResult.response_text });

            await this.executeIntent(localResult);
            return;
        }

        // No local match - use API
        this.updateStatus('⚙️', 'Thinking...', '');

        // Reduced timeout: 8 seconds
        const controller = new AbortController();
        this.apiTimeout = setTimeout(() => {
            controller.abort();
        }, 8000);

        try {
            const response = await fetch('/api/voice-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transcript: command,
                    page: window.location.pathname,
                    conversation: this.conversation.slice(-2) // Reduced: only last 2 messages
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

            let fallback = "Sorry, couldn't get that. Try again?";
            if (error.name === 'AbortError') {
                fallback = "Taking too long. Try again?";
            }

            this.updateStatus('❓', 'Oops', fallback);

            this.speak(fallback, () => {
                this.processingCommand = false;
                this.scheduleRestart(400); // Reduced from 800
            }, 1.15);
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
        // NOTE: add_shopping_item is NOT a navigation intent - user stays on current page
        const navigationIntents = [
            'navigate',
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

        // Speak the response, then ask follow-up for non-navigation intents
        this.speak(response_text, () => {
            this.processingCommand = false;

            // For non-navigation intents, ask "anything else?" and keep listening
            if (!willNavigate && this.modalOpen) {
                this.askFollowUp();
            }
        }, 1.1); // Slightly faster speech

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

            // If we're already on the shopping page, reload to show new item
            // But do NOT navigate away from other pages - let user continue conversation
            if (window.location.pathname.includes('/shopping/')) {
                this.speakTimeout = setTimeout(() => {
                    location.reload();
                }, 300);
            }
            // DO NOT navigate away from current page
            // executeIntent() will handle follow-up ("Anything else?")

        } catch (error) {
            console.error('Add to shopping failed:', error);
            this.updateStatus('❌', 'Failed', error.message || 'Could not add item');
        }
    }

    async getDefaultShoppingList() {
        try {
            // 1) Fetch lists WITH cookies (credentials: same-origin)
            const response = await fetch('/shopping/api/lists.php?action=get_all', {
                credentials: 'same-origin'
            });

            if (!response.ok) {
                console.error('Failed to fetch shopping lists:', response.status);
                return null;
            }

            const data = await response.json().catch(() => null);
            if (!data || !data.success) {
                console.error('Invalid shopping lists response');
                return null;
            }

            // If lists exist, return the first one
            if (data.lists && data.lists[0]) {
                return data.lists[0].id;
            }

            // 2) No lists exist - auto-create "Main List"
            console.log('No shopping lists found, creating Main List...');
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('name', 'Main List');
            formData.append('icon', '🛒');

            const createResponse = await fetch('/shopping/api/lists.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            if (!createResponse.ok) {
                console.error('Failed to create shopping list:', createResponse.status);
                return null;
            }

            const createData = await createResponse.json().catch(() => null);
            if (createData && createData.success && createData.list_id) {
                return createData.list_id;
            }

            return null;
        } catch (error) {
            console.error('Failed to get/create shopping list:', error);
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
            }, 800);
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
        }, 600);
    }

    navigateToCreateEvent(slots) {
        let url = '/calendar/?new=1';
        if (slots.title) url += '&content=' + encodeURIComponent(slots.title);
        if (slots.date) url += '&date=' + this.parseDate(slots.date);
        if (slots.time) url += '&time=' + slots.time;

        this.speakTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 600);
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
        }, 600);
    }

    async getWeather(intent) {
        // Weather details are handled by the API response_text
        // Navigate to weather page after speaking
        this.speakTimeout = setTimeout(() => {
            if (!window.location.pathname.includes('/weather/')) {
                this.navigate('/weather/');
            }
        }, 1000);
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
            }, 600);
        } catch (error) {
            console.error('Send message failed:', error);
        }
    }

    async showNextEvent() {
        this.speakTimeout = setTimeout(() => {
            this.navigate('/calendar/');
        }, 800);
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
            }, 600);
        } catch (error) {
            console.error('Mark all read failed:', error);
        }
    }

    async getAISuggestions() {
        this.speakTimeout = setTimeout(() => {
            this.navigate('/home/#suggestions');
        }, 800);
    }

    navigate(url) {
        this.updateStatus('🧭', 'Navigating...', '');
        this.speakTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 600); // Reduced from 1500
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

        // Start typing animation alongside speech
        this.typeText(text);

        // Native app TTS
        if (window.AndroidVoice && typeof window.AndroidVoice.speak === 'function') {
            try {
                this.isSpeaking = true;
                this.updateStatus('🔊', 'Speaking...', '');
                window.AndroidVoice.speak(text);

                // Fallback timer: assume TTS done after estimated time
                // (in case native callback doesn't fire)
                const estimatedDuration = Math.max(1200, text.split(' ').length * 350);
                setTimeout(() => {
                    if (this.isSpeaking) {
                        console.log('🔊 Native TTS fallback timeout triggered');
                        window.SuziVoice?.onTtsEnd?.();
                    }
                    if (onEndCallback) onEndCallback();
                }, estimatedDuration);

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
        }, (text.length * 80) + 2000); // Reduced from (100 * len) + 3000

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

    /**
     * Typing animation - displays words one by one
     */
    typeText(text, onComplete = null) {
        if (!text || !this.dom.voiceTranscript) {
            if (onComplete) onComplete();
            return;
        }

        // Clear any existing typing animation
        this.stopTyping();

        const words = text.split(' ');
        let currentIndex = 0;
        this.dom.voiceTranscript.textContent = '';

        // Calculate delay per word based on speech rate
        // Average speaking rate is about 150 words per minute = 400ms per word
        // We use slightly faster typing to finish before speech ends
        const delayPerWord = Math.max(80, Math.min(200, (text.length * 60) / words.length));

        const typeNextWord = () => {
            if (currentIndex < words.length && this.modalOpen) {
                this.dom.voiceTranscript.textContent = words.slice(0, currentIndex + 1).join(' ');
                currentIndex++;
                this.typingTimeout = setTimeout(typeNextWord, delayPerWord);
            } else {
                // Done typing
                this.stopTyping();
                if (onComplete) onComplete();
            }
        };

        // Start typing
        typeNextWord();
    }

    stopTyping() {
        if (this.typingTimeout) {
            clearTimeout(this.typingTimeout);
            this.typingTimeout = null;
        }
        // Clear any intervals
        this.typingIntervals.forEach(id => clearInterval(id));
        this.typingIntervals = [];
    }

    /**
     * Ask follow-up question to continue the conversation
     */
    askFollowUp() {
        if (!this.modalOpen) return;

        // Small delay before asking follow-up
        setTimeout(() => {
            if (!this.modalOpen || this.processingCommand) return;

            // Randomize follow-up phrases
            const followUps = [
                'Anything else?',
                'What else can I help with?',
                'Need anything else?',
                'Is there more?'
            ];
            const followUp = followUps[Math.floor(Math.random() * followUps.length)];

            this.updateStatus('🎤', 'Ready', followUp);

            // Speak the follow-up and start listening
            this.speak(followUp, () => {
                if (this.modalOpen && !this.processingCommand) {
                    // Start listening for the next command
                    this.scheduleRestart(200);
                }
            }, 1.2);
        }, 400);
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