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

                // Provide specific error messages based on error code and platform
                let message = 'Could not get your location';
                const isNativeApp = window.NativeBridge?.isNativeApp;

                if (err.code === 1) {
                    // PERMISSION_DENIED
                    if (isNativeApp) {
                        message = 'Location permission denied. Please enable location access in your device settings for this app.';
                    } else {
                        message = 'Location permission denied. Please enable location access in your browser settings.';
                    }
                } else if (err.code === 2) {
                    // POSITION_UNAVAILABLE
                    message = 'Location unavailable. Please check if GPS/Location is enabled on your device.';
                } else if (err.code === 3) {
                    // TIMEOUT
                    if (isNativeApp) {
                        message = 'Location request timed out. Please ensure GPS is enabled and try again.';
                    } else {
                        message = 'Location request timed out. Please try again or check your connection.';
                    }
                } else if (!navigator.geolocation && !isNativeApp) {
                    message = 'Geolocation is not supported by your browser.';
                } else if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && !isNativeApp) {
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

        // Map theme button
        document.getElementById('btn-map-theme').addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleThemePicker();
        });

        // Theme picker options
        document.querySelectorAll('.theme-option').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const theme = btn.dataset.theme;
                TrackingMap.setTheme(theme);
                this.updateThemeSelection(theme);
                this.hideThemePicker();
            });
        });

        // Close theme picker on outside click
        document.addEventListener('click', (e) => {
            const themePicker = document.getElementById('theme-picker');
            const themeBtn = document.getElementById('btn-map-theme');
            if (!themePicker.contains(e.target) && !themeBtn.contains(e.target)) {
                this.hideThemePicker();
            }
        });

        // Initialize theme selection indicator
        this.updateThemeSelection(TrackingMap.currentTheme || 'dark');

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

        // Update popup content with avatar image
        const avatar = document.getElementById('popup-avatar');
        avatar.style.backgroundColor = member.avatar_color;
        avatar.innerHTML = `
            <img src="${member.avatar_url}"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                 style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
            <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;">${Format.initials(member.name)}</span>
        `;

        document.getElementById('popup-name').textContent = member.name;

        let status = Format.statusText(member.status, member.motion_state);
        if (member.updated_at) {
            status += ' - ' + Format.timeAgo(member.updated_at);
        }
        document.getElementById('popup-status').textContent = status;

        // Update member details
        this.updateMemberDetails(member);

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
    },

    /**
     * Toggle theme picker visibility
     */
    toggleThemePicker() {
        const themePicker = document.getElementById('theme-picker');
        themePicker.classList.toggle('hidden');

        if (!themePicker.classList.contains('hidden')) {
            document.getElementById('btn-map-theme').classList.add('active');
        } else {
            document.getElementById('btn-map-theme').classList.remove('active');
        }
    },

    /**
     * Hide theme picker
     */
    hideThemePicker() {
        const themePicker = document.getElementById('theme-picker');
        themePicker.classList.add('hidden');
        document.getElementById('btn-map-theme').classList.remove('active');
    },

    /**
     * Update theme selection indicator
     */
    updateThemeSelection(theme) {
        document.querySelectorAll('.theme-option').forEach(btn => {
            if (btn.dataset.theme === theme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    },

    /**
     * Update member details in popup
     */
    updateMemberDetails(member) {
        // Speed
        const speedRow = document.getElementById('detail-speed');
        const speedValue = document.getElementById('popup-speed');
        if (member.speed_mps && member.speed_mps > 0) {
            speedValue.textContent = Format.speed(member.speed_mps);
            speedRow.classList.remove('hidden');
        } else {
            speedValue.textContent = 'Stationary';
            speedRow.classList.remove('hidden');
        }

        // Bearing / Direction
        const bearingRow = document.getElementById('detail-bearing');
        const bearingValue = document.getElementById('popup-bearing');
        if (member.bearing_deg !== null && member.bearing_deg !== undefined) {
            bearingValue.textContent = this.formatBearing(member.bearing_deg);
            bearingRow.classList.remove('hidden');
        } else {
            bearingRow.classList.add('hidden');
        }

        // Accuracy
        const accuracyRow = document.getElementById('detail-accuracy');
        const accuracyValue = document.getElementById('popup-accuracy');
        if (member.accuracy_m) {
            accuracyValue.textContent = '±' + Math.round(member.accuracy_m) + ' m';
            accuracyRow.classList.remove('hidden');
        } else {
            accuracyRow.classList.add('hidden');
        }

        // Updated time
        const updatedValue = document.getElementById('popup-updated');
        if (member.updated_at) {
            updatedValue.textContent = Format.timeAgo(member.updated_at);
        } else if (member.recorded_at) {
            updatedValue.textContent = Format.timeAgo(member.recorded_at);
        } else {
            updatedValue.textContent = '--';
        }

        // Coordinates / Address
        const coordsValue = document.getElementById('popup-coordinates');
        if (member.lat && member.lng) {
            coordsValue.textContent = 'Loading address...';
            this.reverseGeocode(member.lat, member.lng).then(address => {
                coordsValue.textContent = address;
            });
        } else {
            coordsValue.textContent = '--';
        }
    },

    /**
     * Reverse geocode coordinates to address
     */
    async reverseGeocode(lat, lng) {
        try {
            const token = window.TRACKING_CONFIG.mapboxToken;
            const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${lng},${lat}.json?access_token=${token}&types=address,place,locality,neighborhood`;

            const response = await fetch(url);
            const data = await response.json();

            if (data.features && data.features.length > 0) {
                // Get the most specific address available
                const place = data.features[0];
                return place.place_name.split(',').slice(0, 2).join(',');
            }
            return lat.toFixed(5) + ', ' + lng.toFixed(5);
        } catch (err) {
            console.error('Geocoding error:', err);
            return lat.toFixed(5) + ', ' + lng.toFixed(5);
        }
    },

    /**
     * Format bearing to compass direction
     */
    formatBearing(degrees) {
        const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        const index = Math.round(degrees / 45) % 8;
        return directions[index] + ' (' + Math.round(degrees) + '°)';
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
