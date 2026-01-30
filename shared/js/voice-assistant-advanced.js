/**
 * SUZI VOICE ASSISTANT - Complete Rebuild
 * Based on working FlashVoice pattern
 */

(function() {
    'use strict';

    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    var synth = window.speechSynthesis;

    var recognition = null;
    var currentUtterance = null;
    var isListening = false;
    var isSpeaking = false;
    var isProcessing = false;
    var isModalOpen = false;
    var conversation = [];

    var silenceTimer = null;
    var speakTimer = null;

    var SILENCE_TIMEOUT = 3000;
    var MAX_LISTEN_TIME = 20000;

    // DOM elements
    var modal, statusIcon, statusText, statusSubtext, transcriptEl, micBtn, suggestionsEl;

    function getElements() {
        modal = document.getElementById('voiceModal');
        statusIcon = document.getElementById('statusIcon');
        statusText = document.getElementById('statusText');
        statusSubtext = document.getElementById('statusSubtext');
        transcriptEl = document.getElementById('voiceTranscript');
        micBtn = document.getElementById('modalMicBtn');
        suggestionsEl = document.getElementById('voiceSuggestions');
    }

    function clearTimers() {
        if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
        if (speakTimer) { clearTimeout(speakTimer); speakTimer = null; }
    }

    function setStatus(icon, text, sub) {
        getElements();
        if (statusIcon) statusIcon.textContent = icon;
        if (statusText) statusText.textContent = text;
        if (statusSubtext) statusSubtext.textContent = sub || '';
    }

    function setTranscript(text) {
        getElements();
        if (transcriptEl) transcriptEl.textContent = text;
    }

    function setMicState(state) {
        getElements();
        if (micBtn) {
            micBtn.classList.remove('listening', 'speaking', 'thinking');
            if (state) micBtn.classList.add(state);
        }
    }

    function showSuggestions(show) {
        getElements();
        if (suggestionsEl) {
            suggestionsEl.style.display = show ? 'block' : 'none';
        }
    }

    // Initialize speech recognition
    function initRecognition() {
        if (!SpeechRecognition) {
            console.log('[Suzi] Speech recognition not supported');
            return;
        }

        recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;
        recognition.lang = 'en-US';

        recognition.onstart = function() {
            console.log('[Suzi] Listening...');
            isListening = true;
            setStatus('🎤', 'Listening...', 'Speak now');
            setMicState('listening');

            silenceTimer = setTimeout(function() {
                console.log('[Suzi] Silence timeout');
                if (isListening) stopListening();
            }, SILENCE_TIMEOUT);

            setTimeout(function() {
                if (isListening) {
                    console.log('[Suzi] Max time reached');
                    stopListening();
                }
            }, MAX_LISTEN_TIME);
        };

        recognition.onresult = function(event) {
            if (silenceTimer) clearTimeout(silenceTimer);

            var result = event.results[event.results.length - 1];
            var transcript = result[0].transcript;

            setTranscript(transcript);

            if (result.isFinal) {
                console.log('[Suzi] Final:', transcript);
                if (transcript.trim()) {
                    handleUserInput(transcript.trim());
                }
            } else {
                // Reset silence timer on interim
                silenceTimer = setTimeout(function() {
                    if (isListening) stopListening();
                }, SILENCE_TIMEOUT);
            }
        };

        recognition.onerror = function(event) {
            console.log('[Suzi] Error:', event.error);
            isListening = false;
            clearTimers();

            if (event.error === 'not-allowed') {
                setStatus('🚫', 'Microphone blocked', 'Check browser settings');
                setMicState(null);
            } else if (event.error !== 'aborted' && event.error !== 'no-speech') {
                setStatus('🎤', 'Tap to speak', 'Ask me anything');
                setMicState(null);
            }
        };

        recognition.onend = function() {
            console.log('[Suzi] Recognition ended');
            isListening = false;
            clearTimers();

            if (isModalOpen && !isProcessing && !isSpeaking) {
                setStatus('🎤', 'Tap to speak', 'Ask me anything');
                setMicState(null);
            }
        };

        console.log('[Suzi] Recognition initialized');
    }

    function startListening() {
        if (isListening || isProcessing || isSpeaking) {
            console.log('[Suzi] Busy, cannot listen');
            return;
        }

        stopSpeaking();

        if (!recognition) {
            initRecognition();
        }

        if (recognition) {
            try {
                recognition.start();
            } catch (e) {
                console.log('[Suzi] Start error:', e);
                try {
                    recognition.abort();
                    setTimeout(function() { recognition.start(); }, 100);
                } catch (e2) {
                    console.error('[Suzi] Failed to start:', e2);
                }
            }
        }
    }

    function stopListening() {
        clearTimers();
        isListening = false;

        if (recognition) {
            try { recognition.abort(); } catch (e) {}
        }
    }

    // Text to Speech
    function speak(text, callback) {
        if (!text) {
            if (callback) callback();
            return;
        }

        if (!synth) {
            console.log('[Suzi] No speech synthesis');
            setTranscript(text);
            if (callback) setTimeout(callback, 1000);
            return;
        }

        console.log('[Suzi] Speaking:', text.substring(0, 50));

        isSpeaking = true;
        setStatus('🔊', 'Speaking...', '');
        setMicState('speaking');
        setTranscript(text);
        showSuggestions(false);

        // Cancel any current speech
        synth.cancel();

        var utterance = new SpeechSynthesisUtterance(text);

        // Get voices and pick English one
        var voices = synth.getVoices();
        if (voices.length > 0) {
            for (var i = 0; i < voices.length; i++) {
                if (voices[i].lang.indexOf('en') === 0) {
                    utterance.voice = voices[i];
                    break;
                }
            }
        }

        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        // Fallback timer
        var fallbackMs = Math.max(3000, text.length * 80);
        speakTimer = setTimeout(function() {
            console.log('[Suzi] Speech timeout fallback');
            isSpeaking = false;
            setMicState(null);
            if (callback) callback();
        }, fallbackMs);

        utterance.onstart = function() {
            console.log('[Suzi] Speech started');
        };

        utterance.onend = function() {
            console.log('[Suzi] Speech ended');
            clearTimeout(speakTimer);
            isSpeaking = false;
            setMicState(null);
            if (callback) callback();
        };

        utterance.onerror = function(e) {
            console.log('[Suzi] Speech error:', e.error);
            clearTimeout(speakTimer);
            isSpeaking = false;
            setMicState(null);
            if (callback) callback();
        };

        currentUtterance = utterance;

        // Small delay helps Chrome
        setTimeout(function() {
            synth.speak(utterance);
        }, 100);
    }

    function stopSpeaking() {
        clearTimeout(speakTimer);
        isSpeaking = false;

        if (synth) {
            synth.cancel();
        }

        currentUtterance = null;
    }

    // Handle user input
    function handleUserInput(text) {
        stopListening();

        // Check for exit commands
        var lower = text.toLowerCase().trim();
        if (lower === 'bye' || lower === 'goodbye' || lower === 'close' ||
            lower === 'stop' || lower === 'exit' || lower === 'cancel') {
            speak('Goodbye!', function() {
                setTimeout(closeModal, 500);
            });
            return;
        }

        // Send to AI
        isProcessing = true;
        setStatus('🧠', 'Thinking...', '');
        setMicState('thinking');
        showSuggestions(false);

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
        .then(function(response) { return response.json(); })
        .then(function(data) {
            isProcessing = false;

            if (data.success && data.response) {
                conversation.push({ role: 'assistant', content: data.response });

                speak(data.response, function() {
                    if (data.action) {
                        executeAction(data.action);
                    } else {
                        setStatus('🎤', 'Tap to speak', 'Ask me anything');
                        showSuggestions(conversation.length <= 2);
                    }
                });
            } else {
                speak('Sorry, I had trouble with that. Please try again.', function() {
                    setStatus('🎤', 'Tap to speak', 'Ask me anything');
                });
            }
        })
        .catch(function(error) {
            console.error('[Suzi] API error:', error);
            isProcessing = false;

            speak('Sorry, something went wrong. Please try again.', function() {
                setStatus('🎤', 'Tap to speak', 'Ask me anything');
            });
        });
    }

    // Execute action from AI
    function executeAction(action) {
        if (!action || !action.type) return;

        console.log('[Suzi] Action:', action.type);

        var paths = {
            home: '/home/',
            shopping: '/shopping/',
            notes: '/notes/',
            calendar: '/calendar/',
            schedule: '/schedule/',
            weather: '/weather/',
            messages: '/messages/',
            tracking: '/tracking/',
            notifications: '/notifications/',
            games: '/games/'
        };

        if (action.type === 'navigate' && action.data && action.data.to) {
            setStatus('🧭', 'Opening...', '');
            setTimeout(function() {
                closeModal();
                window.location.href = paths[action.data.to] || '/home/';
            }, 500);
        } else {
            setStatus('🎤', 'Tap to speak', 'Ask me anything');
        }
    }

    // Modal functions
    function openModal() {
        console.log('[Suzi] Opening modal');
        getElements();

        if (!modal) {
            console.error('[Suzi] Modal element not found!');
            return;
        }

        // Reset state
        isModalOpen = true;
        isListening = false;
        isSpeaking = false;
        isProcessing = false;
        conversation = [];
        clearTimers();
        stopSpeaking();
        stopListening();

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        setStatus('🎤', 'Tap to speak', 'Ask me anything');
        setTranscript("Hi! I'm Suzi. Tap the microphone to talk to me!");
        setMicState(null);
        showSuggestions(true);
    }

    function closeModal() {
        console.log('[Suzi] Closing modal');

        stopListening();
        stopSpeaking();
        clearTimers();

        isModalOpen = false;
        isListening = false;
        isSpeaking = false;
        isProcessing = false;

        getElements();
        if (modal) {
            modal.classList.remove('active');
        }
        document.body.style.overflow = '';
    }

    function toggleListening() {
        console.log('[Suzi] Toggle, speaking:', isSpeaking, 'listening:', isListening, 'processing:', isProcessing);

        if (isSpeaking) {
            stopSpeaking();
            setStatus('🎤', 'Tap to speak', 'Ask me anything');
            setMicState(null);
        } else if (isListening) {
            stopListening();
            setStatus('🎤', 'Tap to speak', 'Ask me anything');
            setMicState(null);
        } else if (!isProcessing) {
            startListening();
        }
    }

    function executeSuggestion(text) {
        if (isProcessing || isSpeaking || isListening) return;
        handleUserInput(text);
    }

    // Initialize
    console.log('[Suzi] Initializing...');
    initRecognition();

    // Preload voices
    if (synth) {
        synth.getVoices();
        if (synth.onvoiceschanged !== undefined) {
            synth.onvoiceschanged = function() {
                synth.getVoices();
            };
        }
    }

    console.log('[Suzi] Ready!');

    // Expose public API
    window.SuziVoice = {
        open: openModal,
        close: closeModal,
        toggle: function() {
            if (isModalOpen) closeModal();
            else openModal();
        },
        toggleListening: toggleListening,
        executeSuggestion: executeSuggestion,
        getInstance: function() { return window.SuziVoice; }
    };

    // Backwards compatibility
    window.AdvancedVoiceAssistant = {
        getInstance: function() { return window.SuziVoice; },
        openModal: openModal,
        closeModal: closeModal
    };

})();
