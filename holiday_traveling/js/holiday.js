/**
 * ============================================
 * RELATIVES - HOLIDAY TRAVELING
 * Travel Planning & Trip Management
 * ============================================
 */

console.log('%c✈️ Holiday Planner Loading...', 'font-size: 16px; font-weight: bold; color: #667eea;');

// ============================================
// PARTICLE SYSTEM - DISABLED FOR PERFORMANCE
// ============================================
class ParticleSystem {
    constructor(canvasId) {
        // Disabled - canvas hidden via CSS for performance
    }
    destroy() {}
}

// ============================================
// HOLIDAY PLANNER
// ============================================

class HolidayPlanner {
    static instance = null;

    constructor() {
        if (HolidayPlanner.instance) {
            return HolidayPlanner.instance;
        }

        this.trips = window.TRAVEL_PLANS || [];
        this.familyMembers = window.FAMILY_MEMBERS || [];
        this.currentFilter = 'upcoming';

        HolidayPlanner.instance = this;
        this.init();
    }

    static getInstance() {
        if (!HolidayPlanner.instance) {
            HolidayPlanner.instance = new HolidayPlanner();
        }
        return HolidayPlanner.instance;
    }

    init() {
        console.log('✈️ Initializing Holiday Planner...');

        this.setupFormHandlers();
        this.setupModalHandlers();

        // Hide loader
        const loader = document.getElementById('appLoader');
        if (loader) {
            loader.classList.add('hidden');
        }

        console.log('✅ Holiday Planner initialized');
    }

    // ============================================
    // MODAL HANDLERS
    // ============================================

    setupModalHandlers() {
        // Close modal on overlay click
        const modal = document.getElementById('newTripModal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeModal();
                }
            });
        }

        // ESC key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });
    }

    openNewTripModal() {
        const modal = document.getElementById('newTripModal');
        if (modal) {
            modal.classList.add('active');

            // Set default dates
            const today = new Date();
            const nextWeek = new Date(today);
            nextWeek.setDate(nextWeek.getDate() + 7);

            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');

            if (startDate) startDate.value = this.formatDate(today);
            if (endDate) endDate.value = this.formatDate(nextWeek);

            // Focus on destination
            const destination = document.getElementById('destination');
            if (destination) destination.focus();
        }
    }

    closeModal() {
        const modal = document.getElementById('newTripModal');
        if (modal) {
            modal.classList.remove('active');

            // Reset form
            const form = document.getElementById('newTripForm');
            if (form) form.reset();
        }
    }

    // ============================================
    // FORM HANDLERS
    // ============================================

    setupFormHandlers() {
        const form = document.getElementById('newTripForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.createTrip();
            });
        }
    }

    async createTrip() {
        const form = document.getElementById('newTripForm');
        if (!form) return;

        const formData = new FormData(form);
        const tripData = {
            destination: formData.get('destination'),
            start_date: formData.get('start_date'),
            end_date: formData.get('end_date'),
            notes: formData.get('notes'),
            travelers: formData.getAll('travelers[]')
        };

        // Validate
        if (!tripData.destination || !tripData.start_date || !tripData.end_date) {
            this.showNotification('Please fill in all required fields', 'error');
            return;
        }

        // Show loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span>🔄</span> Creating...';
        submitBtn.disabled = true;

        try {
            const response = await fetch('/holiday_traveling/api/trips.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(tripData)
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification('Trip created successfully!', 'success');
                this.closeModal();

                // Reload page to show new trip
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            } else {
                this.showNotification(result.error || 'Failed to create trip', 'error');
            }
        } catch (error) {
            console.error('Create trip error:', error);
            this.showNotification('Failed to create trip. Please try again.', 'error');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }

    // ============================================
    // TRIP MANAGEMENT
    // ============================================

    filterTrips(filter) {
        this.currentFilter = filter;

        // Update filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filter === filter);
        });

        // Filter trips (would need to re-render from server or cache)
        console.log('Filtering trips by:', filter);

        // For now, reload page with filter param
        // In a real implementation, you'd filter client-side or fetch filtered data
    }

    viewTrip(tripId) {
        console.log('View trip:', tripId);
        // Could open a detail modal or navigate to trip page
        this.showNotification('Trip details coming soon!', 'info');
    }

    editTrip(tripId) {
        console.log('Edit trip:', tripId);
        // Could open edit modal with trip data
        this.showNotification('Trip editing coming soon!', 'info');
    }

    // ============================================
    // VIEWS
    // ============================================

    showCalendarView() {
        console.log('Calendar view');
        this.showNotification('Calendar view coming soon!', 'info');
    }

    showMapView() {
        console.log('Map view');
        this.showNotification('Map view coming soon!', 'info');
    }

    // ============================================
    // EXPLORE DESTINATIONS
    // ============================================

    exploreDest(type) {
        const destinations = {
            beach: ['Durban', 'Cape Town', 'Plettenberg Bay', 'Ballito'],
            mountain: ['Drakensberg', 'Cederberg', 'Blyde River Canyon'],
            city: ['Johannesburg', 'Cape Town', 'Pretoria', 'Durban'],
            safari: ['Kruger National Park', 'Addo Elephant Park', 'Pilanesberg']
        };

        const options = destinations[type] || [];
        const destination = options[Math.floor(Math.random() * options.length)];

        // Pre-fill the destination in the form
        const input = document.getElementById('destination');
        if (input) {
            input.value = destination;
        }

        this.openNewTripModal();
        this.showNotification(`How about ${destination}?`, 'success');
    }

    // ============================================
    // UTILITIES
    // ============================================

    formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    showNotification(message, type = 'info') {
        // Check if there's a global notification system
        if (window.showToast) {
            window.showToast(message, type);
            return;
        }

        // Create simple notification
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}</span>
                <span class="notification-text">${message}</span>
            </div>
        `;

        notification.style.cssText = `
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%);
            background: ${type === 'success' ? 'rgba(76, 175, 80, 0.9)' : type === 'error' ? 'rgba(244, 67, 54, 0.9)' : 'rgba(33, 150, 243, 0.9)'};
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            z-index: 10001;
            backdrop-filter: blur(8px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        `;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.animation = 'slideDown 0.3s ease forwards';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
}

// Add CSS for notifications
const style = document.createElement('style');
style.textContent = `
    @keyframes slideUp {
        from { transform: translateX(-50%) translateY(20px); opacity: 0; }
        to { transform: translateX(-50%) translateY(0); opacity: 1; }
    }
    @keyframes slideDown {
        from { transform: translateX(-50%) translateY(0); opacity: 1; }
        to { transform: translateX(-50%) translateY(20px); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    new HolidayPlanner();
});

// Also try to initialize immediately if DOM is already loaded
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    new HolidayPlanner();
}
