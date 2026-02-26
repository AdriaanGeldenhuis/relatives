package za.co.relatives.app.ui.tracking

import android.graphics.Color as AndroidColor
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.mapbox.geojson.Feature
import com.mapbox.geojson.FeatureCollection
import com.mapbox.geojson.Point
import com.mapbox.maps.CameraOptions
import com.mapbox.maps.MapView
import com.mapbox.maps.Style
import com.mapbox.maps.plugin.annotation.annotations
import com.mapbox.maps.plugin.annotation.generated.CircleAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.createCircleAnnotationManager
import za.co.relatives.app.R
import za.co.relatives.app.data.TrackingStore

/**
 * Main tracking map screen — replaces the WebView map.
 *
 * Shows a fullscreen Mapbox map with family member pins and
 * a bottom sheet family panel.
 */
@Composable
fun TrackingMapScreen(
    viewModel: TrackingViewModel,
    onNavigateToEvents: () -> Unit,
    onNavigateToGeofences: () -> Unit,
    onNavigateToSettings: () -> Unit,
    onBack: () -> Unit,
    onRequestPermissions: (() -> Unit)? = null,
) {
    val members by viewModel.members.collectAsState()
    val trackingEnabled by viewModel.trackingEnabled.collectAsState()
    var showPanel by remember { mutableStateOf(true) }
    var selectedMember by remember { mutableStateOf<TrackingStore.MemberLocation?>(null) }
    var mapView by remember { mutableStateOf<MapView?>(null) }

    LaunchedEffect(Unit) {
        viewModel.pollNow()
        viewModel.startPolling()
    }

    DisposableEffect(Unit) {
        onDispose { viewModel.stopPolling() }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        // Mapbox Map
        AndroidView(
            modifier = Modifier.fillMaxSize(),
            factory = { context ->
                MapView(context).also { mv ->
                    mapView = mv
                    mv.mapboxMap.loadStyle(Style.DARK) {
                        // Initial camera — South Africa
                        mv.mapboxMap.setCamera(
                            CameraOptions.Builder()
                                .center(Point.fromLngLat(28.0473, -26.2041))
                                .zoom(10.0)
                                .build()
                        )
                    }
                }
            },
            update = { mv ->
                updateMapAnnotations(mv, members)
            }
        )

        // Top bar overlay
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .align(Alignment.TopCenter)
                .background(Color(0xCC1A1A2E))
                .padding(horizontal = 16.dp, vertical = 12.dp)
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton(onClick = onBack, modifier = Modifier.size(32.dp)) {
                        Text("<", color = Color.White, fontSize = 18.sp)
                    }
                    Spacer(Modifier.width(8.dp))
                    Column {
                        Text(
                            "Family Tracking",
                            color = Color.White,
                            fontWeight = FontWeight.Bold,
                            fontSize = 16.sp,
                        )
                        Text(
                            if (trackingEnabled) "Tracking active" else "Tracking off",
                            color = if (trackingEnabled) Color(0xFF22C55E) else Color(0xFF9CA3AF),
                            fontSize = 12.sp,
                        )
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    TopBarButton("Events") { onNavigateToEvents() }
                    TopBarButton("Zones") { onNavigateToGeofences() }
                    TopBarButton("Settings") { onNavigateToSettings() }
                }
            }
        }

        // Bottom family panel
        AnimatedVisibility(
            visible = showPanel,
            modifier = Modifier.align(Alignment.BottomCenter),
            enter = slideInVertically { it },
            exit = slideOutVertically { it },
        ) {
            FamilyPanel(
                members = members,
                trackingEnabled = trackingEnabled,
                selectedMember = selectedMember,
                onMemberClick = { member ->
                    selectedMember = member
                    mapView?.let { mv ->
                        mv.mapboxMap.setCamera(
                            CameraOptions.Builder()
                                .center(Point.fromLngLat(member.lng, member.lat))
                                .zoom(15.0)
                                .build()
                        )
                    }
                },
                onToggleTracking = {
                    if (trackingEnabled) {
                        viewModel.disableTracking()
                    } else {
                        onRequestPermissions?.invoke() ?: viewModel.enableTracking()
                    }
                },
                onWakeAll = { viewModel.wakeAllDevices() },
            )
        }

        // Panel toggle
        IconButton(
            onClick = { showPanel = !showPanel },
            modifier = Modifier
                .align(Alignment.BottomEnd)
                .padding(end = 16.dp, bottom = if (showPanel) 200.dp else 16.dp)
                .size(40.dp)
                .background(Color(0xFF667eea), CircleShape),
        ) {
            Text(
                if (showPanel) "v" else "^",
                color = Color.White,
                fontSize = 14.sp,
            )
        }
    }
}

@Composable
private fun TopBarButton(label: String, onClick: () -> Unit) {
    Text(
        text = label,
        color = Color(0xFFCCCCCC),
        fontSize = 12.sp,
        modifier = Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(Color(0x33FFFFFF))
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 6.dp),
    )
}

@Composable
private fun FamilyPanel(
    members: List<TrackingStore.MemberLocation>,
    trackingEnabled: Boolean,
    selectedMember: TrackingStore.MemberLocation?,
    onMemberClick: (TrackingStore.MemberLocation) -> Unit,
    onToggleTracking: () -> Unit,
    onWakeAll: () -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(topStart = 16.dp, topEnd = 16.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xFF1A1A2E)),
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            // Tracking toggle button
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Button(
                    onClick = onToggleTracking,
                    modifier = Modifier.weight(1f),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = if (trackingEnabled) Color(0xFFEF4444) else Color(0xFF667eea),
                    ),
                    shape = RoundedCornerShape(8.dp),
                ) {
                    Text(
                        if (trackingEnabled) "Disable Live Location" else "Enable Live Location",
                        fontSize = 13.sp,
                    )
                }
                Button(
                    onClick = onWakeAll,
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF374151)),
                    shape = RoundedCornerShape(8.dp),
                ) {
                    Text("Wake", fontSize = 13.sp)
                }
            }

            Spacer(Modifier.height(12.dp))

            // Family member cards
            if (members.isEmpty()) {
                Text(
                    "No family members online",
                    color = Color(0xFF6B7280),
                    fontSize = 14.sp,
                    modifier = Modifier.padding(vertical = 16.dp),
                )
            } else {
                LazyRow(
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    contentPadding = PaddingValues(horizontal = 4.dp),
                ) {
                    items(members, key = { it.memberId }) { member ->
                        MemberCard(
                            member = member,
                            isSelected = selectedMember?.memberId == member.memberId,
                            onClick = { onMemberClick(member) },
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun MemberCard(
    member: TrackingStore.MemberLocation,
    isSelected: Boolean,
    onClick: () -> Unit,
) {
    val bgColor = if (isSelected) Color(0xFF667eea) else Color(0xFF2D2D44)
    val memberColor = member.color?.let {
        try { Color(AndroidColor.parseColor(it)) } catch (_: Exception) { null }
    } ?: Color(0xFF667eea)

    Card(
        modifier = Modifier
            .width(130.dp)
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(10.dp),
        colors = CardDefaults.cardColors(containerColor = bgColor),
    ) {
        Column(modifier = Modifier.padding(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    modifier = Modifier
                        .size(10.dp)
                        .background(memberColor, CircleShape)
                )
                Spacer(Modifier.width(6.dp))
                Text(
                    member.name,
                    color = Color.White,
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Medium,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Spacer(Modifier.height(4.dp))
            Text(
                when (member.motionState) {
                    "moving" -> "Moving"
                    "idle", "still" -> "Stationary"
                    else -> member.motionState ?: "Unknown"
                },
                color = when (member.motionState) {
                    "moving" -> Color(0xFF22C55E)
                    else -> Color(0xFF9CA3AF)
                },
                fontSize = 11.sp,
            )
            member.speed?.let { speed ->
                if (speed > 0.5f) {
                    Text(
                        "${String.format("%.0f", speed * 3.6)} km/h",
                        color = Color(0xFF9CA3AF),
                        fontSize = 10.sp,
                    )
                }
            }
            member.updatedAt?.let { time ->
                Text(
                    formatTimeAgo(time),
                    color = Color(0xFF6B7280),
                    fontSize = 10.sp,
                )
            }
        }
    }
}

private fun updateMapAnnotations(mapView: MapView, members: List<TrackingStore.MemberLocation>) {
    try {
        val annotationManager = mapView.annotations.createCircleAnnotationManager()
        annotationManager.deleteAll()
        members.forEach { member ->
            val color = member.color?.let {
                try { AndroidColor.parseColor(it) } catch (_: Exception) { null }
            } ?: AndroidColor.parseColor("#667eea")

            annotationManager.create(
                CircleAnnotationOptions()
                    .withPoint(Point.fromLngLat(member.lng, member.lat))
                    .withCircleRadius(10.0)
                    .withCircleColor(color)
                    .withCircleStrokeWidth(3.0)
                    .withCircleStrokeColor(AndroidColor.WHITE)
            )
        }
    } catch (_: Exception) {
        // Map not yet ready
    }
}

internal fun formatTimeAgo(dateStr: String): String {
    return try {
        val formats = arrayOf(
            java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", java.util.Locale.US),
            java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US),
        )
        var date: java.util.Date? = null
        for (fmt in formats) {
            try { date = fmt.parse(dateStr); break } catch (_: Exception) {}
        }
        if (date == null) return dateStr

        val diff = (System.currentTimeMillis() - date.time) / 1000
        when {
            diff < 60 -> "just now"
            diff < 3600 -> "${diff / 60}m ago"
            diff < 86400 -> "${diff / 3600}h ago"
            else -> "${diff / 86400}d ago"
        }
    } catch (_: Exception) { dateStr }
}
