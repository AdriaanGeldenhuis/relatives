package za.co.relatives.app.tracking

import com.google.gson.JsonObject

internal data class TrackingPolicy(
    val mode: Int = 1,
    val accountAllowsSharing: Boolean = true,
    val movingSeconds: Int = 30,
    val idleSeconds: Int = 600,
    val targetAccuracyM: Int = 100,
) {
    val canCollect: Boolean get() = mode != 0 && accountAllowsSharing

    fun intervalMs(moving: Boolean, motionAvailable: Boolean, lowPower: Boolean, critical: Boolean): Long {
        val base = if (moving) movingSeconds else if (motionAvailable) idleSeconds else minOf(120, idleSeconds)
        val floor = if (critical) 300 else if (lowPower) 180 else 0
        return 1000L * maxOf(base, floor)
    }

    companion object {
        fun from(json: JsonObject): TrackingPolicy = TrackingPolicy(
            mode = json.number("mode")?.toInt()?.coerceIn(0, 2) ?: 1,
            accountAllowsSharing = json.flag("location_sharing") ?: true,
            movingSeconds = json.number("moving_interval")?.toInt()?.coerceIn(15, 600) ?: 30,
            idleSeconds = json.number("idle_interval")?.toInt()?.coerceIn(300, 3600) ?: 600,
            targetAccuracyM = json.number("min_accuracy_m")?.toInt()?.coerceIn(10, 500) ?: 100,
        )
    }
}
