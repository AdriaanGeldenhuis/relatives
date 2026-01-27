/**
 * UI Controls Module
 */

window.UIControls = {
    popup: null,

    init() {
        this.popup = document.getElementById('member-popup');

        // Center all button
        document.getElementById('btn-center-all').addEventListener('click', () => {
            TrackingMap.fitAll();
            TrackingState.stopFollowing();
        });

        // My location button
        document.getElementById('btn-my-location').addEventListener('click', async () => {
            try {
                const loc = await TrackingMap.getCurrentLocation();
                TrackingMap.panTo(loc.lat, loc.lng, 15);
            } catch (err) {
                console.error('Geolocation error:', err);

                // Provide specific error messages based on error code
                let message = 'Could not get your location';

                if (err.code === 1) {
                    // PERMISSION_DENIED
                    message = 'Location permission denied. Please enable location access in your browser settings.';
                } else if (err.code === 2) {
                    // POSITION_UNAVAILABLE
                    message = 'Location unavailable. Please check if GPS/Location is enabled on your device.';
                } else if (err.code === 3) {
                    // TIMEOUT
                    message = 'Location request timed out. Please try again.';
                } else if (!navigator.geolocation) {
                    message = 'Geolocation is not supported by your browser.';
                } else if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                    message = 'Location requires HTTPS. Please use a secure connection.';
                }

                Toast.show(message, 'error');
            }
        });

        // Geofences button
        document.getElementById('btn-geofences').addEventListener('click', () => {
            TrackingState.toggleGeofences();
            document.getElementById('btn-geofences').classList.toggle('active', TrackingState.showGeofences);
        });

        // Events button
        document.getElementById('btn-events').addEventListener('click', () => {
            window.location.href = 'events.php';
        });

        // Settings button
        document.getElementById('btn-settings').addEventListener('click', () => {
            window.location.href = 'settings.php';
        });

        // Popup controls
        document.getElementById('popup-close').addEventListener('click', () => this.hidePopup());
        document.getElementById('btn-follow').addEventListener('click', () => this.startFollow());
        document.getElementById('btn-directions').addEventListener('click', () => this.getDirections());

        // Listen for member selection
        TrackingState.on('member:selected', (userId) => {
            if (userId) {
                this.showPopup(userId);
            } else {
                this.hidePopup();
            }
        });

        // Click outside popup to close
        document.addEventListener('click', (e) => {
            if (!this.popup.contains(e.target) &&
                !e.target.closest('.marker') &&
                !e.target.closest('.member-item') &&
                !this.popup.classList.contains('hidden')) {
                TrackingState.clearSelection();
            }
        });
    },

    showPopup(userId) {
        const member = TrackingState.getMember(userId);
        if (!member) return;

        // Update popup content
        const avatar = document.getElementById('popup-avatar');
        avatar.style.backgroundColor = member.avatar_color;
        avatar.textContent = Format.initials(member.name);

        document.getElementById('popup-name').textContent = member.name;

        let status = Format.statusText(member.status, member.motion_state);
        if (member.updated_at) {
            status += ' - ' + Format.timeAgo(member.updated_at);
        }
        document.getElementById('popup-status').textContent = status;

        // Update follow button text
        const followBtn = document.getElementById('btn-follow');
        if (TrackingState.followingMember === userId) {
            followBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                </svg>
                Unfollow
            `;
        } else {
            followBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                Follow
            `;
        }

        this.popup.classList.remove('hidden');
    },

    hidePopup() {
        this.popup.classList.add('hidden');
    },

    startFollow() {
        const userId = TrackingState.selectedMember;
        if (!userId) return;

        if (TrackingState.followingMember === userId) {
            TrackingState.stopFollowing();
            Toast.show('Stopped following');
        } else {
            TrackingState.startFollowing(userId);
            const member = TrackingState.getMember(userId);
            Toast.show(`Following ${member.name}`);
        }

        this.showPopup(userId); // Refresh popup
    },

    async getDirections() {
        const userId = TrackingState.selectedMember;
        if (!userId) return;

        try {
            const myLoc = await TrackingMap.getCurrentLocation();

            const response = await TrackingAPI.getDirections(
                { lat: myLoc.lat, lng: myLoc.lng },
                { userId }
            );

            if (response.success) {
                TrackingState.setDirectionsRoute(response.data);
                Directions.show(response.data);
                this.hidePopup();
            }
        } catch (err) {
            Toast.show(err.message || 'Could not get directions', 'error');
        }
    }
};

/**
 * Toast notifications
 */
window.Toast = {
    container: null,

    init() {
        this.container = document.getElementById('toast-container');
    },

    show(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;

        this.container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
};
