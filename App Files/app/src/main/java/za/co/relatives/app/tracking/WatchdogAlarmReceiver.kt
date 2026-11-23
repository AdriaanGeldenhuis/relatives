package za.co.relatives.app.tracking

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Cancel the recurring while-idle alarm installed by older releases. */
class WatchdogAlarmReceiver : BroadcastReceiver() {
    companion object {
        fun cancel(context: Context) {
            val pending = PendingIntent.getBroadcast(
                context, 7001, Intent(context, WatchdogAlarmReceiver::class.java),
                PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_IMMUTABLE,
            ) ?: return
            context.getSystemService(AlarmManager::class.java)?.cancel(pending)
            pending.cancel()
        }
    }

    override fun onReceive(context: Context, intent: Intent) = cancel(context)
}
