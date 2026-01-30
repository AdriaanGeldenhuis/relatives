/**
 * SUZI VOICE ASSISTANT v11 - Complete Fresh Rebuild
 */

var SuziVoice = (function() {

    // State
    var isOpen = false;
    var isListening = false;
    var isSpeaking = false;
    var isProcessing = false;
    var conversation = [];

    // Speech APIs
    var recognition = null;
    var synth = window.speechSynthesis;
    var voices = [];

    // Timers
    var silenceTimer = null;
    var speakTimer = null;

    // DOM elements
    var modal, statusIcon, statusText, statusSubtext, transcript, micBtn, suggestions;

    // Load voices
    function loadVoices() {
        voices = synth.getVoices();
        console.log('[Suzi] Loaded', voices.length, 'voices');
    }

    if (synth) {
        loadVoices();
        if (synth.onvoiceschanged !== undefined) {
            synth.onvoiceschanged = loadVoices;
        }
    }

    // Get DOM elements
    function getDom() {
        modal = document.getElementById('voiceModal');
        statusIcon = document.getElementById('statusIcon');
        statusText = document.getElementById('statusText');
        statusSubtext = document.getElementById('statusSubtext');
        transcript = document.getElementById('voiceTranscript');
        micBtn = document.getElementById('modalMicBtn');
        suggestions = document.getElementById('voiceSuggestions');
    }

    // Setup speech recognition
    function setupRecognition() {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            console.log('[Suzi] No speech recognition support');
            return;
        }

        recognition = new SpeechRecognition();
        recognition.lang = 'en-US';
        recognition.continuous = false;
        recognition.interimResults = true;

        recognition.onstart = function() {
            console.log('[Suzi] Listening...');
            isListening = true;
            setUI('listening');

            // Auto-stop after 3 seconds of silence
            silenceTimer = setTimeout(function() {
                if (isListening) {
                    console.log('[Suzi] Silence timeout');
                    stopListening();
                }
            }, 3000);
        };

        recognition.onresult = function(e) {
            clearTimeout(silenceTimer);
            var result = e.results[e.results.length - 1];
            var text = result[0].transcript;

            if (transcript) transcript.textContent = text;

            if (result.isFinal && text.trim()) {
                console.log('[Suzi] Got:', text);
                processInput(text.trim());
            } else {
                // Reset silence timer on interim results
                silenceTimer = setTimeout(function() {
                    if (isListening) stopListening();
                }, 3000);
            }
        };

        recognition.onerror = function(e) {
            console.log('[Suzi] Error:', e.error);
            isListening = false;
            clearTimeout(silenceTimer);
            if (e.error === 'not-allowed') {
                setUI('error', 'Microphone blocked');
            } else {
                setUI('ready');
            }
        };

        recognition.onend = function() {
            console.log('[Suzi] Recognition ended');
            isListening = false;
            clearTimeout(silenceTimer);
            if (isOpen && !isProcessing && !isSpeaking) {
                setUI('ready');
            }
        };

        console.log('[Suzi] Speech recognition ready');
    }

    // Speak text
    function speak(text, callback) {
        if (!text) {
            if (callback) callback();
            return;
        }

        console.log('[Suzi] Speaking:', text.substring(0, 50) + '...');

        isSpeaking = true;
        setUI('speaking');
        if (transcript) transcript.textContent = text;

        // Cancel any ongoing speech
        if (synth) synth.cancel();

        // Create utterance
        var utterance = new SpeechSynthesisUtterance(text);

        // Try to find an English voice
        if (voices.length === 0) voices = synth.getVoices();
        for (var i = 0; i < voices.length; i++) {
            if (voices[i].lang.indexOf('en') === 0) {
                utterance.voice = voices[i];
                break;
            }
        }

        utterance.rate = 1;
        utterance.pitch = 1;
        utterance.volume = 1;

        // Fallback timer in case onend doesn't fire
        var fallbackMs = Math.max(3000, text.length * 80);
        speakTimer = setTimeout(function() {
            console.log('[Suzi] Speech fallback timer');
            isSpeaking = false;
            if (callback) callback();
        }, fallbackMs);

        utterance.onend = function() {
            console.log('[Suzi] Speech ended');
            clearTimeout(speakTimer);
            isSpeaking = false;
            if (callback) callback();
        };

        utterance.onerror = function(e) {
            console.log('[Suzi] Speech error:', e);
            clearTimeout(speakTimer);
            isSpeaking = false;
            if (callback) callback();
        };

        // Chrome bug workaround - need small delay
        setTimeout(function() {
            synth.speak(utterance);
        }, 100);
    }

    // Stop speaking
    function stopSpeaking() {
        clearTimeout(speakTimer);
        if (synth) synth.cancel();
        isSpeaking = false;
    }

    // Start listening
    function startListening() {
        if (isListening || isProcessing || isSpeaking) return;

        stopSpeaking();

        if (recognition) {
            try {
                recognition.start();
            } catch (e) {
                console.log('[Suzi] Start error:', e);
            }
        }
    }

    // Stop listening
    function stopListening() {
        clearTimeout(silenceTimer);
        isListening = false;
        if (recognition) {
            try { recognition.abort(); } catch(e) {}
        }
    }

    // Process user input
    function processInput(text) {
        stopListening();

        // Check for close commands
        var lower = text.toLowerCase();
        if (lower === 'bye' || lower === 'goodbye' || lower === 'close' || lower === 'stop') {
            speak('Goodbye!', function() {
                setTimeout(close, 500);
            });
            return;
        }

        // Send to AI
        isProcessing = true;
        setUI('thinking');

        conversation.push({ role: 'user', content: text });

        fetch('/api/voice-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transcript: text,
                conversation: conversation.slice(-10)
            }),
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.response) {
                conversation.push({ role: 'assistant', content: data.response });
                speak(data.response, function() {
                    isProcessing = false;
                    if (data.action) {
                        doAction(data.action);
                    } else {
                        setUI('ready');
                    }
                });
            } else {
                throw new Error('No response');
            }
        })
        .catch(function(e) {
            console.error('[Suzi] API error:', e);
            isProcessing = false;
            speak('Sorry, please try again.', function() {
                setUI('ready');
            });
        });
    }

    // Execute action
    function doAction(action) {
        var type = action.type;
        var data = action.data || {};

        if (type === 'navigate') {
            var paths = {
                home: '/home/', shopping: '/shopping/', notes: '/notes/',
                calendar: '/calendar/', schedule: '/schedule/', messages: '/messages/',
                tracking: '/tracking/', notifications: '/notifications/', games: '/games/'
            };
            setUI('navigating');
            setTimeout(function() {
                close();
                window.location.href = paths[data.to] || '/home/';
            }, 800);
        } else {
            setUI('ready');
        }
    }

    // Set UI state
    function setUI(state, msg) {
        getDom();
        if (!modal) return;

        var states = {
            ready: ['🎤', 'Tap mic to speak', 'Ask me anything'],
            listening: ['🎤', 'Listening...', 'Speak now'],
            thinking: ['🧠', 'Thinking...', 'Processing'],
            speaking: ['🔊', 'Speaking...', ''],
            navigating: ['🧭', 'Opening...', ''],
            error: ['🚫', msg || 'Error', 'Please try again']
        };

        var s = states[state] || states.ready;
        if (statusIcon) statusIcon.textContent = s[0];
        if (statusText) statusText.textContent = s[1];
        if (statusSubtext) statusSubtext.textContent = s[2];

        if (micBtn) {
            micBtn.className = 'modal-mic-btn';
            if (state === 'listening') micBtn.className += ' listening';
            if (state === 'speaking') micBtn.className += ' speaking';
            if (state === 'thinking') micBtn.className += ' thinking';
        }

        if (suggestions) {
            suggestions.style.display = (state === 'ready' && conversation.length === 0) ? 'block' : 'none';
        }
    }

    // Open modal
    function open() {
        console.log('[Suzi] Opening');
        getDom();
        if (!modal) {
            console.error('[Suzi] Modal not found!');
            return;
        }

        // Reset
        isOpen = true;
        isListening = false;
        isSpeaking = false;
        isProcessing = false;
        conversation = [];
        stopSpeaking();
        stopListening();

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        setUI('ready');
        if (transcript) transcript.textContent = 'Tap the mic and ask me anything!';

        // Say hello after short delay
        setTimeout(function() {
            speak("Hi! I'm Suzi. What can I help you with?", function() {
                setUI('ready');
            });
        }, 500);
    }

    // Close modal
    function close() {
        console.log('[Suzi] Closing');
        stopListening();
        stopSpeaking();

        isOpen = false;
        isListening = false;
        isSpeaking = false;
        isProcessing = false;

        getDom();
        if (modal) modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Toggle listening
    function toggleListening() {
        console.log('[Suzi] Toggle listening');
        if (isSpeaking) {
            stopSpeaking();
            setUI('ready');
        } else if (isListening) {
            stopListening();
            setUI('ready');
        } else if (!isProcessing) {
            startListening();
        }
    }

    // Execute suggestion
    function executeSuggestion(text) {
        if (isProcessing || isSpeaking) return;
        processInput(text);
    }

    // Initialize
    console.log('[Suzi] v11 Loading...');
    setupRecognition();
    console.log('[Suzi] v11 Ready!');

    // Public API
    return {
        open: open,
        close: close,
        toggle: function() { isOpen ? close() : open(); },
        toggleListening: toggleListening,
        executeSuggestion: executeSuggestion,
        getInstance: function() { return SuziVoice; }
    };

})();

// Backwards compatibility
var AdvancedVoiceAssistant = {
    getInstance: function() { return SuziVoice; },
    openModal: function() { SuziVoice.open(); },
    closeModal: function() { SuziVoice.close(); }
};

console.log('[Suzi] API ready on window.SuziVoice');
