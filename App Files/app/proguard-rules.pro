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

# ── Android services & receivers ──────────────────────────────────────
# Declared in manifest — keep findable.
-keep class za.co.relatives.app.tracking.TrackingService
-keep class za.co.relatives.app.services.RelativesFirebaseService
-keep class za.co.relatives.app.receivers.BootReceiver
-keep class za.co.relatives.app.tracking.ActivityTransitionsReceiver
-keep class za.co.relatives.app.tracking.GeofenceReceiver

# ── Application class ────────────────────────────────────────────────
-keep class za.co.relatives.app.RelativesApplication { *; }

# ── ViewModels (instantiated by reflection via ViewModelProvider) ────
-keep class za.co.relatives.app.ui.tracking.TrackingViewModel { *; }

# ── Data classes used by Gson / JSON serialisation ───────────────────
-keep class za.co.relatives.app.data.TrackingStore$MemberLocation { *; }
-keep class za.co.relatives.app.data.QueuedLocationEntity { *; }
-keep class za.co.relatives.app.network.ApiException { *; }

# ── Tracking workers (instantiated by WorkManager via reflection) ────
-keep class za.co.relatives.app.tracking.LocationUploadWorker { *; }
-keep class za.co.relatives.app.tracking.TrackingRestartWorker { *; }
