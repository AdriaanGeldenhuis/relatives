# ═══════════════════════════════════════════════════════════════════════
#  Relatives app — ProGuard / R8 rules
# ═══════════════════════════════════════════════════════════════════════

# ── Debugging ─────────────────────────────────────────────────────────
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile

# ── WebView JavaScript interface ──────────────────────────────────────
# TrackingBridge is injected into WebView via addJavascriptInterface.
# R8 must not rename or remove any @JavascriptInterface methods.
-keepclassmembers class za.co.relatives.app.tracking.TrackingBridge {
    @android.webkit.JavascriptInterface <methods>;
}

# ── Gson ──────────────────────────────────────────────────────────────
# Gson uses reflection to serialise/deserialise.
-keepattributes Signature
-keepattributes *Annotation*
-dontwarn sun.misc.**
-keep class com.google.gson.** { *; }
-keep class * extends com.google.gson.TypeAdapter
-keep class * implements com.google.gson.TypeAdapterFactory
-keep class * implements com.google.gson.JsonSerializer
-keep class * implements com.google.gson.JsonDeserializer

# ── OkHttp ────────────────────────────────────────────────────────────
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn javax.annotation.**
-keepnames class okhttp3.internal.publicsuffix.PublicSuffixDatabase

# ── Room ──────────────────────────────────────────────────────────────
-keep class * extends androidx.room.RoomDatabase
-keepclassmembers class * {
    @androidx.room.* <methods>;
}
-keep @androidx.room.Entity class *
-keep @androidx.room.Dao class *

# ── Firebase ──────────────────────────────────────────────────────────
-keep class com.google.firebase.** { *; }
-dontwarn com.google.firebase.**

# ── Google Play Services Location ─────────────────────────────────────
-keep class com.google.android.gms.location.** { *; }
-dontwarn com.google.android.gms.**

# ── Google Play Billing ───────────────────────────────────────────────
-keep class com.android.billingclient.** { *; }
-dontwarn com.android.billingclient.**

# ── WorkManager ───────────────────────────────────────────────────────
-keep class * extends androidx.work.Worker
-keep class * extends androidx.work.ListenableWorker {
    public <init>(android.content.Context, androidx.work.WorkerParameters);
}

# ── Kotlin coroutines ─────────────────────────────────────────────────
-dontwarn kotlinx.coroutines.**

# ── Android services ──────────────────────────────────────────────────
# Foreground service declared in manifest — keep it findable.
-keep class za.co.relatives.app.tracking.TrackingService
-keep class za.co.relatives.app.services.RelativesFirebaseService
-keep class za.co.relatives.app.receivers.BootReceiver
