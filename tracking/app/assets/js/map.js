/**
 * Map Module
 */

window.TrackingMap = {
    map: null,
    markers: {},
    geofenceCircles: {},
    directionsLayer: null,
    currentTheme: 'dark',

    // Map style URLs
    themes: {
        dark: 'mapbox://styles/mapbox/dark-v11',
        light: 'mapbox://styles/mapbox/light-v11',
        satellite: 'mapbox://styles/mapbox/satellite-streets-v12'
    },

    /**
     * Initialize map
     */
    init() {
        const config = window.TRACKING_CONFIG;

        mapboxgl.accessToken = config.mapboxToken;

        // Load saved theme from localStorage
        const savedTheme = localStorage.getItem('tracking_map_theme');
        if (savedTheme && this.themes[savedTheme]) {
            this.currentTheme = savedTheme;
        }

        this.map = new mapboxgl.Map({
            container: 'map',
            style: this.themes[this.currentTheme],
            center: [config.defaultCenter[1], config.defaultCenter[0]], // lng, lat
            zoom: config.defaultZoom,
            attributionControl: false
        });

        // Add controls
        this.map.addControl(new mapboxgl.AttributionControl({
            compact: true
        }), 'bottom-left');

        // Map loaded
        this.map.on('load', () => {
            this.onMapLoaded();
        });

        // Listen for state changes
        TrackingState.on('members:updated', (members) => this.updateMarkers(members));
        TrackingState.on('geofences:toggled', (show) => this.toggleGeofences(show));
        TrackingState.on('directions:updated', (route) => this.showDirections(route));
        TrackingState.on('directions:cleared', () => this.clearDirections());
    },

    /**
     * Map loaded callback
     */
    onMapLoaded() {
        // Add directions source/layer
        this.map.addSource('directions', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'directions-line',
            type: 'line',
            source: 'directions',
            layout: {
                'line-join': 'round',
                'line-cap': 'round'
            },
            paint: {
                'line-color': '#667eea',
                'line-width': 4,
                'line-opacity': 0.8
            }
        });

        // Load initial data
        window.dispatchEvent(new Event('map:ready'));
    },

    /**
     * Update member markers
     */
    updateMarkers(members) {
        const existingIds = new Set(Object.keys(this.markers));
        const currentIds = new Set(members.map(m => String(m.user_id)));

        // Remove markers for members no longer present
        existingIds.forEach(id => {
            if (!currentIds.has(id)) {
                this.markers[id].remove();
                delete this.markers[id];
            }
        });

        // Add/update markers
        members.forEach(member => {
            const id = String(member.user_id);

            if (this.markers[id]) {
                // Update existing
                this.updateMarker(id, member);
            } else {
                // Create new
                this.createMarker(member);
            }
        });

        // If following, pan to member
        if (TrackingState.followingMember) {
            const member = members.find(m => m.user_id === TrackingState.followingMember);
            if (member) {
                this.panTo(member.lat, member.lng);
            }
        }
    },

    /**
     * Create marker for member
     */
    createMarker(member) {
        const el = document.createElement('div');
        el.className = 'marker';
        el.innerHTML = `
            <div class="marker-pin" style="--pin-color: ${member.avatar_color}">
                <div class="marker-avatar">
                    <img src="${member.avatar_url}"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                         alt="${member.name}">
                    <span class="marker-fallback">${Format.initials(member.name)}</span>
                </div>
                <div class="marker-point"></div>
            </div>
            <div class="marker-pulse"></div>
        `;

        // Update class based on status
        this.updateMarkerClass(el, member);

        // Click handler
        el.addEventListener('click', () => {
            TrackingState.selectMember(member.user_id);
            this.panTo(member.lat, member.lng);
        });

        const marker = new mapboxgl.Marker(el)
            .setLngLat([member.lng, member.lat])
            .addTo(this.map);

        const markerId = String(member.user_id);
        this.markers[markerId] = marker;
        this.markers[markerId].element = el;
        this.markers[markerId].data = member;
    },

    /**
     * Update existing marker
     */
    updateMarker(id, member) {
        const marker = this.markers[id];
        marker.setLngLat([member.lng, member.lat]);
        marker.data = member;
        this.updateMarkerClass(marker.element, member);
    },

    /**
     * Update marker CSS class based on status
     */
    updateMarkerClass(el, member) {
        el.classList.remove('moving', 'stale', 'offline');

        if (member.status === 'offline') {
            el.classList.add('offline');
        } else if (member.status === 'stale') {
            el.classList.add('stale');
        } else if (member.motion_state === 'moving') {
            el.classList.add('moving');
        }
    },

    /**
     * Pan to location
     */
    panTo(lat, lng, zoom = null) {
        const options = {
            center: [lng, lat],
            duration: 500
        };
        if (zoom) options.zoom = zoom;

        this.map.easeTo(options);
    },

    /**
     * Fit all members in view
     */
    fitAll() {
        const members = TrackingState.members;
        if (members.length === 0) return;

        if (members.length === 1) {
            this.panTo(members[0].lat, members[0].lng, 14);
            return;
        }

        const bounds = new mapboxgl.LngLatBounds();
        members.forEach(m => bounds.extend([m.lng, m.lat]));

        this.map.fitBounds(bounds, {
            padding: 80,
            maxZoom: 15,
            duration: 500
        });
    },

    /**
     * Toggle geofences visibility
     */
    toggleGeofences(show) {
        if (show) {
            this.showGeofences();
        } else {
            this.hideGeofences();
        }
    },

    /**
     * Show geofences on map
     */
    async showGeofences() {
        try {
            const response = await TrackingAPI.getGeofences();
            const geofences = response.data.geofences;

            geofences.forEach(geo => {
                if (geo.type === 'circle' && !this.geofenceCircles[geo.id]) {
                    this.addGeofenceCircle(geo);
                }
            });
        } catch (err) {
            console.error('Failed to load geofences:', err);
        }
    },

    /**
     * Add geofence circle to map
     */
    addGeofenceCircle(geofence) {
        // Create circle as GeoJSON source
        const sourceId = `geofence-${geofence.id}`;

        if (!this.map.getSource(sourceId)) {
            this.map.addSource(sourceId, {
                type: 'geojson',
                data: this.createCircleGeoJSON(
                    geofence.center_lat,
                    geofence.center_lng,
                    geofence.radius_m
                )
            });

            this.map.addLayer({
                id: `${sourceId}-fill`,
                type: 'fill',
                source: sourceId,
                paint: {
                    'fill-color': '#667eea',
                    'fill-opacity': 0.15
                }
            });

            this.map.addLayer({
                id: `${sourceId}-line`,
                type: 'line',
                source: sourceId,
                paint: {
                    'line-color': '#667eea',
                    'line-width': 2,
                    'line-opacity': 0.6
                }
            });
        }

        // Add label marker
        const labelEl = document.createElement('div');
        labelEl.className = 'geofence-label';
        labelEl.textContent = geofence.name;

        const marker = new mapboxgl.Marker(labelEl)
            .setLngLat([geofence.center_lng, geofence.center_lat])
            .addTo(this.map);

        this.geofenceCircles[geofence.id] = { sourceId, marker };
    },

    /**
     * Create circle GeoJSON (approximation using polygon)
     */
    createCircleGeoJSON(lat, lng, radiusM) {
        const points = 64;
        const coords = [];

        for (let i = 0; i <= points; i++) {
            const angle = (i / points) * 2 * Math.PI;
            const dx = radiusM * Math.cos(angle);
            const dy = radiusM * Math.sin(angle);

            // Convert to lat/lng offset
            const latOffset = dy / 111320;
            const lngOffset = dx / (111320 * Math.cos(lat * Math.PI / 180));

            coords.push([lng + lngOffset, lat + latOffset]);
        }

        return {
            type: 'Feature',
            geometry: {
                type: 'Polygon',
                coordinates: [coords]
            }
        };
    },

    /**
     * Hide geofences
     */
    hideGeofences() {
        Object.keys(this.geofenceCircles).forEach(id => {
            const geo = this.geofenceCircles[id];

            if (this.map.getLayer(`${geo.sourceId}-fill`)) {
                this.map.removeLayer(`${geo.sourceId}-fill`);
            }
            if (this.map.getLayer(`${geo.sourceId}-line`)) {
                this.map.removeLayer(`${geo.sourceId}-line`);
            }
            if (this.map.getSource(geo.sourceId)) {
                this.map.removeSource(geo.sourceId);
            }

            geo.marker.remove();
        });

        this.geofenceCircles = {};
    },

    /**
     * Show directions route
     */
    showDirections(route) {
        if (!route || !route.geometry) return;

        this.map.getSource('directions').setData(route.geometry);

        // Fit route in view
        const coords = route.geometry.coordinates;
        const bounds = coords.reduce((b, c) => b.extend(c), new mapboxgl.LngLatBounds(coords[0], coords[0]));

        this.map.fitBounds(bounds, {
            padding: 80,
            duration: 500
        });
    },

    /**
     * Clear directions route
     */
    clearDirections() {
        this.map.getSource('directions').setData({
            type: 'FeatureCollection',
            features: []
        });
    },

    /**
     * Set map theme/style
     */
    setTheme(theme) {
        if (!this.themes[theme]) return;

        this.currentTheme = theme;
        localStorage.setItem('tracking_map_theme', theme);

        // Change map style
        this.map.setStyle(this.themes[theme]);

        // Re-add directions layer after style change
        this.map.once('style.load', () => {
            // Re-add directions source/layer
            if (!this.map.getSource('directions')) {
                this.map.addSource('directions', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });

                this.map.addLayer({
                    id: 'directions-line',
                    type: 'line',
                    source: 'directions',
                    layout: {
                        'line-join': 'round',
                        'line-cap': 'round'
                    },
                    paint: {
                        'line-color': '#667eea',
                        'line-width': 4,
                        'line-opacity': 0.8
                    }
                });
            }

            // Re-add geofences if they were showing
            if (TrackingState.showGeofences) {
                this.geofenceCircles = {};
                this.showGeofences();
            }
        });

        // Dispatch theme changed event
        window.dispatchEvent(new CustomEvent('map:themeChanged', { detail: { theme } }));
    },

    /**
     * Get current theme
     */
    getTheme() {
        return this.currentTheme;
    },

    /**
     * Get user's current location
     */
    getCurrentLocation() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation not supported'));
                return;
            }

            // Check if HTTPS (required for geolocation in modern browsers)
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                reject({ code: 0, message: 'HTTPS required for geolocation' });
                return;
            }

            navigator.geolocation.getCurrentPosition(
                pos => resolve({
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy
                }),
                err => reject(err),
                {
                    enableHighAccuracy: true,
                    timeout: 15000,        // Increased from 10s to 15s
                    maximumAge: 60000      // Accept cached position up to 1 minute old
                }
            );
        });
    }
};
