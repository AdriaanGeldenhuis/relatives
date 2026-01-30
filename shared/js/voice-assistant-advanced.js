/**
 * ============================================
 * SUZI VOICE ASSISTANT v10.1
 * Complete rewrite - Fixed initialization
 * ============================================
 */

(function() {
    'use strict';

    // ==================== CONFIGURATION ====================
    const CONFIG = {
        SILENCE_TIMEOUT: 3000,
        MAX_LISTEN_TIME: 30000,
        API_TIMEOUT: 20000,
        TTS_FALLBACK_MS_PER_CHAR: 80,
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
    let initialized = false;

    const isNative = !!(window.AndroidVoice && typeof window.AndroidVoice.startListening === 'function');

    // ==================== DOM CACHING ====================
    function cacheDom() {
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
        if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
        if (autoStopTimer) { clearTimeout(autoStopTimer); autoStopTimer = null; }
        if (ttsFallbackTimer) { clearTimeout(ttsFallbackTimer); ttsFallbackTimer = null; }
    }

    function startSilenceTimer() {
        if (silenceTimer) clearTimeout(silenceTimer);
        silenceTimer = setTimeout(function() {
            if (state.isListening) {
                console.log('[Suzi] Silence timeout');
                stopListening();
            }
        }, CONFIG.SILENCE_TIMEOUT);
    }

    // ==================== SPEECH RECOGNITION ====================
    function initRecognition() {
        if (isNative || !SpeechRecognition) return;

        recognition = new SpeechRecognition();
        recognition.lang = navigator.language || 'en-US';
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;

        recognition.onstart = function() {
            console.log('[Suzi] Listening started');
            state.isListening = true;
            updateUI('listening');
            startSilenceTimer();
            autoStopTimer = setTimeout(function() {
                if (state.isListening) stopListening();
            }, CONFIG.MAX_LISTEN_TIME);
        };

        recognition.onresult = function(event) {
            var result = event.results[event.results.length - 1];
            var text = result[0].transcript.trim();
            if (silenceTimer) clearTimeout(silenceTimer);
            startSilenceTimer();

            if (result.isFinal) {
                showTranscript(text);
                if (text) handleInput(text);
            } else {
                showTranscript(text + '...');
            }
        };

        recognition.onerror = function(event) {
            console.log('[Suzi] Error:', event.error);
            state.isListening = false;
            clearAllTimers();
            if (event.error === 'not-allowed') {
                showStatus('🚫', 'Microphone blocked', 'Enable in browser settings');
            } else if (event.error !== 'aborted' && event.error !== 'no-speech') {
                updateUI('ready');
            }
        };

        recognition.onend = function() {
            console.log('[Suzi] Recognition ended');
            state.isListening = false;
            clearAllTimers();
            if (state.isOpen && !state.isProcessing && !state.isSpeaking) {
                updateUI('ready');
            }
        };
    }

    function startListening() {
        if (state.isListening || state.isProcessing || state.isSpeaking) {
            console.log('[Suzi] Cannot listen - busy');
            return;
        }
        stopSpeaking();

        if (isNative && window.AndroidVoice && window.AndroidVoice.startListening) {
            window.AndroidVoice.startListening();
        } else if (recognition) {
            try {
                recognition.start();
            } catch (e) {
                console.log('[Suzi] Start error:', e);
            }
        }
    }

    function stopListening() {
        clearAllTimers();
        state.isListening = false;
        if (isNative && window.AndroidVoice && window.AndroidVoice.stopListening) {
            window.AndroidVoice.stopListening();
        } else if (recognition) {
            try { recognition.abort(); } catch (e) {}
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

        if (isNative && window.AndroidVoice && window.AndroidVoice.speak) {
            window._suziTtsCallback = function() {
                state.isSpeaking = false;
                window._suziTtsCallback = null;
                if (onComplete) onComplete();
            };
            window.AndroidVoice.speak(text);
            var duration = Math.max(3000, text.split(' ').length * 500);
            ttsFallbackTimer = setTimeout(function() {
                if (state.isSpeaking) {
                    state.isSpeaking = false;
                    if (window._suziTtsCallback) {
                        var cb = window._suziTtsCallback;
                        window._suziTtsCallback = null;
                        cb();
                    }
                }
            }, duration);
            return;
        }

        if (!synthesis) {
            state.isSpeaking = false;
            if (onComplete) onComplete();
            return;
        }

        synthesis.cancel();
        var utterance = new SpeechSynthesisUtterance(text);
        currentUtterance = utterance;

        var voices = synthesis.getVoices();
        var voice = voices.find(function(v) { return v.lang.startsWith('en'); });
        if (voice) utterance.voice = voice;
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        var fallbackTime = (text.length * CONFIG.TTS_FALLBACK_MS_PER_CHAR) + 2000;
        ttsFallbackTimer = setTimeout(function() {
            if (state.isSpeaking) {
                state.isSpeaking = false;
                if (onComplete) onComplete();
            }
        }, fallbackTime);

        utterance.onend = function() {
            if (ttsFallbackTimer) clearTimeout(ttsFallbackTimer);
            state.isSpeaking = false;
            currentUtterance = null;
            if (onComplete) onComplete();
        };

        utterance.onerror = function(e) {
            if (ttsFallbackTimer) clearTimeout(ttsFallbackTimer);
            state.isSpeaking = false;
            currentUtterance = null;
            if (onComplete) onComplete();
        };

        synthesis.speak(utterance);
    }

    function stopSpeaking() {
        if (ttsFallbackTimer) { clearTimeout(ttsFallbackTimer); ttsFallbackTimer = null; }
        if (synthesis) synthesis.cancel();
        state.isSpeaking = false;
        currentUtterance = null;
        window._suziTtsCallback = null;
    }

    // ==================== INPUT HANDLING ====================
    function handleInput(text) {
        if (!text || state.isProcessing) return;
        console.log('[Suzi] Input:', text);
        stopListening();

        var lower = text.toLowerCase().trim();
        var closeCommands = ['bye', 'goodbye', 'stop', 'close', 'exit', 'cancel'];
        for (var i = 0; i < closeCommands.length; i++) {
            if (lower === closeCommands[i] || lower.indexOf(closeCommands[i] + ' ') === 0) {
                var goodbyes = ['Goodbye!', 'Bye!', 'See you!'];
                speak(goodbyes[Math.floor(Math.random() * goodbyes.length)], function() {
                    setTimeout(closeModal, 500);
                });
                return;
            }
        }
        processWithAI(text);
    }

    function processWithAI(text) {
        state.isProcessing = true;
        updateUI('thinking');
        conversation.push({ role: 'user', content: text });

        var controller = new AbortController();
        var timeout = setTimeout(function() { controller.abort(); }, CONFIG.API_TIMEOUT);

        fetch('/api/voice-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transcript: text,
                conversation: conversation.slice(-CONFIG.MAX_CONVERSATION_HISTORY)
            }),
            signal: controller.signal,
            credentials: 'same-origin'
        })
        .then(function(response) {
            clearTimeout(timeout);
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (!data.success) throw new Error(data.response || 'Failed');
            conversation.push({ role: 'assistant', content: data.response });
            if (conversation.length > CONFIG.MAX_CONVERSATION_HISTORY * 2) {
                conversation = conversation.slice(-CONFIG.MAX_CONVERSATION_HISTORY * 2);
            }
            speak(data.response, function() {
                state.isProcessing = false;
                if (data.action) {
                    executeAction(data.action);
                } else {
                    updateUI('ready');
                }
            });
        })
        .catch(function(error) {
            console.error('[Suzi] AI error:', error);
            state.isProcessing = false;
            speak('Sorry, please try again.', function() {
                updateUI('ready');
            });
        });
    }

    // ==================== ACTION EXECUTION ====================
    function executeAction(action) {
        console.log('[Suzi] Action:', action);
        var type = action.type;
        var data = action.data;

        if (type === 'navigate') {
            navigate(data.to);
        } else if (type === 'add_shopping') {
            addToShopping(data.item, data.category);
        } else if (type === 'create_note') {
            createNote(data.content);
        } else if (type === 'create_event') {
            createEvent(data);
        } else if (type === 'create_reminder') {
            createReminder(data);
        } else if (type === 'send_message') {
            sendMessage(data.content);
        } else if (type === 'find_member') {
            findMember(data.name);
        } else {
            updateUI('ready');
        }
    }

    function navigate(destination) {
        var paths = {
            home: '/home/', shopping: '/shopping/', notes: '/notes/',
            calendar: '/calendar/', schedule: '/schedule/', weather: '/weather/',
            messages: '/messages/', tracking: '/tracking/', notifications: '/notifications/',
            games: '/games/', help: '/help/'
        };
        updateUI('navigating');
        setTimeout(function() {
            closeModal();
            window.location.href = paths[destination] || '/home/';
        }, 800);
    }

    function addToShopping(item, category) {
        category = category || 'other';
        fetch('/shopping/api/lists.php?action=get_all', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(listData) {
            var listId = window.currentListId;
            if (!listId && listData.success && listData.lists && listData.lists[0]) {
                listId = listData.lists[0].id;
            }
            if (listId) {
                var formData = new FormData();
                formData.append('action', 'add');
                formData.append('list_id', listId);
                formData.append('name', item);
                formData.append('category', category);
                return fetch('/shopping/api/items.php', {
                    method: 'POST', body: formData, credentials: 'same-origin'
                });
            }
        })
        .then(function() {
            if (window.location.pathname.indexOf('/shopping/') !== -1) {
                setTimeout(function() { location.reload(); }, 500);
            }
        })
        .catch(function(e) { console.error('[Suzi] Shopping error:', e); });
        updateUI('ready');
    }

    function createNote(content) {
        var url = '/notes/?new=1';
        if (content) url += '&content=' + encodeURIComponent(content);
        setTimeout(function() { closeModal(); window.location.href = url; }, 800);
    }

    function createEvent(data) {
        var url = '/calendar/?new=1';
        if (data.title) url += '&content=' + encodeURIComponent(data.title);
        if (data.date) url += '&date=' + data.date;
        if (data.time) url += '&time=' + data.time;
        setTimeout(function() { closeModal(); window.location.href = url; }, 800);
    }

    function createReminder(data) {
        var url = '/schedule/?new=1';
        if (data.title) url += '&content=' + encodeURIComponent(data.title);
        if (data.date) url += '&date=' + data.date;
        if (data.time) url += '&time=' + data.time;
        setTimeout(function() { closeModal(); window.location.href = url; }, 800);
    }

    function sendMessage(content) {
        if (!content) { navigate('messages'); return; }
        var formData = new FormData();
        formData.append('content', content);
        formData.append('to_family', '1');
        fetch('/messages/api/send.php', {
            method: 'POST', body: formData, credentials: 'same-origin'
        }).catch(function(e) { console.error('[Suzi] Message error:', e); });
        updateUI('ready');
    }

    function findMember(name) {
        var url = '/tracking/?search=' + encodeURIComponent(name);
        setTimeout(function() { closeModal(); window.location.href = url; }, 800);
    }

    // ==================== UI MANAGEMENT ====================
    function updateUI(uiState) {
        if (!dom) cacheDom();
        if (!dom || !dom.modal) return;

        if (dom.micBtn) {
            dom.micBtn.className = 'modal-mic-btn';
            if (uiState === 'listening') dom.micBtn.className += ' listening';
            else if (uiState === 'speaking') dom.micBtn.className += ' speaking';
            else if (uiState === 'thinking') dom.micBtn.className += ' thinking';
        }

        var states = {
            ready: { icon: '🎤', text: 'Tap mic to speak', sub: 'Ask me anything' },
            listening: { icon: '🎤', text: 'Listening...', sub: 'Speak now' },
            thinking: { icon: '🧠', text: 'Thinking...', sub: 'Processing' },
            speaking: { icon: '🔊', text: 'Speaking...', sub: '' },
            navigating: { icon: '🧭', text: 'Opening...', sub: '' }
        };
        var s = states[uiState] || states.ready;
        showStatus(s.icon, s.text, s.sub);

        if (dom.suggestions) {
            dom.suggestions.style.display = (uiState === 'ready' && conversation.length === 0) ? 'block' : 'none';
        }
    }

    function showStatus(icon, text, subtext) {
        if (!dom) cacheDom();
        if (dom.statusIcon) dom.statusIcon.textContent = icon;
        if (dom.statusText) dom.statusText.textContent = text;
        if (dom.statusSubtext) dom.statusSubtext.textContent = subtext || '';
    }

    function showTranscript(text) {
        if (!dom) cacheDom();
        if (dom.transcript) dom.transcript.textContent = text;
    }

    // ==================== MODAL CONTROL ====================
    function openModal() {
        console.log('[Suzi] Opening modal');
        if (!initialized) init();
        cacheDom();
        if (!dom.modal) {
            console.error('[Suzi] Modal not found!');
            return;
        }

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

        setTimeout(function() {
            speak("Hi! I'm Suzi. What can I help you with?");
        }, 300);
    }

    function closeModal() {
        console.log('[Suzi] Closing modal');
        stopListening();
        stopSpeaking();
        clearAllTimers();

        state.isOpen = false;
        state.isListening = false;
        state.isSpeaking = false;
        state.isProcessing = false;

        if (!dom) cacheDom();
        if (dom && dom.modal) {
            dom.modal.classList.remove('active');
        }
        document.body.style.overflow = '';
    }

    function toggleListening() {
        console.log('[Suzi] Toggle listening, state:', state);
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

    // ==================== INITIALIZATION ====================
    function init() {
        if (initialized) return;
        initialized = true;
        console.log('[Suzi] Voice v10.1 - Initializing...');
        initRecognition();
        if (synthesis) {
            synthesis.getVoices();
            if (typeof speechSynthesis !== 'undefined' && speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = function() { synthesis.getVoices(); };
            }
        }
        console.log('[Suzi] Voice v10.1 Ready!', isNative ? '(Native)' : '(Web)');
    }

    // ==================== EXPOSE PUBLIC API IMMEDIATELY ====================
    var publicAPI = {
        open: openModal,
        close: closeModal,
        toggle: function() { state.isOpen ? closeModal() : openModal(); },
        toggleListening: toggleListening,
        executeSuggestion: executeSuggestion,
        getInstance: function() { return publicAPI; },
        // Native hooks
        onSttResult: function(text) {
            if (text && text.trim()) {
                state.isListening = false;
                handleInput(text.trim());
            }
        },
        onTtsEnd: function() {
            state.isSpeaking = false;
            if (ttsFallbackTimer) clearTimeout(ttsFallbackTimer);
            if (window._suziTtsCallback) {
                var cb = window._suziTtsCallback;
                window._suziTtsCallback = null;
                cb();
            } else if (state.isOpen && !state.isProcessing) {
                updateUI('ready');
            }
        },
        onTtsStart: function() {
            state.isSpeaking = true;
            updateUI('speaking');
        },
        onSttStart: function() {
            state.isListening = true;
            updateUI('listening');
        },
        onSttStop: function() {
            state.isListening = false;
            if (state.isOpen && !state.isProcessing) {
                updateUI('ready');
            }
        }
    };

    // Expose to window IMMEDIATELY (not on DOMContentLoaded)
    window.SuziVoice = publicAPI;
    window.AdvancedVoiceAssistant = {
        getInstance: function() { return publicAPI; },
        openModal: openModal,
        closeModal: closeModal
    };

    console.log('[Suzi] API exposed to window.SuziVoice');

    // Auto-init on DOM ready (just for recognition setup)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
