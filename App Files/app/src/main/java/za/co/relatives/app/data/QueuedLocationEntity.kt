package za.co.relatives.app.data

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey
import java.util.UUID

/**
 * Room entity representing a queued location update waiting to be uploaded.
 * Locations are stored locally first then batch-uploaded when connectivity allows.
 */
@Entity(tableName = "queued_locations")
data class QueuedLocationEntity(

    @PrimaryKey
    @ColumnInfo(name = "client_event_id")
    val clientEventId: String = UUID.randomUUID().toString(),

    @ColumnInfo(name = "lat")
    val lat: Double? = null,

    @ColumnInfo(name = "lng")
    val lng: Double? = null,

    @ColumnInfo(name = "accuracy")
    val accuracy: Double? = null,

    @ColumnInfo(name = "altitude")
    val altitude: Double? = null,

    @ColumnInfo(name = "bearing")
    val bearing: Double? = null,

    @ColumnInfo(name = "speed")
    val speed: Double? = null,

    @ColumnInfo(name = "speed_kmh")
    val speedKmh: Double? = null,

    @ColumnInfo(name = "is_moving")
    val isMoving: Boolean = false,

    @ColumnInfo(name = "battery_level")
    val batteryLevel: Int? = null,

    @ColumnInfo(name = "timestamp")
    val timestamp: Long = System.currentTimeMillis(),

    @ColumnInfo(name = "retry_count")
    val retryCount: Int = 0,

    @ColumnInfo(name = "sent")
    val sent: Boolean = false,

    @ColumnInfo(name = "created_at")
    val createdAt: Long = System.currentTimeMillis()
)
