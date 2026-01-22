/**
 * ============================================
 * FLASH CHALLENGE - Voice Module
 * Speech-to-text input with Web Speech API
 * ============================================
 */

(function() {
    'use strict';

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const SpeechGrammarList = window.SpeechGrammarList || window.webkitSpeechGrammarList;

    let recognition = null;
    let isListening = false;
    let finalTranscript = '';
    let interimTranscript = '';
    let silenceTimer = null;
    let autoStopTimer = null;

    const SILENCE_TIMEOUT = 2000; // Stop after 2 seconds of silence
    const MAX_LISTEN_TIME = 25000; // Max 25 seconds of listening

    /**
     * FlashVoice - Handles voice input
     */
    window.FlashVoice = {
        /**
         * Callbacks
         */
        onStart: null,
        onResult: null,
        onInterim: null,
        onEnd: null,
        onError: null,
        onNoSupport: null,

        /**
         * Check if speech recognition is supported
         */
        isSupported: function() {
            return !!SpeechRecognition;
        },

        /**
         * Check if currently listening
         */
        isListening: function() {
            return isListening;
        },

        /**
         * Get the current transcript
         */
        getTranscript: function() {
            return finalTranscript;
        },

        /**
         * Initialize recognition instance
         */
        init: function() {
            if (!this.isSupported()) {
                console.warn('Speech recognition not supported');
                if (this.onNoSupport) this.onNoSupport();
                return false;
            }

            recognition = new SpeechRecognition();

            // Configuration
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.maxAlternatives = 1;
            recognition.lang = 'en-US';

            // Event handlers
            recognition.onstart = () => {
                isListening = true;
                finalTranscript = '';
                interimTranscript = '';

                if (this.onStart) this.onStart();

                // Auto-stop after max time
                autoStopTimer = setTimeout(() => {
                    this.stop();
                }, MAX_LISTEN_TIME);
            };

            recognition.onresult = (event) => {
                interimTranscript = '';

                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const result = event.results[i];

                    if (result.isFinal) {
                        finalTranscript += result[0].transcript + ' ';

                        // Reset silence timer
                        this._resetSilenceTimer();

                        if (this.onResult) {
                            this.onResult(finalTranscript.trim());
                        }
                    } else {
                        interimTranscript += result[0].transcript;

                        // Reset silence timer on any speech
                        this._resetSilenceTimer();

                        if (this.onInterim) {
                            this.onInterim(interimTranscript);
                        }
                    }
                }
            };

            recognition.onerror = (event) => {
                console.warn('Speech recognition error:', event.error);

                let errorMessage = 'Voice input error';

                switch (event.error) {
                    case 'not-allowed':
                    case 'permission-denied':
                        errorMessage = 'Microphone access denied. Please allow microphone permission.';
                        break;
                    case 'no-speech':
                        errorMessage = 'No speech detected. Please try again.';
                        break;
                    case 'audio-capture':
                        errorMessage = 'No microphone found. Please check your device.';
                        break;
                    case 'network':
                        errorMessage = 'Network error. Speech recognition requires internet.';
                        break;
                    case 'aborted':
                        // User stopped, not an error
                        return;
                }

                if (this.onError) this.onError(errorMessage, event.error);
            };

            recognition.onend = () => {
                isListening = false;
                this._clearTimers();

                if (this.onEnd) {
                    this.onEnd(finalTranscript.trim());
                }
            };

            recognition.onnomatch = () => {
                if (this.onError) {
                    this.onError('Could not understand. Please try again.', 'no-match');
                }
            };

            return true;
        },

        /**
         * Start listening
         */
        start: function() {
            if (!this.isSupported()) {
                if (this.onNoSupport) this.onNoSupport();
                return false;
            }

            if (isListening) {
                return true;
            }

            if (!recognition) {
                this.init();
            }

            try {
                recognition.start();
                return true;
            } catch (e) {
                console.error('Failed to start recognition:', e);

                // Might be already started, try to abort and restart
                try {
                    recognition.abort();
                    setTimeout(() => {
                        recognition.start();
                    }, 100);
                } catch (e2) {
                    if (this.onError) {
                        this.onError('Failed to start voice input', 'start-failed');
                    }
                }
                return false;
            }
        },

        /**
         * Stop listening
         */
        stop: function() {
            this._clearTimers();

            if (recognition && isListening) {
                try {
                    recognition.stop();
                } catch (e) {
                    // Ignore stop errors
                }
            }

            isListening = false;
        },

        /**
         * Abort listening (discard results)
         */
        abort: function() {
            this._clearTimers();
            finalTranscript = '';
            interimTranscript = '';

            if (recognition && isListening) {
                try {
                    recognition.abort();
                } catch (e) {
                    // Ignore abort errors
                }
            }

            isListening = false;
        },

        /**
         * Reset for new input
         */
        reset: function() {
            this.abort();
            finalTranscript = '';
            interimTranscript = '';
        },

        /**
         * Request microphone permission
         */
        requestPermission: async function() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                // Stop the stream immediately, we just wanted permission
                stream.getTracks().forEach(track => track.stop());
                return { granted: true };
            } catch (e) {
                console.warn('Microphone permission denied:', e);
                return {
                    granted: false,
                    error: e.name === 'NotAllowedError'
                        ? 'Microphone access denied'
                        : 'Failed to access microphone'
                };
            }
        },

        /**
         * Check microphone permission status
         */
        checkPermission: async function() {
            if (!navigator.permissions) {
                // Permissions API not supported, assume unknown
                return 'unknown';
            }

            try {
                const result = await navigator.permissions.query({ name: 'microphone' });
                return result.state; // 'granted', 'denied', or 'prompt'
            } catch (e) {
                return 'unknown';
            }
        },

        /**
         * Internal: Reset silence timer
         */
        _resetSilenceTimer: function() {
            if (silenceTimer) {
                clearTimeout(silenceTimer);
            }

            silenceTimer = setTimeout(() => {
                // Auto-stop after silence
                if (isListening && finalTranscript.trim()) {
                    this.stop();
                }
            }, SILENCE_TIMEOUT);
        },

        /**
         * Internal: Clear all timers
         */
        _clearTimers: function() {
            if (silenceTimer) {
                clearTimeout(silenceTimer);
                silenceTimer = null;
            }
            if (autoStopTimer) {
                clearTimeout(autoStopTimer);
                autoStopTimer = null;
            }
        },

        /**
         * Trigger haptic feedback (if supported)
         */
        hapticFeedback: function(type = 'light') {
            if ('vibrate' in navigator) {
                switch (type) {
                    case 'light':
                        navigator.vibrate(10);
                        break;
                    case 'medium':
                        navigator.vibrate(25);
                        break;
                    case 'heavy':
                        navigator.vibrate([50, 30, 50]);
                        break;
                    case 'success':
                        navigator.vibrate([30, 50, 30]);
                        break;
                    case 'error':
                        navigator.vibrate([100, 50, 100]);
                        break;
                }
            }
        }
    };

    // Auto-init on load
    if (FlashVoice.isSupported()) {
        FlashVoice.init();
    }

})();
