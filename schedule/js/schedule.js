/**
 * ============================================
 * RELATIVES SCHEDULE - ENHANCED VERSION
 * With Focus Mode, Productivity & Smart Features
 * Complete implementation matching notes.js structure
 * ============================================ */

console.log('%c⏰ Enhanced Schedule Loading v3.0...', 'font-size: 16px; font-weight: bold; color: #667eea;');

// ============================================
// PARTICLE SYSTEM - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS, class is a no-op stub
// ============================================
class ParticleSystem {
    constructor(canvasId) {
        // Disabled - canvas hidden via CSS for performance
    }
    destroy() {}
}

// ============================================
// FOCUS MODE MANAGER
// ============================================
class FocusModeManager {
    constructor() {
        this.activeSession = null;
        this.timerInterval = null;
        this.startTime = null;
        this.notificationsBlocked = false;
    }
    
    start(eventId, duration, blockNotifications = true) {
        this.activeSession = {
            eventId: eventId,
            duration: duration * 60, // Convert to seconds
            blockNotifications: blockNotifications
        };
        
        this.startTime = Date.now();
        
        if (blockNotifications) {
            this.blockNotifications();
        }
        
        this.startTimer();
        this.showFocusOverlay();
        
        // Request full screen on mobile
        if (this.isMobile() && document.documentElement.requestFullscreen) {
            document.documentElement.requestFullscreen().catch(err => {
                console.log('Fullscreen request failed:', err);
            });
        }
    }
    
    startTimer() {
        const updateTimer = () => {
            if (!this.activeSession) return;
            
            const elapsed = Math.floor((Date.now() - this.startTime) / 1000);
            const remaining = this.activeSession.duration - elapsed;
            
            if (remaining <= 0) {
                this.complete();
                return;
            }
            
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            const display = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            const timerEl = document.getElementById('focusTimer');
            if (timerEl) {
                timerEl.textContent = display;
            }
            
            // Update title for visibility
            document.title = `⏱️ ${display} - Focus Mode`;
        };
        
        updateTimer();
        this.timerInterval = setInterval(updateTimer, 1000);
    }
    
    async complete() {
        if (!this.activeSession) return;
        
        this.stopTimer();
        
        // Show rating modal
        const eventId = this.activeSession.eventId;
        this.activeSession = null;
        
        document.getElementById('ratingEventId').value = eventId;
        showModal('ratingModal');
        
        this.hideFocusOverlay();
        this.unblockNotifications();
        
        // Exit fullscreen
        if (document.fullscreenElement) {
            document.exitFullscreen();
        }
        
        document.title = 'Schedule - Relatives';
        
        // Play completion sound
        this.playCompletionSound();
    }
    
    async pause() {
        if (!this.activeSession) return;
        
        this.stopTimer();
        showToast('Focus session paused', 'info');
    }
    
    async end(eventId) {
        this.stopTimer();
        this.hideFocusOverlay();
        this.unblockNotifications();
        
        if (document.fullscreenElement) {
            document.exitFullscreen();
        }
        
        document.title = 'Schedule - Relatives';
        
        this.activeSession = null;
        
        // Show rating modal
        document.getElementById('ratingEventId').value = eventId;
        showModal('ratingModal');
    }
    
    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }
    
    showFocusOverlay() {
        const overlay = document.getElementById('focusModeOverlay');
        if (overlay) {
            overlay.style.display = 'flex';
            setTimeout(() => overlay.classList.add('active'), 10);
        }
    }
    
    hideFocusOverlay() {
        const overlay = document.getElementById('focusModeOverlay');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.style.display = 'none', 300);
        }
    }
    
    blockNotifications() {
        this.notificationsBlocked = true;
    }
    
    unblockNotifications() {
        this.notificationsBlocked = false;
    }
    
    playCompletionSound() {
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBTGH0fPTgjMGHm7A7+OZSA0PVqzn77BdGAg+ltryxnMpBSuBzvLZiTgIF2m98OScTgwOUKXh8bllHAU7k9r0yXkqBSh+zPDdkEAKFF601+ytVhQJRp/f8r1sIQU');
            audio.volume = 0.3;
            audio.play();
        } catch (err) {
            console.log('Sound play failed:', err);
        }
    }
    
    isMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }
}

const focusManager = new FocusModeManager();

// ============================================
// API HELPER (Same pattern as notes)
// ============================================
async function apiCall(endpoint, data = {}, method = 'POST') {
    const maxRetries = 2;
    let lastError = null;
    
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
        try {
            const options = { 
                method: method,
                credentials: 'same-origin'
            };
            
            if (method === 'POST') {
                const formData = new FormData();
                for (const key in data) {
                    if (data[key] !== null && data[key] !== undefined) {
                        formData.append(key, data[key]);
                    }
                }
                options.body = formData;
            }
            
            const url = method === 'GET' && Object.keys(data).length > 0
                ? endpoint + '?' + new URLSearchParams(data)
                : endpoint;
            
            const response = await fetch(url, options);
            
            if (!response.ok) {
                if (response.status === 401) {
                    showToast('Session expired. Please login again.', 'error');
                    setTimeout(() => window.location.href = '/login.php', 2000);
                    throw new Error('Session expired');
                }
                
                if (response.status === 403) throw new Error('Access denied');
                if (response.status === 404) throw new Error('Resource not found');
                if (response.status >= 500) throw new Error('Server error. Please try again.');
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Request failed');
            }
            
            return result;
            
        } catch (error) {
            lastError = error;
            
            if (error.message.includes('Session expired') || error.message.includes('Access denied')) {
                throw error;
            }
            
            if (attempt < maxRetries) {
                console.warn(`API call failed, retrying (${attempt + 1}/${maxRetries})...`);
                await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));
                continue;
            }
            
            throw error;
        }
    }
    
    throw lastError;
}

// ============================================
// ADD EVENT
// ============================================
async function addEvent(event) {
    if (event) event.preventDefault();
    
    const titleInput = document.getElementById('eventTitle');
    const dateInput = document.getElementById('eventDate');
    const startInput = document.getElementById('eventStart');
    const endInput = document.getElementById('eventEnd');
    const typeSelect = document.getElementById('eventType');
    const reminderCheckbox = document.getElementById('enableReminder');
    const reminderMinutesInput = document.getElementById('reminderMinutes');
    const recurringCheckbox = document.getElementById('enableRecurring');
    const repeatRuleSelect = document.getElementById('repeatRule');
    const focusModeCheckbox = document.getElementById('focusMode');

    const title = titleInput.value.trim();
    const eventDate = dateInput.value;
    const start = startInput.value;
    const end = endInput.value;
    const type = typeSelect.value;
    const reminderMinutes = reminderCheckbox.checked ? parseInt(reminderMinutesInput.value || 15) : 0;
    const repeatRule = recurringCheckbox.checked ? repeatRuleSelect.value : null;
    const focusMode = focusModeCheckbox.checked ? 1 : 0;

    // Get selected color
    const colorRadio = document.querySelector('input[name="eventColor"]:checked');
    const color = colorRadio ? colorRadio.value : '#667eea';

    if (!title || !eventDate || !start || !end) {
        showToast('Please fill all required fields', 'error');
        return;
    }

    // Validate times
    if (start >= end) {
        showToast('End time must be after start time', 'error');
        return;
    }
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="btn-icon">⏳</span><span class="btn-text">Adding...</span>';
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'add',
            title: title,
            date: eventDate,
            start_time: start,
            end_time: end,
            kind: type,
            reminder_minutes: reminderMinutes,
            repeat_rule: repeatRule,
            focus_mode: focusMode,
            color: color
        });
        
        if (result.conflict) {
            const conflictEvent = result.conflict;
            const conflictStart = new Date(conflictEvent.starts_at).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
            const conflictEnd = new Date(conflictEvent.ends_at).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
            
            showToast(`⚠️ Time conflict with "${conflictEvent.title}" (${conflictStart} - ${conflictEnd})`, 'warning');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            return;
        }
        
        showToast(`✓ Added "${title}"!`, 'success');
        
        titleInput.value = '';
        reminderCheckbox.checked = false;
        recurringCheckbox.checked = false;
        focusModeCheckbox.checked = false;
        toggleReminderInput();
        toggleRecurringInput();
        titleInput.focus();
        
        addEventToDOM(result.event);
        updateStats();
        
        // Show tips for first event
        if (window.ScheduleApp.allEvents.length === 0) {
            setTimeout(() => {
                showToast('💡 Tip: Enable Focus Mode for distraction-free work!', 'info');
            }, 2000);
        }
        
    } catch (error) {
        showToast(error.message || 'Failed to add event', 'error');
        console.error('Add event error:', error);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
}

// ============================================
// TOGGLE EVENT
// ============================================
async function toggleEvent(eventId) {
    const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
    if (!eventCard) {
        console.warn('Event card not found:', eventId);
        return;
    }
    
    const checkbox = eventCard.querySelector('.event-checkbox input[type="checkbox"]');
    if (!checkbox) {
        console.warn('Checkbox not found for event:', eventId);
        return;
    }
    
    const wasChecked = checkbox.checked;
    
    // Optimistic UI update
    if (wasChecked) {
        eventCard.classList.add('done');
    } else {
        eventCard.classList.remove('done');
    }
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'toggle',
            event_id: eventId
        });
        
        // Verify status matches
        if (result.status === 'done') {
            eventCard.classList.add('done');
            checkbox.checked = true;
            
            // Celebration animation
            createConfetti(eventCard);
        } else {
            eventCard.classList.remove('done');
            checkbox.checked = false;
        }
        
        updateStats();
        
    } catch (error) {
        // Revert on error
        checkbox.checked = !wasChecked;
        if (wasChecked) {
            eventCard.classList.remove('done');
        } else {
            eventCard.classList.add('done');
        }
        
        showToast(error.message || 'Failed to update event', 'error');
        console.error('Toggle error:', error);
    }
}

// ============================================
// DELETE EVENT
// ============================================
async function deleteEvent(eventId) {
    const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
    if (!eventCard) {
        console.warn('Event card not found:', eventId);
        return;
    }
    
    const eventName = eventCard.getAttribute('data-item-name') || 
                      eventCard.querySelector('.note-title')?.textContent.trim() || 
                      'this event';
    
    if (!confirm(`Delete "${eventName}"?`)) {
        return;
    }
    
    // Optimistic UI update
    eventCard.style.opacity = '0';
    eventCard.style.transform = 'translateX(-100px)';
    
    try {
        await apiCall(window.ScheduleApp.API.events, {
            action: 'delete',
            event_id: eventId
        });
        
        showToast(`Deleted "${eventName}"`, 'success');
        
        // Remove from DOM after animation
        setTimeout(() => {
            eventCard.remove();
            updateStats();
            
            // Check if we need to show empty state
            const remainingEvents = document.querySelectorAll('.event-card');
            if (remainingEvents.length === 0) {
                showEmptyState();
            }
        }, 300);
        
    } catch (error) {
        // Revert on error
        eventCard.style.opacity = '1';
        eventCard.style.transform = 'translateX(0)';
        
        showToast(error.message || 'Failed to delete event', 'error');
        console.error('Delete error:', error);
    }
}

// ============================================
// EDIT EVENT
// ============================================
function editEvent(eventId) {
    const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
    if (!eventCard) {
        showToast('Event not found', 'error');
        return;
    }
    
    // Get event data from window.ScheduleApp.allEvents
    const eventData = window.ScheduleApp.allEvents.find(e => e.id == eventId);
    
    if (!eventData) {
        showToast('Event data not found', 'error');
        return;
    }
    
    // Parse date and times
    const startTime = new Date(eventData.starts_at);
    const endTime = new Date(eventData.ends_at);
    const eventDate = startTime.toISOString().split('T')[0];

    // Populate form
    document.getElementById('editEventId').value = eventId;
    document.getElementById('editEventTitle').value = eventData.title || '';
    document.getElementById('editEventDate').value = eventDate;
    document.getElementById('editEventStart').value = startTime.toTimeString().slice(0, 5);
    document.getElementById('editEventEnd').value = endTime.toTimeString().slice(0, 5);
    document.getElementById('editEventType').value = eventData.kind || 'todo';
    document.getElementById('editEventNotes').value = eventData.notes || '';
    document.getElementById('editEventAssign').value = eventData.assigned_to || '';

    // Populate reminder fields
    const hasReminder = eventData.reminder_minutes && eventData.reminder_minutes > 0;
    document.getElementById('editEnableReminder').checked = hasReminder;
    document.getElementById('editReminderMinutes').value = eventData.reminder_minutes || 15;
    document.getElementById('editReminderMinutes').style.display = hasReminder ? 'block' : 'none';

    // Populate recurring fields
    const hasRecurring = eventData.repeat_rule && eventData.repeat_rule !== '';
    document.getElementById('editEnableRecurring').checked = hasRecurring;
    document.getElementById('editRepeatRule').value = eventData.repeat_rule || 'weekly';
    document.getElementById('editRepeatRule').style.display = hasRecurring ? 'block' : 'none';

    // Populate focus mode
    document.getElementById('editFocusMode').checked = eventData.focus_mode == 1;

    // Populate color picker
    const eventColor = eventData.color || '#667eea';
    const colorRadios = document.querySelectorAll('input[name="editEventColor"]');
    colorRadios.forEach(radio => {
        radio.checked = (radio.value === eventColor);
    });

    showModal('editEventModal');
}

async function saveEditedEvent(event) {
    if (event) event.preventDefault();

    const eventId = document.getElementById('editEventId').value;
    const title = document.getElementById('editEventTitle').value.trim();
    const eventDate = document.getElementById('editEventDate').value;
    const start = document.getElementById('editEventStart').value;
    const end = document.getElementById('editEventEnd').value;
    const type = document.getElementById('editEventType').value;
    const notes = document.getElementById('editEventNotes').value.trim();
    const assignedTo = document.getElementById('editEventAssign').value;

    // Get new fields
    const reminderCheckbox = document.getElementById('editEnableReminder');
    const reminderMinutesInput = document.getElementById('editReminderMinutes');
    const recurringCheckbox = document.getElementById('editEnableRecurring');
    const repeatRuleSelect = document.getElementById('editRepeatRule');
    const focusModeCheckbox = document.getElementById('editFocusMode');

    const reminderMinutes = reminderCheckbox.checked ? parseInt(reminderMinutesInput.value || 15) : 0;
    const repeatRule = recurringCheckbox.checked ? repeatRuleSelect.value : null;
    const focusMode = focusModeCheckbox.checked ? 1 : 0;

    // Get selected color
    const colorRadio = document.querySelector('input[name="editEventColor"]:checked');
    const color = colorRadio ? colorRadio.value : '#667eea';

    if (!title || !eventDate || !start || !end) {
        showToast('Please fill all required fields', 'error');
        return;
    }

    // Validate times
    if (start >= end) {
        showToast('End time must be after start time', 'error');
        return;
    }

    // Show loading
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';

    try {
        await apiCall(window.ScheduleApp.API.events, {
            action: 'update',
            event_id: eventId,
            title: title,
            date: eventDate,
            start_time: start,
            end_time: end,
            kind: type,
            notes: notes || null,
            assigned_to: assignedTo || null,
            reminder_minutes: reminderMinutes,
            repeat_rule: repeatRule,
            focus_mode: focusMode,
            color: color
        });

        showToast('Event updated!', 'success');
        closeModal('editEventModal');

        // If date changed, remove from current view and reload
        if (eventDate !== window.ScheduleApp.selectedDate) {
            const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
            if (eventCard) {
                eventCard.remove();
            }
            // Remove from allEvents array
            window.ScheduleApp.allEvents = window.ScheduleApp.allEvents.filter(e => e.id != eventId);
            updateStats();
            showToast(`Event moved to ${eventDate}`, 'info');
        } else {
            // Update DOM if staying on same date
            updateEventInDOM(eventId, {
                title,
                start,
                end,
                kind: type,
                notes,
                assigned_to: assignedTo
            });
        }
        
    } catch (error) {
        showToast(error.message || 'Failed to update event', 'error');
        console.error('Update error:', error);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

// ============================================
// UPDATE EVENT IN DOM
// ============================================
function updateEventInDOM(eventId, data) {
    const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
    if (!eventCard) {
        console.warn('Event card not found for update:', eventId);
        return;
    }
    
    const oldType = eventCard.getAttribute('data-note-type');
    const newType = data.kind;
    
    // Update event title
    const nameEl = eventCard.querySelector('.note-title');
    if (nameEl && data.title) {
        // Preserve badges
        const badges = nameEl.querySelectorAll('.focus-badge, .repeat-badge');
        nameEl.textContent = data.title;
        badges.forEach(badge => nameEl.appendChild(badge));
        eventCard.setAttribute('data-item-name', data.title);
    }
    
    // Update times
    if (data.start && data.end) {
        const today = window.ScheduleApp.selectedDate;
        const startTime = new Date(`${today}T${data.start}`);
        const endTime = new Date(`${today}T${data.end}`);
        const diffMs = endTime - startTime;
        const hours = Math.floor(diffMs / (1000 * 60 * 60));
        const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
        
        const timeEl = eventCard.querySelector('.event-time');
        if (timeEl) {
            timeEl.textContent = `⏰ ${startTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} - ${endTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}`;
        }
        
        const durationEl = eventCard.querySelector('.event-duration');
        if (durationEl) {
            durationEl.textContent = `⏱️ ${hours > 0 ? hours + 'h ' : ''}${minutes}m`;
        }
        
        // Update data attributes
        eventCard.dataset.start = `${today} ${data.start}:00`;
        eventCard.dataset.end = `${today} ${data.end}:00`;
    }
    
    // Update notes
    if (data.notes !== undefined) {
        let notesEl = eventCard.querySelector('.event-notes');
        if (data.notes) {
            if (!notesEl) {
                notesEl = document.createElement('div');
                notesEl.className = 'note-body event-notes';
                const detailsEl = eventCard.querySelector('.event-details');
                detailsEl.after(notesEl);
            }
            notesEl.textContent = data.notes.substring(0, 100) + (data.notes.length > 100 ? '...' : '');
        } else if (notesEl) {
            notesEl.remove();
        }
    }
    
    // Update assignment
    if (data.assigned_to !== undefined) {
        const footerEl = eventCard.querySelector('.event-footer');
        if (footerEl) {
            let assignedDiv = footerEl.querySelector('.event-assigned');
            
            if (data.assigned_to) {
                const member = window.ScheduleApp.familyMembers.find(m => m.id == data.assigned_to);
                const memberName = member ? member.full_name : 'Unknown';
                const memberColor = member ? member.avatar_color : '#667eea';
                const initial = memberName.charAt(0).toUpperCase();
                
                if (!assignedDiv) {
                    assignedDiv = document.createElement('div');
                    assignedDiv.className = 'event-assigned';
                    footerEl.appendChild(assignedDiv);
                }
                assignedDiv.innerHTML = `
                    → 
                    <div class="author-avatar-mini" style="background: ${memberColor}">
                        ${initial}
                    </div>
                    <span>${escapeHtml(memberName)}</span>
                `;
            } else if (assignedDiv) {
                assignedDiv.remove();
            }
        }
    }
    
    // Move to new type if changed
    if (oldType && newType && oldType !== newType) {
        const typeColor = window.ScheduleApp.types[newType]?.color || '#667eea';
        eventCard.style.background = typeColor;
        eventCard.setAttribute('data-note-type', newType);
        
        // Animate transition
        eventCard.style.transform = 'scale(1.05)';
        setTimeout(() => {
            eventCard.style.transform = 'scale(1)';
        }, 200);
    }
}

// ============================================
// DUPLICATE EVENT
// ============================================
async function duplicateEvent(eventId) {
    const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
    if (!eventCard) return;
    
    const event = window.ScheduleApp.allEvents.find(e => e.id == eventId);
    if (!event) return;
    
    const startTime = new Date(event.starts_at);
    const endTime = new Date(event.ends_at);
    
    // Add 1 hour to both times
    startTime.setHours(startTime.getHours() + 1);
    endTime.setHours(endTime.getHours() + 1);
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'add',
            title: event.title + ' (Copy)',
            date: window.ScheduleApp.selectedDate,
            start_time: startTime.toTimeString().slice(0, 5),
            end_time: endTime.toTimeString().slice(0, 5),
            kind: event.kind,
            notes: event.notes,
            reminder_minutes: event.reminder_minutes,
            focus_mode: event.focus_mode
        });
        
        showToast('✓ Event duplicated!', 'success');
        addEventToDOM(result.event);
        updateStats();
        
    } catch (error) {
        showToast(error.message || 'Failed to duplicate event', 'error');
        console.error('Duplicate error:', error);
    }
}

// ============================================
// CLEAR DONE
// ============================================
async function clearDone() {
    const doneCards = document.querySelectorAll('.event-card.done');
    const doneCount = doneCards.length;
    
    if (doneCount === 0) {
        showToast('No completed events to clear', 'info');
        return;
    }
    
    if (!confirm(`Clear ${doneCount} completed event${doneCount > 1 ? 's' : ''}?`)) {
        return;
    }
    
    try {
        await apiCall(window.ScheduleApp.API.events, {
            action: 'clear_done',
            date: window.ScheduleApp.selectedDate
        });
        
        showToast(`Cleared ${doneCount} event${doneCount > 1 ? 's' : ''}!`, 'success');
        
        // Remove from DOM
        doneCards.forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'scale(0.8)';
        });
        
        setTimeout(() => {
            doneCards.forEach(card => card.remove());
            updateStats();
            
            // Show empty state if no events left
            const remainingEvents = document.querySelectorAll('.event-card');
            if (remainingEvents.length === 0) {
                showEmptyState();
            }
        }, 400);
        
    } catch (error) {
        showToast(error.message || 'Failed to clear events', 'error');
        console.error('Clear done error:', error);
    }
}

// ============================================
// BULK MODE
// ============================================
function toggleBulkMode() {
    window.ScheduleApp.bulkMode = !window.ScheduleApp.bulkMode;
    
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const bulkModeBtn = document.getElementById('bulkModeBtn');
    const bulkSelects = document.querySelectorAll('.bulk-select-checkbox');
    const regularCheckboxes = document.querySelectorAll('.event-checkbox');
    
    if (window.ScheduleApp.bulkMode) {
        if (bulkActionsBar) bulkActionsBar.style.display = 'flex';
        if (bulkModeBtn) bulkModeBtn.classList.add('active');
        
        bulkSelects.forEach(el => el.style.display = 'block');
        regularCheckboxes.forEach(el => {
            el.style.display = 'none';
            const checkbox = el.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.disabled = true;
        });
        
        window.ScheduleApp.selectedItems.clear();
        updateBulkCount();
        
        showToast('✅ Bulk mode enabled', 'info');
        
    } else {
        if (bulkActionsBar) bulkActionsBar.style.display = 'none';
        if (bulkModeBtn) bulkModeBtn.classList.remove('active');
        
        bulkSelects.forEach(el => el.style.display = 'none');
        regularCheckboxes.forEach(el => {
            el.style.display = 'flex';
            const checkbox = el.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.disabled = false;
        });
        
        window.ScheduleApp.selectedItems.clear();
        document.querySelectorAll('.bulk-checkbox').forEach(cb => cb.checked = false);
        
        showToast('Bulk mode disabled', 'info');
    }
}

function updateBulkCount() {
    const countEl = document.getElementById('bulkSelectedCount');
    if (countEl) {
        countEl.textContent = window.ScheduleApp.selectedItems.size;
    }
    
    const bulkButtons = document.querySelectorAll('.bulk-buttons .btn:not(:last-child)');
    const hasSelection = window.ScheduleApp.selectedItems.size > 0;
    
    bulkButtons.forEach(btn => {
        btn.disabled = !hasSelection;
        btn.style.opacity = hasSelection ? '1' : '0.5';
        btn.style.cursor = hasSelection ? 'pointer' : 'not-allowed';
        btn.style.pointerEvents = hasSelection ? 'auto' : 'none';
    });
}

// Handle bulk checkbox change
document.addEventListener('change', (e) => {
    if (e.target.classList.contains('bulk-checkbox')) {
        const eventId = parseInt(e.target.dataset.eventId);
        
        if (e.target.checked) {
            window.ScheduleApp.selectedItems.add(eventId);
        } else {
            window.ScheduleApp.selectedItems.delete(eventId);
        }
        
        updateBulkCount();
    }
});

// Bulk operations
async function bulkMarkDone() {
    if (window.ScheduleApp.selectedItems.size === 0) {
        showToast('No events selected', 'error');
        return;
    }
    
    const count = window.ScheduleApp.selectedItems.size;
    const eventIds = Array.from(window.ScheduleApp.selectedItems);
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'bulk_mark_done',
            event_ids: JSON.stringify(eventIds)
        });
        
        showToast(`✓ Marked ${result.count || count} events as done!`, 'success');
        
        // Update DOM
        eventIds.forEach((eventId, index) => {
            setTimeout(() => {
                const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
                if (eventCard) {
                    eventCard.classList.add('done');
                    
                    const checkbox = eventCard.querySelector('.event-checkbox input[type="checkbox"]');
                    if (checkbox) checkbox.checked = true;
                    
                    createConfetti(eventCard);
                }
            }, index * 50);
        });
        
        setTimeout(() => {
            toggleBulkMode();
            updateStats();
        }, eventIds.length * 50 + 300);
        
    } catch (error) {
        showToast(error.message || 'Failed to mark events', 'error');
        console.error('Bulk mark done error:', error);
    }
}

function showBulkTypeModal() {
    if (window.ScheduleApp.selectedItems.size === 0) {
        showToast('No events selected', 'error');
        return;
    }
    
    showModal('bulkTypeModal');
}

async function applyBulkType(type) {
    if (window.ScheduleApp.selectedItems.size === 0) {
        showToast('No events selected', 'error');
        closeModal('bulkTypeModal');
        return;
    }
    
    const count = window.ScheduleApp.selectedItems.size;
    const eventIds = Array.from(window.ScheduleApp.selectedItems);
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'bulk_change_type',
            event_ids: JSON.stringify(eventIds),
            kind: type
        });
        
        const typeInfo = window.ScheduleApp.types[type] || { name: type };
        showToast(`✓ Changed ${result.count || count} events to ${typeInfo.name}!`, 'success');
        
        closeModal('bulkTypeModal');
        
        const typeColor = window.ScheduleApp.types[type]?.color || '#667eea';
        
        // Update DOM
        eventIds.forEach((eventId, index) => {
            setTimeout(() => {
                const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
                if (eventCard) {
                    eventCard.style.background = typeColor;
                    eventCard.setAttribute('data-note-type', type);
                    eventCard.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        eventCard.style.transform = 'scale(1)';
                    }, 200);
                }
            }, index * 80);
        });
        
        setTimeout(() => {
            toggleBulkMode();
        }, eventIds.length * 80 + 400);
        
    } catch (error) {
        showToast(error.message || 'Failed to change type', 'error');
        console.error('Bulk change type error:', error);
        closeModal('bulkTypeModal');
    }
}

function showBulkAssignModal() {
    if (window.ScheduleApp.selectedItems.size === 0) {
        showToast('No events selected', 'error');
        return;
    }
    
    showModal('bulkAssignModal');
}

async function applyBulkAssign(userId) {
    if (window.ScheduleApp.selectedItems.size === 0) {
        showToast('No events selected', 'error');
        closeModal('bulkAssignModal');
        return;
    }
    
    const count = window.ScheduleApp.selectedItems.size;
    const eventIds = Array.from(window.ScheduleApp.selectedItems);
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'bulk_assign',
            event_ids: JSON.stringify(eventIds),
            assign_to: userId
        });
        
        const member = window.ScheduleApp.familyMembers.find(m => m.id == userId);
        const memberName = member ? member.full_name : 'member';
        
        showToast(`✓ Assigned ${result.count || count} events to ${memberName}!`, 'success');
        
        closeModal('bulkAssignModal');
        
        // Update DOM
        eventIds.forEach((eventId, index) => {
            setTimeout(() => {
                const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
                if (eventCard) {
                    const footerEl = eventCard.querySelector('.event-footer');
                    if (footerEl && member) {
                        let assignedDiv = footerEl.querySelector('.event-assigned');
                        
                        if (!assignedDiv) {
                            assignedDiv = document.createElement('div');
                            assignedDiv.className = 'event-assigned';
                            footerEl.appendChild(assignedDiv);
                        }
                        
                        const memberColor = member.avatar_color || '#667eea';
                        const initial = memberName.charAt(0).toUpperCase();
                        
                        assignedDiv.innerHTML = `
                            → 
                            <div class="author-avatar-mini" style="background: ${memberColor}">
                                ${initial}
                            </div>
                            <span>${escapeHtml(memberName)}</span>
                        `;
                        
                        assignedDiv.style.backgroundColor = '#43e97b22';
                        setTimeout(() => {
                            assignedDiv.style.backgroundColor = 'transparent';
                        }, 500);
                    }
                }
            }, index * 50);
        });
        
        setTimeout(() => {
            toggleBulkMode();
        }, eventIds.length * 50 + 300);
        
    } catch (error) {
        showToast(error.message || 'Failed to assign events', 'error');
        console.error('Bulk assign error:', error);
        closeModal('bulkAssignModal');
    }
}

async function bulkDelete() {
    if (window.ScheduleApp.selectedItems.size === 0) {
        showToast('No events selected', 'error');
        return;
    }
    
    const count = window.ScheduleApp.selectedItems.size;
    const eventIds = Array.from(window.ScheduleApp.selectedItems);
    
    if (!confirm(`Delete ${count} selected event${count > 1 ? 's' : ''}? This cannot be undone.`)) {
        return;
    }
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'bulk_delete',
            event_ids: JSON.stringify(eventIds)
        });
        
        showToast(`✓ Deleted ${result.count || count} events!`, 'success');
        
        // Remove from DOM
        eventIds.forEach((eventId, index) => {
            setTimeout(() => {
                const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
                if (eventCard) {
                    eventCard.style.opacity = '0';
                    eventCard.style.transform = 'translateX(-100px)';
                    
                    setTimeout(() => {
                        eventCard.remove();
                    }, 300);
                }
            }, index * 50);
        });
        
        setTimeout(() => {
            toggleBulkMode();
            updateStats();
            
            const remainingEvents = document.querySelectorAll('.event-card');
            if (remainingEvents.length === 0) {
                setTimeout(() => showEmptyState(), 300);
            }
        }, eventIds.length * 50 + 400);
        
    } catch (error) {
        showToast(error.message || 'Failed to delete events', 'error');
        console.error('Bulk delete error:', error);
    }
}

// ============================================
// DATE NAVIGATION
// ============================================
function changeDate(days) {
    const current = new Date(window.ScheduleApp.selectedDate);
    current.setDate(current.getDate() + days);
    const newDate = current.toISOString().split('T')[0];
    
    window.location.href = `?date=${newDate}&view=${window.ScheduleApp.viewMode}`;
}

function goToToday() {
    const today = new Date().toISOString().split('T')[0];
    window.location.href = `?date=${today}&view=${window.ScheduleApp.viewMode}`;
}

function showDatePicker() {
    showModal('datePickerModal');
}

function goToPickedDate() {
    const date = document.getElementById('datePickerInput').value;
    if (date) {
        window.location.href = `?date=${date}&view=${window.ScheduleApp.viewMode}`;
    }
}

function changeView(view) {
    if (view === 'week') {
        showWeekView();
    } else if (view === 'timeline') {
        showTimelineView();
    } else {
        window.location.href = `?date=${window.ScheduleApp.selectedDate}&view=${view}`;
    }
}

// Show week view modal
async function showWeekView() {
    showModal('weekViewModal');
    const contentEl = document.getElementById('weekViewContent');
    contentEl.innerHTML = '<div class="week-loading">Loading week view...</div>';

    try {
        // Get week dates
        const selectedDate = new Date(window.ScheduleApp.selectedDate);
        const dayOfWeek = selectedDate.getDay();
        const monday = new Date(selectedDate);
        monday.setDate(selectedDate.getDate() - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));

        const weekDays = [];
        for (let i = 0; i < 7; i++) {
            const day = new Date(monday);
            day.setDate(monday.getDate() + i);
            weekDays.push(day);
        }

        // Fetch events for the week
        const startDate = weekDays[0].toISOString().split('T')[0];
        const endDate = weekDays[6].toISOString().split('T')[0];

        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'get_week',
            start_date: startDate,
            end_date: endDate
        }, 'GET');

        const events = result.events || [];
        const eventsByDate = {};

        events.forEach(event => {
            const date = event.starts_at.split(' ')[0];
            if (!eventsByDate[date]) eventsByDate[date] = [];
            eventsByDate[date].push(event);
        });

        // Render week view
        let html = '<div class="week-view-grid">';

        weekDays.forEach(day => {
            const dateStr = day.toISOString().split('T')[0];
            const isToday = dateStr === new Date().toISOString().split('T')[0];
            const isSelected = dateStr === window.ScheduleApp.selectedDate;
            const dayEvents = eventsByDate[dateStr] || [];

            html += `
                <div class="week-day ${isToday ? 'today' : ''} ${isSelected ? 'selected' : ''}"
                     onclick="goToDate('${dateStr}')">
                    <div class="week-day-header">
                        <span class="week-day-name">${day.toLocaleDateString('en-US', { weekday: 'short' })}</span>
                        <span class="week-day-number">${day.getDate()}</span>
                    </div>
                    <div class="week-day-events">
                        ${dayEvents.length === 0 ? '<span class="no-events">No events</span>' :
                            dayEvents.slice(0, 4).map(event => {
                                const startTime = new Date(event.starts_at);
                                const typeColor = window.ScheduleApp.types[event.kind]?.color || '#667eea';
                                return `
                                    <div class="week-event ${event.status}" style="border-left-color: ${typeColor}">
                                        <span class="event-time">${startTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}</span>
                                        <span class="event-name">${escapeHtml(event.title)}</span>
                                    </div>
                                `;
                            }).join('')}
                        ${dayEvents.length > 4 ? `<span class="more-events">+${dayEvents.length - 4} more</span>` : ''}
                    </div>
                    <div class="week-day-count">${dayEvents.length} events</div>
                </div>
            `;
        });

        html += '</div>';
        contentEl.innerHTML = html;

    } catch (error) {
        contentEl.innerHTML = '<div class="error-state">Failed to load week view. Please try again.</div>';
        console.error('Week view error:', error);
    }
}

function goToDate(dateStr) {
    closeModal('weekViewModal');
    window.location.href = `?date=${dateStr}&view=day`;
}

function showTimelineView() {
    showToast('Timeline view coming soon!', 'info');
}

// ============================================
// FOCUS MODE FUNCTIONS
// ============================================
function startFocusMode() {
    showModal('focusSessionModal');
}

async function createFocusSession(event) {
    if (event) event.preventDefault();
    
    const title = document.getElementById('focusTitle').value.trim();
    const duration = parseInt(document.getElementById('focusDuration').value);
    const start = document.getElementById('focusStart').value;
    const blockNotifications = document.getElementById('focusBlockNotifications').checked;
    
    if (!title || !start) {
        showToast('Please fill all fields', 'error');
        return;
    }
    
    // Calculate end time
    const startDate = new Date();
    const [hours, minutes] = start.split(':');
    startDate.setHours(parseInt(hours), parseInt(minutes), 0);
    const endDate = new Date(startDate.getTime() + duration * 60000);
    const endTime = endDate.toTimeString().slice(0, 5);
    
    try {
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'add',
            title: title,
            date: window.ScheduleApp.selectedDate,
            start_time: start,
            end_time: endTime,
            kind: 'focus',
            focus_mode: 1
        });
        
        closeModal('focusSessionModal');
        
        // Start focus session
        await apiCall(window.ScheduleApp.API.events, {
            action: 'start_focus',
            event_id: result.event.id
        });
        
        focusManager.start(result.event.id, duration, blockNotifications);
        
        addEventToDOM(result.event);
        showToast('🎯 Focus session started!', 'success');
        
    } catch (error) {
        showToast(error.message || 'Failed to start focus session', 'error');
        console.error('Focus session error:', error);
    }
}

async function startFocusSession(eventId) {
    const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
    if (!eventCard) return;
    
    const startTime = new Date(eventCard.dataset.start);
    const endTime = new Date(eventCard.dataset.end);
    const duration = Math.round((endTime - startTime) / 60000); // minutes
    
    try {
        await apiCall(window.ScheduleApp.API.events, {
            action: 'start_focus',
            event_id: eventId
        });
        
        focusManager.start(eventId, duration, true);
        
        eventCard.classList.add('in_progress');
        showToast('🎯 Focus mode activated!', 'success');
        
    } catch (error) {
        showToast(error.message || 'Failed to start focus session', 'error');
        console.error('Start focus error:', error);
    }
}

function takeFocusBreak() {
    focusManager.pause();
    showToast('☕ Take a 5-minute break!', 'info');
    
    setTimeout(() => {
        if (confirm('Break time is over. Ready to continue?')) {
            focusManager.startTimer();
            showToast('🎯 Back to focus!', 'success');
        }
    }, 300000); // 5 minutes
}

async function endFocusSession(eventId) {
    focusManager.end(eventId);
}

async function selectRating(rating) {
    const eventId = document.getElementById('ratingEventId').value;
    document.getElementById('selectedRating').value = rating;
    
    // Highlight selected stars
    document.querySelectorAll('.star-btn').forEach((btn, index) => {
        if (index < rating) {
            btn.classList.add('selected');
        } else {
            btn.classList.remove('selected');
        }
    });
    
    try {
        await apiCall(window.ScheduleApp.API.events, {
            action: 'end_focus',
            event_id: eventId,
            rating: rating
        });
        
        closeModal('ratingModal');
        
        showToast(`✓ Session completed! Rating: ${rating}/5 ⭐`, 'success');
        
        // Update event card
        const eventCard = document.querySelector(`[data-event-id="${eventId}"]`);
        if (eventCard) {
            eventCard.classList.remove('in_progress');
            eventCard.classList.add('done');
            
            const checkbox = eventCard.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = true;
            
            // Add rating badge
            const details = eventCard.querySelector('.event-details');
            if (details && !details.querySelector('.event-rating')) {
                const ratingDiv = document.createElement('div');
                ratingDiv.className = 'event-rating';
                ratingDiv.innerHTML = `⭐ ${rating}/5`;
                details.appendChild(ratingDiv);
            }
        }
        
        updateStats();
        
    } catch (error) {
        showToast(error.message || 'Failed to rate session', 'error');
        console.error('Rating error:', error);
    }
}

// ============================================
// ANALYTICS
// ============================================
async function showAnalytics() {
    showModal('analyticsModal');
    
    const contentEl = document.getElementById('analyticsContent');
    contentEl.innerHTML = '<div class="analytics-loading">Loading analytics...</div>';
    
    try {
        const endDate = window.ScheduleApp.selectedDate;
        const startDate = new Date(endDate);
        startDate.setDate(startDate.getDate() - 30);
        const startDateStr = startDate.toISOString().split('T')[0];
        
        const result = await apiCall(window.ScheduleApp.API.events, {
            action: 'get_productivity',
            start_date: startDateStr,
            end_date: endDate
        }, 'GET');
        
        if (result.productivity && result.productivity.length > 0) {
            renderAnalytics(result.productivity);
        } else {
            contentEl.innerHTML = '<div class="empty-state"><p>No productivity data yet. Complete some events to see your analytics!</p></div>';
        }
        
    } catch (error) {
        contentEl.innerHTML = '<div class="error-state"><p>Failed to load analytics. Please try again.</p></div>';
        console.error('Analytics error:', error);
    }
}

function renderAnalytics(data) {
    const contentEl = document.getElementById('analyticsContent');
    
    // Calculate totals
    let totalStudy = 0;
    let totalWork = 0;
    let totalFocus = 0;
    let totalCompleted = 0;
    let totalTasks = 0;
    
    data.forEach(day => {
        totalStudy += parseInt(day.study_minutes) || 0;
        totalWork += parseInt(day.work_minutes) || 0;
        totalFocus += parseInt(day.focus_minutes) || 0;
        totalCompleted += parseInt(day.completed_tasks) || 0;
        totalTasks += parseInt(day.total_tasks) || 0;
    });
    
    const avgProductivity = data.reduce((sum, day) => sum + parseFloat(day.productivity_score || 0), 0) / data.length;
    const completionRate = totalTasks > 0 ? (totalCompleted / totalTasks * 100).toFixed(1) : 0;
    
    contentEl.innerHTML = `
        <div class="analytics-summary">
            <div class="analytics-card">
                <div class="analytics-icon">📚</div>
                <div class="analytics-value">${Math.floor(totalStudy / 60)}h ${totalStudy % 60}m</div>
                <div class="analytics-label">Study Time</div>
            </div>
            <div class="analytics-card">
                <div class="analytics-icon">💼</div>
                <div class="analytics-value">${Math.floor(totalWork / 60)}h ${totalWork % 60}m</div>
                <div class="analytics-label">Work Time</div>
            </div>
            <div class="analytics-card">
                <div class="analytics-icon">🎯</div>
                <div class="analytics-value">${Math.floor(totalFocus / 60)}h ${totalFocus % 60}m</div>
                <div class="analytics-label">Focus Time</div>
            </div>
            <div class="analytics-card">
                <div class="analytics-icon">✓</div>
                <div class="analytics-value">${completionRate}%</div>
                <div class="analytics-label">Completion Rate</div>
            </div>
        </div>
        
        <div class="analytics-chart">
            <h3>Daily Productivity (Last 30 Days)</h3>
            <div class="chart-bars">
                ${data.slice(-30).map(day => {
                    const totalMinutes = (parseInt(day.study_minutes) || 0) + (parseInt(day.work_minutes) || 0) + (parseInt(day.focus_minutes) || 0);
                    const height = Math.min((totalMinutes / 480) * 100, 100); // Max 8 hours
                    const date = new Date(day.date);
                    const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                    
                    return `
                        <div class="chart-bar-wrapper" title="${day.date}: ${Math.floor(totalMinutes / 60)}h ${totalMinutes % 60}m">
                            <div class="chart-bar" style="height: ${height}%">
                                <div class="bar-segment bar-study" style="height: ${(parseInt(day.study_minutes) || 0) / totalMinutes * 100}%"></div>
                                <div class="bar-segment bar-work" style="height: ${(parseInt(day.work_minutes) || 0) / totalMinutes * 100}%"></div>
                                <div class="bar-segment bar-focus" style="height: ${(parseInt(day.focus_minutes) || 0) / totalMinutes * 100}%"></div>
                            </div>
                            <div class="chart-label">${dayName}</div>
                        </div>
                    `;
                }).join('')}
            </div>
            <div class="chart-legend">
                <span class="legend-item"><span class="legend-color bar-study"></span> Study</span>
                <span class="legend-item"><span class="legend-color bar-work"></span> Work</span>
                <span class="legend-item"><span class="legend-color bar-focus"></span> Focus</span>
            </div>
        </div>
        
        <div class="analytics-insights">
            <h3>📈 Insights</h3>
            <ul>
                ${avgProductivity >= 70 ? '<li class="insight-good">🎉 Great productivity! Keep up the excellent work!</li>' : ''}
                ${avgProductivity < 50 ? '<li class="insight-warn">💡 Try scheduling more focus sessions to boost productivity.</li>' : ''}
                ${completionRate >= 80 ? '<li class="insight-good">✨ Excellent task completion rate!</li>' : ''}
                ${completionRate < 60 ? '<li class="insight-warn">⚠️ Consider breaking tasks into smaller chunks for better completion.</li>' : ''}
                ${totalFocus < 300 ? '<li class="insight-info">🎯 Try adding more focus sessions for deep work.</li>' : ''}
                ${totalStudy > totalWork ? '<li class="insight-info">📚 You\'re spending more time on studying than work.</li>' : ''}
            </ul>
        </div>
    `;
}

// ============================================
// TEMPLATES
// ============================================
function showTemplates() {
    showModal('templatesModal');
    loadTemplates();
}

async function loadTemplates() {
    const contentEl = document.getElementById('templatesContent');
    
    // Show default templates
    contentEl.innerHTML = `
        <div class="templates-grid">
            <div class="template-card" onclick="applyTemplate('productive-morning')">
                <div class="template-icon">🌅</div>
                <div class="template-name">Productive Morning</div>
                <div class="template-desc">Study session + work blocks</div>
            </div>
            <div class="template-card" onclick="applyTemplate('deep-work-day')">
                <div class="template-icon">🎯</div>
                <div class="template-name">Deep Work Day</div>
                <div class="template-desc">Focus sessions with breaks</div>
            </div>
            <div class="template-card" onclick="applyTemplate('study-intensive')">
                <div class="template-icon">📚</div>
                <div class="template-name">Study Intensive</div>
                <div class="template-desc">Multiple study blocks</div>
            </div>
            <div class="template-card" onclick="applyTemplate('balanced-day')">
                <div class="template-icon">⚖️</div>
                <div class="template-name">Balanced Day</div>
                <div class="template-desc">Work, study, and breaks</div>
            </div>
        </div>
        <button onclick="createNewTemplate()" class="btn btn-primary" style="margin-top: 20px;">
            <span class="btn-icon">+</span>
            <span>Create Custom Template</span>
        </button>
    `;
}

async function applyTemplate(templateName) {
    const templates = {
        'productive-morning': [
            { title: 'Morning Study Session', start: '08:00', end: '10:00', kind: 'study' },
            { title: 'Coffee Break', start: '10:00', end: '10:15', kind: 'break' },
            { title: 'Work Block 1', start: '10:15', end: '12:00', kind: 'work' },
            { title: 'Lunch Break', start: '12:00', end: '13:00', kind: 'break' }
        ],
        'deep-work-day': [
            { title: 'Deep Work Session 1', start: '09:00', end: '11:00', kind: 'focus', focus_mode: 1 },
            { title: 'Break', start: '11:00', end: '11:15', kind: 'break' },
            { title: 'Deep Work Session 2', start: '11:15', end: '13:00', kind: 'focus', focus_mode: 1 },
            { title: 'Lunch', start: '13:00', end: '14:00', kind: 'break' },
            { title: 'Deep Work Session 3', start: '14:00', end: '16:00', kind: 'focus', focus_mode: 1 }
        ],
        'study-intensive': [
            { title: 'Study Block 1', start: '08:00', end: '10:00', kind: 'study' },
            { title: 'Break', start: '10:00', end: '10:15', kind: 'break' },
            { title: 'Study Block 2', start: '10:15', end: '12:15', kind: 'study' },
            { title: 'Lunch', start: '12:15', end: '13:00', kind: 'break' },
            { title: 'Study Block 3', start: '13:00', end: '15:00', kind: 'study' },
            { title: 'Break', start: '15:00', end: '15:15', kind: 'break' },
            { title: 'Study Block 4', start: '15:15', end: '17:00', kind: 'study' }
        ],
        'balanced-day': [
            { title: 'Morning Work', start: '09:00', end: '11:00', kind: 'work' },
            { title: 'Break', start: '11:00', end: '11:15', kind: 'break' },
            { title: 'Study Session', start: '11:15', end: '13:00', kind: 'study' },
            { title: 'Lunch', start: '13:00', end: '14:00', kind: 'break' },
            { title: 'Afternoon Work', start: '14:00', end: '16:00', kind: 'work' },
            { title: 'Break', start: '16:00', end: '16:15', kind: 'break' },
            { title: 'Personal Time', start: '16:15', end: '17:00', kind: 'todo' }
        ]
    };
    
    const template = templates[templateName];
    if (!template) {
        showToast('Template not found', 'error');
        return;
    }
    
    if (!confirm(`Apply "${templateName.replace(/-/g, ' ')}" template? This will create ${template.length} events.`)) {
        return;
    }
    
    closeModal('templatesModal');
    
    let successCount = 0;
    
    for (const event of template) {
        try {
            const result = await apiCall(window.ScheduleApp.API.events, {
                action: 'add',
                title: event.title,
                date: window.ScheduleApp.selectedDate,
                start_time: event.start,
                end_time: event.end,
                kind: event.kind,
                focus_mode: event.focus_mode || 0
            });
            
            addEventToDOM(result.event);
            successCount++;
            
            // Small delay between requests
            await new Promise(resolve => setTimeout(resolve, 200));
            
        } catch (error) {
            console.error('Failed to create event:', event.title, error);
        }
    }
    
    showToast(`✓ Created ${successCount} events from template!`, 'success');
    updateStats();
}

function createNewTemplate() {
    showToast('Custom templates coming soon!', 'info');
}

// ============================================
// EXPORT
// ============================================
function exportSchedule() {
    const events = Array.from(document.querySelectorAll('.event-card')).map(card => {
        const name = card.querySelector('.note-title')?.textContent.trim() || '';
        const timeEl = card.querySelector('.event-time');
        const time = timeEl ? timeEl.textContent.replace('⏰ ', '').trim() : '';
        const durationEl = card.querySelector('.event-duration');
        const duration = durationEl ? durationEl.textContent.replace('⏱️ ', '').trim() : '';
        const type = card.getAttribute('data-note-type') || 'todo';
        const status = card.classList.contains('done') ? 'Done' : 
                      card.classList.contains('in_progress') ? 'In Progress' : 'Pending';
        const notes = card.querySelector('.event-notes')?.textContent.trim() || '';
        
        return { name, time, duration, type, status, notes };
    });
    
    let csv = 'Event,Time,Duration,Type,Status,Notes\n';
    
    events.forEach(event => {
        csv += `"${event.name}","${event.time}","${event.duration}","${event.type}","${event.status}","${event.notes}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `schedule-${window.ScheduleApp.selectedDate}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
    
    showToast('📥 Schedule exported!', 'success');
}

// ============================================
// ADD EVENT TO DOM
// ============================================
function addEventToDOM(event) {
    const type = event.kind || 'todo';
    const typeColor = window.ScheduleApp.types[type]?.color || '#667eea';
    const typeIcon = window.ScheduleApp.types[type]?.icon || '✅';
    
    // Calculate duration
    const startTime = new Date(event.starts_at);
    const endTime = new Date(event.ends_at);
    const diffMs = endTime - startTime;
    const hours = Math.floor(diffMs / (1000 * 60 * 60));
    const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
    
    // Build badges
    let badges = '';
    if (event.focus_mode) {
        badges += '<span class="focus-badge">🎯</span>';
    }
    if (event.repeat_rule) {
        badges += '<span class="repeat-badge">🔁</span>';
    }
    
    // Build details HTML
    let detailsHTML = `
        <div class="event-time">
            ⏰ ${startTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} - ${endTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}
        </div>
        <div class="event-duration">
            ⏱️ ${hours > 0 ? hours + 'h ' : ''}${minutes}m
        </div>
    `;
    
    if (event.reminder_minutes) {
        detailsHTML += `
            <div class="event-reminder">
                🔔 ${event.reminder_minutes}m before
            </div>
        `;
    }
    
    if (event.productivity_rating) {
        detailsHTML += `
            <div class="event-rating">
                ⭐ ${event.productivity_rating}/5
            </div>
        `;
    }
    
    // Build notes HTML
    let notesHTML = '';
    if (event.notes) {
        notesHTML = `
            <div class="note-body event-notes">
                ${escapeHtml(event.notes.substring(0, 100))}${event.notes.length > 100 ? '...' : ''}
            </div>
        `;
    }
    
    // Build meta HTML
    let metaHTML = `
        <div class="note-author">
            <div class="author-avatar-mini" style="background: ${event.avatar_color || '#667eea'}">
                ${event.added_by_name ? event.added_by_name.charAt(0).toUpperCase() : 'Y'}
            </div>
            <span>${escapeHtml(event.added_by_name || 'You')}</span>
        </div>
    `;
    
    if (event.assigned_to_name) {
        const assignedColor = event.assigned_color || '#667eea';
        const initial = event.assigned_to_name.charAt(0).toUpperCase();
        metaHTML += `
            <div class="event-assigned">
                → 
                <div class="author-avatar-mini" style="background: ${assignedColor}">
                    ${initial}
                </div>
                <span>${escapeHtml(event.assigned_to_name)}</span>
            </div>
        `;
    }
    
    // Build action buttons
    let actionButtons = '';
    if (event.status === 'pending' && event.focus_mode) {
        actionButtons += `
            <button onclick="startFocusSession(${event.id})" 
                    class="note-action" 
                    title="Start Focus">
                🎯
            </button>
        `;
    }
    actionButtons += `
        <button onclick="editEvent(${event.id})" 
                class="note-action" 
                title="Edit">
            ✏️
        </button>
        <button onclick="duplicateEvent(${event.id})" 
                class="note-action" 
                title="Duplicate">
            📋
        </button>
        <button onclick="deleteEvent(${event.id})" 
                class="note-action" 
                title="Delete">
            🗑️
        </button>
    `;
    
    // Create event card
    const eventCard = document.createElement('div');
    eventCard.className = `note-card event-card ${event.status || 'pending'} ${event.focus_mode ? 'focus-event' : ''}`;
    eventCard.setAttribute('data-note-id', event.id);
    eventCard.setAttribute('data-event-id', event.id);
    eventCard.setAttribute('data-note-type', type);
    eventCard.setAttribute('data-item-name', escapeHtml(event.title));
    eventCard.setAttribute('data-start', event.starts_at);
    eventCard.setAttribute('data-end', event.ends_at);
    eventCard.style.background = typeColor;
    
    eventCard.innerHTML = `
        <div class="bulk-select-checkbox" style="display: none;">
            <input type="checkbox" class="bulk-checkbox" data-event-id="${event.id}">
        </div>
        <div class="note-header">
            <div class="event-checkbox">
                <input type="checkbox" id="event_${event.id}" ${event.status === 'done' ? 'checked' : ''} onchange="toggleEvent(${event.id})">
            </div>
            <div class="note-actions">${actionButtons}</div>
        </div>
        <div class="note-title event-title">${escapeHtml(event.title)}${badges}</div>
        <div class="event-details">${detailsHTML}</div>
        ${notesHTML}
        <div class="note-footer event-footer">${metaHTML}</div>
    `;
    
    // Find or create grid
    let notesGrid = document.querySelector('.notes-grid');
    if (!notesGrid) {
        const notesSection = document.querySelector('.notes-section');
        if (!notesSection) return;
        
        const emptyState = notesSection.querySelector('.empty-state');
        if (emptyState) emptyState.remove();
        
        notesGrid = document.createElement('div');
        notesGrid.className = 'notes-grid';
        notesSection.appendChild(notesGrid);
    }
    
    // Add with animation
    eventCard.style.opacity = '0';
    eventCard.style.transform = 'translateY(-20px)';
    notesGrid.insertBefore(eventCard, notesGrid.firstChild);
    
    requestAnimationFrame(() => {
        eventCard.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        eventCard.style.opacity = '1';
        eventCard.style.transform = 'translateY(0)';
    });
    
    // Add to allEvents array
    window.ScheduleApp.allEvents.push(event);
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function updateStats() {
    const events = document.querySelectorAll('.event-card');
    const doneEvents = document.querySelectorAll('.event-card.done');
    const inProgressEvents = document.querySelectorAll('.event-card.in_progress');
    
    const total = events.length;
    const done = doneEvents.length;
    const inProgress = inProgressEvents.length;
    const pending = total - done - inProgress;
    const percentage = total > 0 ? Math.round((done / total) * 100) : 0;
    
    // Update progress bar
    const progressFill = document.querySelector('.progress-fill');
    const progressText = document.querySelector('.progress-text');
    
    if (progressFill) progressFill.style.width = percentage + '%';
    if (progressText) progressText.innerHTML = `<span class="progress-icon">✓</span>${done} of ${total} completed (${percentage}%)`;
}

function showEmptyState() {
    const notesSection = document.querySelector('.notes-section');
    if (!notesSection) return;
    
    const notesGrid = notesSection.querySelector('.notes-grid');
    if (notesGrid) notesGrid.remove();
    
    const listActions = document.querySelector('.list-actions');
    if (listActions) listActions.style.display = 'none';
    
    const oldEmptyState = notesSection.querySelector('.empty-state');
    if (oldEmptyState) oldEmptyState.remove();
    
    const emptyState = document.createElement('div');
    emptyState.className = 'empty-state glass-card';
    emptyState.innerHTML = `
        <div class="empty-icon">📅</div>
        <h2>No events scheduled for today</h2>
        <p>Add events to plan your day and track your productivity</p>
        <div class="empty-actions">
            <button onclick="showQuickAdd()" class="btn btn-primary btn-lg">
                <span class="btn-icon">+</span>
                <span>Add First Event</span>
            </button>
        </div>
    `;
    
    emptyState.style.opacity = '0';
    notesSection.appendChild(emptyState);
    
    requestAnimationFrame(() => {
        emptyState.style.transition = 'opacity 0.3s ease';
        emptyState.style.opacity = '1';
    });
}

function createConfetti(element) {
    const rect = element.getBoundingClientRect();
    const colors = ['#667eea', '#764ba2', '#43e97b', '#f093fb', '#4facfe'];
    
    for (let i = 0; i < 15; i++) {
        const confetti = document.createElement('div');
        confetti.className = 'confetti';
        confetti.style.left = rect.left + rect.width / 2 + 'px';
        confetti.style.top = rect.top + rect.height / 2 + 'px';
        confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        confetti.style.setProperty('--tx', (Math.random() - 0.5) * 200 + 'px');
        confetti.style.setProperty('--ty', -Math.random() * 200 - 50 + 'px');
        confetti.style.setProperty('--r', Math.random() * 360 + 'deg');
        
        document.body.appendChild(confetti);
        
        setTimeout(() => confetti.remove(), 1000);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        const firstInput = modal.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), textarea, select');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.schedule-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `schedule-toast toast-${type}`;
    
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    toast.innerHTML = `
        <span class="toast-icon">${icons[type] || icons.info}</span>
        <span class="toast-message">${escapeHtml(message)}</span>
    `;
    
    document.body.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showQuickAdd() {
    showModal('addEventModal');
    setTimeout(() => {
        document.getElementById('eventTitle').focus();
    }, 100);
}

function toggleReminderInput() {
    const checkbox = document.getElementById('enableReminder');
    const input = document.getElementById('reminderMinutes');
    input.style.display = checkbox.checked ? 'inline-block' : 'none';
    if (checkbox.checked) {
        input.value = '15';
    }
}

function toggleRecurringInput() {
    const checkbox = document.getElementById('enableRecurring');
    const select = document.getElementById('repeatRule');
    select.style.display = checkbox.checked ? 'inline-block' : 'none';
}

// Edit modal toggle functions
function toggleEditReminderInput() {
    const checkbox = document.getElementById('editEnableReminder');
    const input = document.getElementById('editReminderMinutes');
    input.style.display = checkbox.checked ? 'inline-block' : 'none';
    if (checkbox.checked && !input.value) {
        input.value = '15';
    }
}

function toggleEditRecurringInput() {
    const checkbox = document.getElementById('editEnableRecurring');
    const select = document.getElementById('editRepeatRule');
    select.style.display = checkbox.checked ? 'inline-block' : 'none';
}

// ============================================
// INITIALIZE
// ============================================
let particleSystemInstance = null;

document.addEventListener('DOMContentLoaded', () => {
    console.log('%c✅ Enhanced Schedule Ready!', 'font-size: 14px; font-weight: bold; color: #43e97b;');
    
    // Initialize particle system
    const particlesCanvas = document.getElementById('particles');
    if (particlesCanvas) {
        particleSystemInstance = new ParticleSystem('particles');
    }
    
    // Update stats
    updateStats();
    
    // Initialize focus timer if active session
    if (window.ScheduleApp.activeFocusSession) {
        const duration = Math.floor((new Date(window.ScheduleApp.activeFocusSession.ends_at) - new Date(window.ScheduleApp.activeFocusSession.starts_at)) / 60000);
        focusManager.start(window.ScheduleApp.activeFocusSession.id, duration, false);
    }
    
    // Close modals on backdrop click
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) {
            closeModal(e.target.id);
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        // Ctrl+N - Focus add event
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            document.getElementById('eventTitle').focus();
        }
        
        // Ctrl+B - Toggle bulk mode
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            toggleBulkMode();
        }
        
        // Ctrl+F - Start focus mode
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            startFocusMode();
        }
        
        // Ctrl+E - Export
        if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
            e.preventDefault();
            exportSchedule();
        }
        
        // ESC - Close modals
        if (e.key === 'Escape') {
            const activeModals = document.querySelectorAll('.modal.active');
            if (activeModals.length > 0) {
                activeModals.forEach(modal => closeModal(modal.id));
            } else if (window.ScheduleApp.bulkMode) {
                toggleBulkMode();
            }
        }
    });
    
    // Animate cards on load
    const cards = document.querySelectorAll('.event-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.05}s`;
    });
});

window.addEventListener('beforeunload', () => {
    if (particleSystemInstance) {
        particleSystemInstance.destroy();
        particleSystemInstance = null;
    }
});

// Export functions to global scope
window.addEvent = addEvent;
window.toggleEvent = toggleEvent;
window.deleteEvent = deleteEvent;
window.duplicateEvent = duplicateEvent;
window.editEvent = editEvent;
window.saveEditedEvent = saveEditedEvent;
window.clearDone = clearDone;
window.toggleBulkMode = toggleBulkMode;
window.bulkMarkDone = bulkMarkDone;
window.showBulkTypeModal = showBulkTypeModal;
window.applyBulkType = applyBulkType;
window.showBulkAssignModal = showBulkAssignModal;
window.applyBulkAssign = applyBulkAssign;
window.bulkDelete = bulkDelete;
window.changeDate = changeDate;
window.goToToday = goToToday;
window.showDatePicker = showDatePicker;
window.goToPickedDate = goToPickedDate;
window.changeView = changeView;
window.showQuickAdd = showQuickAdd;
window.exportSchedule = exportSchedule;
window.showModal = showModal;
window.closeModal = closeModal;
window.toggleReminderInput = toggleReminderInput;
window.toggleRecurringInput = toggleRecurringInput;
window.toggleEditReminderInput = toggleEditReminderInput;
window.toggleEditRecurringInput = toggleEditRecurringInput;
window.startFocusMode = startFocusMode;
window.createFocusSession = createFocusSession;
window.startFocusSession = startFocusSession;
window.takeFocusBreak = takeFocusBreak;
window.endFocusSession = endFocusSession;
window.selectRating = selectRating;
window.showAnalytics = showAnalytics;
window.showTemplates = showTemplates;
window.applyTemplate = applyTemplate;
window.createNewTemplate = createNewTemplate;
window.showWeekView = showWeekView;
window.showTimelineView = showTimelineView;
window.goToDate = goToDate;

console.log('%c✅ Enhanced Schedule with Focus Mode Ready!', 'font-size: 16px; font-weight: bold; color: #43e97b;');