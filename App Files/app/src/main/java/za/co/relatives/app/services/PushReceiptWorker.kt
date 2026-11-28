package za.co.relatives.app.services

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import kotlinx.coroutines.CancellationException
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager

class PushReceiptWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    companion object {
        private val validReceipt = Regex("[a-f0-9]{32}")
        private val validMessage = Regex("[1-9][0-9]{0,18}")

        fun enqueue(context: Context, receipt: String, message: String? = null, expectedBinding: String? = null) {
            if (!validReceipt.matches(receipt)) return
            val binding = PreferencesManager(context).sessionBinding() ?: return
            if (message != null && (!validMessage.matches(message) || expectedBinding != binding)) return
            val work = OneTimeWorkRequestBuilder<PushReceiptWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setInputData(workDataOf("receipt" to receipt, "binding" to binding, "message_id" to message))
                .build()
            val purpose = if (message == null) "push_receipt" else "chat_read"
            WorkManager.getInstance(context).enqueueUniqueWork("${purpose}_$receipt", ExistingWorkPolicy.KEEP, work)
        }
    }

    override suspend fun doWork(): Result {
        val preferences = PreferencesManager(applicationContext)
        val cookie = preferences.sessionToken ?: return Result.success()
        val receipt = inputData.getString("receipt") ?: return Result.success()
        val binding = inputData.getString("binding")
        if (preferences.sessionBinding() != binding) return Result.success()
        val message = inputData.getString("message_id")
        return try {
            val status = ApiClient(applicationContext).acknowledgePush(receipt, cookie, markRead = message != null)
            if (status == 204 && message != null && preferences.sessionBinding() == binding) {
                NotificationHelper.markMessagesRead(applicationContext, setOf(message))
                (applicationContext as? RelativesApplication)?.notificationEvents?.tryEmit(Unit)
            }
            if ((status >= 500 || status == 429) && runAttemptCount < 4) Result.retry() else Result.success()
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            if (runAttemptCount < 3) Result.retry() else Result.success()
        }
    }
}
