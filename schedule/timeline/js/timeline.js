/**
 * ============================================
 * RELATIVES - TIMELINE VIEW JAVASCRIPT
 * Interactive timeline functionality
 * ============================================
 */

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    initTimeline();
    initTouchHandlers();
    updateCurrentTime();

    // Update current time indicator every minute
    setInterval(updateCurrentTime, 60000);
});

// ============================================
// TIMELINE INITIALIZATION
// ============================================

function initTimeline() {
    console.log('📊 Timeline initialized');

    // Add hover effects to events
    const events = document.querySelectorAll('.timeline-event');
    events.forEach((event, index) => {
        event.style.animationDelay = `${index * 0.05}s`;
        event.classList.add('animate-in');
    });
}

// ============================================
// TOUCH HANDLERS FOR NATIVE APP
// ============================================

function initTouchHandlers() {
    // Date navigation buttons
    const dateNavBtns = document.querySelectorAll('.date-nav-btn, .date-nav-today');
    dateNavBtns.forEach(btn => {
        const onclickAttr = btn.getAttribute('onclick');
        if (onclickAttr) {
            btn.removeAttribute('onclick');
            addTouchHandler(btn, () => eval(onclickAttr));
        }
    });

    // View toggle buttons already use <a> tags, so they work natively

    // Event cards
    const eventCards = document.querySelectorAll('.timeline-event, .event-list-item');
    eventCards.forEach(card => {
        const onclickAttr = card.getAttribute('onclick');
        if (onclickAttr) {
            card.removeAttribute('onclick');
            addTouchHandler(card, () => eval(onclickAttr));
        }
    });

    console.log('✅ Touch handlers initialized');
}

function addTouchHandler(element, handler) {
    let touchHandled = false;

    element.addEventListener('touchstart', function() {
        element.classList.add('touched');
    }, { passive: true });

    element.addEventListener('touchend', function(e) {
        element.classList.remove('touched');
        e.preventDefault();
        touchHandled = true;
        handler();
        setTimeout(() => { touchHandled = false; }, 300);
    }, { passive: false });

    element.addEventListener('touchcancel', function() {
        element.classList.remove('touched');
    }, { passive: true });

    element.addEventListener('click', function(e) {
        if (!touchHandled) {
            handler();
        }
    });
}

// ============================================
// DATE NAVIGATION
// ============================================

function changeDate(days) {
    const currentDate = new Date(window.selectedDate);
    currentDate.setDate(currentDate.getDate() + days);

    const newDate = currentDate.toISOString().split('T')[0];
    window.location.href = `/schedule/timeline/?date=${newDate}`;
}

function goToToday() {
    const today = new Date().toISOString().split('T')[0];
    window.location.href = `/schedule/timeline/?date=${today}`;
}

// ============================================
// CURRENT TIME INDICATOR
// ============================================

function updateCurrentTime() {
    const timeLine = document.getElementById('currentTimeLine');
    if (!timeLine) return;

    const now = new Date();
    const currentHour = now.getHours();
    const currentMinute = now.getMinutes();

    // Timeline bounds (from PHP)
    const timelineStart = 5;
    const timelineEnd = 23;
    const totalHours = timelineEnd - timelineStart;

    // Calculate position
    if (currentHour >= timelineStart && currentHour <= timelineEnd) {
        const position = ((currentHour - timelineStart) + (currentMinute / 60)) / totalHours * 100;
        timeLine.style.top = `${position}%`;
        timeLine.style.display = 'block';
    } else {
        timeLine.style.display = 'none';
    }

    // Update time badge in header
    const timeBadge = document.querySelector('.current-time-badge');
    if (timeBadge) {
        timeBadge.textContent = `${String(currentHour).padStart(2, '0')}:${String(currentMinute).padStart(2, '0')}`;
    }
}

// ============================================
// EVENT DETAILS MODAL
// ============================================

function showEventDetails(eventId) {
    const event = window.timelineEvents.find(e => e.id == eventId);
    if (!event) {
        console.error('Event not found:', eventId);
        return;
    }

    const modal = document.getElementById('eventDetailModal');
    const title = document.getElementById('modalEventTitle');
    const body = document.getElementById('modalEventBody');
    const editLink = document.getElementById('editEventLink');

    const type = event.kind || 'todo';
    const typeInfo = window.eventTypes[type] || window.eventTypes['todo'];

    const startTime = new Date(event.starts_at);
    const endTime = new Date(event.ends_at);
    const duration = event.duration_minutes || 0;

    title.textContent = `${typeInfo.icon} ${event.title}`;

    body.innerHTML = `
        <div class="event-detail">
            <div class="detail-row">
                <span class="detail-label">📅 Date</span>
                <span class="detail-value">${formatDate(startTime)}</span>
            </div>

            <div class="detail-row highlight">
                <span class="detail-label">🕐 Start Time</span>
                <span class="detail-value time-large">${formatTime(startTime)}</span>
            </div>

            <div class="detail-row highlight">
                <span class="detail-label">🏁 End Time</span>
                <span class="detail-value time-large">${formatTime(endTime)}</span>
            </div>

            <div class="detail-row highlight">
                <span class="detail-label">⏱️ Duration</span>
                <span class="detail-value time-large">${formatDuration(duration)}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">📋 Type</span>
                <span class="detail-value">${typeInfo.icon} ${typeInfo.name}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">📊 Status</span>
                <span class="detail-value status-${event.status}">${formatStatus(event.status)}</span>
            </div>

            ${event.notes ? `
            <div class="detail-row notes">
                <span class="detail-label">📝 Notes</span>
                <span class="detail-value">${escapeHtml(event.notes)}</span>
            </div>
            ` : ''}

            ${event.assigned_to_name ? `
            <div class="detail-row">
                <span class="detail-label">👤 Assigned To</span>
                <span class="detail-value">${escapeHtml(event.assigned_to_name)}</span>
            </div>
            ` : ''}

            ${event.focus_mode ? `
            <div class="detail-row">
                <span class="detail-label">🎯 Focus Mode</span>
                <span class="detail-value">Enabled</span>
            </div>
            ` : ''}

            ${event.repeat_rule ? `
            <div class="detail-row">
                <span class="detail-label">🔁 Repeat</span>
                <span class="detail-value">${capitalizeFirst(event.repeat_rule)}</span>
            </div>
            ` : ''}

            ${event.productivity_rating ? `
            <div class="detail-row">
                <span class="detail-label">⭐ Rating</span>
                <span class="detail-value">${'⭐'.repeat(event.productivity_rating)} (${event.productivity_rating}/5)</span>
            </div>
            ` : ''}
        </div>

        <style>
            .event-detail {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            .detail-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 12px;
            }

            .detail-row.highlight {
                background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
                border: 1px solid rgba(102, 126, 234, 0.3);
            }

            .detail-row.notes {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .detail-label {
                color: rgba(255, 255, 255, 0.7);
                font-weight: 600;
                font-size: 0.9rem;
            }

            .detail-value {
                color: white;
                font-weight: 700;
            }

            .detail-value.time-large {
                font-family: 'Space Grotesk', sans-serif;
                font-size: 1.25rem;
            }

            .status-done {
                color: #43e97b;
            }

            .status-pending {
                color: rgba(255, 255, 255, 0.6);
            }

            .status-in_progress {
                color: #f093fb;
            }
        </style>
    `;

    editLink.href = `/schedule/?date=${window.selectedDate}&edit=${eventId}`;

    modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

// Close modal on backdrop click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.active');
        modals.forEach(modal => modal.classList.remove('active'));
    }
});

// ============================================
// HELPER FUNCTIONS
// ============================================

function formatTime(date) {
    return date.toLocaleTimeString('en-ZA', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

function formatDate(date) {
    return date.toLocaleDateString('en-ZA', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    });
}

function formatDuration(minutes) {
    if (minutes < 60) {
        return `${minutes} minutes`;
    }
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (mins === 0) {
        return `${hours} hour${hours > 1 ? 's' : ''}`;
    }
    return `${hours}h ${mins}m`;
}

function formatStatus(status) {
    const statusMap = {
        'done': '✅ Completed',
        'pending': '⏳ Pending',
        'in_progress': '▶️ In Progress',
        'cancelled': '❌ Cancelled'
    };
    return statusMap[status] || status;
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1).replace('_', ' ');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// ANIMATIONS
// ============================================

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
    .timeline-event.animate-in {
        animation: slideInRight 0.4s ease forwards;
        opacity: 0;
    }

    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .timeline-event.done.animate-in {
        animation: slideInRight 0.4s ease forwards;
    }

    .timeline-event.done.animate-in {
        animation-name: slideInRightFaded;
    }

    @keyframes slideInRightFaded {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 0.7;
            transform: translateX(0);
        }
    }

    .touched {
        transform: scale(0.98) !important;
        opacity: 0.9;
    }
`;
document.head.appendChild(style);

console.log('📊 Timeline JS loaded successfully');
