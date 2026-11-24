package za.co.relatives.app.ui.tracking

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.gson.JsonObject

@Composable
fun SettingsScreen(
    viewModel: TrackingViewModel,
    onBack: () -> Unit,
) {
    val settings by viewModel.settings.collectAsState()
    val alertRules by viewModel.alertRules.collectAsState()
    val loading by viewModel.settingsLoading.collectAsState()
    val saveSuccess by viewModel.saveSuccess.collectAsState()
    val snackbar = remember { SnackbarHostState() }

    // Local state for form fields
    var mode by remember { mutableStateOf("1") }
    var movingInterval by remember { mutableStateOf("30") }
    var idleInterval by remember { mutableStateOf("120") }
    var speedThreshold by remember { mutableStateOf("1.0") }
    var distanceThreshold by remember { mutableStateOf("20") }
    var minAccuracy by remember { mutableStateOf("50") }
    var historyDays by remember { mutableStateOf("30") }
    var eventsDays by remember { mutableStateOf("30") }

    // Alert toggles
    var alertsEnabled by remember { mutableStateOf(true) }
    var arrivePlaceEnabled by remember { mutableStateOf(true) }
    var leavePlaceEnabled by remember { mutableStateOf(true) }
    var enterGeofenceEnabled by remember { mutableStateOf(true) }
    var exitGeofenceEnabled by remember { mutableStateOf(true) }
    var cooldownSeconds by remember { mutableStateOf("900") }

    LaunchedEffect(Unit) {
        viewModel.loadSettings()
    }

    // Populate form from loaded settings
    LaunchedEffect(settings) {
        settings?.let { s ->
            mode = s.get("mode")?.asString ?: "1"
            movingInterval = s.get("moving_interval_seconds")?.asString ?: "30"
            idleInterval = s.get("idle_interval_seconds")?.asString ?: "120"
            speedThreshold = s.get("speed_threshold_mps")?.asString ?: "1.0"
            distanceThreshold = s.get("distance_threshold_m")?.asString ?: "20"
            minAccuracy = s.get("min_accuracy_m")?.asString ?: "50"
            historyDays = s.get("history_retention_days")?.asString ?: "30"
            eventsDays = s.get("events_retention_days")?.asString ?: "30"
        }
    }

    LaunchedEffect(alertRules) {
        alertRules?.let { r ->
            alertsEnabled = r.get("enabled")?.let { it.asInt == 1 } ?: true
            arrivePlaceEnabled = r.get("arrive_place_enabled")?.let { it.asInt == 1 } ?: true
            leavePlaceEnabled = r.get("leave_place_enabled")?.let { it.asInt == 1 } ?: true
            enterGeofenceEnabled = r.get("enter_geofence_enabled")?.let { it.asInt == 1 } ?: true
            exitGeofenceEnabled = r.get("exit_geofence_enabled")?.let { it.asInt == 1 } ?: true
            cooldownSeconds = r.get("cooldown_seconds")?.asString ?: "900"
        }
    }

    LaunchedEffect(saveSuccess) {
        when (saveSuccess) {
            true -> { snackbar.showSnackbar("Settings saved"); viewModel.clearSaveStatus() }
            false -> { snackbar.showSnackbar("Failed to save settings"); viewModel.clearSaveStatus() }
            null -> {}
        }
    }

    val fieldColors = OutlinedTextFieldDefaults.colors(
        focusedTextColor = Color.White,
        unfocusedTextColor = Color.White,
        focusedBorderColor = Color(0xFF667eea),
        unfocusedBorderColor = Color(0xFF374151),
        cursorColor = Color(0xFF667eea),
        focusedLabelColor = Color(0xFF667eea),
        unfocusedLabelColor = Color(0xFF9CA3AF),
    )

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFF0F0F1A))
    ) {
        // Top bar
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .background(Color(0xFF1A1A2E))
                .padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "<",
                color = Color.White,
                fontSize = 18.sp,
                modifier = Modifier
                    .clip(CircleShape)
                    .clickable(onClick = onBack)
                    .padding(8.dp),
            )
            Spacer(Modifier.width(8.dp))
            Column {
                Text("Settings", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                Text("Configure tracking behavior.", color = Color(0xFF9CA3AF), fontSize = 12.sp)
            }
        }

        Column(
            modifier = Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // Tracking Mode
            SettingsCard(title = "Tracking Mode") {
                SettingsField("Mode (0=Off, 1=Normal, 2=Active)", mode, { mode = it }, fieldColors)
            }

            // Intervals
            SettingsCard(title = "Intervals & Thresholds") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Column(Modifier.weight(1f)) {
                        SettingsField("Moving Interval (sec)", movingInterval, { movingInterval = it }, fieldColors, KeyboardType.Number)
                    }
                    Column(Modifier.weight(1f)) {
                        SettingsField("Idle Interval (sec)", idleInterval, { idleInterval = it }, fieldColors, KeyboardType.Number)
                    }
                }
                Spacer(Modifier.height(4.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Column(Modifier.weight(1f)) {
                        SettingsField("Speed Threshold (m/s)", speedThreshold, { speedThreshold = it }, fieldColors, KeyboardType.Decimal)
                    }
                    Column(Modifier.weight(1f)) {
                        SettingsField("Distance Threshold (m)", distanceThreshold, { distanceThreshold = it }, fieldColors, KeyboardType.Number)
                    }
                }
                Spacer(Modifier.height(4.dp))
                SettingsField("Min Accuracy (m)", minAccuracy, { minAccuracy = it }, fieldColors, KeyboardType.Number)
            }

            // Data Retention
            SettingsCard(title = "Data Retention") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Column(Modifier.weight(1f)) {
                        SettingsField("History (days)", historyDays, { historyDays = it }, fieldColors, KeyboardType.Number)
                    }
                    Column(Modifier.weight(1f)) {
                        SettingsField("Events (days)", eventsDays, { eventsDays = it }, fieldColors, KeyboardType.Number)
                    }
                }
            }

            // Alert Rules
            SettingsCard(title = "Alert Rules") {
                ToggleRow("Alerts Enabled", alertsEnabled) { alertsEnabled = it }
                ToggleRow("Arrive at Place", arrivePlaceEnabled) { arrivePlaceEnabled = it }
                ToggleRow("Leave Place", leavePlaceEnabled) { leavePlaceEnabled = it }
                ToggleRow("Enter Geofence", enterGeofenceEnabled) { enterGeofenceEnabled = it }
                ToggleRow("Exit Geofence", exitGeofenceEnabled) { exitGeofenceEnabled = it }
                Spacer(Modifier.height(4.dp))
                SettingsField("Cooldown (sec)", cooldownSeconds, { cooldownSeconds = it }, fieldColors, KeyboardType.Number)
            }

            // Save button
            Button(
                onClick = {
                    val settingsPayload = JsonObject().apply {
                        addProperty("mode", mode.toIntOrNull() ?: 1)
                        addProperty("moving_interval_seconds", movingInterval.toIntOrNull() ?: 30)
                        addProperty("idle_interval_seconds", idleInterval.toIntOrNull() ?: 120)
                        addProperty("speed_threshold_mps", speedThreshold.toFloatOrNull() ?: 1.0f)
                        addProperty("distance_threshold_m", distanceThreshold.toIntOrNull() ?: 20)
                        addProperty("min_accuracy_m", minAccuracy.toIntOrNull() ?: 50)
                        addProperty("history_retention_days", historyDays.toIntOrNull() ?: 30)
                        addProperty("events_retention_days", eventsDays.toIntOrNull() ?: 30)
                    }
                    val alertsPayload = JsonObject().apply {
                        addProperty("enabled", if (alertsEnabled) 1 else 0)
                        addProperty("arrive_place_enabled", if (arrivePlaceEnabled) 1 else 0)
                        addProperty("leave_place_enabled", if (leavePlaceEnabled) 1 else 0)
                        addProperty("enter_geofence_enabled", if (enterGeofenceEnabled) 1 else 0)
                        addProperty("exit_geofence_enabled", if (exitGeofenceEnabled) 1 else 0)
                        addProperty("cooldown_seconds", cooldownSeconds.toIntOrNull() ?: 900)
                    }
                    viewModel.saveSettings(settingsPayload, alertsPayload)
                },
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF667eea)),
                shape = RoundedCornerShape(10.dp),
            ) {
                Text("Save Settings", fontSize = 15.sp, modifier = Modifier.padding(vertical = 4.dp))
            }

            Spacer(Modifier.height(32.dp))
        }

        SnackbarHost(hostState = snackbar)
    }
}

@Composable
private fun SettingsCard(title: String, content: @Composable () -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color(0xFF1A1A2E)),
        shape = RoundedCornerShape(12.dp),
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            Text(title, color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
            Spacer(Modifier.height(10.dp))
            content()
        }
    }
}

@Composable
private fun SettingsField(
    label: String,
    value: String,
    onValueChange: (String) -> Unit,
    colors: androidx.compose.material3.TextFieldColors,
    keyboardType: KeyboardType = KeyboardType.Text,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label, fontSize = 11.sp) },
        colors = colors,
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun ToggleRow(label: String, checked: Boolean, onCheckedChange: (Boolean) -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, color = Color.White, fontSize = 13.sp)
        Switch(
            checked = checked,
            onCheckedChange = onCheckedChange,
            colors = SwitchDefaults.colors(
                checkedTrackColor = Color(0xFF667eea),
                uncheckedTrackColor = Color(0xFF374151),
            ),
        )
    }
}
