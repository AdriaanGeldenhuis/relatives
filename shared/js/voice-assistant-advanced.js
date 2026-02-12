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
                        setStatus('🎤', 'Tap to speak', 'Ask me anything');
                        showSuggestions(conversation.length <= 2);
                    });
                }
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

        if (actionFn) {
            actionFn(function(success, errorMsg) {
                if (success) {
                    speak(aiResponse, function() {
                        setStatus('🎤', 'Tap to speak', 'Ask me anything');
                        showSuggestions(false);
                    });
                } else {
                    var errText = errorMsg || 'Sorry, I couldn\'t complete that action. Please try again.';
                    speak(errText, function() {
                        setStatus('🎤', 'Tap to speak', 'Ask me anything');
                        showSuggestions(false);
                    });
                }
            });
        } else {
            speak(aiResponse, function() {
                setStatus('🎤', 'Tap to speak', 'Ask me anything');
                showSuggestions(false);
            });
        }
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
            setStatus('🧭', 'Opening...', '');
            setTimeout(function() {
                closeModal();
                window.location.href = paths[action.data.to] || '/home/';
            }, 500);
        } else {
            setStatus('🎤', 'Tap to speak', 'Ask me anything');
            showSuggestions(conversation.length <= 2);
        }
    }

    // Add item to shopping list via API
    function addShoppingItem(itemName, category, callback) {
        setStatus('🛒', 'Adding to list...', '');

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
        setStatus('📝', 'Creating note...', '');

        var formData = new FormData();
        formData.append('action', 'create');
        formData.append('title', title || content.substring(0, 50));
        formData.append('content', content);

        fetch('/notes/api/notes.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                console.log('[Suzi] Note created');
                refreshPageData('note');
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

    // Create a calendar event via API
    function createCalendarEvent(eventData, callback) {
        setStatus('📅', 'Creating event...', '');

        var startTime = eventData.time || '09:00';
        var endTime = calculateEndTime(startTime);
        var eventDate = eventData.date || new Date().toISOString().split('T')[0];

        var formData = new FormData();
        formData.append('action', 'create');
        formData.append('title', eventData.title);
        formData.append('date', eventDate);
        formData.append('start_time', startTime);
        formData.append('end_time', endTime);
        formData.append('kind', 'event');

        fetch('/api/events.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                console.log('[Suzi] Event created, id:', result.event_id);
                refreshPageData('event', {
                    id: result.event_id,
                    title: eventData.title,
                    date: eventDate,
                    starts_at: eventDate + ' ' + startTime + ':00',
                    ends_at: eventDate + ' ' + endTime + ':00',
                    kind: 'event',
                    color: '#3498db'
                });
                if (callback) callback(true);
            } else {
                console.error('[Suzi] Failed to create event:', result.error);
                if (callback) callback(false, 'Sorry, I couldn\'t add that to your calendar. ' + (result.message || ''));
            }
        })
        .catch(function(error) {
            console.error('[Suzi] Event error:', error);
            if (callback) callback(false, 'Sorry, there was a problem creating the event.');
        });
    }

    // Create a reminder via API
    function createReminder(reminderData, callback) {
        setStatus('⏰', 'Setting reminder...', '');

        var startTime = reminderData.time || '09:00';
        var endTime = calculateEndTime(startTime);
        var reminderDate = reminderData.date || new Date().toISOString().split('T')[0];

        var formData = new FormData();
        formData.append('action', 'create');
        formData.append('title', reminderData.title);
        formData.append('date', reminderDate);
        formData.append('start_time', startTime);
        formData.append('end_time', endTime);
        formData.append('kind', 'todo');

        fetch('/api/events.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                console.log('[Suzi] Reminder created, id:', result.event_id);
                refreshPageData('reminder', {
                    id: result.event_id,
                    title: reminderData.title,
                    date: reminderDate,
                    starts_at: reminderDate + ' ' + startTime + ':00',
                    ends_at: reminderDate + ' ' + endTime + ':00',
                    kind: 'todo',
                    color: '#9b59b6'
                });
                if (callback) callback(true);
            } else {
                console.error('[Suzi] Failed to create reminder:', result.error);
                if (callback) callback(false, 'Sorry, I couldn\'t set that reminder. ' + (result.message || ''));
            }
        })
        .catch(function(error) {
            console.error('[Suzi] Reminder error:', error);
            if (callback) callback(false, 'Sorry, there was a problem setting the reminder.');
        });
    }

    // Refresh the current page data after voice action
    function refreshPageData(type, eventObj) {
        var path = window.location.pathname;

        try {
            // Calendar page
            if (path.indexOf('/calendar') === 0) {
                if (typeof window.addEventToCalendar === 'function') {
                    window.addEventToCalendar(eventObj);
                    console.log('[Suzi] Calendar updated live');
                } else if (typeof window.loadMonthData === 'function' && window.currentYear && window.currentMonth) {
                    window.loadMonthData(window.currentYear, window.currentMonth, 'fadeIn');
                    console.log('[Suzi] Calendar month reloaded');
                }
            }

            // Schedule page
            if (path.indexOf('/schedule') === 0) {
                if (typeof window.loadScheduleData === 'function' && window.ScheduleApp && window.ScheduleApp.selectedDate) {
                    window.loadScheduleData(window.ScheduleApp.selectedDate, 'fadeIn');
                    console.log('[Suzi] Schedule reloaded');
                }
            }

            // Shopping page
            if (path.indexOf('/shopping') === 0 && type === 'shopping') {
                if (typeof window.loadItems === 'function') {
                    window.loadItems();
                    console.log('[Suzi] Shopping list reloaded');
                }
            }

            // Notes page
            if (path.indexOf('/notes') === 0 && type === 'note') {
                if (typeof window.loadNotes === 'function') {
                    window.loadNotes();
                    console.log('[Suzi] Notes reloaded');
                }
            }
        } catch (e) {
            console.log('[Suzi] Page refresh skipped:', e.message);
        }
    }

    // Calculate end time as 1 hour after start time
    function calculateEndTime(startTime) {
        var parts = startTime.split(':');
        var hour = (parseInt(parts[0], 10) + 1) % 24;
        var minute = parts[1] || '00';
        return (hour < 10 ? '0' : '') + hour + ':' + minute;
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
