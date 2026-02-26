package za.co.relatives.app.ui.tracking

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.viewinterop.AndroidView
import com.mapbox.geojson.Point
import com.mapbox.maps.CameraOptions
import com.mapbox.maps.MapView
import com.mapbox.maps.Style
import com.mapbox.maps.plugin.annotation.annotations
import com.mapbox.maps.plugin.annotation.generated.CircleAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.createCircleAnnotationManager

@Composable
fun GeofencesScreen(
    viewModel: TrackingViewModel,
    onBack: () -> Unit,
) {
    val geofences by viewModel.geofences.collectAsState()
    val loading by viewModel.geofencesLoading.collectAsState()
    var showAddDialog by remember { mutableStateOf(false) }
    var deleteTarget by remember { mutableStateOf<TrackingViewModel.Geofence?>(null) }

    LaunchedEffect(Unit) {
        viewModel.loadGeofences()
    }

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
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
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
                    Text("Geofences", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                    Text(
                        "Set up zones to get alerts.",
                        color = Color(0xFF9CA3AF),
                        fontSize = 12.sp,
                    )
                }
            }
            Button(
                onClick = { showAddDialog = true },
                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF667eea)),
                shape = RoundedCornerShape(8.dp),
            ) {
                Text("+ Add", fontSize = 13.sp)
            }
        }

        if (loading && geofences.isEmpty()) {
            Box(
                modifier = Modifier.fillMaxSize(),
                contentAlignment = Alignment.Center,
            ) {
                CircularProgressIndicator(color = Color(0xFF667eea), modifier = Modifier.size(32.dp))
            }
        } else if (geofences.isEmpty()) {
            Box(
                modifier = Modifier.fillMaxSize(),
                contentAlignment = Alignment.Center,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("No geofences yet", color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Medium)
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "Create geofences to get alerts when\nfamily members enter or leave areas.",
                        color = Color(0xFF6B7280),
                        fontSize = 13.sp,
                    )
                }
            }
        } else {
            LazyColumn(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(geofences, key = { it.id }) { gf ->
                    GeofenceCard(
                        geofence = gf,
                        onDelete = { deleteTarget = gf },
                    )
                }
            }
        }
    }

    // Add geofence dialog
    if (showAddDialog) {
        AddGeofenceDialog(
            onDismiss = { showAddDialog = false },
            onSave = { name, lat, lng, radius ->
                viewModel.addGeofence(name, "circle", lat, lng, radius, null)
                showAddDialog = false
            },
        )
    }

    // Delete confirmation
    deleteTarget?.let { gf ->
        AlertDialog(
            onDismissRequest = { deleteTarget = null },
            containerColor = Color(0xFF1A1A2E),
            title = { Text("Delete Geofence", color = Color.White) },
            text = { Text("Delete \"${gf.name}\"?", color = Color(0xFF9CA3AF)) },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.deleteGeofence(gf.id)
                    deleteTarget = null
                }) {
                    Text("Delete", color = Color(0xFFEF4444))
                }
            },
            dismissButton = {
                TextButton(onClick = { deleteTarget = null }) {
                    Text("Cancel", color = Color(0xFF9CA3AF))
                }
            },
        )
    }
}

@Composable
private fun GeofenceCard(
    geofence: TrackingViewModel.Geofence,
    onDelete: () -> Unit,
) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color(0xFF1A1A2E)),
        shape = RoundedCornerShape(12.dp),
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        modifier = Modifier
                            .size(10.dp)
                            .background(
                                if (geofence.type == "circle") Color(0xFF667eea) else Color(0xFFf093fb),
                                CircleShape,
                            )
                    )
                    Spacer(Modifier.width(8.dp))
                    Text(geofence.name, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                    Spacer(Modifier.width(8.dp))
                    Text(
                        if (geofence.active) "Active" else "Inactive",
                        color = if (geofence.active) Color(0xFF22C55E) else Color(0xFF6B7280),
                        fontSize = 11.sp,
                        modifier = Modifier
                            .background(
                                if (geofence.active) Color(0x3322C55E) else Color(0x336B7280),
                                RoundedCornerShape(4.dp),
                            )
                            .padding(horizontal = 6.dp, vertical = 2.dp),
                    )
                }
                Text(
                    "Delete",
                    color = Color(0xFFEF4444),
                    fontSize = 12.sp,
                    modifier = Modifier
                        .clip(RoundedCornerShape(4.dp))
                        .clickable(onClick = onDelete)
                        .padding(4.dp),
                )
            }

            Spacer(Modifier.height(6.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                if (geofence.type == "circle") {
                    Text(
                        "Radius: ${geofence.radiusM.toInt()}m",
                        color = Color(0xFF9CA3AF),
                        fontSize = 12.sp,
                    )
                }
                Text(
                    geofence.type.replaceFirstChar { it.uppercase() },
                    color = Color(0xFF9CA3AF),
                    fontSize = 12.sp,
                )
            }

            // Mini map preview
            if (geofence.centerLat != 0.0 && geofence.centerLng != 0.0) {
                Spacer(Modifier.height(8.dp))
                AndroidView(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(120.dp)
                        .clip(RoundedCornerShape(8.dp)),
                    factory = { context ->
                        MapView(context).also { mv ->
                            mv.mapboxMap.loadStyle(Style.DARK) {
                                mv.mapboxMap.setCamera(
                                    CameraOptions.Builder()
                                        .center(Point.fromLngLat(geofence.centerLng, geofence.centerLat))
                                        .zoom(
                                            if (geofence.type == "circle") {
                                                (16.0 - kotlin.math.ln(geofence.radiusM / 100.0) / kotlin.math.ln(2.0)).coerceIn(10.0, 16.0)
                                            } else 14.0
                                        )
                                        .build()
                                )
                                try {
                                    val am = mv.annotations.createCircleAnnotationManager()
                                    am.create(
                                        CircleAnnotationOptions()
                                            .withPoint(Point.fromLngLat(geofence.centerLng, geofence.centerLat))
                                            .withCircleRadius(8.0)
                                            .withCircleColor(android.graphics.Color.parseColor("#667eea"))
                                            .withCircleStrokeWidth(2.0)
                                            .withCircleStrokeColor(android.graphics.Color.WHITE)
                                    )
                                } catch (_: Exception) {}
                            }
                        }
                    },
                )
            }
        }
    }
}

@Composable
private fun AddGeofenceDialog(
    onDismiss: () -> Unit,
    onSave: (name: String, lat: Double, lng: Double, radius: Float) -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var lat by remember { mutableStateOf("") }
    var lng by remember { mutableStateOf("") }
    var radius by remember { mutableStateOf("200") }

    val fieldColors = OutlinedTextFieldDefaults.colors(
        focusedTextColor = Color.White,
        unfocusedTextColor = Color.White,
        focusedBorderColor = Color(0xFF667eea),
        unfocusedBorderColor = Color(0xFF374151),
        cursorColor = Color(0xFF667eea),
        focusedLabelColor = Color(0xFF667eea),
        unfocusedLabelColor = Color(0xFF9CA3AF),
    )

    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = Color(0xFF1A1A2E),
        title = { Text("Add Geofence", color = Color.White) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("Name") },
                    colors = fieldColors,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(
                        value = lat,
                        onValueChange = { lat = it },
                        label = { Text("Latitude") },
                        colors = fieldColors,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        singleLine = true,
                        modifier = Modifier.weight(1f),
                    )
                    OutlinedTextField(
                        value = lng,
                        onValueChange = { lng = it },
                        label = { Text("Longitude") },
                        colors = fieldColors,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        singleLine = true,
                        modifier = Modifier.weight(1f),
                    )
                }
                OutlinedTextField(
                    value = radius,
                    onValueChange = { radius = it },
                    label = { Text("Radius (meters)") },
                    colors = fieldColors,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text(
                    "Tip: You can also long-press on the map to set coordinates.",
                    color = Color(0xFF6B7280),
                    fontSize = 11.sp,
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    val latD = lat.toDoubleOrNull() ?: return@TextButton
                    val lngD = lng.toDoubleOrNull() ?: return@TextButton
                    val radiusF = radius.toFloatOrNull() ?: 200f
                    if (name.isBlank()) return@TextButton
                    onSave(name, latD, lngD, radiusF)
                },
            ) {
                Text("Save", color = Color(0xFF667eea))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text("Cancel", color = Color(0xFF9CA3AF))
            }
        },
    )
}
