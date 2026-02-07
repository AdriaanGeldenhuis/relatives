package za.co.relatives.app

import android.app.Application
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager

class RelativesApplication : Application() {

    lateinit var preferencesManager: PreferencesManager
        private set

    override fun onCreate() {
        super.onCreate()
        preferencesManager = PreferencesManager(this)
        NotificationHelper.createChannels(this)
    }
}
