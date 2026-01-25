/**
 * ============================================
 * RELATIVES CALENDAR - ULTIMATE JAVASCRIPT
 * The most magical calendar interactions ever
 * ============================================
 */

console.log('📅 Calendar JavaScript loading...');

// ============================================
// PARTICLE SYSTEM - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS, class is a no-op stub
// ============================================
class ParticleSystem {
    constructor(canvasId) {
        // Disabled - canvas hidden via CSS for performance
    }
}

// ============================================
// 3D TILT EFFECT
// ============================================
class TiltEffect {
    constructor(element) {
        this.element = element;
        this.width = element.offsetWidth;
        this.height = element.offsetHeight;
        this.settings = {
            max: 8,
            perspective: 1200,
            scale: 1.03,
            speed: 400,
            easing: 'cubic-bezier(0.03, 0.98, 0.52, 0.99)',
            glare: true
        };
        
        this.init();
    }
    
    init() {
        this.element.style.transform = 'perspective(1200px) rotateX(0deg) rotateY(0deg)';
        this.element.style.transition = `transform ${this.settings.speed}ms ${this.settings.easing}`;
        
        if (this.settings.glare && !this.element.querySelector('.tilt-glare')) {
            const glare = document.createElement('div');
            glare.className = 'tilt-glare';
            glare.style.cssText = `
                position: absolute;
                inset: 0;
                background: linear-gradient(135deg, 
                    rgba(255,255,255,0) 0%, 
                    rgba(255,255,255,0.1) 50%, 
                    rgba(255,255,255,0) 100%);
                opacity: 0;
                pointer-events: none;
                transition: opacity ${this.settings.speed}ms ${this.settings.easing};
                border-radius: inherit;
            `;
            this.element.style.position = 'relative';
            this.element.appendChild(glare);
        }
        
        this.element.addEventListener('mouseenter', () => this.onMouseEnter());
        this.element.addEventListener('mousemove', (e) => this.onMouseMove(e));
        this.element.addEventListener('mouseleave', () => this.onMouseLeave());
    }
    
    onMouseEnter() {
        this.width = this.element.offsetWidth;
        this.height = this.element.offsetHeight;
        
        const glare = this.element.querySelector('.tilt-glare');
        if (glare) glare.style.opacity = '1';
    }
    
    onMouseMove(e) {
        const rect = this.element.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        const percentX = (x / this.width) - 0.5;
        const percentY = (y / this.height) - 0.5;
        
        const tiltX = percentY * this.settings.max;
        const tiltY = -percentX * this.settings.max;
        
        this.element.style.transform = `
            perspective(${this.settings.perspective}px) 
            rotateX(${tiltX}deg) 
            rotateY(${tiltY}deg) 
            scale3d(${this.settings.scale}, ${this.settings.scale}, ${this.settings.scale})
        `;
        
        const glare = this.element.querySelector('.tilt-glare');
        if (glare) {
            const angle = Math.atan2(percentY, percentX) * (180 / Math.PI);
            glare.style.background = `
                linear-gradient(${angle + 45}deg, 
                    rgba(255,255,255,0) 0%, 
                    rgba(255,255,255,0.15) 50%, 
                    rgba(255,255,255,0) 100%)
            `;
        }
    }
    
    onMouseLeave() {
        this.element.style.transform = `
            perspective(${this.settings.perspective}px) 
            rotateX(0deg) 
            rotateY(0deg) 
            scale3d(1, 1, 1)
        `;
        
        const glare = this.element.querySelector('.tilt-glare');
        if (glare) glare.style.opacity = '0';
    }
}

// ============================================
// NUMBER ANIMATION
// ============================================
function animateNumber(element, start, end, duration) {
    const startTime = performance.now();
    const range = end - start;
    
    function updateNumber(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        const easeOutElastic = (t) => {
            const c4 = (2 * Math.PI) / 3;
            return t === 0 ? 0 : t === 1 ? 1 :
                Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * c4) + 1;
        };
        
        const current = Math.floor(start + range * easeOutElastic(progress));
        element.textContent = current;
        
        if (progress < 1) {
            requestAnimationFrame(updateNumber);
        } else {
            element.textContent = end;
        }
    }
    
    requestAnimationFrame(updateNumber);
}

// ============================================
// GLOBAL STATE
// ============================================
let selectedDate = null;
let draggedEvent = null;
let calendarView = 'month';
let currentWeekStart = null;
let currentDayDate = null;

// ============================================
// VIEW SWITCHING
// ============================================
function switchView(view) {
    calendarView = view;
    
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.closest('.view-btn').classList.add('active');
    
    document.querySelectorAll('.calendar-view').forEach(viewEl => {
        viewEl.classList.remove('active');
    });
    
    const viewElement = document.getElementById(`${view}-view`);
    if (viewElement) {
        viewElement.classList.add('active');
        viewElement.style.animation = 'fadeInUp 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
    }
    
    if (view === 'week') {
        loadWeekView();
    } else if (view === 'day') {
        loadDayView();
    }
    
    showToast(`Switched to ${view} view`, 'success');
}

// ============================================
// NAVIGATION
// ============================================
function navigateCalendar(direction) {
    if (calendarView === 'month') {
        changeMonth(direction);
    } else if (calendarView === 'week') {
        changeWeek(direction);
    } else if (calendarView === 'day') {
        changeDay(direction);
    }
}

async function changeMonth(direction) {
    let newMonth = window.currentMonth + direction;
    let newYear = window.currentYear;

    if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    } else if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    }

    const calendarBody = document.querySelector('.calendar-body');
    if (calendarBody) {
        calendarBody.style.animation = direction > 0
            ? 'slideOutLeft 0.3s ease forwards'
            : 'slideOutRight 0.3s ease forwards';
    }

    // Load new month via AJAX
    await loadMonthData(newYear, newMonth, direction > 0 ? 'slideInRight' : 'slideInLeft');
}

async function goToToday() {
    const today = new Date();

    if (calendarView === 'month') {
        const year = today.getFullYear();
        const month = today.getMonth() + 1;

        // If already on current month, just highlight today
        if (year === window.currentYear && month === window.currentMonth) {
            const todayEl = document.querySelector('.calendar-day.today');
            if (todayEl) {
                todayEl.style.animation = 'pulse 0.5s ease';
                setTimeout(() => todayEl.style.animation = '', 500);
            }
            return;
        }

        const calendarBody = document.querySelector('.calendar-body');
        if (calendarBody) {
            calendarBody.style.animation = 'fadeOut 0.2s ease forwards';
        }

        await loadMonthData(year, month, 'fadeIn');
    } else if (calendarView === 'week') {
        currentWeekStart = today;
        loadWeekView();
    } else if (calendarView === 'day') {
        currentDayDate = today;
        loadDayView(today);
    }
}

async function loadMonthData(year, month, animationIn) {
    try {
        // Fetch the calendar page for the new month
        const response = await fetch(`?year=${year}&month=${month}&ajax=1`);
        const html = await response.text();

        // Parse the HTML
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // Extract the calendar body content
        const newCalendarBody = doc.querySelector('.calendar-body');
        const newMonthDisplay = doc.querySelector('#currentMonthDisplay');
        const newEventsScript = doc.querySelector('script[data-events]');

        // Update the calendar body
        const calendarBody = document.querySelector('.calendar-body');
        if (calendarBody && newCalendarBody) {
            calendarBody.innerHTML = newCalendarBody.innerHTML;
            calendarBody.style.animation = `${animationIn} 0.3s ease forwards`;
        }

        // Update the month display
        const monthDisplay = document.getElementById('currentMonthDisplay');
        if (monthDisplay && newMonthDisplay) {
            monthDisplay.textContent = newMonthDisplay.textContent;
        }

        // Update global variables
        window.currentYear = year;
        window.currentMonth = month;

        // Update events array from new page
        const eventsDataEl = doc.getElementById('eventsData');
        if (eventsDataEl) {
            try {
                window.events = JSON.parse(eventsDataEl.textContent);
            } catch (e) {
                console.error('Failed to parse events data');
            }
        }

        // Re-initialize tilt effects on new elements
        document.querySelectorAll('.calendar-day[data-tilt]').forEach(card => {
            if (!card._tiltInitialized) {
                new TiltEffect(card);
                card._tiltInitialized = true;
            }
        });

    } catch (error) {
        console.error('Failed to load month:', error);
        // Fallback to page refresh
        window.location.href = `?year=${year}&month=${month}`;
    }
}

// ============================================
// WEEK VIEW - 3 ROW LAYOUT
// ============================================
function loadWeekView() {
    const today = currentWeekStart || new Date();

    const weekStart = new Date(today);
    weekStart.setDate(today.getDate() - today.getDay());
    currentWeekStart = weekStart;

    const weekDays = [];
    for (let i = 0; i < 7; i++) {
        const day = new Date(weekStart);
        day.setDate(weekStart.getDate() + i);
        weekDays.push(day);
    }

    // Always use 3-row layout
    render3RowWeekView(weekDays);

    loadWeekEvents(weekDays);

    const monthDisplay = document.getElementById('currentMonthDisplay');
    if (monthDisplay) {
        monthDisplay.textContent = currentWeekStart.toLocaleDateString('en-ZA', {
            month: 'long',
            year: 'numeric'
        });
    }
}

function render3RowWeekView(weekDays) {
    // Reorder: Mon, Tue, Wed, Thu, Fri, Sat, Sun
    // weekDays[0] = Sunday, weekDays[1] = Monday, etc.
    const orderedDays = [
        weekDays[1], weekDays[2], weekDays[3], // Mon, Tue, Wed
        weekDays[4], weekDays[5], weekDays[6], // Thu, Fri, Sat
        weekDays[0] // Sunday
    ];

    const weekView = document.getElementById('week-view');
    if (!weekView) return;

    // Create 3-row grid structure
    let gridHTML = '<div class="week-3row-grid">';

    // Row 1: Mon, Tue, Wed
    gridHTML += '<div class="week-row">';
    for (let i = 0; i < 3; i++) {
        const day = orderedDays[i];
        const isToday = day.toDateString() === new Date().toDateString();
        const dateStr = day.toISOString().split('T')[0];
        gridHTML += `
            <div class="week-day-card ${isToday ? 'today' : ''}"
                 data-date="${dateStr}"
                 onclick="selectDay('${dateStr}')">
                <div class="day-header-info">
                    <span class="day-name">${day.toLocaleDateString('en-ZA', { weekday: 'short' })}</span>
                    <span class="day-num">${day.getDate()}</span>
                </div>
                <div class="day-events-list" data-date="${dateStr}"></div>
            </div>
        `;
    }
    gridHTML += '</div>';

    // Row 2: Thu, Fri, Sat
    gridHTML += '<div class="week-row">';
    for (let i = 3; i < 6; i++) {
        const day = orderedDays[i];
        const isToday = day.toDateString() === new Date().toDateString();
        const dateStr = day.toISOString().split('T')[0];
        gridHTML += `
            <div class="week-day-card ${isToday ? 'today' : ''}"
                 data-date="${dateStr}"
                 onclick="selectDay('${dateStr}')">
                <div class="day-header-info">
                    <span class="day-name">${day.toLocaleDateString('en-ZA', { weekday: 'short' })}</span>
                    <span class="day-num">${day.getDate()}</span>
                </div>
                <div class="day-events-list" data-date="${dateStr}"></div>
            </div>
        `;
    }
    gridHTML += '</div>';

    // Row 3: Sunday (full width)
    gridHTML += '<div class="week-row sunday-row">';
    const sunday = orderedDays[6];
    const isSundayToday = sunday.toDateString() === new Date().toDateString();
    const sundayDateStr = sunday.toISOString().split('T')[0];
    gridHTML += `
        <div class="week-day-card ${isSundayToday ? 'today' : ''}"
             data-date="${sundayDateStr}"
             onclick="selectDay('${sundayDateStr}')">
            <div class="day-header-info">
                <span class="day-name">${sunday.toLocaleDateString('en-ZA', { weekday: 'long' })}</span>
                <span class="day-num">${sunday.getDate()}</span>
            </div>
            <div class="day-events-list" data-date="${sundayDateStr}"></div>
        </div>
    `;
    gridHTML += '</div>';

    gridHTML += '</div>';

    // Replace week view content
    weekView.innerHTML = gridHTML;
}

async function loadWeekEvents(weekDays) {
    const startDate = weekDays[0].toISOString().split('T')[0];
    const endDate = weekDays[6].toISOString().split('T')[0];
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_events_for_range');
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.events) {
            positionWeekEvents(data.events, weekDays);
        }
    } catch (error) {
        console.error('Error loading week events:', error);
    }
}

function positionWeekEvents(events, weekDays) {
    // Add events to 3-row week view day cards
    events.forEach(event => {
        const eventDate = new Date(event.starts_at);
        const dateStr = eventDate.toISOString().split('T')[0];

        const eventsList = document.querySelector(`.day-events-list[data-date="${dateStr}"]`);
        if (eventsList) {
            const eventEl = document.createElement('div');
            eventEl.className = 'week-mini-event';
            eventEl.style.background = event.color || '#667eea';
            eventEl.textContent = `${formatTime(event.starts_at)} ${event.title}`;
            eventEl.onclick = (e) => {
                e.stopPropagation();
                showEventDetails(event.id);
            };
            eventsList.appendChild(eventEl);
        }
    });
}

function changeWeek(direction) {
    if (!currentWeekStart) currentWeekStart = new Date();
    
    currentWeekStart.setDate(currentWeekStart.getDate() + (direction * 7));
    loadWeekView();
}

// ============================================
// DAY VIEW
// ============================================
function loadDayView(date = null) {
    const selectedDate = date ? new Date(date) : (currentDayDate || new Date());
    currentDayDate = selectedDate;
    
    const dayViewHeader = document.querySelector('.day-view-header');
    if (dayViewHeader) {
        dayViewHeader.innerHTML = `
            <div class="day-view-date">
                ${selectedDate.toLocaleDateString('en-ZA', { 
                    month: 'long', 
                    day: 'numeric',
                    year: 'numeric'
                })}
            </div>
            <div class="day-view-weekday">
                ${selectedDate.toLocaleDateString('en-ZA', { weekday: 'long' })}
            </div>
        `;
    }
    
    const dayTimeline = document.querySelector('.day-timeline');
    if (dayTimeline) {
        let timelineHTML = '';
        
        for (let hour = 0; hour <= 23; hour++) {
            const displayHour = hour === 0 ? 12 : (hour > 12 ? hour - 12 : hour);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            timelineHTML += `<div class="day-hour">${displayHour}:00 ${ampm}</div>`;
        }
        
        dayTimeline.innerHTML = timelineHTML;
    }
    
    const dayEventsColumn = document.querySelector('.day-events-column');
    if (dayEventsColumn) {
        let slotsHTML = '';
        const dateStr = selectedDate.toISOString().split('T')[0];
        
        for (let hour = 0; hour <= 23; hour++) {
            slotsHTML += `
                <div class="day-hour-slot" 
                     data-date="${dateStr}" 
                     data-hour="${hour}"
                     onclick="quickAddEvent('${dateStr}', ${hour})">
                </div>
            `;
        }
        
        dayEventsColumn.innerHTML = slotsHTML;
    }
    
    loadDayEvents(selectedDate);
    
    const monthDisplay = document.getElementById('currentMonthDisplay');
    if (monthDisplay) {
        monthDisplay.textContent = currentDayDate.toLocaleDateString('en-ZA', {
            month: 'long',
            year: 'numeric'
        });
    }
}

async function loadDayEvents(date) {
    const dateStr = date.toISOString().split('T')[0];
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_events_for_day');
        formData.append('date', dateStr);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.events) {
            positionDayEvents(data.events);
        }
    } catch (error) {
        console.error('Error loading day events:', error);
    }
}

function positionDayEvents(events) {
    const dayEventsColumn = document.querySelector('.day-events-column');
    if (!dayEventsColumn) return;

    const hourHeight = 60; // Height of each hour slot in pixels (from CSS .day-hour)

    events.forEach(event => {
        const startDate = new Date(event.starts_at);
        const endDate = new Date(event.ends_at);

        const startHour = startDate.getHours();
        const startMinutes = startDate.getMinutes();
        const endHour = endDate.getHours();
        const endMinutes = endDate.getMinutes();

        // Calculate position based on hour slots
        const startOffset = startHour + (startMinutes / 60);
        const endOffset = endHour + (endMinutes / 60);
        const top = startOffset * hourHeight;
        const height = Math.max((endOffset - startOffset) * hourHeight, 30); // Min height 30px
        
        const eventEl = document.createElement('div');
        eventEl.className = 'day-view-event';
        eventEl.style.cssText = `
            top: ${top}px;
            height: ${height}px;
            background: ${event.color};
        `;
        eventEl.innerHTML = `
            <div class="day-view-event-time">
                ${formatTime(event.starts_at)} - ${formatTime(event.ends_at)}
            </div>
            <div class="day-view-event-title">${event.title}</div>
            ${event.location ? `<div class="day-view-event-location">📍 ${event.location}</div>` : ''}
        `;
        eventEl.onclick = () => showEventDetails(event.id);
        
        dayEventsColumn.appendChild(eventEl);
    });
}

function changeDay(direction) {
    if (!currentDayDate) currentDayDate = new Date();
    
    currentDayDate.setDate(currentDayDate.getDate() + direction);
    loadDayView(currentDayDate);
}

// ============================================
// QUICK ADD EVENT
// ============================================
function quickAddEvent(dateStr, hour) {
    const startTime = hour.toString().padStart(2, '0') + ':00';
    const endTime = (hour + 1).toString().padStart(2, '0') + ':00';
    
    document.getElementById('eventStartDate').value = dateStr;
    document.getElementById('eventStartTime').value = startTime;
    document.getElementById('eventEndDate').value = dateStr;
    document.getElementById('eventEndTime').value = endTime;
    
    showCreateEventModal(dateStr);
}

// ============================================
// SELECT DAY
// ============================================
function selectDay(dateStr) {
    document.querySelectorAll('.calendar-day').forEach(day => {
        day.classList.remove('selected');
    });
    
    const dayEl = document.querySelector(`[data-date="${dateStr}"]`);
    if (dayEl) {
        dayEl.classList.add('selected');
        dayEl.style.animation = 'daySelect 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
        setTimeout(() => {
            dayEl.style.animation = '';
        }, 500);
    }
    
    selectedDate = dateStr;
    showDayEvents(dateStr);
}

function showDayEvents(dateStr) {
    const dayEvents = window.events.filter(e => {
        const eventDate = e.starts_at.split(' ')[0];
        return eventDate === dateStr;
    });

    // Store selected date for adding events
    window.selectedDayForEvent = dateStr;

    // Get the modal elements
    const titleEl = document.getElementById('dayEventsTitle');
    const listEl = document.getElementById('dayEventsList');

    titleEl.textContent = `📅 ${formatDate(dateStr)}`;

    if (dayEvents.length === 0) {
        listEl.innerHTML = `
            <div class="day-no-events">
                <div class="day-no-events-icon">📭</div>
                <p>No events on this day</p>
            </div>
        `;
    } else {
        listEl.innerHTML = dayEvents.map(event => `
            <div class="day-event-card">
                <div class="day-event-color" style="background: ${event.color};"></div>
                <div class="day-event-info" onclick="closeModal('dayEventsModal'); showEventDetails(${event.id});">
                    <div class="day-event-title">${escapeHtml(event.title)}</div>
                    <div class="day-event-time">
                        ${event.all_day ? '🌅 All day' : `⏰ ${formatTime(event.starts_at)}${event.ends_at ? ' - ' + formatTime(event.ends_at) : ''}`}
                        ${event.eventLocation ? ` • 📍 ${escapeHtml(event.eventLocation)}` : ''}
                    </div>
                    <span class="day-event-type">${getEventTypeLabel(event.kind)}</span>
                </div>
                <div class="day-event-actions">
                    <button class="day-event-action edit" onclick="closeModal('dayEventsModal'); openEditEvent(${event.id});" title="Edit">✏️</button>
                    <button class="day-event-action delete" onclick="confirmDeleteFromDay(${event.id});" title="Delete">🗑️</button>
                </div>
            </div>
        `).join('');
    }

    showModal('dayEventsModal');
}

function getEventTypeLabel(kind) {
    const labels = {
        // Calendar types
        birthday: '🎂 Birthday',
        anniversary: '💍 Anniversary',
        holiday: '🎉 Holiday',
        family_event: '👨‍👩‍👧‍👦 Family',
        date: '💕 Date',
        reminder: '🔔 Reminder',
        event: '📅 Event',
        // Schedule types
        work: '💼 Work',
        study: '📚 Study',
        church: '⛪ Church',
        focus: '🎯 Focus',
        break: '☕ Break',
        todo: '✅ To-Do'
    };
    return labels[kind] || '📅 Event';
}

function getPriorityLabel(priority) {
    const labels = {
        low: '🟢 Low',
        medium: '🟡 Medium',
        high: '🔴 High',
        urgent: '🚨 Urgent'
    };
    return labels[priority] || '🟡 Medium';
}

// Set duration from preset
function setDurationPreset(minutes) {
    const startTime = document.getElementById('eventStartTime').value;
    if (startTime) {
        const [hours, mins] = startTime.split(':').map(Number);
        const startDate = new Date();
        startDate.setHours(hours, mins, 0, 0);

        const endDate = new Date(startDate.getTime() + minutes * 60 * 1000);
        const endHours = String(endDate.getHours()).padStart(2, '0');
        const endMins = String(endDate.getMinutes()).padStart(2, '0');

        document.getElementById('eventEndTime').value = `${endHours}:${endMins}`;

        // Visual feedback
        showToast(`Duration set to ${minutes} minutes`, 'success');
    } else {
        showToast('Please set a start time first', 'error');
    }
}

// Set duration from preset (edit modal)
function setEditDurationPreset(minutes) {
    const startTime = document.getElementById('editEventStartTime').value;
    if (startTime) {
        const [hours, mins] = startTime.split(':').map(Number);
        const startDate = new Date();
        startDate.setHours(hours, mins, 0, 0);

        const endDate = new Date(startDate.getTime() + minutes * 60 * 1000);
        const endHours = String(endDate.getHours()).padStart(2, '0');
        const endMins = String(endDate.getMinutes()).padStart(2, '0');

        document.getElementById('editEventEndTime').value = `${endHours}:${endMins}`;

        showToast(`Duration set to ${minutes} minutes`, 'success');
    } else {
        showToast('Please set a start time first', 'error');
    }
}

function addEventForDay() {
    closeModal('dayEventsModal');
    showCreateEventModal(window.selectedDayForEvent || new Date().toISOString().split('T')[0]);
}

async function confirmDeleteFromDay(eventId) {
    if (confirm('Are you sure you want to delete this event?')) {
        try {
            await deleteEventById(eventId);
            showToast('Event deleted!', 'success');
            // Refresh the day events modal
            showDayEvents(window.selectedDayForEvent);
            // Also update the calendar
            removeEventFromCalendar(eventId);
        } catch (error) {
            showToast('Failed to delete event', 'error');
        }
    }
}

// ============================================
// EVENT TYPE COLORS AND SETTINGS
// ============================================
const eventTypeSettings = {
    // Calendar types
    birthday: { color: '#e74c3c', allDay: true, yearlyRepeat: true, hideTime: true },
    anniversary: { color: '#9b59b6', allDay: true, yearlyRepeat: true, hideTime: true },
    holiday: { color: '#f39c12', allDay: true, yearlyRepeat: false, hideTime: false },
    family_event: { color: '#2ecc71', allDay: false, yearlyRepeat: false, hideTime: false },
    date: { color: '#e91e63', allDay: false, yearlyRepeat: false, hideTime: false },
    reminder: { color: '#3498db', allDay: false, yearlyRepeat: false, hideTime: false },
    event: { color: '#3498db', allDay: false, yearlyRepeat: false, hideTime: false },
    // Schedule types
    work: { color: '#43e97b', allDay: false, yearlyRepeat: false, hideTime: false },
    study: { color: '#667eea', allDay: false, yearlyRepeat: false, hideTime: false },
    church: { color: '#9b59b6', allDay: false, yearlyRepeat: false, hideTime: false },
    focus: { color: '#4facfe', allDay: false, yearlyRepeat: false, hideTime: false },
    break: { color: '#feca57', allDay: false, yearlyRepeat: false, hideTime: false },
    todo: { color: '#f093fb', allDay: false, yearlyRepeat: false, hideTime: false }
};

// Duration presets for quick selection
const durationPresets = [
    { label: '15m', minutes: 15, icon: '⚡' },
    { label: '25m', minutes: 25, icon: '🍅' },
    { label: '30m', minutes: 30, icon: '⏱️' },
    { label: '45m', minutes: 45, icon: '⏰' },
    { label: '1h', minutes: 60, icon: '🕐' },
    { label: '90m', minutes: 90, icon: '📚' },
    { label: '2h', minutes: 120, icon: '🎯' }
];

// Priority colors
const priorityColors = {
    low: '#10b981',
    medium: '#f59e0b',
    high: '#ef4444',
    urgent: '#dc2626'
};

function onEventTypeChange(selectElement, isEdit = false) {
    const type = selectElement.value;
    const settings = eventTypeSettings[type] || { color: '#3498db', allDay: false, yearlyRepeat: false, hideTime: false };

    // Get element IDs based on create/edit mode
    const prefix = isEdit ? 'edit' : '';
    const allDayCheckbox = document.getElementById(isEdit ? 'editEventAllDay' : 'eventAllDay');
    const timeSectionId = isEdit ? 'editEventTimeSection' : 'eventTimeSection';
    const repeatSectionId = isEdit ? 'editEventRepeatSection' : 'eventRepeatSection';
    const recurrenceSelect = document.getElementById(isEdit ? 'editEventRecurrence' : 'eventRecurrence');
    const timeSection = document.getElementById(timeSectionId);
    const repeatSection = document.getElementById(repeatSectionId);

    // Handle birthday/anniversary: hide time section and set yearly repeat
    if (settings.hideTime) {
        // Hide time section completely
        if (timeSection) {
            timeSection.style.display = 'none';
        }
        // Hide repeat section (auto-set to yearly)
        if (repeatSection) {
            repeatSection.style.display = 'none';
        }
        // Set all day
        if (allDayCheckbox) {
            allDayCheckbox.checked = true;
        }
        // Set yearly repeat
        if (recurrenceSelect) {
            recurrenceSelect.value = 'yearly';
        }
    } else {
        // Show time section
        if (timeSection) {
            timeSection.style.display = 'block';
        }
        // Show repeat section
        if (repeatSection) {
            repeatSection.style.display = 'block';
        }
        // Reset all day if not a special all-day type
        if (allDayCheckbox && !settings.allDay) {
            allDayCheckbox.checked = false;
            if (isEdit) {
                toggleEditAllDay();
            } else {
                toggleAllDay();
            }
        }
    }

    // Set color
    const colorName = isEdit ? 'editEventColor' : 'eventColor';
    const colorRadios = document.querySelectorAll(`input[name="${colorName}"]`);
    colorRadios.forEach(radio => {
        if (radio.value === settings.color) {
            radio.checked = true;
        }
    });

    console.log('Event type changed to:', type, 'Settings:', settings);
}

// ============================================
// CREATE EVENT MODAL
// ============================================
function showCreateEventModal(preselectedDate = null) {
    const modal = document.getElementById('createEventModal');

    if (preselectedDate) {
        document.getElementById('eventStartDate').value = preselectedDate;
        document.getElementById('eventEndDate').value = preselectedDate;
    } else {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('eventStartDate').value = today;
    }

    const now = new Date();
    const startTime = new Date(now.getTime() + 60 * 60 * 1000);
    document.getElementById('eventStartTime').value =
        startTime.getHours().toString().padStart(2, '0') + ':00';

    const endTime = new Date(startTime.getTime() + 60 * 60 * 1000);
    document.getElementById('eventEndTime').value =
        endTime.getHours().toString().padStart(2, '0') + ':00';

    // Reset all day checkbox
    document.getElementById('eventAllDay').checked = false;
    toggleAllDay();

    showModal('createEventModal');
    document.getElementById('eventTitle').focus();
}

function toggleAllDay() {
    const allDay = document.getElementById('eventAllDay').checked;
    const startTime = document.getElementById('eventStartTime');
    const endTime = document.getElementById('eventEndTime');
    
    if (allDay) {
        startTime.disabled = true;
        endTime.disabled = true;
        startTime.style.opacity = '0.5';
        endTime.style.opacity = '0.5';
    } else {
        startTime.disabled = false;
        endTime.disabled = false;
        startTime.style.opacity = '1';
        endTime.style.opacity = '1';
    }
}

async function saveNewEvent() {
    console.log('saveNewEvent() called');

    try {
        const title = document.getElementById('eventTitle').value.trim();
        const startDate = document.getElementById('eventStartDate').value;
        const startTime = document.getElementById('eventStartTime').value;
        const endDate = document.getElementById('eventEndDate').value || startDate;
        const endTime = document.getElementById('eventEndTime').value || startTime;
        const allDay = document.getElementById('eventAllDay').checked ? 1 : 0;
        const locationEl = document.getElementById('eventLocation');
        const eventLocation = locationEl ? locationEl.value.trim() : '';
        const notes = document.getElementById('eventNotes').value.trim();
        const reminderMinutes = document.getElementById('eventReminder').value;
        const colorEl = document.querySelector('input[name="eventColor"]:checked');
        const color = colorEl ? colorEl.value : '#3498db';
        const kind = document.getElementById('eventKind').value;
        const recurrenceRule = document.getElementById('eventRecurrence').value;

        if (!title) {
            showToast('Please enter event title', 'error');
            return;
        }

        if (!startDate) {
            showToast('Please select a start date', 'error');
            return;
        }

        // For all-day events, don't require time
        const startsAt = allDay ? `${startDate} 00:00:00` : `${startDate} ${startTime || '00:00'}:00`;
        const endsAt = allDay ? `${endDate || startDate} 23:59:59` : `${endDate || startDate} ${endTime || startTime || '23:59'}:00`;

        console.log('Creating event:', { title, startsAt, endsAt, allDay, kind, color });

        const formData = new FormData();
        formData.append('action', 'create_event');
        formData.append('title', title);
        formData.append('notes', notes);
        formData.append('location', eventLocation);
        formData.append('starts_at', startsAt);
        formData.append('ends_at', endsAt);
        formData.append('all_day', allDay);
        formData.append('color', color);
        formData.append('reminder_minutes', reminderMinutes || '0');
        formData.append('kind', kind);
        formData.append('recurrence_rule', recurrenceRule || '');

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        // Check if response is OK
        if (!response.ok) {
            console.error('Server response not OK:', response.status, response.statusText);
            throw new Error(`Server error: ${response.status}`);
        }

        const text = await response.text();
        console.log('Raw response:', text);

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Failed to parse JSON:', text);
            throw new Error('Invalid server response');
        }

        if (data.success) {
            showToast('✓ Event created!', 'success');
            confetti();
            closeModal('createEventModal');

            // Add event to calendar in real-time
            addEventToCalendar({
                id: data.event_id || Date.now(),
                title: title,
                starts_at: startsAt,
                ends_at: endsAt,
                color: color,
                kind: kind,
                all_day: allDay
            });

            // Reset form
            document.getElementById('eventTitle').value = '';
            document.getElementById('eventNotes').value = '';
            if (locationEl) locationEl.value = '';

        } else {
            throw new Error(data.error || 'Failed to create event');
        }

    } catch (error) {
        console.error('Create event error:', error);
        showToast(error.message, 'error');
    }
}

// Add event to calendar without page reload
function addEventToCalendar(event) {
    console.log('Adding event to calendar:', event);

    const dateStr = event.starts_at.split(' ')[0];
    console.log('Looking for day cell with date:', dateStr);

    const dayCell = document.querySelector(`.calendar-day[data-date="${dateStr}"]`);
    console.log('Found day cell:', dayCell);

    if (dayCell) {
        let eventsContainer = dayCell.querySelector('.day-events');
        if (!eventsContainer) {
            console.log('Creating new events container');
            eventsContainer = document.createElement('div');
            eventsContainer.className = 'day-events';
            dayCell.appendChild(eventsContainer);
        }

        const eventEl = document.createElement('div');
        eventEl.className = 'day-event';
        eventEl.style.background = event.color || '#3498db';
        eventEl.textContent = event.title;
        eventEl.dataset.eventId = event.id;
        eventEl.onclick = (e) => {
            e.stopPropagation();
            showEventDetails(event.id);
        };

        // Add with animation
        eventEl.style.animation = 'fadeInUp 0.3s ease';
        eventsContainer.appendChild(eventEl);
        console.log('Event added to calendar successfully');

        // Add to window.events array for details view
        if (window.events) {
            // Add full event data for details modal
            window.events.push({
                ...event,
                full_name: window.currentUser?.name || 'You',
                avatar_color: '#667eea',
                created_at: new Date().toISOString().replace('T', ' ').substring(0, 19)
            });
        }
    } else {
        console.log('Day cell not found for date:', dateStr, '- event may be in a different month');
    }
}

// Update event on calendar without page reload
function updateEventOnCalendar(event) {
    console.log('Updating event on calendar:', event);

    // Remove old event element
    const oldEventEl = document.querySelector(`.day-event[data-event-id="${event.id}"]`);
    if (oldEventEl) {
        oldEventEl.remove();
    }

    // Update in window.events array
    if (window.events) {
        const index = window.events.findIndex(e => e.id == event.id);
        if (index !== -1) {
            window.events[index] = { ...window.events[index], ...event };
        }
    }

    // Add to new location
    addEventToCalendar(event);
}

// Remove event from calendar without page reload
function removeEventFromCalendar(eventId) {
    console.log('Removing event from calendar:', eventId);

    const eventEl = document.querySelector(`.day-event[data-event-id="${eventId}"]`);
    if (eventEl) {
        eventEl.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => eventEl.remove(), 300);
    }

    // Remove from window.events array
    if (window.events) {
        const index = window.events.findIndex(e => e.id == eventId);
        if (index !== -1) {
            window.events.splice(index, 1);
        }
    }
}

// ============================================
// SHOW EVENT DETAILS
// ============================================
function showEventDetails(eventId) {
    const event = window.events.find(e => e.id == eventId);
    
    if (!event) return;
    
    const start = new Date(event.starts_at);
    const end = new Date(event.ends_at);
    const duration = Math.round((end - start) / (1000 * 60));
    
    const detailsContent = document.getElementById('eventDetailsContent');
    detailsContent.innerHTML = `
        <div style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 30px;">
                <div style="flex: 1;">
                    <div style="display: inline-block; padding: 8px 16px; border-radius: 12px; background: ${event.color}; color: white; font-size: 12px; font-weight: 800; margin-bottom: 15px;">
                        ${event.kind.toUpperCase()}
                    </div>
                    <h3 style="font-size: 28px; color: #333; margin-bottom: 10px; font-weight: 900;">
                        ${event.title}
                    </h3>
                </div>
            </div>
            
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); padding: 25px; border-radius: 16px; margin-bottom: 25px; color: white;">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 12px; opacity: 0.9; margin-bottom: 5px; font-weight: 700;">START</div>
                        <div style="font-size: 20px; font-weight: 800;">
                            ${formatDate(event.starts_at)}
                        </div>
                        <div style="font-size: 16px; opacity: 0.9;">
                            ${event.all_day ? 'All day' : formatTime(event.starts_at)}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 12px; opacity: 0.9; margin-bottom: 5px; font-weight: 700;">END</div>
                        <div style="font-size: 20px; font-weight: 800;">
                            ${formatDate(event.ends_at)}
                        </div>
                        <div style="font-size: 16px; opacity: 0.9;">
                            ${event.all_day ? 'All day' : formatTime(event.ends_at)}
                        </div>
                    </div>
                </div>
                ${!event.all_day ? `
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.3);">
                        <div style="font-size: 14px; opacity: 0.9;">
                            ⏱️ Duration: ${Math.floor(duration / 60)}h ${duration % 60}m
                        </div>
                    </div>
                ` : ''}
            </div>
            
            ${event.location ? `
                <div style="margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 14px;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 700;">📍 LOCATION</div>
                    <div style="font-size: 16px; color: #333; font-weight: 600;">
                        ${event.location}
                    </div>
                    <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(event.location)}" 
                       target="_blank"
                       style="display: inline-block; margin-top: 10px; color: #667eea; text-decoration: none; font-size: 14px; font-weight: 700;">
                        Open in Google Maps →
                    </a>
                </div>
            ` : ''}
            
            ${event.notes ? `
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 10px; font-weight: 700;">📝 NOTES</div>
                    <div style="color: #333; line-height: 1.8; white-space: pre-wrap;">
                        ${event.notes}
                    </div>
                </div>
            ` : ''}
            
            ${event.reminder_minutes ? `
                <div style="margin-bottom: 20px; padding: 15px; background: #fff3cd; border-radius: 12px; border-left: 4px solid #f39c12;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">⏰</span>
                        <div>
                            <div style="font-weight: 700; color: #333;">Reminder set</div>
                            <div style="font-size: 14px; color: #666;">
                                ${event.reminder_minutes} minutes before event
                            </div>
                        </div>
                    </div>
                </div>
            ` : ''}
            
            <div style="margin-bottom: 25px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
                <div style="font-size: 14px; color: #666; margin-bottom: 12px; font-weight: 700;">CREATED BY</div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: ${event.avatar_color}; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        ${event.full_name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <div style="font-weight: 700; color: #333; font-size: 16px;">${event.full_name}</div>
                        <div style="font-size: 13px; color: #666;">
                            Created ${formatDate(event.created_at)} at ${formatTime(event.created_at)}
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <button onclick="openEditEvent(${event.id})" class="btn btn-primary" style="flex: 1; min-width: 100px;">
                    <span class="btn-icon">✏️</span>
                    <span class="btn-text">Edit</span>
                </button>
                <button onclick="shareEvent(${event.id})" class="btn btn-secondary" style="flex: 1; min-width: 100px;">
                    <span class="btn-icon">📤</span>
                    <span class="btn-text">Share</span>
                </button>
                <button onclick="deleteEvent(${event.id})" class="btn" style="flex: 1; min-width: 100px; background: #f56565; color: white;">
                    <span class="btn-icon">🗑️</span>
                    <span class="btn-text">Delete</span>
                </button>
            </div>
        </div>
    `;
    
    showModal('eventDetailsModal');
}

async function deleteEvent(eventId) {
    if (!confirm('Delete this event?')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_event');
        formData.append('event_id', eventId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Event deleted', 'success');
            closeModal('eventDetailsModal');

            // Remove event from calendar in real-time
            removeEventFromCalendar(eventId);

        } else {
            throw new Error(data.error || 'Failed to delete event');
        }

    } catch (error) {
        console.error('Delete event error:', error);
        showToast(error.message, 'error');
    }
}

function shareEvent(eventId) {
    const event = window.events.find(e => e.id == eventId);

    if (!event) return;

    const shareText = `📅 ${event.title}\n` +
                     `📍 ${event.location || 'No location'}\n` +
                     `🕐 ${formatDate(event.starts_at)} at ${formatTime(event.starts_at)}\n` +
                     `${event.notes ? '\n📝 ' + event.notes : ''}`;

    if (navigator.share) {
        navigator.share({
            title: event.title,
            text: shareText
        }).then(() => {
            showToast('Event shared!', 'success');
        }).catch(err => {
            console.log('Share cancelled');
        });
    } else {
        navigator.clipboard.writeText(shareText).then(() => {
            showToast('Event details copied to clipboard!', 'success');
        }).catch(() => {
            showToast('Failed to copy', 'error');
        });
    }
}

function openEditEvent(eventId) {
    const event = window.events.find(e => e.id == eventId);
    if (!event) {
        showToast('Event not found', 'error');
        return;
    }

    closeModal('eventDetailsModal');

    document.getElementById('editEventId').value = event.id;
    document.getElementById('editEventTitle').value = event.title;
    document.getElementById('editEventNotes').value = event.notes || '';

    const editLocationEl = document.getElementById('editEventLocation');
    if (editLocationEl) editLocationEl.value = event.location || '';

    // Parse dates and times
    const startDate = event.starts_at.split(' ')[0];
    const startTime = event.starts_at.split(' ')[1]?.substring(0, 5) || '00:00';
    const endDate = event.ends_at.split(' ')[0];
    const endTime = event.ends_at.split(' ')[1]?.substring(0, 5) || '23:59';

    document.getElementById('editEventStartDate').value = startDate;
    document.getElementById('editEventStartTime').value = startTime;
    document.getElementById('editEventEndDate').value = endDate;
    document.getElementById('editEventEndTime').value = endTime;

    document.getElementById('editEventAllDay').checked = event.all_day == 1;
    toggleEditAllDay();

    document.getElementById('editEventKind').value = event.kind || 'event';
    document.getElementById('editEventRecurrence').value = event.recurrence_rule || '';
    document.getElementById('editEventReminder').value = event.reminder_minutes || '0';

    // Set color
    const colorRadios = document.querySelectorAll('input[name="editEventColor"]');
    colorRadios.forEach(radio => {
        radio.checked = (radio.value === event.color);
    });
    // If no match, select first
    if (!document.querySelector('input[name="editEventColor"]:checked')) {
        colorRadios[0].checked = true;
    }

    showModal('editEventModal');
}

function toggleEditAllDay() {
    const allDay = document.getElementById('editEventAllDay').checked;
    const startTime = document.getElementById('editEventStartTime');
    const endTime = document.getElementById('editEventEndTime');

    if (allDay) {
        startTime.disabled = true;
        endTime.disabled = true;
        startTime.style.opacity = '0.5';
        endTime.style.opacity = '0.5';
    } else {
        startTime.disabled = false;
        endTime.disabled = false;
        startTime.style.opacity = '1';
        endTime.style.opacity = '1';
    }
}

async function saveEventChanges() {
    console.log('saveEventChanges() called');

    try {
        const eventId = document.getElementById('editEventId').value;
        const title = document.getElementById('editEventTitle').value.trim();
        const startDate = document.getElementById('editEventStartDate').value;
        const startTime = document.getElementById('editEventStartTime').value;
        const endDate = document.getElementById('editEventEndDate').value || startDate;
        const endTime = document.getElementById('editEventEndTime').value || startTime;
        const allDay = document.getElementById('editEventAllDay').checked ? 1 : 0;
        const editLocationEl = document.getElementById('editEventLocation');
        const eventLocation = editLocationEl ? editLocationEl.value.trim() : '';
        const notes = document.getElementById('editEventNotes').value.trim();
        const kind = document.getElementById('editEventKind').value;
        const recurrenceRule = document.getElementById('editEventRecurrence').value;
        const reminderMinutes = document.getElementById('editEventReminder').value;
        const color = document.querySelector('input[name="editEventColor"]:checked').value;

        if (!title) {
            showToast('Please enter event title', 'error');
            return;
        }

        const startsAt = allDay ? `${startDate} 00:00:00` : `${startDate} ${startTime}:00`;
        const endsAt = allDay ? `${endDate} 23:59:59` : `${endDate} ${endTime}:00`;

        const formData = new FormData();
        formData.append('action', 'update_event');
        formData.append('event_id', eventId);
        formData.append('title', title);
        formData.append('notes', notes);
        formData.append('location', eventLocation);
        formData.append('starts_at', startsAt);
        formData.append('ends_at', endsAt);
        formData.append('all_day', allDay);
        formData.append('color', color);
        formData.append('reminder_minutes', reminderMinutes);
        formData.append('kind', kind);
        formData.append('recurrence_rule', recurrenceRule);

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showToast('✓ Event updated!', 'success');
            closeModal('editEventModal');

            // Update event on calendar in real-time
            updateEventOnCalendar({
                id: eventId,
                title: title,
                starts_at: startsAt,
                ends_at: endsAt,
                color: color,
                kind: kind,
                all_day: allDay,
                notes: notes,
                location: eventLocation
            });

        } else {
            throw new Error(data.error || 'Failed to update event');
        }

    } catch (error) {
        console.error('Update event error:', error);
        showToast(error.message, 'error');
    }
}

function syncGoogleCalendar() {
    showToast('🔄 Google Calendar sync coming soon!', 'info');
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        const content = modal.querySelector('.modal-content');
        content.style.animation = 'modalExit 0.4s ease forwards';
        
        setTimeout(() => {
            modal.classList.remove('active');
            document.body.style.overflow = '';
            content.style.animation = '';
        }, 400);
    }
}

document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        const modalId = e.target.id;
        if (modalId) {
            closeModal(modalId);
        } else {
            e.target.remove();
        }
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            if (modal.id) {
                closeModal(modal.id);
            } else {
                modal.remove();
            }
        });
    }
});

// ============================================
// CONFETTI
// ============================================
function confetti() {
    for (let i = 0; i < 150; i++) {
        createConfettiPiece();
    }
}

function createConfettiPiece() {
    const confetti = document.createElement('div');
    const colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#00f2fe', '#43e97b', '#f39c12', '#e74c3c'];
    
    confetti.style.cssText = `
        position: fixed;
        width: ${5 + Math.random() * 10}px;
        height: ${5 + Math.random() * 10}px;
        background: ${colors[Math.floor(Math.random() * colors.length)]};
        top: -10px;
        left: ${Math.random() * 100}%;
        border-radius: ${Math.random() > 0.5 ? '50%' : '0'};
        animation: confettiFall ${2 + Math.random() * 3}s linear forwards;
        z-index: 9999;
        opacity: ${0.5 + Math.random() * 0.5};
    `;
    
    document.body.appendChild(confetti);
    setTimeout(() => confetti.remove(), 5000);
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================
function showToast(message, type = 'info') {
    document.querySelectorAll('.calendar-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `calendar-toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);

    // Errors stay longer (6 seconds), success/info 3 seconds
    const duration = type === 'error' ? 6000 : 3000;
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, duration);

    // Also log errors to console
    if (type === 'error') {
        console.error('Calendar Error:', message);
    }
}

// ============================================
// FORMATTING HELPERS
// ============================================
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-ZA', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

function formatTime(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleTimeString('en-ZA', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: true
    });
}

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    console.log('📅 Calendar System Initialized');
    
    new ParticleSystem('particles');
    
    document.querySelectorAll('.stat-value[data-count]').forEach(element => {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !element.classList.contains('animated')) {
                    const target = parseInt(element.dataset.count);
                    animateNumber(element, 0, target, 2000);
                    element.classList.add('animated');
                }
            });
        });
        observer.observe(element);
    });
    
    document.querySelectorAll('[data-tilt]').forEach(card => new TiltEffect(card));

    const heroMonth = document.querySelector('.hero-month');
    if (heroMonth) {
        const text = heroMonth.textContent;
        heroMonth.textContent = '';
        text.split('').forEach((char, index) => {
            const span = document.createElement('span');
            span.textContent = char;
            span.style.display = 'inline-block';
            span.style.animation = `letterPop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) ${index * 0.05}s backwards`;
            heroMonth.appendChild(span);
        });
    }

    // Re-render week view on window resize (for mobile/desktop switch)
    let resizeTimeout;
    let lastWidth = window.innerWidth;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            const currentWidth = window.innerWidth;
            const crossedMobileThreshold = (lastWidth <= 480) !== (currentWidth <= 480);
            if (crossedMobileThreshold && calendarView === 'week') {
                loadWeekView();
            }
            lastWidth = currentWidth;
        }, 250);
    });

    console.log('✅ Calendar Page Initialized');
});

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function deleteEventById(eventId) {
    const formData = new FormData();
    formData.append('action', 'delete_event');
    formData.append('event_id', eventId);

    const response = await fetch('', {
        method: 'POST',
        body: formData
    });

    const data = await response.json();

    if (!data.success) {
        throw new Error(data.error || 'Failed to delete event');
    }

    return data;
}

// ============================================
// EXPORT FUNCTIONS TO GLOBAL SCOPE
// ============================================
window.showDayEvents = showDayEvents;
window.addEventForDay = addEventForDay;
window.confirmDeleteFromDay = confirmDeleteFromDay;
window.getEventTypeLabel = getEventTypeLabel;

console.log('✅ Calendar JavaScript loaded');