package za.co.relatives.app

import android.app.Application
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager

class RelativesApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        // Initialize Utilities
        PreferencesManager.init(this)
        NotificationHelper.createNotificationChannel(this)
    }
}