/**
 * Polling Module
 *
 * Handles periodic data refresh and session keepalive.
 */

window.Polling = {
    locationInterval: null,
    keepaliveInterval: null,
    isRunning: false,

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

                // Update session indicator
                this.updateSessionIndicator(response.data.session);

                // Hide loading on first successful fetch
                document.getElementById('loading-overlay').classList.add('hidden');
            }
        } catch (err) {
            console.error('Failed to fetch locations:', err);

            if (err.status === 401) {
                // Session expired, redirect to login
                window.location.href = '/login.php';
            }
        }
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

    updateSessionIndicator(session) {
        const indicator = document.getElementById('session-indicator');

        if (session && session.active) {
            indicator.classList.remove('hidden');
        } else {
            indicator.classList.add('hidden');
        }
    }
};
