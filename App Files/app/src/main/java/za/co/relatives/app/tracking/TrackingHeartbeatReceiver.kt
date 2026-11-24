package za.co.relatives.app.tracking

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock
import android.util.Log

class TrackingHeartbeatReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "TrackingHeartbeat"
        const val ACTION_HEARTBEAT = "za.co.relatives.app.tracking.HEARTBEAT"
        private const val HEARTBEAT_INTERVAL_MS = 15 * 60 * 1000L // 15 minutes
        private const val REQUEST_CODE = 101

        fun schedule(context: Context) {
            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val intent = Intent(context, TrackingHeartbeatReceiver::class.java).apply {
                action = ACTION_HEARTBEAT
            }
            val pendingIntent = PendingIntent.getBroadcast(
                context,
                REQUEST_CODE,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )

            // Use an inexact, repeating alarm that can wake the device up.
            // This is the most battery-efficient way to ensure our service is revived.
            alarmManager.setInexactRepeating(
                AlarmManager.ELAPSED_REALTIME_WAKEUP,
                SystemClock.elapsedRealtime() + HEARTBEAT_INTERVAL_MS,
                HEARTBEAT_INTERVAL_MS,
                pendingIntent
            )
            Log.i(TAG, "Heartbeat alarm scheduled.")
        }

        fun cancel(context: Context) {
            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val intent = Intent(context, TrackingHeartbeatReceiver::class.java).apply {
                action = ACTION_HEARTBEAT
            }
            val pendingIntent = PendingIntent.getBroadcast(
                context,
                REQUEST_CODE,
                intent,
                PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_IMMUTABLE
            )

            if (pendingIntent != null) {
                alarmManager.cancel(pendingIntent)
                pendingIntent.cancel()
                Log.i(TAG, "Heartbeat alarm cancelled.")
            }
        }
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == ACTION_HEARTBEAT) {
            Log.d(TAG, "Heartbeat alarm received.")
            val prefs = context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
            if (prefs.getBoolean("tracking_enabled", false)) {
                Log.d(TAG, "Tracking is enabled, ensuring service is running.")
                TrackingService.start(context)
            } else {
                Log.d(TAG, "Tracking is disabled, cancelling heartbeat as a precaution.")
                cancel(context)
            }
        }
    }
}
