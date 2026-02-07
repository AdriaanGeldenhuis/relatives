package za.co.relatives.app.data

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

/**
 * Data access object for the queued location offline buffer.
 *
 * Locations are inserted as they arrive from the fused provider, read back in
 * oldest-first order by [LocationUploadWorker], and pruned once acknowledged by
 * the server.
 */
@Dao
interface QueuedLocationDao {

    /** Insert a single location into the queue. */
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(entity: QueuedLocationEntity)

    /** Insert a batch of locations into the queue. */
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAll(entities: List<QueuedLocationEntity>)

    /**
     * Retrieve the oldest unsent locations, capped at [limit].
     * Items that have exceeded the maximum retry count can still be returned;
     * it is the caller's responsibility to decide whether to retry or discard.
     */
    @Query("SELECT * FROM queued_locations WHERE sent = 0 ORDER BY timestamp ASC LIMIT :limit")
    suspend fun getUnsent(limit: Int = 100): List<QueuedLocationEntity>

    /** Mark a single location as successfully uploaded. */
    @Query("UPDATE queued_locations SET sent = 1 WHERE client_event_id = :id")
    suspend fun markSent(id: String)

    /** Increment the retry counter after a transient upload failure. */
    @Query("UPDATE queued_locations SET retry_count = retry_count + 1 WHERE client_event_id = :id")
    suspend fun incrementRetry(id: String)

    /** Delete all locations that have already been sent. */
    @Query("DELETE FROM queued_locations WHERE sent = 1")
    suspend fun deleteSent()

    /**
     * Keep the queue bounded: delete the oldest sent rows first, then oldest
     * unsent rows, until the total row count is at most [maxSize].
     */
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

    /** Nuclear option -- wipe the entire queue. */
    @Query("DELETE FROM queued_locations")
    suspend fun deleteAll()

    /** Count of pending (unsent) items. */
    @Query("SELECT COUNT(*) FROM queued_locations WHERE sent = 0")
    suspend fun unsentCount(): Int
}
