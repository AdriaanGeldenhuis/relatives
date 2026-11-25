package za.co.relatives.app.tracking

import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class TrackingReliabilityTest {
    @Test
    fun acknowledgesOnlyMatchedTerminalResults() {
        val results = JsonParser.parseString("""
            [
              {"index":0,"client_event_id":"wake","status":"stored"},
              {"index":1,"client_event_id":"another-fix","status":"stored"},
              {"index":2,"status":"skipped"},
              {"index":3,"client_event_id":"retry","status":"retry"},
              {"index":9,"status":"stored"},
              null
            ]
        """.trimIndent()).asJsonArray
        assertEquals(
            mapOf("wake" to "stored", "old-client-id" to "skipped"),
            UploadReceipts.acknowledged(listOf("wake", "other", "old-client-id", "retry"), results),
        )
    }

    @Test
    fun usesConfiguredCadenceAndBatteryFloors() {
        val config = TrackingPolicy(movingSeconds = 15, idleSeconds = 300)
        assertEquals(15_000L, config.intervalMs(true, true, false, false))
        assertEquals(300_000L, config.intervalMs(false, true, false, false))
        assertEquals(120_000L, config.intervalMs(false, false, false, false))
        assertEquals(180_000L, config.intervalMs(true, true, true, false))
        assertEquals(300_000L, config.intervalMs(true, true, false, true))
        assertFalse(config.copy(mode = 0).canCollect)
        assertFalse(config.copy(accountAllowsSharing = false).canCollect)
    }
}
