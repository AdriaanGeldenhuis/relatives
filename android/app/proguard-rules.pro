# Add project specific ProGuard rules here.

# Keep WebView JavaScript interface methods
-keepclassmembers class com.relatives.app.webview.WebViewBridge {
    @android.webkit.JavascriptInterface <methods>;
}

# Keep OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**
-keepnames class okhttp3.internal.publicsuffix.PublicSuffixDatabase

# Keep Google Play Services Location
-keep class com.google.android.gms.location.** { *; }

# Keep tracking service and receiver
-keep class com.relatives.app.tracking.TrackingLocationService { *; }
-keep class com.relatives.app.receiver.BootReceiver { *; }
