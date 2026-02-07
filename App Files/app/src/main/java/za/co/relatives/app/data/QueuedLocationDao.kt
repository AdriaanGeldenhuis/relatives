package za.co.relatives.app.data

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

/**
 * DAO for queued location operations.
 * Supports batch insert, batch retrieval for upload, and cleanup.
 */
@Dao
interface QueuedLocationDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(location: QueuedLocationEntity)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAll(locations: List<QueuedLocationEntity>)

    /** Get unsent locations for batch upload (oldest first, limit 100 to match server batch max) */
    @Query("SELECT * FROM queued_locations WHERE sent = 0 ORDER BY createdAt ASC LIMIT 100")
    suspend fun getUnsent(): List<QueuedLocationEntity>

    /** Count unsent locations */
    @Query("SELECT COUNT(*) FROM queued_locations WHERE sent = 0")
    suspend fun getUnsentCount(): Int

    /** Mark locations as sent by their IDs */
    @Query("UPDATE queued_locations SET sent = 1 WHERE clientEventId IN (:ids)")
    suspend fun markSent(ids: List<String>)

    /** Increment retry count for failed items */
    @Query("UPDATE queued_locations SET retryCount = retryCount + 1 WHERE clientEventId IN (:ids)")
    suspend fun incrementRetry(ids: List<String>)

    /** Delete sent locations (cleanup after successful upload) */
    @Query("DELETE FROM queued_locations WHERE sent = 1")
    suspend fun deleteSent()

    /** Trim to max size: delete oldest entries beyond limit (keep most recent 300) */
    @Query("DELETE FROM queued_locations WHERE clientEventId NOT IN (SELECT clientEventId FROM queued_locations ORDER BY createdAt DESC LIMIT 300)")
    suspend fun trimToMaxSize()

    /** Delete all locations (for reset/logout) */
    @Query("DELETE FROM queued_locations")
    suspend fun deleteAll()
}
