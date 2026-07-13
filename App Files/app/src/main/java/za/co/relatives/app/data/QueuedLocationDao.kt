package za.co.relatives.app.data

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

/**
 * DAO for the offline location upload queue.
 */
@Dao
interface QueuedLocationDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(entity: QueuedLocationEntity)

    @Query("SELECT * FROM queued_locations WHERE sent = 0 ORDER BY timestamp ASC LIMIT :limit")
    suspend fun getUnsent(limit: Int = 100): List<QueuedLocationEntity>

    @Query("UPDATE queued_locations SET sent = 1 WHERE client_event_id = :id")
    suspend fun markSent(id: String)

    @Query("UPDATE queued_locations SET retry_count = retry_count + 1 WHERE client_event_id = :id")
    suspend fun incrementRetry(id: String)

    @Query("DELETE FROM queued_locations WHERE sent = 1")
    suspend fun deleteSent()

    /**
     * Age-based expiry: drop points older than [cutoffMillis] regardless of
     * send state. A week-old fix is worthless on a live map, and this is the
     * ONLY thing that may discard unsent points — a flaky connection must
     * never cause data loss the way a retry-count cull did.
     */
    @Query("DELETE FROM queued_locations WHERE timestamp < :cutoffMillis")
    suspend fun deleteOlderThan(cutoffMillis: Long)

    @Query(
        """
        DELETE FROM queued_locations WHERE client_event_id IN (
            SELECT client_event_id FROM queued_locations
            ORDER BY sent DESC, timestamp ASC
            LIMIT MAX(0, (SELECT COUNT(*) FROM queued_locations) - :maxSize)
        )
        """
    )
    suspend fun trimToMaxSize(maxSize: Int = 300)

    @Query("SELECT COUNT(*) FROM queued_locations WHERE sent = 0")
    suspend fun unsentCount(): Int
}
