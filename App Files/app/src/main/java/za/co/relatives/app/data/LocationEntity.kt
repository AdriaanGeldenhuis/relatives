package za.co.relatives.app.data

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "queued_locations")
data class LocationEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,
    val lat: Double,
    val lng: Double,
    val accuracy: Float,
    val altitude: Double?,
    val speed: Float,
    val heading: Float?,
    val battery: Int,
    val isMoving: Boolean,
    val timestamp: Long = System.currentTimeMillis()
)
