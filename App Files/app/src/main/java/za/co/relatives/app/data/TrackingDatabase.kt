package za.co.relatives.app.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

/**
 * Room database backing the offline location queue.
 * Singleton — access via [getInstance].
 */
@Database(entities = [QueuedLocationEntity::class], version = 1, exportSchema = false)
abstract class TrackingDatabase : RoomDatabase() {

    abstract fun locationDao(): QueuedLocationDao

    companion object {
        @Volatile
        private var INSTANCE: TrackingDatabase? = null

        fun getInstance(context: Context): TrackingDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    TrackingDatabase::class.java,
                    "tracking_queue.db",
                ).fallbackToDestructiveMigration().build().also { INSTANCE = it }
            }
    }
}
