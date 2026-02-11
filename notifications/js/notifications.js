/**
 * NOTIFICATIONS JS - COMPLETE REBUILD v2.1
 * All buttons work, optimized for native app
 * AJAX-based navigation - no page refreshes
 */

console.log('🔔 Notifications JS Loading...');

// Current filter state
let currentFilter = new URLSearchParams(window.location.search).get('filter') || 'all';

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
// AJAX NAVIGATION - NO PAGE REFRESH
// ============================================

async function loadNotificationsData(filter, animationIn = 'fadeIn') {
    try {
        console.log('Loading notifications for filter:', filter);

        // Animate out current content
        const notesSection = document.querySelector('.notes-section');
        if (notesSection) {
            notesSection.style.animation = 'fadeOut 0.2s ease forwards';
            await new Promise(resolve => setTimeout(resolve, 200));
        }

        // Fetch the notifications page for the new filter
        const response = await fetch(`/notifications/?filter=${filter}&ajax=1`);
        const html = await response.text();

        // Parse the HTML
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // Extract and update the notes section (main content)
        const newNotesSection = doc.querySelector('.notes-section');
        if (notesSection && newNotesSection) {
            notesSection.innerHTML = newNotesSection.innerHTML;
            notesSection.style.animation = `${animationIn} 0.3s ease forwards`;
        }

        // Update filter buttons active state
        const filterButtons = document.querySelectorAll('.filter-btn');
        filterButtons.forEach(btn => {
            const btnFilter = btn.getAttribute('data-filter');
            if (btnFilter === filter) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Update stats bar
        const newStatsBar = doc.querySelector('.week-stats-bar');
        const statsBar = document.querySelector('.week-stats-bar');
        if (newStatsBar && statsBar) {
            statsBar.outerHTML = newStatsBar.outerHTML;
        } else if (newStatsBar && !statsBar) {
            // Stats bar appeared, insert it
            const filterSection = document.querySelector('.search-filter-section');
            if (filterSection) {
                filterSection.insertAdjacentHTML('afterend', newStatsBar.outerHTML);
            }
        } else if (!newStatsBar && statsBar) {
            // Stats bar disappeared, remove it
            statsBar.remove();
        }

        // Update quick action buttons in hero section
        const newQuickActions = doc.querySelector('.quick-actions');
        const quickActions = document.querySelector('.quick-actions');
        if (quickActions && newQuickActions) {
            quickActions.innerHTML = newQuickActions.innerHTML;
        }

        // Update list actions (progress bar section)
        const newListActions = doc.querySelector('.list-actions');
        const listActions = document.querySelector('.list-actions');
        if (newListActions && listActions) {
            listActions.outerHTML = newListActions.outerHTML;
        } else if (newListActions && !listActions) {
            const notesGrid = document.querySelector('.notes-grid');
            if (notesGrid) {
                notesGrid.insertAdjacentHTML('beforebegin', newListActions.outerHTML);
            }
        } else if (!newListActions && listActions) {
            listActions.remove();
        }

        // Update current filter state
        currentFilter = filter;

        // Update URL without refresh
        const newUrl = filter === 'all' ? '/notifications/' : `/notifications/?filter=${filter}`;
        window.history.pushState({ filter: filter }, '', newUrl);

        // Re-animate notification cards
        document.querySelectorAll('.notification-card').forEach((card, index) => {
            card.style.animation = `fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.05}s backwards`;
        });

        console.log('Notifications loaded successfully for filter:', filter);

    } catch (error) {
        console.error('Failed to load notifications:', error);
        showToast('Failed to load notifications. Please try again.', 'error');
    }
}

// AJAX refresh of notifications content (same filter)
async function refreshNotifications(animationIn = 'fadeIn') {
    await loadNotificationsData(currentFilter, animationIn);
}

// Handle filter button clicks
function handleFilterClick(event) {
    event.preventDefault();
    const filter = event.currentTarget.getAttribute('data-filter');
    if (filter && filter !== currentFilter) {
        loadNotificationsData(filter, 'fadeIn');
    }
}

// Initialize filter buttons for AJAX navigation
function initFilterButtons() {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        // Get filter from href
        const href = btn.getAttribute('href');
        let filter = 'all';
        if (href) {
            const match = href.match(/filter=([^&]+)/);
            filter = match ? match[1] : 'all';
        }

        // Set data attribute and remove href for AJAX handling
        btn.setAttribute('data-filter', filter);
        btn.removeAttribute('href');
        btn.style.cursor = 'pointer';

        // Add click handler
        btn.addEventListener('click', handleFilterClick);
    });
}

// Handle browser back/forward navigation
window.addEventListener('popstate', (event) => {
    if (event.state && event.state.filter !== undefined) {
        loadNotificationsData(event.state.filter, 'fadeIn');
    } else {
        // Default to 'all' filter
        const urlParams = new URLSearchParams(window.location.search);
        const filter = urlParams.get('filter') || 'all';
        loadNotificationsData(filter, 'fadeIn');
    }
});

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

async function apiRequest(action, data = {}) {
    try {
        const formData = new URLSearchParams();
        formData.append('action', action);
        
        for (const key in data) {
            formData.append(key, data[key]);
        }
        
        const response = await fetch('/notifications/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        return await response.json();
        
    } catch (error) {
        console.error('API Request Error:', error);
        showToast('Network error. Please try again.', 'error');
        return { success: false, error: error.message };
    }
}

async function markAsRead(notificationId) {
    const result = await apiRequest('mark_read', { notification_id: notificationId });
    
    if (result.success) {
        const card = document.querySelector(`[data-notification-id="${notificationId}"]`);
        if (card) {
            card.classList.remove('unread');
            card.classList.add('read');
            
            const dot = card.querySelector('.unread-dot');
            if (dot) dot.remove();
        }
        
        updateUnreadCount();
    }
}

async function handleNotificationClick(notificationId, actionUrl) {
    await markAsRead(notificationId);
    
    if (actionUrl) {
        setTimeout(() => {
            window.location.href = actionUrl;
        }, 200);
    }
}

async function markAllRead() {
    if (!confirm('Mark all notifications as read?')) return;

    showToast('Marking all as read...', 'info');

    const result = await apiRequest('mark_all_read');

    if (result.success) {
        showToast('All notifications marked as read! 🎉', 'success');
        // Use AJAX refresh instead of page reload
        setTimeout(() => refreshNotifications('fadeIn'), 500);
    } else {
        showToast('Failed to mark all as read', 'error');
    }
}

async function deleteNotification(notificationId) {
    if (!confirm('Delete this notification?')) return;
    
    const card = document.querySelector(`[data-notification-id="${notificationId}"]`);
    
    const result = await apiRequest('delete_notification', { notification_id: notificationId });
    
    if (result.success) {
        if (card) {
            card.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(() => {
                card.remove();
                updateUnreadCount();
                
                // Check if group is empty
                const group = card.closest('.notification-group');
                if (group && !group.querySelector('.notification-card')) {
                    group.remove();
                }
                
                // Check if all notifications are gone - refresh to show empty state
                if (!document.querySelector('.notification-card')) {
                    setTimeout(() => refreshNotifications('fadeIn'), 300);
                }
            }, 300);
        }
        
        showToast('Notification deleted', 'success');
    } else {
        showToast('Failed to delete notification', 'error');
    }
}

function showClearConfirm() {
    const modal = document.getElementById('clearConfirmModal');
    if (modal) {
        modal.classList.add('active');
    }
}

async function clearAllRead() {
    closeModal('clearConfirmModal');

    showToast('Clearing read notifications...', 'info');

    const result = await apiRequest('clear_all');

    if (result.success) {
        showToast('Cleared successfully! 🎉', 'success');
        // Use AJAX refresh instead of page reload
        setTimeout(() => refreshNotifications('fadeIn'), 500);
    } else {
        showToast('Failed to clear notifications', 'error');
    }
}

function updateUnreadCount() {
    const unreadCards = document.querySelectorAll('.notification-card.unread');
    const count = unreadCards.length;
    
    const banner = document.querySelector('.unread-banner');
    if (banner) {
        if (count === 0) {
            banner.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(() => banner.remove(), 300);
        } else {
            const text = banner.querySelector('.unread-text strong');
            if (text) text.textContent = count;
        }
    }
    
    const unreadTab = document.querySelector('.filter-tab[href*="unread"] .tab-badge');
    if (unreadTab) {
        if (count === 0) {
            unreadTab.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(() => unreadTab.remove(), 300);
        } else {
            unreadTab.textContent = count;
        }
    }
}

// ============================================
// PREFERENCES
// ============================================

async function showPreferences() {
    const modal = document.getElementById('preferencesModal');
    if (!modal) return;
    
    modal.classList.add('active');
    
    const content = document.getElementById('preferencesContent');
    content.innerHTML = '<div class="loading">Loading preferences...</div>';
    
    try {
        const response = await fetch('/notifications/api/preferences.php?action=get');
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load');
        }
        
        renderPreferences(data.preferences, data.weather_schedule);
        
    } catch (error) {
        console.error('Error loading preferences:', error);
        content.innerHTML = '<div class="error">Failed to load. Please try again.</div>';
    }
}

function renderPreferences(preferences, weatherSchedule) {
    const content = document.getElementById('preferencesContent');
    
    const categories = [
        { id: 'message', name: 'Messages', icon: '💬' },
        { id: 'shopping', name: 'Shopping', icon: '🛒' },
        { id: 'calendar', name: 'Calendar', icon: '📅' },
        { id: 'schedule', name: 'Schedule', icon: '⏰' },
        { id: 'tracking', name: 'Location', icon: '📍' },
        { id: 'weather', name: 'Weather', icon: '🌤️' },
        { id: 'note', name: 'Notes', icon: '📝' },
        { id: 'system', name: 'System', icon: '⚙️' }
    ];
    
    const prefsMap = {};
    preferences.forEach(p => {
        prefsMap[p.category] = p;
    });
    
    let html = '<div class="preferences-grid">';
    
    categories.forEach(cat => {
        const pref = prefsMap[cat.id] || {
            enabled: 1,
            push_enabled: 1,
            sound_enabled: 1,
            vibrate_enabled: 1
        };
        
        html += `
            <div class="pref-card">
                <div class="pref-header">
                    <span class="pref-icon">${cat.icon}</span>
                    <span class="pref-name">${cat.name}</span>
                </div>
                <div class="pref-controls">
                    <label class="pref-toggle">
                        <input type="checkbox" 
                               class="pref-checkbox" 
                               data-category="${cat.id}" 
                               data-setting="enabled" 
                               ${pref.enabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                        <span class="toggle-label">Enable</span>
                    </label>
                    <label class="pref-toggle">
                        <input type="checkbox" 
                               class="pref-checkbox" 
                               data-category="${cat.id}" 
                               data-setting="push_enabled" 
                               ${pref.push_enabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                        <span class="toggle-label">Push</span>
                    </label>
                    <label class="pref-toggle">
                        <input type="checkbox" 
                               class="pref-checkbox" 
                               data-category="${cat.id}" 
                               data-setting="sound_enabled" 
                               ${pref.sound_enabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                        <span class="toggle-label">Sound</span>
                    </label>
                    <label class="pref-toggle">
                        <input type="checkbox" 
                               class="pref-checkbox" 
                               data-category="${cat.id}" 
                               data-setting="vibrate_enabled" 
                               ${pref.vibrate_enabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                        <span class="toggle-label">Vibrate</span>
                    </label>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    
    // Weather schedule
    const ws = weatherSchedule || {
        enabled: 1,
        notification_time: '07:00:00',
        voice_enabled: 1,
        include_forecast: 1
    };
    
    html += `
        <div class="weather-schedule-section">
            <h3>🌤️ Daily Weather Updates</h3>
            <div class="weather-schedule-form">
                <label class="pref-toggle">
                    <input type="checkbox" id="weatherEnabled" ${ws.enabled ? 'checked' : ''}>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Enable daily weather</span>
                </label>
                
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" id="weatherTime" value="${ws.notification_time.substring(0, 5)}" class="form-control">
                </div>
                
                <label class="pref-toggle">
                    <input type="checkbox" id="weatherVoice" ${ws.voice_enabled ? 'checked' : ''}>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Voice prompt</span>
                </label>
                
                <label class="pref-toggle">
                    <input type="checkbox" id="weatherForecast" ${ws.include_forecast ? 'checked' : ''}>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Include forecast</span>
                </label>
            </div>
        </div>
    `;
    
    html += `
        <div class="preferences-actions">
            <button onclick="savePreferences()" class="btn btn-primary">
                Save Preferences
            </button>
            <button onclick="testWeatherNotification()" class="btn btn-secondary" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                🌤️ Test Weather
            </button>
            <button onclick="testNotification()" class="btn btn-secondary">
                Test Notification
            </button>
        </div>
    `;
    
    content.innerHTML = html;
    
    // Attach listeners
    document.querySelectorAll('.pref-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', saveCategoryPreference);
    });
}

async function saveCategoryPreference(event) {
    const checkbox = event.target;
    const category = checkbox.dataset.category;
    const setting = checkbox.dataset.setting;
    const value = checkbox.checked ? 1 : 0;
    
    try {
        const settings = {};
        settings[setting] = value;
        
        const formData = new URLSearchParams();
        formData.append('action', 'update');
        formData.append('category', category);
        formData.append('settings', JSON.stringify(settings));
        
        const response = await fetch('/notifications/api/preferences.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed');
        }
        
        showToast('Saved', 'success');
        
    } catch (error) {
        console.error('Save error:', error);
        checkbox.checked = !checkbox.checked;
        showToast('Failed to save', 'error');
    }
}

async function savePreferences() {
    try {
        const formData = new URLSearchParams();
        formData.append('action', 'update_weather_schedule');
        formData.append('enabled', document.getElementById('weatherEnabled').checked ? 1 : 0);
        formData.append('time', document.getElementById('weatherTime').value + ':00');
        formData.append('voice_enabled', document.getElementById('weatherVoice').checked ? 1 : 0);
        formData.append('include_forecast', document.getElementById('weatherForecast').checked ? 1 : 0);
        
        const response = await fetch('/notifications/api/preferences.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed');
        }
        
        showToast('All preferences saved! 🎉', 'success');
        closeModal('preferencesModal');
        
    } catch (error) {
        console.error('Save error:', error);
        showToast('Failed to save some preferences', 'error');
    }
}

async function testNotification() {
    try {
        const formData = new URLSearchParams();
        formData.append('action', 'test');

        const response = await fetch('/notifications/api/preferences.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed');
        }

        showToast('Test notification sent! Check your notifications.', 'success');

        // Use AJAX refresh instead of page reload
        setTimeout(() => refreshNotifications('fadeIn'), 1500);

    } catch (error) {
        console.error('Test error:', error);
        showToast('Failed to send test', 'error');
    }
}

async function testWeatherNotification() {
    try {
        showToast('Fetching weather data...', 'info');

        const formData = new URLSearchParams();
        formData.append('action', 'test_weather');

        const response = await fetch('/notifications/api/preferences.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed to send weather notification');
        }

        showToast('Weather notification sent! Check your notifications.', 'success');

        // Use AJAX refresh instead of page reload
        setTimeout(() => refreshNotifications('fadeIn'), 1500);

    } catch (error) {
        console.error('Weather test error:', error);
        showToast('Failed: ' + error.message, 'error');
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================

function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.notif-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `notif-toast toast-${type}`;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    console.log('🔔 Initializing Notifications...');

    // Initialize particle system
    new ParticleSystem('particles');

    // Initialize AJAX filter buttons (no page refresh)
    initFilterButtons();

    // Set initial history state
    window.history.replaceState({ filter: currentFilter }, '', window.location.href);

    // Animate notification cards
    document.querySelectorAll('.notification-card').forEach((card, index) => {
        card.style.animation = `fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.05}s backwards`;
    });

    // Native app: request notification permission when visiting this page
    if (window.TrackingBridge && typeof TrackingBridge.requestNotificationPermission === 'function') {
        TrackingBridge.requestNotificationPermission();
    }

    console.log('✅ Notifications Initialized (AJAX Mode)');
});

// Modal close on outside click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

// Modal close on ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const activeModal = document.querySelector('.modal.active');
        if (activeModal) {
            activeModal.classList.remove('active');
        }
    }
});

// Prevent accidental back navigation
let lastTouchTime = 0;
document.addEventListener('touchstart', (e) => {
    const now = Date.now();
    if (now - lastTouchTime < 300) {
        e.preventDefault();
    }
    lastTouchTime = now;
});

console.log('✅ Notifications JS Loaded Successfully');