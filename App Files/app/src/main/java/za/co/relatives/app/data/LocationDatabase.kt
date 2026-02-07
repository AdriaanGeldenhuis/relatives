package za.co.relatives.app.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(entities = [LocationEntity::class], version = 1, exportSchema = false)
abstract class LocationDatabase : RoomDatabase() {
    abstract fun locationDao(): LocationDao

    companion object {
        @Volatile
        private var INSTANCE: LocationDatabase? = null

        fun getInstance(context: Context): LocationDatabase {
            return INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    LocationDatabase::class.java,
                    "tracking_queue.db"
                ).fallbackToDestructiveMigration().build().also { INSTANCE = it }
            }
        }
    }
}

@androidx.room.Dao
interface LocationDao {
    @androidx.room.Insert
    fun insert(location: LocationEntity)

    @androidx.room.Query("SELECT * FROM queued_locations ORDER BY timestamp ASC LIMIT 50")
    fun getPending(): List<LocationEntity>

    @androidx.room.Delete
    fun delete(locations: List<LocationEntity>)

    @androidx.room.Query("DELETE FROM queued_locations")
    fun deleteAll()

    @androidx.room.Query("SELECT COUNT(*) FROM queued_locations")
    fun count(): Int
}
