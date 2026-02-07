package za.co.relatives.app.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

/**
 * Room database for tracking location queue.
 * Replaces SharedPreferences-based queue for reliability.
 */
@Database(entities = [QueuedLocationEntity::class], version = 1, exportSchema = false)
abstract class TrackingDatabase : RoomDatabase() {

    abstract fun queuedLocationDao(): QueuedLocationDao

    companion object {
        @Volatile
        private var INSTANCE: TrackingDatabase? = null

        fun getInstance(context: Context): TrackingDatabase {
            return INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    TrackingDatabase::class.java,
                    "tracking_queue.db"
                )
                    .fallbackToDestructiveMigration()
                    .build()
                    .also { INSTANCE = it }
            }
        }
    }
}
