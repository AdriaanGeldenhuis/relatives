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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

@Composable
fun EventsScreen(
    viewModel: TrackingViewModel,
    onBack: () -> Unit,
) {
    val events by viewModel.events.collectAsState()
    val loading by viewModel.eventsLoading.collectAsState()
    var activeFilter by remember { mutableStateOf<String?>(null) }
    val listState = rememberLazyListState()

    LaunchedEffect(activeFilter) {
        viewModel.loadEvents(type = activeFilter, reset = true)
    }

    // Load more when near the end
    val shouldLoadMore by remember {
        derivedStateOf {
            val lastVisible = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            lastVisible >= events.size - 5
        }
    }
    LaunchedEffect(shouldLoadMore) {
        if (shouldLoadMore && events.isNotEmpty()) {
            viewModel.loadEvents(type = activeFilter)
        }
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
                Text("Events", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                Text(
                    "Recent tracking activity for your family.",
                    color = Color(0xFF9CA3AF),
                    fontSize = 12.sp,
                )
            }
        }

        // Filter chips
        LazyRow(
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            val filters = listOf(
                null to "All",
                "enter_geofence" to "Enter Geofence",
                "exit_geofence" to "Exit Geofence",
                "arrive_place" to "Arrive Place",
                "leave_place" to "Leave Place",
            )
            items(filters) { (type, label) ->
                FilterChip(
                    label = label,
                    selected = activeFilter == type,
                    onClick = { activeFilter = type },
                )
            }
        }

        // Event list
        if (events.isEmpty() && !loading) {
            Box(
                modifier = Modifier.fillMaxSize(),
                contentAlignment = Alignment.Center,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("No events yet", color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Medium)
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "Events will appear here when family members\nenter or leave geofences and places.",
                        color = Color(0xFF6B7280),
                        fontSize = 13.sp,
                    )
                }
            }
        } else {
            LazyColumn(
                state = listState,
                modifier = Modifier
                    .fillMaxSize()
                    .padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(events, key = { "${it.id}_${it.occurredAt}" }) { event ->
                    EventCard(event)
                }
                if (loading) {
                    item {
                        Box(
                            modifier = Modifier.fillMaxWidth().padding(16.dp),
                            contentAlignment = Alignment.Center,
                        ) {
                            CircularProgressIndicator(
                                color = Color(0xFF667eea),
                                modifier = Modifier.size(24.dp),
                                strokeWidth = 2.dp,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun FilterChip(label: String, selected: Boolean, onClick: () -> Unit) {
    val bg = if (selected) Color(0xFF667eea) else Color(0xFF2D2D44)
    val textColor = if (selected) Color.White else Color(0xFF9CA3AF)
    Text(
        text = label,
        color = textColor,
        fontSize = 12.sp,
        modifier = Modifier
            .clip(RoundedCornerShape(16.dp))
            .background(bg)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 7.dp),
    )
}

@Composable
private fun EventCard(event: TrackingViewModel.TrackingEvent) {
    val (nodeColor, label, action) = when (event.eventType) {
        "enter_geofence" -> Triple(Color(0xFF22C55E), "Entered Geofence", "entered")
        "exit_geofence" -> Triple(Color(0xFFEF4444), "Exited Geofence", "left")
        "arrive_place" -> Triple(Color(0xFF3B82F6), "Arrived at Place", "entered")
        "leave_place" -> Triple(Color(0xFFF97316), "Left Place", "left")
        else -> Triple(Color(0xFF6B7280), "Event", "at")
    }

    Card(
        colors = CardDefaults.cardColors(containerColor = Color(0xFF1A1A2E)),
        shape = RoundedCornerShape(10.dp),
    ) {
        Row(modifier = Modifier.padding(12.dp)) {
            Box(
                modifier = Modifier
                    .size(10.dp)
                    .background(nodeColor, CircleShape)
                    .align(Alignment.CenterVertically)
            )
            Spacer(Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Text(label, color = nodeColor, fontSize = 11.sp, fontWeight = FontWeight.Medium)
                    Text(
                        formatTimeAgo(event.occurredAt),
                        color = Color(0xFF6B7280),
                        fontSize = 11.sp,
                    )
                }
                Spacer(Modifier.height(4.dp))
                Text(
                    "${event.userName} $action ${event.targetName}",
                    color = Color.White,
                    fontSize = 13.sp,
                )
            }
        }
    }
}
