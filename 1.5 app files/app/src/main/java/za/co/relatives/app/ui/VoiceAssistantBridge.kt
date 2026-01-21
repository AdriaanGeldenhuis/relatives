package za.co.relatives.app.ui

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import android.util.Log
import android.webkit.JavascriptInterface
import android.webkit.WebView
import androidx.core.content.ContextCompat
import java.util.*

class VoiceAssistantBridge(
    private val activity: Activity,
    private val webView: WebView
) : RecognitionListener {

    private val TAG = "VoiceAssistantBridge"

    private var speechRecognizer: SpeechRecognizer? = null
    private var textToSpeech: TextToSpeech? = null
    private var ttsInitialized = false

    private val mainHandler = Handler(Looper.getMainLooper())

    init {
        Log.d(TAG, "VoiceAssistantBridge initialized")
        initializeTTS()
    }

    private fun initializeTTS() {
        textToSpeech = TextToSpeech(activity.applicationContext) { status ->
            if (status == TextToSpeech.SUCCESS) {
                textToSpeech?.language = Locale.US
                ttsInitialized = true
                Log.d(TAG, "TTS initialized successfully")
            } else {
                Log.e(TAG, "TTS initialization failed")
            }
        }

        textToSpeech?.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) {
                Log.d(TAG, "TTS started speaking")
                callJavaScript("AdvancedVoiceAssistant.onNativeSpeakStart()")
            }

            override fun onDone(utteranceId: String?) {
                Log.d(TAG, "TTS finished speaking")
                callJavaScript("AdvancedVoiceAssistant.onNativeSpeakDone()")
            }

            override fun onError(utteranceId: String?) {
                Log.e(TAG, "TTS error")
                callJavaScript("AdvancedVoiceAssistant.onNativeError('tts-error', 'TTS failed to speak')")
            }
        })
    }

    @JavascriptInterface
    fun startListening() {
        Log.d(TAG, "startListening() called from JavaScript")

        mainHandler.post {
            if (!checkMicrophonePermission()) {
                Log.e(TAG, "Microphone permission not granted")
                callJavaScript("AdvancedVoiceAssistant.onNativeError('not-allowed', 'Microphone permission denied')")
                return@post
            }

            try {
                // Destroy any previous instance FIRST to avoid "recognizer busy" errors.
                if (speechRecognizer != null) {
                    speechRecognizer?.destroy()
                    speechRecognizer = null
                    Log.d(TAG, "Previous recognizer destroyed")
                }

                // Create a new recognizer instance
                speechRecognizer = SpeechRecognizer.createSpeechRecognizer(activity.applicationContext)

                if (speechRecognizer == null) {
                    Log.e(TAG, "SpeechRecognizer not available on this device")
                    callJavaScript("AdvancedVoiceAssistant.onNativeError('not-supported', 'Speech recognition not available')")
                    return@post
                }

                speechRecognizer?.setRecognitionListener(this)

                val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
                    putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                    putExtra(RecognizerIntent.EXTRA_LANGUAGE, "en-ZA")
                    putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false)
                    putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1)
                    putExtra(RecognizerIntent.EXTRA_CALLING_PACKAGE, activity.packageName)
                }

                Log.d(TAG, "Starting speech recognition...")
                speechRecognizer?.startListening(intent)

                // Notify JS that listening has started
                callJavaScript("AdvancedVoiceAssistant.onNativeListeningStart()")

            } catch (e: Exception) {
                Log.e(TAG, "Failed to start listening", e)
                callJavaScript("AdvancedVoiceAssistant.onNativeError('error', '${e.message}')")
            }
        }
    }

    @JavascriptInterface
    fun stopListening() {
        Log.d(TAG, "stopListening() called")

        mainHandler.post {
            try {
                if (speechRecognizer != null) {
                    speechRecognizer?.stopListening()
                    speechRecognizer?.destroy()
                    speechRecognizer = null
                    Log.d(TAG, "Speech recognizer stopped and destroyed")
                }
                callJavaScript("AdvancedVoiceAssistant.onNativeListeningStop()")
            } catch (e: Exception) {
                Log.e(TAG, "Error stopping recognizer", e)
            }
        }
    }

    @JavascriptInterface
    fun speak(text: String) {
        Log.d(TAG, "speak() called with: $text")

        mainHandler.post {
            if (!ttsInitialized) {
                Log.e(TAG, "TTS not initialized")
                return@post
            }

            try {
                val params = Bundle()
                params.putString(TextToSpeech.Engine.KEY_PARAM_UTTERANCE_ID, "suzi_speech")

                textToSpeech?.speak(text, TextToSpeech.QUEUE_FLUSH, params, "suzi_speech")
                Log.d(TAG, "TTS speak command sent")
            } catch (e: Exception) {
                Log.e(TAG, "TTS speak failed", e)
            }
        }
    }

    @JavascriptInterface
    fun stopSpeaking() {
        Log.d(TAG, "stopSpeaking() called")

        mainHandler.post {
            try {
                textToSpeech?.stop()
                Log.d(TAG, "TTS stopped")
            } catch (e: Exception) {
                Log.e(TAG, "Error stopping TTS", e)
            }
        }
    }

    private fun checkMicrophonePermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            activity,
            Manifest.permission.RECORD_AUDIO
        ) == PackageManager.PERMISSION_GRANTED
    }

    private fun callJavaScript(script: String) {
        mainHandler.post {
            webView.evaluateJavascript(script) { result ->
                Log.d(TAG, "JS callback executed: $script -> $result")
            }
        }
    }

    // ========== RecognitionListener Implementation ==========

    override fun onReadyForSpeech(params: Bundle?) {
        Log.d(TAG, "Ready for speech")
    }

    override fun onBeginningOfSpeech() {
        Log.d(TAG, "Speech detected - user started speaking")
    }

    override fun onRmsChanged(rmsdB: Float) {
        // Audio level changed - can use for visual feedback
    }

    override fun onBufferReceived(buffer: ByteArray?) {
        // Raw audio buffer - not needed
    }

    override fun onEndOfSpeech() {
        Log.d(TAG, "Speech ended - processing...")
    }

    override fun onError(error: Int) {
        val errorMessage = when (error) {
            SpeechRecognizer.ERROR_AUDIO -> "audio"
            SpeechRecognizer.ERROR_CLIENT -> "client"
            SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> "not-allowed"
            SpeechRecognizer.ERROR_NETWORK -> "network"
            SpeechRecognizer.ERROR_NETWORK_TIMEOUT -> "network-timeout"
            SpeechRecognizer.ERROR_NO_MATCH -> "no-match"
            SpeechRecognizer.ERROR_RECOGNIZER_BUSY -> "busy"
            SpeechRecognizer.ERROR_SERVER -> "server"
            SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> "no-speech"
            else -> "unknown"
        }

        Log.e(TAG, "Recognition error: $errorMessage (code: $error)")

        callJavaScript("AdvancedVoiceAssistant.onNativeError('$errorMessage', 'Recognition error')")

        // Clean up
        speechRecognizer?.destroy()
        speechRecognizer = null
    }

    override fun onResults(results: Bundle?) {
        val matches = results?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)

        if (matches != null && matches.isNotEmpty()) {
            val transcript = matches[0]
            Log.d(TAG, "Recognition result: $transcript")

            // Send transcript to JavaScript
            val safeTranscript = transcript.replace("'", "\\'").replace("\"", "\\\"")
            callJavaScript("AdvancedVoiceAssistant.onNativeTranscript('$safeTranscript')")
        } else {
            Log.w(TAG, "No recognition results")
            callJavaScript("AdvancedVoiceAssistant.onNativeError('no-match', 'No speech recognized')")
        }

        // Clean up recognizer after results
        speechRecognizer?.destroy()
        speechRecognizer = null
    }

    override fun onPartialResults(partialResults: Bundle?) {
        // Partial results - not used
    }

    override fun onEvent(eventType: Int, params: Bundle?) {
        // Additional events - not used
    }

    fun cleanup() {
        Log.d(TAG, "Cleaning up VoiceAssistantBridge")

        mainHandler.post {
            try {
                speechRecognizer?.destroy()
                speechRecognizer = null

                textToSpeech?.stop()
                textToSpeech?.shutdown()
                textToSpeech = null

                Log.d(TAG, "Cleanup complete")
            } catch (e: Exception) {
                Log.e(TAG, "Cleanup error", e)
            }
        }
    }
}
