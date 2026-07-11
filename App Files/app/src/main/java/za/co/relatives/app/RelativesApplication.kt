package za.co.relatives.app

import android.app.Application
import android.util.Log
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager

class RelativesApplication : Application() {

    lateinit var preferencesManager: PreferencesManager
        private set

    override fun onCreate() {
        super.onCreate()
        try {
            preferencesManager = PreferencesManager(this)
        } catch (e: Exception) {
            Log.e("RelativesApp", "PreferencesManager init failed", e)
            preferencesManager = PreferencesManager(this)
        }
        try {
            NotificationHelper.createChannels(this)
        } catch (e: Exception) {
            Log.e("RelativesApp", "Notification channels init failed", e)
        }
    }
}
