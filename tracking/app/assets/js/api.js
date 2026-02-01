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
        return this.request('simple_location.php', {
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
        return this.request('simple_current.php');
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

    // Directions - calls Mapbox API directly from browser (uses public token)
    async getDirections(from, to, profile = 'driving') {
        // Determine destination coordinates
        let toLat, toLng, destinationName;

        if (to.userId) {
            // Get member location from state
            const member = TrackingState.getMember(to.userId);
            if (!member) {
                throw {
                    status: 404,
                    error: 'user_not_found',
                    message: 'User location not available'
                };
            }
            toLat = member.lat;
            toLng = member.lng;
            destinationName = member.name;
        } else if (to.placeId) {
            // For places, we'd need to fetch from API first
            // For now, require lat/lng directly
            throw {
                status: 400,
                error: 'not_implemented',
                message: 'Place directions not yet supported. Use lat/lng.'
            };
        } else {
            toLat = to.lat;
            toLng = to.lng;
            destinationName = to.name || null;
        }

        // Call Mapbox Directions API directly (works with public token)
        const token = window.TRACKING_CONFIG.mapboxToken;
        if (!token) {
            throw {
                status: 503,
                error: 'service_unavailable',
                message: 'Mapbox token not configured'
            };
        }

        const coordinates = `${from.lng},${from.lat};${toLng},${toLat}`;
        const url = `https://api.mapbox.com/directions/v5/mapbox/${profile}/${coordinates}?access_token=${token}&geometries=geojson&overview=full`;

        try {
            const response = await fetch(url);
            const data = await response.json();

            if (!response.ok) {
                throw {
                    status: response.status,
                    error: 'mapbox_error',
                    message: data.message || 'Mapbox API error'
                };
            }

            if (!data.routes || !data.routes[0]) {
                throw {
                    status: 404,
                    error: 'no_route',
                    message: 'No route found between locations'
                };
            }

            const route = data.routes[0];

            // Format result to match expected structure
            const result = {
                success: true,
                data: {
                    profile: profile,
                    from: { lat: from.lat, lng: from.lng },
                    to: { lat: toLat, lng: toLng },
                    distance_m: Math.round(route.distance),
                    duration_s: Math.round(route.duration),
                    distance_text: this.formatDistance(route.distance),
                    duration_text: this.formatDuration(route.duration),
                    geometry: route.geometry,
                    destination_name: destinationName,
                    to_user_id: to.userId || null
                }
            };

            return result;
        } catch (err) {
            if (err.status) throw err;
            throw {
                status: 0,
                error: 'network_error',
                message: 'Could not connect to Mapbox. Check your internet connection.'
            };
        }
    },

    // Helper: Format distance for display
    formatDistance(meters) {
        if (meters < 1000) {
            return Math.round(meters) + ' m';
        }
        const km = meters / 1000;
        if (km < 10) {
            return km.toFixed(1) + ' km';
        }
        return Math.round(km) + ' km';
    },

    // Helper: Format duration for display
    formatDuration(seconds) {
        if (seconds < 60) {
            return 'Less than 1 min';
        }
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) {
            return minutes + ' min';
        }
        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;
        if (mins === 0) {
            return hours + ' hr';
        }
        return hours + ' hr ' + mins + ' min';
    }
};
