/**
 * ============================================
 * SUZI VOICE ASSISTANT v8.0 - STABLE
 * Complete rewrite with proper state machine
 * No loops, no feedback, reliable operation
 * ============================================
 */

class AdvancedVoiceAssistant {
    static instance = null;

    // State machine states
    static STATES = {
        IDLE: 'idle',           // Modal closed
        READY: 'ready',         // Modal open, waiting for user to tap mic
        LISTENING: 'listening', // Actively listening to user
        PROCESSING: 'processing', // Processing command
        SPEAKING: 'speaking',   // TTS speaking response
        NAVIGATING: 'navigating' // About to navigate away
    };

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

        console.log('🎤 Suzi Voice Assistant v8.0 Initializing...');

        // Detect native vs web
        this.isNativeApp = !!(window.AndroidVoice && typeof window.AndroidVoice.startListening === 'function');

        // State machine
        this.state = AdvancedVoiceAssistant.STATES.IDLE;

        // Core components
        this.recognition = null;
        this.synthesis = window.speechSynthesis;
        this.initialized = false;

        // Single timeout for all operations - prevents multiple competing timeouts
        this.operationTimeout = null;

        // Silence timeout - how long to wait for speech before going back to ready
        this.silenceTimeout = null;
        this.SILENCE_DURATION = 8000; // 8 seconds of silence before auto-stop

        // Anti-duplicate protection
        this.lastTranscript = '';
        this.lastTranscriptTime = 0;
        this.commandCooldown = 2000;

        // Conversation history
        this.conversation = [];
        this.maxConversationHistory = 4;

        // DOM cache
        this.dom = {};

        // Current utterance for cancellation
        this.currentUtterance = null;

        // Local intent patterns (fast matching, no API needed)
        this.localIntents = this.buildLocalIntents();

        // Setup global hooks for native app callbacks
        this.setupNativeHooks();

        AdvancedVoiceAssistant.instance = this;
    }

    // ==================== STATE MACHINE ====================

    setState(newState) {
        const oldState = this.state;
        this.state = newState;
        console.log(`🔄 State: ${oldState} → ${newState}`);
        this.updateUIForState(newState);
    }

    updateUIForState(state) {
        switch (state) {
            case AdvancedVoiceAssistant.STATES.IDLE:
                this.updateMicState(false);
                break;

            case AdvancedVoiceAssistant.STATES.READY:
                this.updateMicState(false);
                this.updateStatus('🎤', 'Tap mic to speak', 'I\'m ready to help');
                this.updateTranscript('Tap the microphone to ask me anything');
                this.showSuggestions(true);
                break;

            case AdvancedVoiceAssistant.STATES.LISTENING:
                this.updateMicState(true);
                this.updateStatus('🎤', 'Listening...', 'Speak now');
                this.updateTranscript('Listening...');
                this.showSuggestions(false);
                break;

            case AdvancedVoiceAssistant.STATES.PROCESSING:
                this.updateMicState(false);
                this.updateStatus('⚙️', 'Thinking...', 'Processing your request');
                break;

            case AdvancedVoiceAssistant.STATES.SPEAKING:
                this.updateMicState(false);
                this.updateStatus('🔊', 'Speaking...', '');
                break;

            case AdvancedVoiceAssistant.STATES.NAVIGATING:
                this.updateMicState(false);
                this.updateStatus('🧭', 'Opening...', '');
                break;
        }
    }

    canTransitionTo(newState) {
        const { STATES } = AdvancedVoiceAssistant;
        const validTransitions = {
            [STATES.IDLE]: [STATES.READY],
            [STATES.READY]: [STATES.LISTENING, STATES.SPEAKING, STATES.IDLE],
            [STATES.LISTENING]: [STATES.PROCESSING, STATES.READY, STATES.IDLE, STATES.SPEAKING],
            [STATES.PROCESSING]: [STATES.SPEAKING, STATES.READY, STATES.IDLE, STATES.NAVIGATING],
            [STATES.SPEAKING]: [STATES.READY, STATES.IDLE, STATES.NAVIGATING],
            [STATES.NAVIGATING]: [STATES.IDLE]
        };
        return validTransitions[this.state]?.includes(newState) ?? false;
    }

    // ==================== NATIVE HOOKS ====================

    setupNativeHooks() {
        window.SuziVoice = {
            onSttResult: (text) => {
                if (text && text.trim() && this.state === AdvancedVoiceAssistant.STATES.LISTENING) {
                    this.handleTranscript(text.trim());
                }
            },
            onTtsEnd: () => {
                if (this.state === AdvancedVoiceAssistant.STATES.SPEAKING) {
                    this.onSpeakComplete();
                }
            },
            onTtsStart: () => {
                this.setState(AdvancedVoiceAssistant.STATES.SPEAKING);
            },
            onSttStart: () => {
                this.setState(AdvancedVoiceAssistant.STATES.LISTENING);
            },
            onSttStop: () => {
                if (this.state === AdvancedVoiceAssistant.STATES.LISTENING) {
                    this.setState(AdvancedVoiceAssistant.STATES.READY);
                }
            }
        };
    }

    // ==================== LOCAL INTENT MATCHING ====================

    buildLocalIntents() {
        return [
            // ========== SHOPPING ==========
            {
                patterns: [
                    /^add\s+(.+?)(?:\s+(?:to|on)\s+(?:my|the)?\s*(?:shopping\s*)?(?:list)?)?$/i,
                    /^put\s+(.+?)(?:\s+(?:to|on)\s+(?:my|the)?\s*(?:shopping\s*)?(?:list)?)?$/i,
                    /^(?:i need|we need|get|buy)\s+(.+?)$/i,
                    /^shopping\s+add\s+(.+?)$/i
                ],
                handler: (match) => {
                    let item = (match[1] || '').trim();
                    item = item.replace(/\s+(?:to|on)\s+(?:my|the)\s+(?:shopping\s*)?list\s*$/i, '').trim();
                    item = item.replace(/\s+please\s*$/i, '').trim();
                    const category = this.guessCategory(item);
                    return {
                        intent: 'add_shopping_item',
                        slots: { item, category },
                        response_text: `Added ${item} to your shopping list.`,
                        keepOpen: true
                    };
                }
            },
            {
                patterns: [
                    /^(?:show|open|go\s*to|view)\s*(?:my|the)?\s*shopping(?:\s*list)?$/i,
                    /^shopping\s*list$/i,
                    /^shopping$/i
                ],
                handler: () => ({
                    intent: 'navigate',
                    slots: { destination: 'shopping' },
                    response_text: 'Opening your shopping list!'
                })
            },

            // ========== NAVIGATION - Comprehensive ==========
            {
                patterns: [/^(?:go\s*to|open|show)\s*(?:the\s*)?home(?:\s*page)?$/i, /^home$/i, /^main\s*page$/i],
                handler: () => ({ intent: 'navigate', slots: { destination: 'home' }, response_text: 'Going home!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:my|the)?\s*notes?$/i,
                    /^notes?$/i,
                    /^my\s*notes?$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'notes' }, response_text: 'Opening your notes!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:my|the)?\s*calendar$/i,
                    /^calendar$/i,
                    /^my\s*calendar$/i,
                    /^events?$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'calendar' }, response_text: 'Opening your calendar!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:my|the)?\s*messages?$/i,
                    /^messages?$/i,
                    /^(?:family\s*)?chat$/i,
                    /^inbox$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'messages' }, response_text: 'Opening messages!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:the\s*)?(?:family\s*)?(?:tracking|location|map)$/i,
                    /^(?:where\s*is\s*)?(?:my\s*)?family$/i,
                    /^where\s*is\s*everyone$/i,
                    /^family\s*location$/i,
                    /^tracking$/i,
                    /^location$/i,
                    /^map$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'tracking' }, response_text: 'Opening family tracking!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:the\s*)?weather$/i,
                    /^weather\s*page$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'weather' }, response_text: 'Opening weather!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:my|the)?\s*(?:schedule|reminders?)$/i,
                    /^schedule$/i,
                    /^reminders?$/i,
                    /^my\s*reminders?$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'schedule' }, response_text: 'Opening your schedule!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:my|the)?\s*notifications?$/i,
                    /^notifications?$/i,
                    /^alerts?$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'notifications' }, response_text: 'Opening notifications!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:the\s*)?help$/i,
                    /^help\s*(?:page|center)?$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'help' }, response_text: 'Opening help!' })
            },
            {
                patterns: [
                    /^(?:go\s*to|open|show)\s*(?:the\s*)?games?$/i,
                    /^games?$/i,
                    /^play\s*(?:a\s*)?game$/i
                ],
                handler: () => ({ intent: 'navigate', slots: { destination: 'games' }, response_text: 'Opening games!' })
            },

            // ========== WEATHER ==========
            {
                patterns: [
                    /^(?:what'?s?\s*)?(?:the\s*)?weather(?:\s*today)?$/i,
                    /^how'?s?\s*(?:the\s*)?weather/i,
                    /^is\s*it\s*(?:going\s*to\s*)?(?:rain|sunny|cold|hot)/i
                ],
                handler: () => ({
                    intent: 'get_weather_today',
                    slots: {},
                    response_text: 'Let me check the weather for you.'
                })
            },
            {
                patterns: [
                    /^weather\s*tomorrow$/i,
                    /^tomorrow'?s?\s*weather$/i,
                    /^what'?s?\s*(?:the\s*)?weather\s*tomorrow$/i
                ],
                handler: () => ({
                    intent: 'get_weather_tomorrow',
                    slots: {},
                    response_text: 'Checking tomorrow\'s forecast.'
                })
            },

            // ========== TRACKING - Find Family ==========
            {
                patterns: [
                    /^(?:where'?s?|find|locate)\s+(?:my\s+)?(mom|dad|mum|mother|father|wife|husband|son|daughter|brother|sister|grandma|grandpa|grandmother|grandfather|partner|spouse)/i
                ],
                handler: (match) => ({
                    intent: 'find_member',
                    slots: { member_name: match[1] },
                    response_text: `Looking for ${match[1]}!`
                })
            },
            {
                patterns: [/^(?:where'?s?|find|locate)\s+(.+)/i],
                handler: (match) => ({
                    intent: 'find_member',
                    slots: { member_name: match[1].trim() },
                    response_text: `Looking for ${match[1].trim()}!`
                })
            },

            // ========== TIME & DATE ==========
            {
                patterns: [/^what\s*time\s*is\s*it$/i, /^what'?s?\s*the\s*time$/i, /^time$/i],
                handler: () => {
                    const now = new Date();
                    const time = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                    return { intent: 'smalltalk', slots: {}, response_text: `It's ${time}.`, keepOpen: true };
                }
            },
            {
                patterns: [/^what'?s?\s*(?:today'?s?\s*)?date$/i, /^what\s*day\s*is\s*it$/i, /^what\s*is\s*today$/i],
                handler: () => {
                    const now = new Date();
                    const date = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
                    return { intent: 'smalltalk', slots: {}, response_text: `Today is ${date}.`, keepOpen: true };
                }
            },

            // ========== NOTES ==========
            {
                patterns: [
                    /^(?:create|make|new|add|take)\s*(?:a\s*)?note(?:\s*[:]\s*(.+))?$/i,
                    /^note(?:\s*[:]\s*(.+))?$/i,
                    /^write\s*(?:a\s*)?note(?:\s*[:]\s*(.+))?$/i,
                    /^(?:remember|save)\s+(?:that\s+)?(.+)$/i
                ],
                handler: (match) => ({
                    intent: 'create_note',
                    slots: { content: match[1]?.trim() || '' },
                    response_text: match[1] ? 'Creating your note!' : 'Opening notes for you!'
                })
            },

            // ========== MESSAGES ==========
            {
                patterns: [
                    /^(?:send|tell)\s*(?:a\s*)?message(?:\s*[:]\s*(.+))?$/i,
                    /^message\s*(?:family|everyone)?(?:\s*[:]\s*(.+))?$/i,
                    /^(?:text|message)\s+(.+)$/i
                ],
                handler: (match) => ({
                    intent: 'send_message',
                    slots: { content: match[1]?.trim() || '' },
                    response_text: match[1] ? 'Sending your message!' : 'What would you like to say?'
                })
            },

            // ========== CALENDAR & EVENTS ==========
            {
                patterns: [
                    /^(?:create|add|new|make)\s*(?:an?\s*)?(?:event|appointment)(?:\s*[:]\s*(.+))?$/i,
                    /^(?:schedule|plan)\s*(?:an?\s*)?(?:event|meeting|appointment)?(?:\s*[:]\s*(.+))?$/i
                ],
                handler: (match) => ({
                    intent: 'create_event',
                    slots: { title: match[1]?.trim() || '' },
                    response_text: match[1] ? 'Creating your event!' : 'Opening calendar!'
                })
            },
            {
                patterns: [
                    /^what'?s?\s*(?:on\s*)?(?:my\s*)?(?:schedule|calendar|agenda)(?:\s*today)?$/i,
                    /^(?:do\s*i\s*have\s*)?(?:any\s*)?(?:events?|appointments?)\s*today$/i
                ],
                handler: () => ({
                    intent: 'navigate',
                    slots: { destination: 'calendar' },
                    response_text: 'Let me show you your calendar!'
                })
            },

            // ========== REMINDERS ==========
            {
                patterns: [
                    /^(?:remind\s*me|set\s*(?:a\s*)?reminder)\s*(?:to\s+)?(.+)$/i,
                    /^(?:create|add|new)\s*(?:a\s*)?reminder(?:\s*[:]\s*(.+))?$/i
                ],
                handler: (match) => ({
                    intent: 'create_schedule',
                    slots: { title: match[1]?.trim() || '' },
                    response_text: match[1] ? 'Creating your reminder!' : 'Opening reminders!'
                })
            },

            // ========== GREETINGS ==========
            {
                patterns: [
                    /^(?:hi|hello|hey)(?:\s+(?:suzi|there))?$/i,
                    /^good\s+(?:morning|afternoon|evening|day)$/i,
                    /^howdy$/i
                ],
                handler: () => {
                    const greetings = [
                        'Hey! How can I help you today?',
                        'Hi there! What can I do for you?',
                        'Hello! Ready to help!',
                        'Hey! What do you need?'
                    ];
                    return {
                        intent: 'smalltalk',
                        slots: {},
                        response_text: greetings[Math.floor(Math.random() * greetings.length)],
                        keepOpen: true
                    };
                }
            },
            {
                patterns: [/^(?:thanks?|thank\s*you)(?:\s+suzi)?$/i, /^cheers$/i],
                handler: () => ({
                    intent: 'smalltalk',
                    slots: {},
                    response_text: 'You\'re welcome!',
                    keepOpen: true
                })
            },
            {
                patterns: [/^(?:what\s*can\s*you\s*do|help|commands?)$/i, /^what\s*are\s*(?:you|your)\s*(?:commands?|capabilities)/i],
                handler: () => ({
                    intent: 'smalltalk',
                    slots: {},
                    response_text: 'I can help with: shopping lists, notes, calendar, reminders, messages, weather, finding family, and navigating the app. Just ask!',
                    keepOpen: true
                })
            },
            {
                patterns: [/^who\s*are\s*you$/i, /^what'?s?\s*your\s*name$/i],
                handler: () => ({
                    intent: 'smalltalk',
                    slots: {},
                    response_text: 'I\'m Suzi, your family assistant! I\'m here to help you manage your day.',
                    keepOpen: true
                })
            },

            // ========== JOKES ==========
            {
                patterns: [/^tell\s*(?:me\s*)?(?:a\s*)?joke$/i, /^make\s*me\s*laugh$/i],
                handler: () => {
                    const jokes = [
                        'Why did the smartphone go to therapy? It had too many hang-ups!',
                        'What do you call a fake noodle? An impasta!',
                        'Why don\'t scientists trust atoms? Because they make up everything!',
                        'What did the ocean say to the beach? Nothing, it just waved!'
                    ];
                    return {
                        intent: 'smalltalk',
                        slots: {},
                        response_text: jokes[Math.floor(Math.random() * jokes.length)],
                        keepOpen: true
                    };
                }
            }
        ];
    }

    guessCategory(item) {
        const categories = {
            dairy: ['milk', 'cheese', 'yogurt', 'butter', 'cream', 'eggs', 'yoghurt', 'margarine'],
            meat: ['chicken', 'beef', 'pork', 'lamb', 'fish', 'bacon', 'sausage', 'mince', 'steak', 'chops', 'turkey', 'ham'],
            produce: ['apple', 'banana', 'orange', 'tomato', 'potato', 'onion', 'carrot', 'lettuce', 'spinach', 'fruit', 'vegetable', 'avocado', 'lemon', 'garlic', 'cucumber', 'pepper', 'mushroom', 'broccoli'],
            bakery: ['bread', 'rolls', 'buns', 'cake', 'pastry', 'croissant', 'muffin', 'bagel', 'donut'],
            pantry: ['rice', 'pasta', 'flour', 'sugar', 'salt', 'oil', 'sauce', 'spice', 'cereal', 'coffee', 'tea', 'honey', 'jam'],
            frozen: ['ice cream', 'frozen', 'pizza', 'popsicle'],
            snacks: ['chips', 'chocolate', 'candy', 'cookies', 'biscuits', 'nuts', 'crisps', 'popcorn', 'crackers'],
            beverages: ['juice', 'soda', 'water', 'wine', 'beer', 'coke', 'sprite', 'drink', 'cola', 'lemonade'],
            household: ['soap', 'detergent', 'toilet paper', 'paper towel', 'cleaning', 'shampoo', 'toothpaste', 'tissue', 'trash bags']
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

        return null;
    }

    // ==================== INITIALIZATION ====================

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
        console.log('✅ Suzi Voice Assistant v8.0 READY!');
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
        this.dom.modalMicBtn = document.getElementById('modalMicBtn');
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
            console.log('🎤 Recognition started');
            this.setState(AdvancedVoiceAssistant.STATES.LISTENING);
            this.startSilenceTimer();
        };

        this.recognition.onresult = (event) => {
            const result = event.results[event.results.length - 1];
            const transcript = result[0].transcript.trim();

            // Reset silence timer on any result
            this.resetSilenceTimer();

            // Show interim results
            if (!result.isFinal) {
                this.updateTranscript(transcript + '...');
            } else if (transcript) {
                this.handleTranscript(transcript);
            }
        };

        this.recognition.onerror = (event) => {
            console.log('🎤 Recognition error:', event.error);
            this.clearSilenceTimer();

            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                this.updateStatus('🚫', 'Microphone Blocked', 'Enable in browser settings');
                this.setState(AdvancedVoiceAssistant.STATES.READY);
            } else if (event.error === 'no-speech') {
                // No speech detected - go back to ready state (don't auto-restart!)
                this.setState(AdvancedVoiceAssistant.STATES.READY);
            } else if (event.error === 'aborted') {
                // Intentionally aborted - do nothing
            } else {
                // Other errors - go back to ready
                this.setState(AdvancedVoiceAssistant.STATES.READY);
            }
        };

        this.recognition.onend = () => {
            console.log('🎤 Recognition ended');
            this.clearSilenceTimer();

            // Only change state if still in listening state
            if (this.state === AdvancedVoiceAssistant.STATES.LISTENING) {
                this.setState(AdvancedVoiceAssistant.STATES.READY);
            }
        };
    }

    preloadVoices() {
        if (this.synthesis) {
            this.synthesis.getVoices();
            if (typeof speechSynthesis !== 'undefined' && speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = () => this.synthesis.getVoices();
            }
        }
    }

    // ==================== SILENCE TIMER ====================

    startSilenceTimer() {
        this.clearSilenceTimer();
        this.silenceTimeout = setTimeout(() => {
            if (this.state === AdvancedVoiceAssistant.STATES.LISTENING) {
                console.log('⏱️ Silence timeout - stopping listening');
                this.stopListening();
            }
        }, this.SILENCE_DURATION);
    }

    resetSilenceTimer() {
        if (this.state === AdvancedVoiceAssistant.STATES.LISTENING) {
            this.startSilenceTimer();
        }
    }

    clearSilenceTimer() {
        if (this.silenceTimeout) {
            clearTimeout(this.silenceTimeout);
            this.silenceTimeout = null;
        }
    }

    // ==================== LISTENING ====================

    startListening() {
        const { STATES } = AdvancedVoiceAssistant;

        // Can only start listening from READY state
        if (this.state !== STATES.READY) {
            console.log('⚠️ Cannot start listening from state:', this.state);
            return;
        }

        // Clear any pending operations
        this.clearAllTimeouts();

        if (this.isNativeApp) {
            this.startNativeListening();
            return;
        }

        if (!this.recognition) {
            this.updateStatus('❌', 'Not supported', 'Use Chrome, Edge, or Safari');
            return;
        }

        try {
            this.recognition.start();
        } catch (error) {
            if (error.name === 'InvalidStateError') {
                console.log('🎤 Recognition already active');
            } else {
                console.error('🎤 Start failed:', error);
            }
        }
    }

    stopListening() {
        this.clearSilenceTimer();

        if (this.isNativeApp) {
            this.stopNativeListening();
            return;
        }

        if (this.recognition) {
            try {
                this.recognition.abort();
            } catch (e) {}
        }
    }

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

        // Clear any previous state
        this.clearAllTimeouts();
        this.conversation = [];
        this.lastTranscript = '';

        // Show modal
        this.dom.voiceModal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Set state to READY - user must tap mic to start listening
        this.setState(AdvancedVoiceAssistant.STATES.READY);

        // Play a short greeting (but don't auto-start listening after!)
        this.speak('Hi! Tap the mic when you\'re ready to speak.', null, 1.1);
    }

    closeModal() {
        // Stop everything
        this.stopListening();
        this.stopSpeaking();
        this.clearAllTimeouts();

        if (this.dom.voiceModal) {
            this.dom.voiceModal.classList.remove('active');
        }

        document.body.style.overflow = '';
        this.setState(AdvancedVoiceAssistant.STATES.IDLE);
    }

    toggleListening() {
        const { STATES } = AdvancedVoiceAssistant;

        if (this.state === STATES.LISTENING) {
            this.stopListening();
        } else if (this.state === STATES.READY) {
            this.startListening();
        } else if (this.state === STATES.SPEAKING) {
            // Stop speaking and go to ready
            this.stopSpeaking();
            this.setState(STATES.READY);
        }
    }

    // ==================== UI UPDATES ====================

    updateMicState(listening) {
        if (this.dom.micBtn) {
            this.dom.micBtn.classList.toggle('listening', listening);
        }
        if (this.dom.voiceStatus) {
            this.dom.voiceStatus.classList.toggle('listening', listening);
        }
        if (this.dom.modalMicBtn) {
            this.dom.modalMicBtn.classList.toggle('listening', listening);
        }
    }

    updateStatus(icon, text, subtext) {
        if (this.dom.statusIcon) this.dom.statusIcon.textContent = icon;
        if (this.dom.statusText) this.dom.statusText.textContent = text;
        if (this.dom.statusSubtext) this.dom.statusSubtext.textContent = subtext || '';
    }

    updateTranscript(text) {
        if (this.dom.voiceTranscript) {
            this.dom.voiceTranscript.textContent = text || 'Tap the microphone to speak';
        }
    }

    showSuggestions(show) {
        if (this.dom.voiceSuggestions) {
            this.dom.voiceSuggestions.style.display = show ? 'block' : 'none';
        }
    }

    // ==================== TRANSCRIPT HANDLING ====================

    handleTranscript(transcript) {
        if (!transcript) return;

        const { STATES } = AdvancedVoiceAssistant;

        // Stop listening first
        this.stopListening();

        // Update display
        this.updateTranscript(transcript);
        this.showSuggestions(false);

        // Check for closing phrases
        const closeWords = [
            'stop', 'bye', 'goodbye', 'cancel', 'exit', 'quit', 'close',
            'nevermind', 'never mind', 'no thanks', "that's all", "that's it",
            "all done", "i'm good", "i'm done", "no thank you"
        ];
        const lower = transcript.toLowerCase().trim();

        if (closeWords.some(w => lower === w || lower.startsWith(w + ' '))) {
            const farewells = ['Goodbye!', 'Bye for now!', 'Talk soon!', 'See you later!'];
            const farewell = farewells[Math.floor(Math.random() * farewells.length)];
            this.speak(farewell, () => this.closeModal(), 1.2);
            return;
        }

        // Check for duplicates
        const now = Date.now();
        if (transcript === this.lastTranscript && (now - this.lastTranscriptTime) < this.commandCooldown) {
            this.setState(STATES.READY);
            return;
        }

        this.lastTranscript = transcript;
        this.lastTranscriptTime = now;

        // Process command
        this.setState(STATES.PROCESSING);
        this.processVoiceCommand(transcript);
    }

    // ==================== COMMAND PROCESSING ====================

    async processVoiceCommand(command) {
        if (!command || command.trim().length === 0) {
            this.setState(AdvancedVoiceAssistant.STATES.READY);
            return;
        }

        // Try local intent first
        const localResult = this.tryLocalIntent(command);

        if (localResult) {
            console.log('⚡ Using local intent');
            this.updateStatus('✅', 'Got it!', '');

            // Add to conversation
            this.conversation.push({ role: 'user', content: command });
            this.conversation.push({ role: 'assistant', content: localResult.response_text });

            await this.executeIntent(localResult);
            return;
        }

        // No local match - use API
        this.updateStatus('⚙️', 'Thinking...', '');

        const controller = new AbortController();
        this.operationTimeout = setTimeout(() => controller.abort(), 10000);

        try {
            const response = await fetch('/api/voice-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transcript: command,
                    page: window.location.pathname,
                    conversation: this.conversation.slice(-4)
                }),
                signal: controller.signal
            });

            this.clearOperationTimeout();

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data || !data.intent) throw new Error('Invalid response');

            // Add to conversation
            this.conversation.push({ role: 'user', content: command });
            this.conversation.push({ role: 'assistant', content: data.response_text });

            // Keep history limited
            if (this.conversation.length > this.maxConversationHistory * 2) {
                this.conversation = this.conversation.slice(-this.maxConversationHistory * 2);
            }

            await this.executeIntent(data);

        } catch (error) {
            this.clearOperationTimeout();
            console.error('Voice command error:', error);

            let fallback = error.name === 'AbortError'
                ? "Taking too long. Tap mic to try again."
                : "Sorry, couldn't get that. Tap mic to try again.";

            this.updateStatus('❓', 'Oops', '');
            this.speak(fallback, () => {
                this.setState(AdvancedVoiceAssistant.STATES.READY);
            }, 1.1);
        }
    }

    async executeIntent(intentData) {
        const { intent, slots, response_text, keepOpen } = intentData;
        const { STATES } = AdvancedVoiceAssistant;

        // Determine if this will navigate away
        const navigationIntents = [
            'navigate', 'create_event', 'create_schedule', 'create_note',
            'send_message', 'view_shopping', 'show_calendar', 'show_schedule',
            'read_messages', 'show_location', 'find_member', 'check_notifications',
            'get_weather_today', 'get_weather_tomorrow', 'get_weather_week'
        ];

        const willNavigate = navigationIntents.includes(intent);

        // Update UI with appropriate icon
        const icons = {
            'add_shopping_item': '🛒',
            'create_note': '📝',
            'create_event': '📅',
            'create_schedule': '⏰',
            'get_weather_today': '🌤️',
            'get_weather_tomorrow': '🌤️',
            'send_message': '💬',
            'navigate': '🧭',
            'smalltalk': '💬',
            'find_member': '📍',
            'error': '❌'
        };

        this.updateStatus(icons[intent] || '✅', 'Got it!', '');

        // Speak response
        this.speak(response_text, () => {
            if (willNavigate) {
                // Already navigating
            } else if (keepOpen) {
                // Stay open and ready for more commands
                this.setState(STATES.READY);
            } else {
                // Default: go back to ready
                this.setState(STATES.READY);
            }
        }, 1.1);

        // Execute the action
        try {
            switch (intent) {
                case 'add_shopping_item':
                    await this.addToShopping(slots.item, slots.quantity, slots.category);
                    break;

                case 'view_shopping':
                    this.navigate('/shopping/');
                    break;

                case 'create_note':
                    this.navigateToCreateNote(slots);
                    break;

                case 'create_event':
                    this.navigateToCreateEvent(slots);
                    break;

                case 'create_schedule':
                    this.navigateToCreateSchedule(slots);
                    break;

                case 'get_weather_today':
                case 'get_weather_tomorrow':
                case 'get_weather_week':
                    this.navigate('/weather/');
                    break;

                case 'send_message':
                    await this.sendMessage(slots.content);
                    break;

                case 'find_member':
                    this.navigate('/tracking/?search=' + encodeURIComponent(slots.member_name || ''));
                    break;

                case 'navigate':
                    this.navigate(this.getNavigationPath(slots.destination));
                    break;

                case 'smalltalk':
                    // Just speak, no action
                    break;
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

            // If on shopping page, reload to show new item
            if (window.location.pathname.includes('/shopping/')) {
                setTimeout(() => location.reload(), 500);
            }

        } catch (error) {
            console.error('Add to shopping failed:', error);
            this.updateStatus('❌', 'Failed', error.message || 'Could not add item');
        }
    }

    async getDefaultShoppingList() {
        try {
            const response = await fetch('/shopping/api/lists.php?action=get_all', {
                credentials: 'same-origin'
            });

            if (!response.ok) return null;

            const data = await response.json().catch(() => null);
            if (!data || !data.success) return null;

            if (data.lists && data.lists[0]) {
                return data.lists[0].id;
            }

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

            if (!createResponse.ok) return null;

            const createData = await createResponse.json().catch(() => null);
            return createData?.success && createData?.list_id ? createData.list_id : null;

        } catch (error) {
            console.error('Failed to get/create shopping list:', error);
            return null;
        }
    }

    navigateToCreateNote(slots) {
        let url = '/notes/?new=1';
        if (slots.content) url += '&content=' + encodeURIComponent(slots.content);
        if (slots.title) url += '&title=' + encodeURIComponent(slots.title);
        this.navigate(url);
    }

    navigateToCreateEvent(slots) {
        let url = '/calendar/?new=1';
        if (slots.title) url += '&content=' + encodeURIComponent(slots.title);
        if (slots.date) url += '&date=' + this.parseDate(slots.date);
        if (slots.time) url += '&time=' + slots.time;
        this.navigate(url);
    }

    navigateToCreateSchedule(slots) {
        let url = '/schedule/?new=1';
        if (slots.title) url += '&content=' + encodeURIComponent(slots.title);
        if (slots.date) url += '&date=' + this.parseDate(slots.date);
        if (slots.time) url += '&time=' + slots.time;
        this.navigate(url);
    }

    async sendMessage(content) {
        if (!content) {
            this.navigate('/messages/');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('content', content);
            formData.append('to_family', '1');

            await fetch('/messages/api/send.php', {
                method: 'POST',
                body: formData
            });

            if (window.location.pathname.includes('/messages/')) {
                setTimeout(() => location.reload(), 500);
            }
        } catch (error) {
            console.error('Send message failed:', error);
        }
    }

    navigate(url) {
        this.setState(AdvancedVoiceAssistant.STATES.NAVIGATING);
        this.updateStatus('🧭', 'Opening...', '');

        this.operationTimeout = setTimeout(() => {
            this.closeModal();
            window.location.href = url;
        }, 800);
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
            help: '/help/',
            games: '/games/'
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

        if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
            return dateString;
        }

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

        this.setState(AdvancedVoiceAssistant.STATES.SPEAKING);
        this.updateTranscript(text);

        // Native app TTS
        if (window.AndroidVoice && typeof window.AndroidVoice.speak === 'function') {
            try {
                window.AndroidVoice.speak(text);

                // Fallback timeout
                const estimatedDuration = Math.max(1500, text.split(' ').length * 400);
                this.operationTimeout = setTimeout(() => {
                    this.onSpeakComplete();
                    if (onEndCallback) onEndCallback();
                }, estimatedDuration);

                return;
            } catch (error) {
                console.error('❌ Native speak failed', error);
            }
        }

        // Web Speech API
        if (!this.synthesis) {
            if (onEndCallback) onEndCallback();
            return;
        }

        // Cancel any current speech
        this.synthesis.cancel();

        const utterance = new SpeechSynthesisUtterance(text);
        this.currentUtterance = utterance;

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

        // Fallback timeout
        const fallbackMs = (text.length * 70) + 2000;
        const fallbackTimeout = setTimeout(() => {
            console.log('🔊 Speech fallback timeout');
            this.onSpeakComplete();
            if (onEndCallback) onEndCallback();
        }, fallbackMs);

        utterance.onend = () => {
            clearTimeout(fallbackTimeout);
            this.onSpeakComplete();
            if (onEndCallback) onEndCallback();
        };

        utterance.onerror = (event) => {
            if (event.error !== 'interrupted' && event.error !== 'canceled') {
                console.error('Speech error:', event.error);
            }
            clearTimeout(fallbackTimeout);
            this.onSpeakComplete();
            if (onEndCallback) onEndCallback();
        };

        this.synthesis.speak(utterance);
    }

    onSpeakComplete() {
        // Only transition if still in speaking state
        if (this.state === AdvancedVoiceAssistant.STATES.SPEAKING) {
            // Will be set by callback or default to READY
        }
    }

    stopSpeaking() {
        if (this.synthesis) {
            this.synthesis.cancel();
        }
        this.currentUtterance = null;
    }

    // ==================== TIMEOUTS ====================

    clearOperationTimeout() {
        if (this.operationTimeout) {
            clearTimeout(this.operationTimeout);
            this.operationTimeout = null;
        }
    }

    clearAllTimeouts() {
        this.clearOperationTimeout();
        this.clearSilenceTimer();
    }

    // ==================== PUBLIC API ====================

    executeSuggestion(command) {
        if (this.state !== AdvancedVoiceAssistant.STATES.READY) return;

        this.updateTranscript(command);
        this.showSuggestions(false);
        this.setState(AdvancedVoiceAssistant.STATES.PROCESSING);
        this.processVoiceCommand(command);
    }
}

// ==================== AUTO-INITIALIZE ====================
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        const instance = AdvancedVoiceAssistant.getInstance();
        instance.init();
    }, 100);
});

// Expose to global scope
window.AdvancedVoiceAssistant = AdvancedVoiceAssistant;
