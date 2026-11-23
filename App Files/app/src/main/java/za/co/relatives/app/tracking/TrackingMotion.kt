package za.co.relatives.app.tracking

/** Location uncertainty and a monotonic clock govern movement, independently of uploads. */
internal object TrackingMotion {
    const val IDLE_AFTER_MS = 120_000L
    const val STILL_DEBOUNCE_MS = 30_000L
    const val STALE_FIX_MS = 120_000L
    const val MAX_UPLOAD_ACCURACY_M = 2_000f

    fun isMovement(
        distanceM: Float?,
        previousAccuracyM: Float?,
        accuracyM: Float?,
        speedMps: Float?,
        speedAccuracyMps: Float?,
    ): Boolean {
        if (accuracyM == null || accuracyM !in 0f..100f) return false
        if (speedMps != null && speedAccuracyMps != null && speedMps - speedAccuracyMps >= 1f) {
            return true
        }
        return distanceM != null && previousAccuracyM != null && previousAccuracyM in 0f..100f &&
            distanceM >= maxOf(35f, previousAccuracyM + accuracyM)
    }
}
