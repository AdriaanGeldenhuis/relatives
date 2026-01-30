/**
 * SUZI VOICE v11 - Fresh Rebuild
 */

(function() {
    'use strict';

    // State
    var state = {
        open: false,
        listening: false,
        speaking: false,
        processing: false
    };

    var conversation = [];
    var recognition = null;
    var synth = window.speechSynthesis;
    var timers = {};

    // DOM refs
    var dom = {};

    function getDOM() {
        dom.modal = document.getElementById('voiceModal');
        dom.icon = document.getElementById('statusIcon');
        dom.text = document.getElementById('statusText');
        dom.subtext = document.getElementById('statusSubtext');
        dom.transcript = document.getElementById('voiceTranscript');
        dom.mic = document.getElementById('modalMicBtn');
        dom.suggestions = document.getElementById('voiceSuggestions');
    }

    // UI Update
    function ui(mode, msg) {
        getDOM();
        if (!dom.modal) return;

        var modes = {
            ready:      { icon: '🎤', text: 'Tap to speak', sub: 'Ask me anything' },
            listening:  { icon: '🎤', text: 'Listening...', sub: 'Speak now' },
            thinking:   { icon: '🧠', text: 'Thinking...', sub: '' },
            speaking:   { icon: '🔊', text: 'Speaking...', sub: '' },
            nav:        { icon: '🧭', text: 'Opening...', sub: '' },
            error:      { icon: '🚫', text: msg || 'Error', sub: '' }
        };

        var m = modes[mode] || modes.ready;
        if (dom.icon) dom.icon.textContent = m.icon;
        if (dom.text) dom.text.textContent = m.text;
        if (dom.subtext) dom.subtext.textContent = m.sub;

        if (dom.mic) {
            dom.mic.className = 'modal-mic-btn';
            if (mode === 'listening') dom.mic.classList.add('listening');
            if (mode === 'speaking') dom.mic.classList.add('speaking');
            if (mode === 'thinking') dom.mic.classList.add('thinking');
        }

        if (dom.suggestions) {
            dom.suggestions.style.display = (mode === 'ready' && conversation.length === 0) ? 'block' : 'none';
        }
    }

    function showText(t) {
        getDOM();
        if (dom.transcript) dom.transcript.textContent = t;
    }

    // Clear all timers
    function clearTimers() {
        for (var k in timers) {
            clearTimeout(timers[k]);
            delete timers[k];
        }
    }

    // SPEECH RECOGNITION
    function initRecognition() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return console.log('[Suzi] No speech recognition');

        recognition = new SR();
        recognition.lang = 'en-US';
        recognition.continuous = false;
        recognition.interimResults = true;

        recognition.onstart = function() {
            console.log('[Suzi] Listening started');
            state.listening = true;
            ui('listening');

            timers.silence = setTimeout(function() {
                if (state.listening) stopListen();
            }, 4000);
        };

        recognition.onresult = function(e) {
            clearTimeout(timers.silence);

            var r = e.results[e.results.length - 1];
            var txt = r[0].transcript;
            showText(txt);

            if (r.isFinal && txt.trim()) {
                console.log('[Suzi] Heard:', txt);
                process(txt.trim());
            } else {
                timers.silence = setTimeout(function() {
                    if (state.listening) stopListen();
                }, 4000);
            }
        };

        recognition.onerror = function(e) {
            console.log('[Suzi] Rec error:', e.error);
            state.listening = false;
            clearTimers();
            if (e.error === 'not-allowed') {
                ui('error', 'Mic blocked');
            } else if (state.open) {
                ui('ready');
            }
        };

        recognition.onend = function() {
            console.log('[Suzi] Rec ended');
            state.listening = false;
            if (state.open && !state.processing && !state.speaking) {
                ui('ready');
            }
        };
    }

    function startListen() {
        if (state.listening || state.processing || state.speaking) return;
        stopSpeak();

        if (recognition) {
            try { recognition.start(); }
            catch(e) { console.log('[Suzi] Start err:', e); }
        }
    }

    function stopListen() {
        clearTimeout(timers.silence);
        state.listening = false;
        if (recognition) {
            try { recognition.abort(); } catch(e) {}
        }
    }

    // TEXT TO SPEECH
    function speak(text, done) {
        if (!text || !synth) {
            if (done) done();
            return;
        }

        console.log('[Suzi] Speak:', text.substring(0, 40));
        state.speaking = true;
        ui('speaking');
        showText(text);

        synth.cancel();

        var utter = new SpeechSynthesisUtterance(text);
        var voices = synth.getVoices();

        // Find English voice
        for (var i = 0; i < voices.length; i++) {
            if (voices[i].lang.indexOf('en') === 0) {
                utter.voice = voices[i];
                break;
            }
        }

        utter.rate = 1;
        utter.pitch = 1;

        // Fallback timer
        var ms = Math.max(2000, text.length * 70);
        timers.speak = setTimeout(function() {
            console.log('[Suzi] Speak timeout');
            state.speaking = false;
            if (done) done();
        }, ms);

        utter.onend = function() {
            console.log('[Suzi] Speak done');
            clearTimeout(timers.speak);
            state.speaking = false;
            if (done) done();
        };

        utter.onerror = function() {
            console.log('[Suzi] Speak error');
            clearTimeout(timers.speak);
            state.speaking = false;
            if (done) done();
        };

        // Chrome needs delay
        setTimeout(function() { synth.speak(utter); }, 50);
    }

    function stopSpeak() {
        clearTimeout(timers.speak);
        if (synth) synth.cancel();
        state.speaking = false;
    }

    // PROCESS INPUT
    function process(text) {
        stopListen();

        var low = text.toLowerCase();
        if (low === 'bye' || low === 'goodbye' || low === 'close' || low === 'stop' || low === 'cancel') {
            speak('Bye!', function() { close(); });
            return;
        }

        state.processing = true;
        ui('thinking');
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
            state.processing = false;
            if (data.success && data.response) {
                conversation.push({ role: 'assistant', content: data.response });
                speak(data.response, function() {
                    if (data.action) {
                        doAction(data.action);
                    } else {
                        ui('ready');
                    }
                });
            } else {
                speak('Sorry, try again.', function() { ui('ready'); });
            }
        })
        .catch(function(e) {
            console.error('[Suzi] API err:', e);
            state.processing = false;
            speak('Sorry, try again.', function() { ui('ready'); });
        });
    }

    // ACTIONS
    function doAction(action) {
        if (action.type === 'navigate') {
            var paths = {
                home: '/home/', shopping: '/shopping/', notes: '/notes/',
                calendar: '/calendar/', schedule: '/schedule/', messages: '/messages/',
                tracking: '/tracking/', games: '/games/', weather: '/weather/'
            };
            ui('nav');
            setTimeout(function() {
                close();
                window.location.href = paths[action.data.to] || '/home/';
            }, 500);
        } else {
            ui('ready');
        }
    }

    // MODAL
    function open() {
        console.log('[Suzi] Open');
        getDOM();
        if (!dom.modal) return console.error('[Suzi] No modal!');

        state.open = true;
        state.listening = false;
        state.speaking = false;
        state.processing = false;
        conversation = [];
        clearTimers();
        stopSpeak();

        dom.modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        ui('ready');
        showText("Hi! I'm Suzi. Tap the mic and ask me anything!");
    }

    function close() {
        console.log('[Suzi] Close');
        stopListen();
        stopSpeak();
        clearTimers();

        state.open = false;
        state.listening = false;
        state.speaking = false;
        state.processing = false;

        getDOM();
        if (dom.modal) dom.modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggle() {
        if (state.speaking) {
            stopSpeak();
            ui('ready');
        } else if (state.listening) {
            stopListen();
            ui('ready');
        } else if (!state.processing) {
            startListen();
        }
    }

    function suggest(text) {
        if (!state.processing && !state.speaking) {
            process(text);
        }
    }

    // INIT
    console.log('[Suzi] v11 init...');
    initRecognition();

    // Preload voices
    if (synth) {
        synth.getVoices();
        if (synth.onvoiceschanged !== undefined) {
            synth.onvoiceschanged = function() { synth.getVoices(); };
        }
    }

    console.log('[Suzi] v11 ready!');

    // PUBLIC API
    window.SuziVoice = {
        open: open,
        close: close,
        toggle: function() { state.open ? close() : open(); },
        toggleListening: toggle,
        executeSuggestion: suggest
    };

    // Backwards compat
    window.AdvancedVoiceAssistant = {
        openModal: open,
        closeModal: close,
        getInstance: function() { return window.SuziVoice; }
    };

})();
