package za.co.relatives.app.services

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

internal class ChatNotificationReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val receipt = intent.getStringExtra("receipt") ?: return
        val message = intent.getStringExtra("message_id") ?: return
        val binding = intent.getStringExtra("binding") ?: return
        PushReceiptWorker.enqueue(context, receipt, message, binding)
    }
}
