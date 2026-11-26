package za.co.relatives.app.tracking

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class TrackingMotionTest {
    @Test
    fun stationaryLocationDriftDoesNotCountAsMovement() {
        assertFalse(TrackingMotion.isMovement(60f, 45f, 40f, 1.5f, 0.8f))
        assertFalse(TrackingMotion.isMovement(20f, 5f, 5f, null, null))
    }

    @Test
    fun accurateDisplacementCanDetectMotionWithoutSpeed() {
        assertTrue(TrackingMotion.isMovement(42f, 12f, 14f, null, null))
    }

    @Test
    fun speedMustBeConfidentAndFromAnAccurateFix() {
        assertTrue(TrackingMotion.isMovement(null, null, 20f, 2.5f, 0.5f))
        assertFalse(TrackingMotion.isMovement(null, null, 150f, 8f, 0.2f))
        assertFalse(TrackingMotion.isMovement(null, null, 20f, 3f, null))
    }

    @Test
    fun drivingCadenceRequiresConfidentVehicleSpeed() {
        assertTrue(TrackingMotion.isDriving(14f, 0.5f, 15f))
        assertFalse(TrackingMotion.isDriving(14f, 0.5f, 700f))
        assertFalse(TrackingMotion.isDriving(6f, 4f, 15f))
        assertFalse(TrackingMotion.isDriving(2f, 0.4f, 15f))
    }
}
