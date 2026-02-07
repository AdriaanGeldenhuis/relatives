package za.co.relatives.app.data

import androidx.room.Entity
import androidx.room.PrimaryKey
import java.util.UUID

/**
 * Room entity for persisted offline location queue.
 * Replaces SharedPreferences JSON queue for reliability and performance.
 *
 * Max 300 entries retained. Uploader flushes in batches via WorkManager.
 */
@Entity(tableName = "queued_locations")
data class QueuedLocationEntity(
    @PrimaryKey
    val clientEventId: String = UUID.randomUUID().toString(),
    val latitude: Double,
    val longitude: Double,
    val accuracy: Float,
    val altitude: Double? = null,
    val bearing: Float? = null,
    val speed: Float? = null,
    val isMoving: Boolean,
    val speedKmh: Float,
    val batteryLevel: Int? = null,
    val timestamp: Long = System.currentTimeMillis(),
    val retryCount: Int = 0,
    val sent: Boolean = false,
    val createdAt: Long = System.currentTimeMillis()
)
