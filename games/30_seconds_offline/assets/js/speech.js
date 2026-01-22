/**
 * 30 Seconds Party - Speech Recognition Module
 * Handles Web Speech API for explainer mic policing
 */

(function() {
    'use strict';

    const SPEECH_CONFIG = {
        LANGUAGE: 'en-US',
        CONTINUOUS: true,
        INTERIM_RESULTS: true,
        MAX_ALTERNATIVES: 1,
        RESTART_DELAY: 100 // ms delay before restarting after end
    };

    window.SpeechRecognition = {
        // Recognition instance
        recognition: null,

        // State
        isSupported: false,
        isListening: false,
        isEnabled: true,

        // Transcript
        finalTranscript: '',
        interimTranscript: '',

        // Callbacks
        callbacks: {
            onResult: null,
            onForbiddenWord: null,
            onNumberDetected: null,
            onError: null,
            onStateChange: null
        },

        // Current items for matching
        currentItems: [],
        focusedIndex: 0,

        /**
         * Initialize speech recognition
         */
        init: function(options = {}) {
            // Check for Web Speech API support
            const SpeechRecognitionAPI = window.SpeechRecognition ||
                                          window.webkitSpeechRecognition;

            if (!SpeechRecognitionAPI) {
                this.isSupported = false;
                console.warn('Speech Recognition API not supported');
                return false;
            }

            this.isSupported = true;
            this.recognition = new SpeechRecognitionAPI();

            // Configure recognition
            this.recognition.lang = options.language || SPEECH_CONFIG.LANGUAGE;
            this.recognition.continuous = SPEECH_CONFIG.CONTINUOUS;
            this.recognition.interimResults = SPEECH_CONFIG.INTERIM_RESULTS;
            this.recognition.maxAlternatives = SPEECH_CONFIG.MAX_ALTERNATIVES;

            // Set up callbacks
            this.callbacks = {
                onResult: options.onResult || null,
                onForbiddenWord: options.onForbiddenWord || null,
                onNumberDetected: options.onNumberDetected || null,
                onError: options.onError || null,
                onStateChange: options.onStateChange || null
            };

            // Bind event handlers
            this.recognition.onresult = this.handleResult.bind(this);
            this.recognition.onerror = this.handleError.bind(this);
            this.recognition.onstart = this.handleStart.bind(this);
            this.recognition.onend = this.handleEnd.bind(this);
            this.recognition.onsoundstart = () => this.updateState('hearing');
            this.recognition.onsoundend = () => this.updateState('listening');

            return true;
        },

        /**
         * Start listening
         */
        start: function(items = [], focusedIndex = 0) {
            if (!this.isSupported || !this.isEnabled) {
                return false;
            }

            if (this.isListening) {
                return true;
            }

            this.currentItems = items;
            this.focusedIndex = focusedIndex;
            this.finalTranscript = '';
            this.interimTranscript = '';

            try {
                this.recognition.start();
                return true;
            } catch (e) {
                console.error('Failed to start speech recognition:', e);
                return false;
            }
        },

        /**
         * Stop listening
         */
        stop: function() {
            if (!this.recognition) return;

            this._manualStop = true;
            try {
                this.recognition.stop();
            } catch (e) {
                // Already stopped
            }
            this.isListening = false;
            this.updateState('stopped');
        },

        /**
         * Update current items and focus
         */
        updateContext: function(items, focusedIndex) {
            this.currentItems = items;
            this.focusedIndex = focusedIndex;
        },

        /**
         * Handle recognition result
         */
        handleResult: function(event) {
            let interimTranscript = '';
            let newFinalTranscript = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;

                if (event.results[i].isFinal) {
                    newFinalTranscript += transcript;
                } else {
                    interimTranscript += transcript;
                }
            }

            // Update transcripts
            if (newFinalTranscript) {
                this.finalTranscript += newFinalTranscript;
            }
            this.interimTranscript = interimTranscript;

            // Get full transcript for analysis
            const fullTranscript = this.finalTranscript + ' ' + this.interimTranscript;

            // Fire result callback
            if (this.callbacks.onResult) {
                this.callbacks.onResult({
                    final: this.finalTranscript,
                    interim: this.interimTranscript,
                    full: fullTranscript.trim()
                });
            }

            // Analyze the new transcript segment
            const newSegment = newFinalTranscript || interimTranscript;
            if (newSegment) {
                this.analyzeSegment(newSegment);
            }
        },

        /**
         * Analyze transcript segment for forbidden words and numbers
         */
        analyzeSegment: function(segment) {
            // Check for number commands
            const detectedNumber = WordMatcher.detectNumber(segment);
            if (detectedNumber && this.callbacks.onNumberDetected) {
                this.callbacks.onNumberDetected(detectedNumber);
            }

            // Check for forbidden words on the focused item
            if (this.currentItems.length > 0 && this.focusedIndex < this.currentItems.length) {
                const focusedItem = this.currentItems[this.focusedIndex];

                if (focusedItem && focusedItem.status === 'normal') {
                    const forbidden = WordMatcher.getForbiddenTokens(focusedItem.text);
                    const matches = WordMatcher.checkForbiddenWords(segment, forbidden);

                    if (matches.length > 0 && this.callbacks.onForbiddenWord) {
                        this.callbacks.onForbiddenWord({
                            index: this.focusedIndex,
                            item: focusedItem.text,
                            matchedWords: matches
                        });
                    }
                }
            }
        },

        /**
         * Handle recognition start
         */
        handleStart: function() {
            this.isListening = true;
            this._manualStop = false;
            this.updateState('listening');
        },

        /**
         * Handle recognition end
         */
        handleEnd: function() {
            this.isListening = false;

            // Auto-restart if not manually stopped and still enabled
            if (!this._manualStop && this.isEnabled) {
                setTimeout(() => {
                    if (this.isEnabled && !this.isListening) {
                        try {
                            this.recognition.start();
                        } catch (e) {
                            // May fail if already starting
                        }
                    }
                }, SPEECH_CONFIG.RESTART_DELAY);
            } else {
                this.updateState('stopped');
            }
        },

        /**
         * Handle recognition error
         */
        handleError: function(event) {
            console.warn('Speech recognition error:', event.error);

            let errorMessage = 'Unknown error';
            let shouldRetry = false;

            switch (event.error) {
                case 'no-speech':
                    errorMessage = 'No speech detected';
                    shouldRetry = true;
                    break;
                case 'audio-capture':
                    errorMessage = 'Microphone not available';
                    break;
                case 'not-allowed':
                    errorMessage = 'Microphone permission denied';
                    break;
                case 'network':
                    errorMessage = 'Network error (may need internet for recognition)';
                    break;
                case 'aborted':
                    errorMessage = 'Recognition aborted';
                    shouldRetry = true;
                    break;
                case 'language-not-supported':
                    errorMessage = 'Language not supported';
                    break;
                case 'service-not-allowed':
                    errorMessage = 'Speech service not allowed';
                    break;
            }

            this.updateState('error', errorMessage);

            if (this.callbacks.onError) {
                this.callbacks.onError({
                    error: event.error,
                    message: errorMessage
                });
            }

            // Auto-retry for transient errors
            if (shouldRetry && this.isEnabled && !this._manualStop) {
                setTimeout(() => {
                    if (this.isEnabled && !this.isListening) {
                        try {
                            this.recognition.start();
                        } catch (e) {
                            // May fail if already starting
                        }
                    }
                }, SPEECH_CONFIG.RESTART_DELAY * 5);
            }
        },

        /**
         * Update state and notify
         */
        updateState: function(state, message = '') {
            if (this.callbacks.onStateChange) {
                this.callbacks.onStateChange({
                    state: state,
                    message: message,
                    isListening: this.isListening
                });
            }
        },

        /**
         * Enable/disable recognition
         */
        setEnabled: function(enabled) {
            this.isEnabled = enabled;
            if (!enabled && this.isListening) {
                this.stop();
            }
        },

        /**
         * Clear transcript
         */
        clearTranscript: function() {
            this.finalTranscript = '';
            this.interimTranscript = '';
        },

        /**
         * Get current transcript
         */
        getTranscript: function() {
            return {
                final: this.finalTranscript,
                interim: this.interimTranscript,
                full: (this.finalTranscript + ' ' + this.interimTranscript).trim()
            };
        },

        /**
         * Check if speech recognition is supported
         */
        checkSupport: function() {
            return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        },

        /**
         * Request microphone permission
         */
        requestPermission: async function() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                // Stop the stream immediately, we just needed permission
                stream.getTracks().forEach(track => track.stop());
                return true;
            } catch (e) {
                console.error('Microphone permission denied:', e);
                return false;
            }
        }
    };
})();
