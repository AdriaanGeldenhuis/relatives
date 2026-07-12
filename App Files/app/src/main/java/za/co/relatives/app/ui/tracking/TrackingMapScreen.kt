package za.co.relatives.app.ui.tracking

import android.graphics.Color as AndroidColor
import android.util.Log
import android.widget.FrameLayout
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
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.mapbox.geojson.Point
import com.mapbox.maps.CameraOptions
import com.mapbox.maps.MapView
import com.mapbox.maps.Style
import com.mapbox.maps.plugin.annotation.annotations
import com.mapbox.maps.plugin.annotation.generated.CircleAnnotationManager
import com.mapbox.maps.plugin.annotation.generated.CircleAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.createCircleAnnotationManager
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
    mapboxTokenReady: Boolean,
    onNavigateToEvents: () -> Unit,
    onNavigateToGeofences: () -> Unit,
    onNavigateToSettings: () -> Unit,
    onBack: () -> Unit,
    onRequestPermissions: (() -> Unit)? = null,
    onMapInitFailed: () -> Unit = {},
) {
    val members by viewModel.members.collectAsState()
    val trackingEnabled by viewModel.trackingEnabled.collectAsState()
    var showPanel by remember { mutableStateOf(true) }
    var selectedMember by remember { mutableStateOf<TrackingStore.MemberLocation?>(null) }
    var mapView by remember { mutableStateOf<MapView?>(null) }
    // ONE annotation manager for the MapView's lifetime. Creating a new one
    // per update leaks a map layer each time and eventually kills the
    // renderer.
    var annotationManager by remember { mutableStateOf<CircleAnnotationManager?>(null) }

    LaunchedEffect(Unit) {
        viewModel.pollNow()
        viewModel.startPolling()
    }

    DisposableEffect(Unit) {
        onDispose { viewModel.stopPolling() }
    }

    // Fit the camera to the family once per activity (state lives in the
    // ViewModel so navigating away and back doesn't refit or lose position).
    LaunchedEffect(members, mapView) {
        if (!viewModel.didInitialCameraFit) {
            val located = members.filter { it.lat != null && it.lng != null }
            val mv = mapView
            if (located.isNotEmpty() && mv != null) {
                viewModel.didInitialCameraFit = true
                val avgLat = located.mapNotNull { it.lat }.average()
                val avgLng = located.mapNotNull { it.lng }.average()
                val cam = CameraOptions.Builder()
                    .center(Point.fromLngLat(avgLng, avgLat))
                    .zoom(if (located.size == 1) 13.0 else 10.0)
                    .build()
                mv.mapboxMap.setCamera(cam)
                viewModel.savedCamera = cam
            }
        }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        // Mapbox Map — only once the access token is available; MapView
        // creation without a token renders nothing (or throws).
        if (mapboxTokenReady) {
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = { context ->
                    // MapView construction is the first touch of the Mapbox
                    // renderer; a native-init failure here is an Error, not
                    // an Exception, and uncaught it kills the whole process.
                    try {
                        MapView(context).also { mv ->
                            mapView = mv
                            annotationManager = mv.annotations.createCircleAnnotationManager()
                            mv.mapboxMap.loadStyle(Style.DARK) {
                                // Restore the previous camera (nav round trip) or
                                // default to South Africa on first creation.
                                mv.mapboxMap.setCamera(
                                    viewModel.savedCamera ?: CameraOptions.Builder()
                                        .center(Point.fromLngLat(28.0473, -26.2041))
                                        .zoom(10.0)
                                        .build()
                                )
                            }
                        }
                    } catch (t: Throwable) {
                        Log.e("TrackingMapScreen", "MapView creation failed", t)
                        // If construction got far enough to assign state, drop
                        // it: the orphaned MapView never attaches, and
                        // onRelease will only ever see the fallback view.
                        try { mapView?.onDestroy() } catch (_: Throwable) {}
                        mapView = null
                        annotationManager = null
                        onMapInitFailed()
                        FrameLayout(context)
                    }
                },
                update = {
                    updateMapAnnotations(annotationManager, members)
                },
                onRelease = { view ->
                    // AndroidView only detaches; without an explicit destroy the
                    // MapView (GL context, tile cache) leaks on every nav round
                    // trip until the activity dies — eventually OOM.
                    (view as? MapView)?.let { mv ->
                        viewModel.savedCamera = mv.mapboxMap.cameraState.let { s ->
                            CameraOptions.Builder()
                                .center(s.center)
                                .zoom(s.zoom)
                                .bearing(s.bearing)
                                .pitch(s.pitch)
                                .build()
                        }
                        mv.onDestroy()
                    }
                    mapView = null
                    annotationManager = null
                },
            )
        } else {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .background(Color(0xFF11111F)),
                contentAlignment = Alignment.Center,
            ) {
                Text("Loading map…", color = Color(0xFF9CA3AF), fontSize = 14.sp)
            }
        }

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
                    val lat = member.lat
                    val lng = member.lng
                    if (lat != null && lng != null) {
                        mapView?.let { mv ->
                            mv.mapboxMap.setCamera(
                                CameraOptions.Builder()
                                    .center(Point.fromLngLat(lng, lat))
                                    .zoom(15.0)
                                    .build()
                            )
                        }
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
    val memberColor = member.avatarColor?.let {
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

private fun updateMapAnnotations(
    manager: CircleAnnotationManager?,
    members: List<TrackingStore.MemberLocation>,
) {
    manager ?: return
    try {
        manager.deleteAll()
        members.forEach { member ->
            val lat = member.lat ?: return@forEach
            val lng = member.lng ?: return@forEach
            val color = member.avatarColor?.let {
                try { AndroidColor.parseColor(it) } catch (_: Exception) { null }
            } ?: AndroidColor.parseColor("#667eea")

            manager.create(
                CircleAnnotationOptions()
                    .withPoint(Point.fromLngLat(lng, lat))
                    .withCircleRadius(10.0)
                    .withCircleColor(color)
                    .withCircleStrokeWidth(3.0)
                    .withCircleStrokeColor(AndroidColor.WHITE)
            )
        }
    } catch (t: Throwable) {
        // Map not yet ready — Mapbox annotation calls can also surface
        // native-init Errors, which must not escape.
        Log.w("TrackingMapScreen", "Annotation update skipped", t)
    }
}

internal fun formatTimeAgo(dateStr: String): String {
    return try {
        // Server timestamps are UTC — parsing in the device zone made every
        // member show "just now" (or negative ages) outside UTC.
        val utc = java.util.TimeZone.getTimeZone("UTC")
        val formats = arrayOf(
            java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", java.util.Locale.US),
            java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US),
        )
        formats.forEach { it.timeZone = utc }
        var date: java.util.Date? = null
        for (fmt in formats) {
            try { date = fmt.parse(dateStr); break } catch (_: Exception) {}
        }
        if (date == null) return dateStr

        val diff = ((System.currentTimeMillis() - date.time) / 1000).coerceAtLeast(0)
        when {
            diff < 60 -> "just now"
            diff < 3600 -> "${diff / 60}m ago"
            diff < 86400 -> "${diff / 3600}h ago"
            else -> "${diff / 86400}d ago"
        }
    } catch (_: Exception) { dateStr }
}
