package za.co.relatives.app.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

/**
 * Room database that acts as an offline queue for location updates.
 *
 * Access via [TrackingDatabase.getInstance] which guarantees a process-wide
 * singleton so that WAL-mode journaling works correctly.
 */
@Database(
    entities = [QueuedLocationEntity::class],
    version = 1,
    exportSchema = false
)
abstract class TrackingDatabase : RoomDatabase() {

    abstract fun queuedLocationDao(): QueuedLocationDao

    companion object {

        private const val DB_NAME = "tracking_queue.db"

        @Volatile
        private var INSTANCE: TrackingDatabase? = null

        /**
         * Thread-safe singleton accessor.
         *
         * Uses double-checked locking so the `synchronized` block is only ever
         * entered once per process lifetime.
         */
        fun getInstance(context: Context): TrackingDatabase {
            return INSTANCE ?: synchronized(this) {
                INSTANCE ?: buildDatabase(context.applicationContext).also { INSTANCE = it }
            }
        }

        private fun buildDatabase(appContext: Context): TrackingDatabase {
            return Room.databaseBuilder(
                appContext,
                TrackingDatabase::class.java,
                DB_NAME
            )
                .fallbackToDestructiveMigration()
                .build()
        }
    }
}
