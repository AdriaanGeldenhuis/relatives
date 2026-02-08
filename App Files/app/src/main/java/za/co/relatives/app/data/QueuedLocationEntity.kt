package za.co.relatives.app.data

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey
import java.util.UUID

/**
 * A location fix queued for upload to the server.
 * Stored in Room so it survives process death and network outages.
 */
@Entity(tableName = "queued_locations")
data class QueuedLocationEntity(
    @PrimaryKey
    @ColumnInfo(name = "client_event_id")
    val clientEventId: String = UUID.randomUUID().toString(),

    val lat: Double,
    val lng: Double,
    val accuracy: Float? = null,
    val altitude: Double? = null,
    val bearing: Float? = null,
    val speed: Float? = null,

    @ColumnInfo(name = "speed_kmh")
    val speedKmh: Float? = null,

    @ColumnInfo(name = "is_moving")
    val isMoving: Boolean = false,

    @ColumnInfo(name = "battery_level")
    val batteryLevel: Int? = null,

    val timestamp: Long = System.currentTimeMillis(),

    @ColumnInfo(name = "retry_count")
    val retryCount: Int = 0,

    val sent: Boolean = false,
)
