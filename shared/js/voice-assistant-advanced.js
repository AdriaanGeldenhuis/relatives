/**
 * SUZI VOICE ASSISTANT - Complete Rebuild
 * Based on working FlashVoice pattern
 */

(function() {
    'use strict';

    var writeCsrf = document.currentScript.dataset.writeCsrf;
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    var synth = window.speechSynthesis;

    var recognition = null;
    var currentUtterance = null;
    var isListening = false;
    var isStarting = false;
    var recognitionError = false;
    var isSpeaking = false;
    var isProcessing = false;
    var isModalOpen = false;
    var conversation = [];

    var silenceTimer = null;
    var speakTimer = null;
    var listenTimer = null;

    var SILENCE_TIMEOUT = 3000;
    var MAX_LISTEN_TIME = 20000;

    // DOM elements
    var modal, consoleEl, statusText, statusSubtext, heardEl, transcriptEl, answerEl, replyEl, replayBtn, micBtn, suggestionsEl, trigger;

    function getElements() {
        modal = document.getElementById('voiceModal');
        consoleEl = document.getElementById('voiceConsole');
        statusText = document.getElementById('statusText');
        statusSubtext = document.getElementById('statusSubtext');
        heardEl = document.getElementById('voiceHeard');
        transcriptEl = document.getElementById('voiceTranscript');
        answerEl = document.getElementById('voiceAnswer');
        replyEl = document.getElementById('voiceReply');
        replayBtn = document.getElementById('voiceReplay');
        micBtn = document.getElementById('modalMicBtn');
        suggestionsEl = document.getElementById('voiceSuggestions');
        trigger = document.getElementById('micBtn');
    }

    function clearTimers() {
        if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
        if (listenTimer) { clearTimeout(listenTimer); listenTimer = null; }
    }

    function setStatus(text, sub) {
        if (statusText) statusText.textContent = text;
        if (statusSubtext) statusSubtext.textContent = sub || '';
    }

    function setReady() {
        setStatus('Tap to speak', 'Ask a question or try a command below.');
    }

    function setTranscript(text) {
        transcriptEl.textContent = text;
        heardEl.hidden = !text;
    }

    function setReply(text) {
        replyEl.textContent = text;
        replyEl.scrollTop = 0;
        answerEl.hidden = !text;
        replayBtn.hidden = !text || !synth;
    }

    function setMicState(state) {
        consoleEl.dataset.state = state || 'ready';
        var label = {
            listening: 'Stop listening',
            speaking: 'Stop speaking',
            thinking: 'Suzi is working'
        }[state] || 'Start listening';
        micBtn.setAttribute('aria-label', label);
        micBtn.setAttribute('aria-disabled', String(state === 'thinking'));
        replayBtn.textContent = state === 'speaking' ? '■ Stop reply' : '↻ Play reply again';
        replayBtn.setAttribute('aria-disabled', String(state === 'listening' || state === 'thinking'));
        suggestionsEl.querySelectorAll('button').forEach(function(button) {
            button.setAttribute('aria-disabled', String(Boolean(state)));
        });
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
            isStarting = false;
            isListening = true;
            if (!isModalOpen) {
                stopListening();
                return;
            }
            setTranscript('');
            setReply('');
            setStatus('Listening…', 'Speak now. Tap the circle to stop.');
            setMicState('listening');

            silenceTimer = setTimeout(function() {
                console.log('[Suzi] Silence timeout');
                if (isListening) stopListening();
            }, SILENCE_TIMEOUT);

            listenTimer = setTimeout(function() {
                if (isListening) {
                    console.log('[Suzi] Max time reached');
                    stopListening();
                }
            }, MAX_LISTEN_TIME);
        };

        recognition.onresult = function(event) {
            if (!isModalOpen || !isListening) return;
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
            isStarting = false;
            isListening = false;
            clearTimers();
            if (!isModalOpen || isProcessing || isSpeaking || event.error === 'aborted') return;

            recognitionError = true;
            setMicState(null);
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                setStatus('Microphone blocked', 'Allow microphone access or try a suggestion below.');
            } else if (event.error === 'no-speech') {
                setStatus('No speech detected', 'Tap the microphone and try again.');
            } else {
                setStatus('Could not start listening', 'Try the microphone again or choose a suggestion.');
            }
        };

        recognition.onend = function() {
            isStarting = false;
            isListening = false;
            clearTimers();
            if (isModalOpen && !isProcessing && !isSpeaking && !recognitionError) {
                setReady();
                setMicState(null);
            }
        };
    }

    function startListening() {
        if (!isModalOpen || isStarting || isListening || isProcessing || isSpeaking) return;

        if (!recognition) {
            setStatus('Microphone unavailable', 'Try a suggestion below or use Help to type a question.');
            return;
        }

        recognitionError = false;
        isStarting = true;
        setStatus('Starting microphone…', '');
        setMicState('thinking');
        try {
            recognition.start();
        } catch (error) {
            isStarting = false;
            setStatus('Could not start listening', 'Tap the microphone to try again.');
            setMicState(null);
        }
    }

    function stopListening() {
        clearTimers();
        if (recognition && (isStarting || isListening)) {
            try { recognition.abort(); } catch (error) {}
        }
        isStarting = false;
        isListening = false;
    }

    // Text to Speech
    function speak(text, callback) {
        if (!text) {
            if (callback) callback();
            return;
        }

        if (!isModalOpen) return;
        setReply(text);
        if (!synth) {
            setMicState(null);
            if (callback) callback();
            return;
        }

        console.log('[Suzi] Speaking:', text.substring(0, 50));

        isSpeaking = true;
        setStatus('Speaking…', 'Tap the circle to stop the reply.');
        setMicState('speaking');

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

        function finishSpeaking() {
            if (currentUtterance !== utterance) return;
            currentUtterance = null;
            clearTimeout(speakTimer);
            isSpeaking = false;
            setMicState(null);
            if (callback) callback();
        }

        utterance.onend = finishSpeaking;
        utterance.onerror = finishSpeaking;
        currentUtterance = utterance;
        speakTimer = setTimeout(finishSpeaking, Math.max(3000, text.length * 80));

        setTimeout(function() {
            if (currentUtterance === utterance) synth.speak(utterance);
        }, 100);
    }

    function stopSpeaking() {
        clearTimeout(speakTimer);
        isSpeaking = false;
        currentUtterance = null;
        if (synth) synth.cancel();
    }

    // Handle user input
    function handleUserInput(text) {
        stopListening();

        // Check for exit commands
        var lower = text.toLowerCase().trim();
        if (lower === 'bye' || lower === 'goodbye' || lower === 'close' ||
            lower === 'stop' || lower === 'exit' || lower === 'cancel') {
            speak('Goodbye!', closeModal);
            return;
        }

        // Send to AI
        isProcessing = true;
        setStatus('Thinking…', 'Suzi is working on your request.');
        setMicState('thinking');

        setTranscript(text);
        setReply('');
        var requestConversation = conversation;
        conversation.push({ role: 'user', content: text });

        fetch('/api/voice-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transcript: text,
                conversation: conversation.slice(-10)
            }),
            credentials: 'same-origin',
            signal: AbortSignal.timeout(18000)
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!isModalOpen || conversation !== requestConversation) return;
            isProcessing = false;

            if (data.success && data.response) {
                conversation.push({ role: 'assistant', content: data.response });

                // For data actions: execute FIRST, then speak based on result
                if (data.action && isDataAction(data.action.type)) {
                    executeDataAction(data.action, data.response);
                } else if (data.action) {
                    // Navigation and other instant actions: speak then act
                    speak(data.response, function() {
                        executeAction(data.action);
                    });
                } else {
                    // No action: just speak
                    speak(data.response, function() {
                        setReady();
                    });
                }
            } else {
                speak('Sorry, I had trouble with that. Please try again.', function() {
                    setReady();
                });
            }
        })
        .catch(function(error) {
            if (!isModalOpen || conversation !== requestConversation) return;
            console.error('[Suzi] API error:', error);
            isProcessing = false;

            speak('Sorry, something went wrong. Please try again.', function() {
                setReady();
            });
        });
    }

    // Check if action type modifies data (needs to complete before confirming)
    function isDataAction(type) {
        return type === 'add_shopping' || type === 'create_event' ||
               type === 'create_reminder' || type === 'create_note';
    }

    // Execute data action FIRST, then speak success or error
    function executeDataAction(action, aiResponse) {
        console.log('[Suzi] Data action:', action.type, action.data);

        var actionFn = null;

        if (action.type === 'add_shopping' && action.data && action.data.item) {
            actionFn = function(cb) { addShoppingItem(action.data.item, action.data.category || 'other', cb); };
        } else if (action.type === 'create_note' && action.data && action.data.content) {
            actionFn = function(cb) { createNote(action.data.title || '', action.data.content, cb); };
        } else if (action.type === 'create_event' && action.data && action.data.title) {
            actionFn = function(cb) { createCalendarEvent(action.data, cb); };
        } else if (action.type === 'create_reminder' && action.data && action.data.title) {
            actionFn = function(cb) { createReminder(action.data, cb); };
        }

        if (!actionFn) {
            speak(aiResponse, setReady);
            return;
        }

        var label = action.data.item || action.data.title || 'this note';
        if (!window.confirm('Save ' + label + ' to your family?')) {
            speak('Okay, I left it as it was.', setReady);
            return;
        }
        var actionConversation = conversation;
        isProcessing = true;
        actionFn(function(success, errorMsg) {
            if (!isModalOpen || conversation !== actionConversation) return;
            isProcessing = false;
            var reply = success
                ? aiResponse
                : errorMsg || 'Sorry, I couldn’t complete that action. Please try again.';
            speak(reply, setReady);
        });
    }

    // Execute non-data actions (navigation etc)
    function executeAction(action) {
        if (!action || !action.type) return;

        console.log('[Suzi] Action:', action.type, action.data);

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
            var navigationConversation = conversation;
            isProcessing = true;
            setMicState('thinking');
            setStatus('Opening...', '');
            setTimeout(function() {
                if (!isModalOpen || conversation !== navigationConversation) return;
                closeModal();
                window.location.href = paths[action.data.to] || '/home/';
            }, 500);
        } else {
            setReady();
        }
    }

    // Add item to shopping list via API
    function addShoppingItem(itemName, category, callback) {
        setStatus('Adding to list...', '');

        // First get available shopping lists
        fetch('/shopping/api/lists.php?action=get_all', {
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.lists && data.lists.length > 0) {
                return data.lists[0].id;
            } else {
                var formData = new FormData();
                formData.append('action', 'create');
                formData.append('name', 'Shopping List');
                formData.append('icon', '🛒');

                return fetch('/shopping/api/lists.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) { return response.json(); })
                .then(function(createData) {
                    if (createData.success && createData.list_id) {
                        return createData.list_id;
                    }
                    throw new Error('Could not create shopping list');
                });
            }
        })
        .then(function(listId) {
            var formData = new FormData();
            formData.append('action', 'add');
            formData.append('list_id', listId);
            formData.append('name', itemName);
            formData.append('category', category);

            return fetch('/shopping/api/items.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                console.log('[Suzi] Item added:', itemName);
                refreshPageData('shopping');
                if (callback) callback(true);
            } else {
                console.error('[Suzi] Failed to add item:', result.error);
                if (callback) callback(false, 'Sorry, I couldn\'t add that to your shopping list.');
            }
        })
        .catch(function(error) {
            console.error('[Suzi] Shopping error:', error);
            if (callback) callback(false, 'Sorry, there was a problem adding that item.');
        });
    }

    // Create a note via API
    function createNote(title, content, callback) {
        setStatus('Creating note...', '');

        var formData = new FormData();
        formData.append('action', 'create_note');
        formData.append('csrf_token', writeCsrf);
        formData.append('title', (title || content.substring(0, 50)).slice(0, 160));
        formData.append('body', content);

        fetch('/notes/', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                console.log('[Suzi] Note created');
                window.dispatchEvent(new CustomEvent('relatives:note-created', { detail: result.note }));
                window.RelativesPlanner.broadcast(window.RelativesPlanner.noteSignal);
                if (callback) callback(true);
            } else {
                console.error('[Suzi] Failed to create note:', result.error);
                if (callback) callback(false, 'Sorry, I couldn\'t create that note.');
            }
        })
        .catch(function(error) {
            console.error('[Suzi] Note error:', error);
            if (callback) callback(false, 'Sorry, there was a problem creating the note.');
        });
    }

    function createCalendarEvent(eventData, callback) {
        setStatus('Creating event...', '');
        saveVoiceEvent(eventData, 'event', callback);
    }

    function createReminder(reminderData, callback) {
        setStatus('Setting reminder...', '');
        saveVoiceEvent(reminderData, 'reminder', callback);
    }

    function saveVoiceEvent(details, kind, callback) {
        var planner = window.RelativesPlanner;
        var date = details.date || planner.today().date;
        var time = details.time || '09:00';
        var match = /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time);
        if (!match || !/^\d{4}-\d{2}-\d{2}$/.test(date) || !details.title) {
            if (callback) callback(false, 'Please give the event a title and a valid date and time.');
            return;
        }
        var hour = Number(time.slice(0, 2));
        var endHour = String((hour + 1) % 24).padStart(2, '0');
        var endDate = hour === 23 && !Number.isNaN(Date.parse(date + 'T12:00:00Z')) ? planner.shiftDate(date, 1) : date;
        planner.request(writeCsrf, 'add', {
            surface: 'calendar',
            title: String(details.title).slice(0, 200),
            date: date,
            end_date: endDate,
            start_time: time,
            end_time: endHour + time.slice(2),
            kind: kind,
            reminder_minutes: kind === 'reminder' ? 15 : 0
        }).then(function(result) {
            window.dispatchEvent(new CustomEvent('relatives:event-saved', { detail: result.event }));
            if (callback) callback(true);
        }).catch(function(error) {
            console.error('[Suzi] Event error:', error);
            if (callback) callback(false, error.message || 'Sorry, there was a problem saving the event.');
        });
    }

    function refreshPageData(type) {
        if (type === 'shopping' && location.pathname.indexOf('/shopping') === 0
            && typeof window.loadItems === 'function') {
            window.loadItems();
        }
    }

    // Modal functions
    function openModal() {
        console.log('[Suzi] Opening modal');
        getElements();

        if (!modal || modal.open) return;

        conversation = [];
        recognitionError = false;
        isModalOpen = true;
        window.closePlusMenu?.();
        setReady();
        setTranscript('');
        setReply('');
        setMicState(null);
        modal.showModal();
        trigger?.setAttribute('aria-expanded', 'true');
    }

    function closeModal() {
        if (!isModalOpen) return;

        isModalOpen = false;
        stopListening();
        stopSpeaking();
        isProcessing = false;
        modal.close();
        trigger?.setAttribute('aria-expanded', 'false');
        trigger?.focus({ preventScroll: true });
    }

    function toggleListening() {
        console.log('[Suzi] Toggle, speaking:', isSpeaking, 'listening:', isListening, 'processing:', isProcessing);

        if (isSpeaking) {
            stopSpeaking();
            setReady();
            setMicState(null);
        } else if (isListening) {
            stopListening();
            setReady();
            setMicState(null);
        } else if (!isStarting && !isProcessing) {
            startListening();
        }
    }

    function executeSuggestion(text) {
        if (!isModalOpen || isStarting || isProcessing || isSpeaking || isListening) return;
        handleUserInput(text);
    }

    function replay() {
        if (!isModalOpen || isStarting || isProcessing || isListening || replayBtn.hidden) return;
        if (isSpeaking) {
            toggleListening();
        } else {
            speak(replyEl.textContent, setReady);
        }
    }

    // Initialize
    getElements();
    initRecognition();
    if (modal) {
        modal.addEventListener('cancel', function(event) {
            event.preventDefault();
            closeModal();
        });
        modal.addEventListener('click', function(event) {
            if (event.target === modal) closeModal();
        });
    }

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
        replay: replay,
        getInstance: function() { return window.SuziVoice; }
    };

    // Backwards compatibility
    window.AdvancedVoiceAssistant = {
        getInstance: function() { return window.SuziVoice; },
        openModal: openModal,
        closeModal: closeModal
    };

})();
