/**
 * Polling Module
 *
 * Handles periodic data refresh and session keepalive.
 */

window.Polling = {
    locationInterval: null,
    keepaliveInterval: null,
    isRunning: false,
    isFirstFetch: true,

    // Intervals in ms
    LOCATION_POLL_MS: 10000, // 10 seconds
    KEEPALIVE_MS: 30000, // 30 seconds

    init() {
        // Start polling when map is ready
        window.addEventListener('map:ready', () => this.start());

        // Stop when page is hidden
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stop();
            } else {
                this.start();
            }
        });

        // Add click handler for wake button
        const indicator = document.getElementById('session-indicator');
        if (indicator) {
            indicator.addEventListener('click', () => this.handleWakeClick());
        }
    },

    start() {
        if (this.isRunning) return;
        this.isRunning = true;

        // Initial fetch
        this.fetchLocations();
        this.sendKeepalive();

        // Start intervals
        this.locationInterval = setInterval(() => this.fetchLocations(), this.LOCATION_POLL_MS);
        this.keepaliveInterval = setInterval(() => this.sendKeepalive(), this.KEEPALIVE_MS);

        console.log('Polling started');
    },

    stop() {
        this.isRunning = false;

        if (this.locationInterval) {
            clearInterval(this.locationInterval);
            this.locationInterval = null;
        }

        if (this.keepaliveInterval) {
            clearInterval(this.keepaliveInterval);
            this.keepaliveInterval = null;
        }

        console.log('Polling stopped');
    },

    async fetchLocations() {
        try {
            const response = await TrackingAPI.getCurrent();

            if (response.success) {
                TrackingState.setMembers(response.data.members);
                TrackingState.setSession(response.data.session);

                // Update session indicator (pass members to check for fresh data)
                this.updateSessionIndicator(response.data.session, response.data.members);

                // Hide loading on first successful fetch
                document.getElementById('loading-overlay').classList.add('hidden');

                // On first fetch, zoom to closest family member
                if (this.isFirstFetch && response.data.members && response.data.members.length > 0) {
                    this.isFirstFetch = false;
                    this.zoomToClosestMember(response.data.members);
                }
            }
        } catch (err) {
            console.error('Failed to fetch locations:', err);

            if (err.status === 401) {
                // Session expired, redirect to login
                window.location.href = '/login.php';
            }
        }
    },

    /**
     * Zoom to the closest family member based on user's current location
     */
    async zoomToClosestMember(members) {
        // Filter members with valid locations (exclude current user)
        const membersWithLocation = members.filter(m =>
            m.lat && m.lng && m.user_id !== window.TRACKING_CONFIG.userId
        );

        if (membersWithLocation.length === 0) {
            // No other members with location, try to fit all or use default
            if (members.length > 0) {
                TrackingMap.fitAll();
            }
            return;
        }

        try {
            // Try to get user's current location
            const userLocation = await TrackingMap.getCurrentLocation();

            // Find closest member
            let closestMember = null;
            let closestDistance = Infinity;

            membersWithLocation.forEach(member => {
                const distance = this.calculateDistance(
                    userLocation.lat, userLocation.lng,
                    parseFloat(member.lat), parseFloat(member.lng)
                );

                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestMember = member;
                }
            });

            if (closestMember) {
                console.log(`Zooming to closest member: ${closestMember.name} (${Math.round(closestDistance)}m away)`);
                TrackingMap.panTo(parseFloat(closestMember.lat), parseFloat(closestMember.lng), 15);

                // Optionally select the member
                TrackingState.selectMember(closestMember.user_id);
            }
        } catch (err) {
            // Could not get user location, just fit all members
            console.log('Could not get user location, fitting all members');
            TrackingMap.fitAll();
        }
    },

    /**
     * Calculate distance between two points using Haversine formula
     */
    calculateDistance(lat1, lng1, lat2, lng2) {
        const R = 6371000; // Earth's radius in meters
        const dLat = this.toRad(lat2 - lat1);
        const dLng = this.toRad(lng2 - lng1);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(this.toRad(lat1)) * Math.cos(this.toRad(lat2)) *
                  Math.sin(dLng / 2) * Math.sin(dLng / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    },

    toRad(deg) {
        return deg * (Math.PI / 180);
    },

    async sendKeepalive() {
        try {
            const response = await TrackingAPI.keepalive();

            if (response.success) {
                this.updateSessionIndicator({
                    active: true,
                    expires_in_seconds: response.data.session.expires_in_seconds
                });
            }
        } catch (err) {
            console.error('Keepalive failed:', err);
        }
    },

    updateSessionIndicator(session, members = null) {
        const indicator = document.getElementById('session-indicator');
        const text = indicator.querySelector('.session-text');

        // Remove all state classes
        indicator.classList.remove('session-sleeping', 'session-active', 'session-waking', 'session-stale', 'hidden');

        if (session && session.active) {
            // Check if any member has fresh data (not stale)
            const hasRecentData = members && members.some(m => m.status === 'active');

            if (hasRecentData) {
                // Active session with recent data - green indicator
                indicator.classList.add('session-active');
                text.textContent = 'Live session active';
                indicator.title = 'Click to refresh locations';
            } else {
                // Active session but all data is stale - yellow/warning indicator
                indicator.classList.add('session-stale');
                text.textContent = 'No recent updates';
                indicator.title = 'Click to request fresh locations';
            }
        } else {
            // No session - show wake button
            indicator.classList.add('session-sleeping');
            text.textContent = 'Wake Devices';
            indicator.title = 'Click to wake tracking devices';
        }
    },

    async handleWakeClick() {
        var indicator = document.getElementById('session-indicator');
        var text = indicator.querySelector('.session-text');
        var originalText = text.textContent;
        var wasWaking = indicator.classList.contains('session-waking');

        // Don't double-click while waking
        if (wasWaking) {
            return;
        }

        // Show refreshing state
        indicator.classList.remove('session-sleeping', 'session-active', 'session-stale');
        indicator.classList.add('session-waking');
        text.textContent = 'Waking devices...';

        try {
            // 1. Wake THIS device's native tracking (if running in native app)
            if (window.NativeBridge && window.NativeBridge.isNativeApp) {
                if (window.TrackingBridge && window.TrackingBridge.wakeAllDevices) {
                    window.TrackingBridge.wakeAllDevices();
                }
            }

            // 2. Call wake_devices API to send FCM push to ALL family devices
            var wakeResponse = await TrackingAPI.request('wake_devices.php', {
                method: 'POST'
            });

            var devicesNotified = 0;
            if (wakeResponse && wakeResponse.success && wakeResponse.data) {
                devicesNotified = wakeResponse.data.devices_notified || 0;
            }

            // 3. Also send keepalive to extend session
            await this.sendKeepalive();

            // 4. Immediately fetch fresh locations
            await this.fetchLocations();

            // 5. Start polling if not already running
            if (!this.isRunning) {
                this.start();
            }

            // Show success toast with device count
            if (window.Toast) {
                if (devicesNotified > 0) {
                    Toast.show(devicesNotified + ' device' + (devicesNotified > 1 ? 's' : '') + ' notified - waiting for updates', 'success');
                } else {
                    Toast.show('Session active - waiting for updates', 'success');
                }
            }
        } catch (err) {
            console.error('Failed to wake devices:', err);

            // Revert to previous state
            indicator.classList.remove('session-waking');
            indicator.classList.add('session-sleeping');
            text.textContent = originalText;

            if (window.Toast) {
                Toast.show('Could not wake devices. Try again.', 'error');
            }
        }
    }
};
