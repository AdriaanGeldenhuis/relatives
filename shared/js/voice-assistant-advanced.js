/**
 * ============================================
 * SUZI VOICE ASSISTANT v10.0
 * Complete rewrite using IIFE pattern
 * Clean state management, better callbacks
 * ============================================
 */

(function() {
    'use strict';

    // ==================== CONFIGURATION ====================
    const CONFIG = {
        SILENCE_TIMEOUT: 3000,      // 3 seconds of silence to auto-stop
        MAX_LISTEN_TIME: 30000,     // Max 30 seconds listening
        API_TIMEOUT: 20000,         // 20 second API timeout
        TTS_FALLBACK_MS_PER_CHAR: 80,  // Fallback timing for TTS
        MAX_CONVERSATION_HISTORY: 10
    };

    // ==================== STATE ====================
    let state = {
        isOpen: false,
        isListening: false,
        isSpeaking: false,
        isProcessing: false
    };

    // ==================== INTERNALS ====================
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let synthesis = window.speechSynthesis;
    let currentUtterance = null;
    let conversation = [];
    let silenceTimer = null;
    let autoStopTimer = null;
    let ttsFallbackTimer = null;
    let dom = null;

    // Native app detection
    const isNative = !!(window.AndroidVoice && typeof window.AndroidVoice.startListening === 'function');

    // ==================== DOM CACHING ====================
    function cacheDom() {
        if (dom) return dom;
        dom = {
            modal: document.getElementById('voiceModal'),
            statusIcon: document.getElementById('statusIcon'),
            statusText: document.getElementById('statusText'),
            statusSubtext: document.getElementById('statusSubtext'),
            transcript: document.getElementById('voiceTranscript'),
            micBtn: document.getElementById('modalMicBtn'),
            suggestions: document.getElementById('voiceSuggestions')
        };
        return dom;
    }

    // ==================== TIMER MANAGEMENT ====================
    function clearAllTimers() {
        if (silenceTimer) {
            clearTimeout(silenceTimer);
            silenceTimer = null;
        }
        if (autoStopTimer) {
            clearTimeout(autoStopTimer);
            autoStopTimer = null;
        }
        if (ttsFallbackTimer) {
            clearTimeout(ttsFallbackTimer);
            ttsFallbackTimer = null;
        }
    }

    function startSilenceTimer() {
        if (silenceTimer) clearTimeout(silenceTimer);
        silenceTimer = setTimeout(() => {
            if (state.isListening) {
                console.log('[Suzi] Silence timeout - stopping');
                stopListening();
            }
        }, CONFIG.SILENCE_TIMEOUT);
    }

    function resetSilenceTimer() {
        if (state.isListening) {
            startSilenceTimer();
        }
    }

    // ==================== SPEECH RECOGNITION ====================
    function initRecognition() {
        if (isNative || !SpeechRecognition) return;

        recognition = new SpeechRecognition();
        recognition.lang = navigator.language || 'en-US';
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;

        recognition.onstart = () => {
            console.log('[Suzi] Listening started');
            state.isListening = true;
            updateUI('listening');
            startSilenceTimer();

            // Auto-stop timer
            autoStopTimer = setTimeout(() => {
                if (state.isListening) {
                    console.log('[Suzi] Max listen time reached');
                    stopListening();
                }
            }, CONFIG.MAX_LISTEN_TIME);
        };

        recognition.onresult = (event) => {
            const result = event.results[event.results.length - 1];
            const text = result[0].transcript.trim();

            resetSilenceTimer();

            if (result.isFinal) {
                showTranscript(text);
                if (text) {
                    handleInput(text);
                }
            } else {
                showTranscript(text + '...');
            }
        };

        recognition.onerror = (event) => {
            console.log('[Suzi] Recognition error:', event.error);
            state.isListening = false;
            clearAllTimers();

            if (event.error === 'not-allowed') {
                showStatus('🚫', 'Microphone blocked', 'Enable in browser settings');
            } else if (event.error !== 'aborted' && event.error !== 'no-speech') {
                updateUI('ready');
            }
        };

        recognition.onend = () => {
            console.log('[Suzi] Recognition ended');
            state.isListening = false;
            clearAllTimers();

            if (state.isOpen && !state.isProcessing && !state.isSpeaking) {
                updateUI('ready');
            }
        };
    }

    function startListening() {
        // Guard: don't start if already busy
        if (state.isListening || state.isProcessing || state.isSpeaking) {
            console.log('[Suzi] Cannot start listening - busy');
            return;
        }

        // Stop any speaking first
        stopSpeaking();

        if (isNative && window.AndroidVoice?.startListening) {
            window.AndroidVoice.startListening();
        } else if (recognition) {
            try {
                recognition.start();
            } catch (e) {
                if (e.name !== 'InvalidStateError') {
                    console.error('[Suzi] Start error:', e);
                }
            }
        }
    }

    function stopListening() {
        clearAllTimers();
        state.isListening = false;

        if (isNative && window.AndroidVoice?.stopListening) {
            window.AndroidVoice.stopListening();
        } else if (recognition) {
            try {
                recognition.abort();
            } catch (e) {
                // Ignore
            }
        }
    }

    // ==================== SPEECH SYNTHESIS ====================
    function speak(text, onComplete) {
        if (!text) {
            if (onComplete) onComplete();
            return;
        }

        state.isSpeaking = true;
        updateUI('speaking');
        showTranscript(text);

        // Native TTS
        if (isNative && window.AndroidVoice?.speak) {
            // Store callback for native
            window._suziTtsCallback = () => {
                state.isSpeaking = false;
                window._suziTtsCallback = null;
                if (onComplete) onComplete();
            };

            window.AndroidVoice.speak(text);

            // Fallback timer
            const duration = Math.max(3000, text.split(' ').length * 500);
            ttsFallbackTimer = setTimeout(() => {
                if (state.isSpeaking) {
                    console.log('[Suzi] TTS fallback triggered');
                    state.isSpeaking = false;
                    if (window._suziTtsCallback) {
                        const cb = window._suziTtsCallback;
                        window._suziTtsCallback = null;
                        cb();
                    }
                }
            }, duration);
            return;
        }

        // Web TTS
        if (!synthesis) {
            state.isSpeaking = false;
            if (onComplete) onComplete();
            return;
        }

        synthesis.cancel();

        const utterance = new SpeechSynthesisUtterance(text);
        currentUtterance = utterance;

        // Get a good voice
        const voices = synthesis.getVoices();
        const voice = voices.find(v => v.lang.startsWith('en') && v.name.toLowerCase().includes('female'))
            || voices.find(v => v.lang.startsWith('en') && !v.name.toLowerCase().includes('male'))
            || voices.find(v => v.lang.startsWith('en'));

        if (voice) utterance.voice = voice;
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        // Fallback timer
        const fallbackTime = (text.length * CONFIG.TTS_FALLBACK_MS_PER_CHAR) + 2000;
        ttsFallbackTimer = setTimeout(() => {
            if (state.isSpeaking) {
                console.log('[Suzi] Web TTS fallback triggered');
                state.isSpeaking = false;
                if (onComplete) onComplete();
            }
        }, fallbackTime);

        utterance.onend = () => {
            if (ttsFallbackTimer) clearTimeout(ttsFallbackTimer);
            state.isSpeaking = false;
            currentUtterance = null;
            if (onComplete) onComplete();
        };

        utterance.onerror = (e) => {
            if (ttsFallbackTimer) clearTimeout(ttsFallbackTimer);
            state.isSpeaking = false;
            currentUtterance = null;
            if (e.error !== 'interrupted' && e.error !== 'canceled') {
                console.error('[Suzi] TTS error:', e.error);
            }
            if (onComplete) onComplete();
        };

        synthesis.speak(utterance);
    }

    function stopSpeaking() {
        if (ttsFallbackTimer) {
            clearTimeout(ttsFallbackTimer);
            ttsFallbackTimer = null;
        }

        if (synthesis) {
            synthesis.cancel();
        }

        state.isSpeaking = false;
        currentUtterance = null;
        window._suziTtsCallback = null;
    }

    // ==================== INPUT HANDLING ====================
    function handleInput(text) {
        if (!text || state.isProcessing) return;

        console.log('[Suzi] Input:', text);
        stopListening();

        // Check close commands
        const lower = text.toLowerCase().trim();
        const closeCommands = ['bye', 'goodbye', 'stop', 'close', 'exit', 'cancel', 'never mind', 'nevermind'];
        if (closeCommands.some(cmd => lower === cmd || lower.startsWith(cmd + ' '))) {
            const goodbyes = ['Goodbye!', 'Bye! Talk soon!', 'See you later!', 'Take care!'];
            speak(goodbyes[Math.floor(Math.random() * goodbyes.length)], () => {
                setTimeout(() => closeModal(), 500);
            });
            return;
        }

        // Process with AI
        processWithAI(text);
    }

    async function processWithAI(text) {
        state.isProcessing = true;
        updateUI('thinking');

        conversation.push({ role: 'user', content: text });

        try {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), CONFIG.API_TIMEOUT);

            const response = await fetch('/api/voice-chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transcript: text,
                    conversation: conversation.slice(-CONFIG.MAX_CONVERSATION_HISTORY)
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

            conversation.push({ role: 'assistant', content: data.response });

            // Trim history
            if (conversation.length > CONFIG.MAX_CONVERSATION_HISTORY * 2) {
                conversation = conversation.slice(-CONFIG.MAX_CONVERSATION_HISTORY * 2);
            }

            // Speak response, THEN set processing to false
            speak(data.response, () => {
                state.isProcessing = false;

                if (data.action) {
                    executeAction(data.action);
                } else {
                    updateUI('ready');
                }
            });

        } catch (error) {
            console.error('[Suzi] AI error:', error);

            let message = 'Sorry, I had trouble processing that. Please try again.';
            if (error.name === 'AbortError') {
                message = 'That took too long. Please try again.';
            }

            state.isProcessing = false;
            speak(message, () => {
                updateUI('ready');
            });
        }
    }

    // ==================== ACTION EXECUTION ====================
    function executeAction(action) {
        console.log('[Suzi] Action:', action);
        const { type, data } = action;

        switch (type) {
            case 'navigate':
                navigate(data.to);
                break;
            case 'add_shopping':
                addToShopping(data.item, data.category);
                break;
            case 'create_note':
                createNote(data.content);
                break;
            case 'create_event':
                createEvent(data);
                break;
            case 'create_reminder':
                createReminder(data);
                break;
            case 'send_message':
                sendMessage(data.content);
                break;
            case 'find_member':
                findMember(data.name);
                break;
            default:
                console.log('[Suzi] Unknown action:', type);
                updateUI('ready');
        }
    }

    function navigate(destination) {
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

        updateUI('navigating');
        setTimeout(() => {
            closeModal();
            window.location.href = paths[destination] || '/home/';
        }, 800);
    }

    async function addToShopping(item, category = 'other') {
        try {
            let listId = window.currentListId;

            if (!listId) {
                const listResponse = await fetch('/shopping/api/lists.php?action=get_all', {
                    credentials: 'same-origin'
                });
                const listData = await listResponse.json();

                if (listData.success && listData.lists?.[0]) {
                    listId = listData.lists[0].id;
                } else {
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

            if (window.location.pathname.includes('/shopping/')) {
                setTimeout(() => location.reload(), 500);
            }
        } catch (error) {
            console.error('[Suzi] Add to shopping failed:', error);
        }

        updateUI('ready');
    }

    function createNote(content) {
        let url = '/notes/?new=1';
        if (content) url += '&content=' + encodeURIComponent(content);
        setTimeout(() => {
            closeModal();
            window.location.href = url;
        }, 800);
    }

    function createEvent(data) {
        let url = '/calendar/?new=1';
        if (data.title) url += '&content=' + encodeURIComponent(data.title);
        if (data.date) url += '&date=' + data.date;
        if (data.time) url += '&time=' + data.time;
        setTimeout(() => {
            closeModal();
            window.location.href = url;
        }, 800);
    }

    function createReminder(data) {
        let url = '/schedule/?new=1';
        if (data.title) url += '&content=' + encodeURIComponent(data.title);
        if (data.date) url += '&date=' + data.date;
        if (data.time) url += '&time=' + data.time;
        setTimeout(() => {
            closeModal();
            window.location.href = url;
        }, 800);
    }

    async function sendMessage(content) {
        if (!content) {
            navigate('messages');
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
            console.error('[Suzi] Send message failed:', error);
        }

        updateUI('ready');
    }

    function findMember(name) {
        const url = '/tracking/?search=' + encodeURIComponent(name);
        setTimeout(() => {
            closeModal();
            window.location.href = url;
        }, 800);
    }

    // ==================== UI MANAGEMENT ====================
    function updateUI(uiState) {
        cacheDom();
        if (!dom) return;

        // Update mic button classes
        if (dom.micBtn) {
            dom.micBtn.classList.remove('listening', 'speaking', 'thinking');
            if (uiState === 'listening') dom.micBtn.classList.add('listening');
            else if (uiState === 'speaking') dom.micBtn.classList.add('speaking');
            else if (uiState === 'thinking') dom.micBtn.classList.add('thinking');
        }

        // Status text
        const states = {
            ready: { icon: '🎤', text: 'Tap mic to speak', sub: 'Ask me anything' },
            listening: { icon: '🎤', text: 'Listening...', sub: 'Speak now' },
            thinking: { icon: '🧠', text: 'Thinking...', sub: 'Processing your request' },
            speaking: { icon: '🔊', text: 'Speaking...', sub: '' },
            navigating: { icon: '🧭', text: 'Opening...', sub: '' }
        };

        const s = states[uiState] || states.ready;
        showStatus(s.icon, s.text, s.sub);

        // Show suggestions only when ready and no conversation yet
        if (dom.suggestions) {
            dom.suggestions.style.display = (uiState === 'ready' && conversation.length === 0) ? 'block' : 'none';
        }
    }

    function showStatus(icon, text, subtext) {
        cacheDom();
        if (dom.statusIcon) dom.statusIcon.textContent = icon;
        if (dom.statusText) dom.statusText.textContent = text;
        if (dom.statusSubtext) dom.statusSubtext.textContent = subtext || '';
    }

    function showTranscript(text) {
        cacheDom();
        if (dom.transcript) {
            dom.transcript.textContent = text;
        }
    }

    // ==================== MODAL CONTROL ====================
    function openModal() {
        cacheDom();
        if (!dom.modal) return;

        // Reset state
        state.isOpen = true;
        state.isListening = false;
        state.isSpeaking = false;
        state.isProcessing = false;
        conversation = [];
        clearAllTimers();

        dom.modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        updateUI('ready');
        showTranscript('Tap the mic and ask me anything!');

        // Greeting
        setTimeout(() => {
            speak("Hi! I'm Suzi. What can I help you with?");
        }, 300);
    }

    function closeModal() {
        stopListening();
        stopSpeaking();
        clearAllTimers();

        // Reset all state
        state.isOpen = false;
        state.isListening = false;
        state.isSpeaking = false;
        state.isProcessing = false;

        cacheDom();
        if (dom.modal) {
            dom.modal.classList.remove('active');
        }
        document.body.style.overflow = '';
    }

    function toggleListening() {
        if (state.isSpeaking) {
            stopSpeaking();
            updateUI('ready');
        } else if (state.isListening) {
            stopListening();
            updateUI('ready');
        } else if (!state.isProcessing) {
            startListening();
        }
    }

    function executeSuggestion(text) {
        if (state.isProcessing || state.isSpeaking) return;
        handleInput(text);
    }

    // ==================== NATIVE APP HOOKS ====================
    function setupNativeHooks() {
        window.SuziVoice = {
            onSttResult: (text) => {
                if (text && text.trim()) {
                    state.isListening = false;
                    handleInput(text.trim());
                }
            },
            onTtsEnd: () => {
                state.isSpeaking = false;
                if (ttsFallbackTimer) clearTimeout(ttsFallbackTimer);
                if (window._suziTtsCallback) {
                    const cb = window._suziTtsCallback;
                    window._suziTtsCallback = null;
                    cb();
                } else if (state.isOpen && !state.isProcessing) {
                    updateUI('ready');
                }
            },
            onTtsStart: () => {
                state.isSpeaking = true;
                updateUI('speaking');
            },
            onSttStart: () => {
                state.isListening = true;
                updateUI('listening');
            },
            onSttStop: () => {
                state.isListening = false;
                if (state.isOpen && !state.isProcessing) {
                    updateUI('ready');
                }
            }
        };
    }

    // ==================== INITIALIZATION ====================
    function init() {
        console.log('[Suzi] Voice v10.0 - Initializing...');
        initRecognition();
        setupNativeHooks();

        // Preload voices
        if (synthesis) {
            synthesis.getVoices();
            if (speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = () => synthesis.getVoices();
            }
        }

        console.log('[Suzi] Voice v10.0 Ready!', isNative ? '(Native Mode)' : '(Web Mode)');
    }

    // ==================== PUBLIC API ====================
    window.SuziVoice = {
        open: openModal,
        close: closeModal,
        toggle: () => state.isOpen ? closeModal() : openModal(),
        toggleListening: toggleListening,
        executeSuggestion: executeSuggestion,
        getInstance: () => window.SuziVoice,
        // State getters
        isOpen: () => state.isOpen,
        isListening: () => state.isListening,
        isSpeaking: () => state.isSpeaking,
        isProcessing: () => state.isProcessing
    };

    // Backwards compatibility
    window.AdvancedVoiceAssistant = {
        getInstance: () => window.SuziVoice,
        openModal: openModal,
        closeModal: closeModal
    };

    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 100);
    }

})();
