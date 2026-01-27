/**
 * API Client
 */

window.TrackingAPI = {
    baseUrl: window.TRACKING_CONFIG?.apiBase || '/tracking/api',

    /**
     * Make API request
     */
    async request(endpoint, options = {}) {
        const url = this.baseUrl + '/' + endpoint;
        const config = {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        };

        if (options.body && typeof options.body === 'object') {
            config.body = JSON.stringify(options.body);
        }

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw {
                    status: response.status,
                    error: data.error || 'request_failed',
                    message: data.message || 'Request failed'
                };
            }

            return data;
        } catch (err) {
            if (err.status) throw err;
            throw {
                status: 0,
                error: 'network_error',
                message: 'Network error. Please check your connection.'
            };
        }
    },

    // Session endpoints
    async keepalive() {
        return this.request('keepalive.php', { method: 'POST' });
    },

    async getSessionStatus() {
        return this.request('session_status.php');
    },

    // Location endpoints
    async sendLocation(location) {
        return this.request('location.php', {
            method: 'POST',
            body: location
        });
    },

    async sendBatch(locations) {
        return this.request('batch.php', {
            method: 'POST',
            body: { locations }
        });
    },

    async getCurrent() {
        return this.request('current.php');
    },

    async getHistory(params = {}) {
        const query = new URLSearchParams(params).toString();
        return this.request('history.php?' + query);
    },

    // Settings endpoints
    async getSettings() {
        return this.request('settings_get.php');
    },

    async saveSettings(settings) {
        return this.request('settings_save.php', {
            method: 'POST',
            body: settings
        });
    },

    // Places endpoints
    async getPlaces() {
        return this.request('places_list.php');
    },

    async addPlace(place) {
        return this.request('places_add.php', {
            method: 'POST',
            body: place
        });
    },

    async deletePlace(id) {
        return this.request('places_delete.php?id=' + id, {
            method: 'DELETE'
        });
    },

    // Geofence endpoints
    async getGeofences() {
        return this.request('geofences_list.php');
    },

    async addGeofence(geofence) {
        return this.request('geofences_add.php', {
            method: 'POST',
            body: geofence
        });
    },

    async updateGeofence(id, data) {
        return this.request('geofences_update.php?id=' + id, {
            method: 'PUT',
            body: data
        });
    },

    async deleteGeofence(id) {
        return this.request('geofences_delete.php?id=' + id, {
            method: 'DELETE'
        });
    },

    // Events endpoints
    async getEvents(params = {}) {
        const query = new URLSearchParams(params).toString();
        return this.request('events_list.php?' + query);
    },

    // Alerts endpoints
    async getAlertRules() {
        return this.request('alerts_rules_get.php');
    },

    async saveAlertRules(rules) {
        return this.request('alerts_rules_save.php', {
            method: 'POST',
            body: rules
        });
    },

    // Directions endpoint
    async getDirections(from, to, profile = 'driving') {
        const params = new URLSearchParams({
            from_lat: from.lat,
            from_lng: from.lng,
            profile
        });

        if (to.userId) {
            params.append('to_user_id', to.userId);
        } else if (to.placeId) {
            params.append('to_place_id', to.placeId);
        } else {
            params.append('to_lat', to.lat);
            params.append('to_lng', to.lng);
        }

        return this.request('directions.php?' + params.toString());
    }
};
