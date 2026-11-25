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
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.utils.PreferencesManager

class PushReceiptWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    companion object {
        private val validReceipt = Regex("[a-f0-9]{32}")

        fun enqueue(context: Context, receipt: String) {
            if (!validReceipt.matches(receipt)) return
            val binding = PreferencesManager(context).sessionBinding() ?: return
            val work = OneTimeWorkRequestBuilder<PushReceiptWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setInputData(workDataOf("receipt" to receipt, "binding" to binding))
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("push_receipt_$receipt", ExistingWorkPolicy.KEEP, work)
        }
    }

    override suspend fun doWork(): Result {
        val preferences = PreferencesManager(applicationContext)
        val cookie = preferences.sessionToken ?: return Result.success()
        val receipt = inputData.getString("receipt") ?: return Result.success()
        if (preferences.sessionBinding() != inputData.getString("binding")) return Result.success()
        return try {
            val status = ApiClient(applicationContext).acknowledgePush(receipt, cookie)
            if (status >= 500 && runAttemptCount < 3) Result.retry() else Result.success()
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            if (runAttemptCount < 3) Result.retry() else Result.success()
        }
    }
}
