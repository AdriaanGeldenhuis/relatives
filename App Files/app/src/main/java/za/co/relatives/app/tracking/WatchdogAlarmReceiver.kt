package za.co.relatives.app.tracking

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * WatchdogAlarmReceiver — AlarmManager backstop for the WorkManager watchdog.
 *
 * On Huawei/Honor (and other aggressive OEMs) the 15-minute periodic
 * TrackingRestartWorker is exactly the kind of JobScheduler work the OEM
 * battery manager defers indefinitely once the app is backgrounded, so the
 * tracking service stayed dead until the user reopened the app. While-idle
 * alarms survive those managers considerably better, so this receiver
 * re-runs the same revive-or-capture logic on a 15-minute alarm chain.
 *
 * setAndAllowWhileIdle needs no exact-alarm permission and is battery-cheap:
 * in Doze it coalesces to at most one firing per idle window.
 */
class WatchdogAlarmReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "WatchdogAlarm"
        private const val REQUEST_CODE = 7001
        private const val INTERVAL_MS = 15 * 60 * 1000L

        private fun pendingIntent(context: Context): PendingIntent =
            PendingIntent.getBroadcast(
                context,
                REQUEST_CODE,
                Intent(context, WatchdogAlarmReceiver::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )

        /** Arm (or re-arm) the next watchdog alarm. */
        fun schedule(context: Context) {
            try {
                val am = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
                am.setAndAllowWhileIdle(
                    AlarmManager.RTC_WAKEUP,
                    System.currentTimeMillis() + INTERVAL_MS,
                    pendingIntent(context),
                )
            } catch (e: Exception) {
                Log.w(TAG, "Could not schedule watchdog alarm", e)
            }
        }

        fun cancel(context: Context) {
            try {
                val am = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
                am.cancel(pendingIntent(context))
            } catch (e: Exception) {
                Log.w(TAG, "Could not cancel watchdog alarm", e)
            }
        }
    }

    override fun onReceive(context: Context, intent: Intent) {
        val prefs = context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
        if (!prefs.getBoolean("tracking_enabled", false)) {
            // User turned tracking off — let the chain die.
            Log.d(TAG, "Tracking disabled; not re-arming alarm.")
            return
        }

        // Always re-arm first: whatever happens below, the chain must survive.
        schedule(context)

        if (!TrackingService.hasLocationPermission(context) ||
            !TrackingService.canReviveFromBackground(context)
        ) {
            return
        }

        if (!TrackingService.isRunning) {
            Log.i(TAG, "Alarm watchdog: TrackingService not running — reviving.")
            TrackingService.revive(context)
        }

        // Staleness fix-capture runs in the worker (receivers must stay
        // fast); this also gives WorkManager a fresh chance on OEMs that
        // throttled the periodic schedule.
        TrackingRestartWorker.enqueue(context)
    }
}
