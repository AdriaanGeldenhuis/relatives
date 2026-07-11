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
        // PreferencesManager is a thin SharedPreferences wrapper; if its
        // constructor ever throws, retrying it identically would just throw
        // again, so let it propagate rather than loop. (Callers that can run
        // before/without the Application already fall back to their own
        // PreferencesManager via `application as? RelativesApplication`.)
        preferencesManager = PreferencesManager(this)
        try {
            NotificationHelper.createChannels(this)
        } catch (e: Exception) {
            Log.e("RelativesApp", "Notification channels init failed", e)
        }
    }
}
