package za.co.relatives.app.tracking

import android.webkit.WebView
import androidx.webkit.JavaScriptReplyProxy
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import org.json.JSONObject
import za.co.relatives.app.MainActivity
import za.co.relatives.app.utils.NotificationHelper

/** Accept commands only from the trusted top-level document displaying the relevant feature. */
internal class TrackingBridge(private val activity: MainActivity) {
    companion object {
        private val ALLOWED_ORIGINS = setOf("https://www.relatives.co.za", "https://relatives.co.za")
    }

    fun install(webView: WebView) {
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) return
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) return

        WebViewCompat.addWebMessageListener(webView, "RelativesNative", ALLOWED_ORIGINS) {
                view, message, origin, isMainFrame, reply ->
            if (!isMainFrame || !activity.isCurrentTrustedPage(view, origin)) return@addWebMessageListener
            val command = message.data ?: return@addWebMessageListener
            val path = view.url?.let { android.net.Uri.parse(it).path }
            if (path == "/profile/notifications.php" && command == "notifications") {
                activity.requestNotificationPermission()
            } else if (path == "/profile/notifications.php" && command == "notification_settings") {
                activity.openNotificationSettings()
            } else if (command == "stop" && (path == "/profile/privacy.php" || path == "/legal/delete-account.php")) {
                activity.stopTrackingService()
            } else if (command == "weather_location") {
                sendReply(reply, JSONObject().put("type", command).put("allowed", activity.prepareWeatherLocation()))
            } else if (path == "/tracking/app/settings.php" && command == "tracking_health") {
                sendReply(reply, JSONObject().put("type", command)
                    .put("location", activity.getTrackingMode())
                    .put("background", FamilyBoundaryMonitor.eligible(activity))
                    .put("geofenceAlerts", NotificationHelper.canShowLocationAlerts(activity)))
            } else if (activity.isTrackingMap(view.url)) {
                handleTrackingCommand(command, reply)
            }
        }
        val script = activity.assets.open("tracking-bridge.js").bufferedReader().use { it.readText() }
        WebViewCompat.addDocumentStartJavaScript(webView, script, ALLOWED_ORIGINS)
    }

    private fun handleTrackingCommand(command: String, reply: JavaScriptReplyProxy) {
        when (command) {
            "state" -> sendState(reply)
            "start" -> activity.startTrackingWithPermissions { sendState(reply) }
            "stop" -> {
                activity.stopTrackingService()
                sendState(reply)
            }
            "refresh_device" -> activity.wakeAllDevices()
            "web_family_polling" -> activity.claimFamilyPolling()
            "refresh_tracking_policy" -> activity.refreshVisibleTrackingPolicy()
            "map_visible" -> {
                activity.onTrackingScreenVisible()
                sendState(reply)
            }
            "map_hidden" -> activity.onTrackingScreenHidden()
            "navigation" -> sendReply(
                reply, JSONObject().put("type", "navigation").put("allowed", activity.prepareWebNavigation()),
            )
        }
    }

    private fun sendState(reply: JavaScriptReplyProxy) {
        val store = activity.trackingStore
        val state = JSONObject().put("type", "state")
            .put("mode", activity.getTrackingMode())
            .put("remoteWake", activity.supportsRemoteWake())
            .put("cacheAge", store.familyCacheAgeMillis())
            .put("family", store.familyLocationsJson())
        sendReply(reply, state)
    }

    private fun sendReply(reply: JavaScriptReplyProxy, payload: JSONObject) {
        // The permission prompt can outlive the WebView document that opened it.
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            runCatching { reply.postMessage(payload.toString()) }
        }
    }
}
