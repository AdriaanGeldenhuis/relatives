package za.co.relatives.app.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

/**
 * Room database backing the offline location queue.
 * Singleton — access via [getInstance].
 */
@Database(entities = [QueuedLocationEntity::class], version = 2, exportSchema = false)
abstract class TrackingDatabase : RoomDatabase() {

    abstract fun locationDao(): QueuedLocationDao

    companion object {
        private val consentMigration = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE queued_locations ADD COLUMN consent_scope TEXT")
                // Old fixes have no verifiable owner on a shared device.
                db.execSQL("DELETE FROM queued_locations WHERE consent_scope IS NULL")
            }
        }

        @Volatile
        private var INSTANCE: TrackingDatabase? = null

        fun getInstance(context: Context): TrackingDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    TrackingDatabase::class.java,
                    "tracking_queue.db",
                ).addMigrations(consentMigration).build().also { INSTANCE = it }
            }
    }
}
