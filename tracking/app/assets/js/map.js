/**
 * Tracking App - Mapbox Map Integration
 *
 * Manages the Mapbox GL JS map instance, member markers with custom HTML
 * avatars, geofence overlays, and route lines.
 *
 * Requires:
 *   - Mapbox GL JS v3 loaded globally (mapboxgl)
 *   - window.MAPBOX_TOKEN set before init()
 *   - Tracking.format (for popups)
 *
 * Usage:
 *   Tracking.map.init('map-container');
 *   Tracking.map.updateMemberMarkers(members);
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    // Default map centre: South Africa
    var DEFAULT_CENTER = [25.5, -28.5]; // [lng, lat]
    var DEFAULT_ZOOM = 5;

    /** @type {mapboxgl.Map|null} */
    var map = null;

    /**
     * Marker store keyed by member id.
     * @type {Object.<string|number, mapboxgl.Marker>}
     */
    var markers = {};

    /** Geofence source/layer names for easy cleanup. */
    var GEOFENCE_SOURCE = 'geofences-source';
    var GEOFENCE_FILL_LAYER = 'geofences-fill';
    var GEOFENCE_LINE_LAYER = 'geofences-line';

    /** Route source/layer names. */
    var ROUTE_SOURCE = 'route-source';
    var ROUTE_LAYER = 'route-layer';

    // -----------------------------------------------------------------------
    // Initialisation
    // -----------------------------------------------------------------------

    /**
     * Create and attach the Mapbox GL map.
     *
     * @param {string} containerId - DOM element id for the map container.
     * @returns {mapboxgl.Map} The map instance.
     */
    function init(containerId) {
        if (map) {
            console.warn('[Map] Already initialised.');
            return map;
        }

        mapboxgl.accessToken = window.MAPBOX_TOKEN;

        map = new mapboxgl.Map({
            container: containerId,
            style: 'mapbox://styles/mapbox/streets-v12',
            center: DEFAULT_CENTER,
            zoom: DEFAULT_ZOOM,
            attributionControl: true,
        });

        // Navigation controls (zoom, compass).
        map.addControl(new mapboxgl.NavigationControl(), 'top-right');

        // Geolocation control.
        map.addControl(
            new mapboxgl.GeolocateControl({
                positionOptions: { enableHighAccuracy: true },
                trackUserLocation: true,
                showUserHeading: true,
            }),
            'top-right'
        );

        map.on('load', function () {
            Tracking.setState('mapReady', true);
            addGeofenceSourceAndLayers();
            addRouteSourceAndLayer();
        });

        return map;
    }

    /**
     * Return the underlying mapboxgl.Map instance (or null).
     * @returns {mapboxgl.Map|null}
     */
    function getMap() {
        return map;
    }

    // -----------------------------------------------------------------------
    // Member markers
    // -----------------------------------------------------------------------

    /**
     * Derive a consistent colour from a member's name or id.
     *
     * @param {Object} member
     * @returns {string} A hex colour.
     */
    function memberColor(member) {
        if (member.color) {
            return member.color;
        }
        var COLORS = [
            '#ef4444', '#3b82f6', '#22c55e', '#a855f7',
            '#f97316', '#06b6d4', '#ec4899', '#14b8a6',
        ];
        var hash = 0;
        var str = String(member.id || member.name || '');
        for (var i = 0; i < str.length; i++) {
            hash = str.charCodeAt(i) + ((hash << 5) - hash);
        }
        return COLORS[Math.abs(hash) % COLORS.length];
    }

    /**
     * Build the custom HTML element for a member marker.
     *
     * @param {Object} member
     * @returns {HTMLElement}
     */
    function buildMarkerElement(member) {
        var color = memberColor(member);
        var initial = (member.name || '?').charAt(0).toUpperCase();

        var el = document.createElement('div');
        el.className = 'tracking-marker';
        el.style.cssText =
            'width:36px;height:36px;border-radius:50%;background:' + color +
            ';color:#fff;display:flex;align-items:center;justify-content:center;' +
            'font-weight:700;font-size:16px;border:3px solid #fff;' +
            'box-shadow:0 2px 6px rgba(0,0,0,0.3);cursor:pointer;';
        el.textContent = initial;
        el.title = member.name || 'Member';

        return el;
    }

    /**
     * Build popup HTML for a member marker.
     *
     * @param {Object} member
     * @returns {string}
     */
    function buildPopupHTML(member) {
        var fmt = Tracking.format || {};
        var speedText = fmt.speed ? fmt.speed(member.speed) : (member.speed || '--');
        var agoText = fmt.timeAgo ? fmt.timeAgo(member.updated_at) : (member.updated_at || '--');
        var icon = fmt.motionIcon ? fmt.motionIcon(member.motion_state) : '';

        return '<div style="min-width:140px">' +
            '<strong>' + escapeHtml(member.name || 'Unknown') + '</strong>' +
            '<div style="font-size:13px;margin-top:4px;">' +
                icon + ' ' + escapeHtml(speedText) +
            '</div>' +
            '<div style="font-size:12px;color:#666;margin-top:2px;">' +
                'Updated: ' + escapeHtml(agoText) +
            '</div>' +
        '</div>';
    }

    /**
     * Escape HTML entities in a string for safe insertion.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * Add or update a marker for a single family member.
     *
     * @param {Object} member - Must include id, name, latitude, longitude.
     */
    function addMemberMarker(member) {
        if (!map || member.latitude == null || member.longitude == null) {
            return;
        }

        var lngLat = [parseFloat(member.longitude), parseFloat(member.latitude)];

        if (markers[member.id]) {
            // Update existing marker position and popup.
            markers[member.id].setLngLat(lngLat);
            markers[member.id].getPopup().setHTML(buildPopupHTML(member));
            return;
        }

        // Create new marker.
        var el = buildMarkerElement(member);
        var popup = new mapboxgl.Popup({ offset: 20, closeButton: false })
            .setHTML(buildPopupHTML(member));

        var marker = new mapboxgl.Marker({ element: el })
            .setLngLat(lngLat)
            .setPopup(popup)
            .addTo(map);

        markers[member.id] = marker;
    }

    /**
     * Update all member markers from a members array.
     * Removes markers for members no longer present.
     *
     * @param {Object[]} members
     */
    function updateMemberMarkers(members) {
        if (!map) {
            return;
        }

        var activeIds = {};
        for (var i = 0; i < members.length; i++) {
            addMemberMarker(members[i]);
            activeIds[members[i].id] = true;
        }

        // Remove stale markers.
        var ids = Object.keys(markers);
        for (var j = 0; j < ids.length; j++) {
            if (!activeIds[ids[j]]) {
                markers[ids[j]].remove();
                delete markers[ids[j]];
            }
        }
    }

    // -----------------------------------------------------------------------
    // Geofences
    // -----------------------------------------------------------------------

    /**
     * Pre-create the GeoJSON source and layers for geofences.
     * Called once after map load.
     */
    function addGeofenceSourceAndLayers() {
        if (!map) return;

        map.addSource(GEOFENCE_SOURCE, {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] },
        });

        map.addLayer({
            id: GEOFENCE_FILL_LAYER,
            type: 'fill',
            source: GEOFENCE_SOURCE,
            paint: {
                'fill-color': '#3b82f6',
                'fill-opacity': 0.12,
            },
        });

        map.addLayer({
            id: GEOFENCE_LINE_LAYER,
            type: 'line',
            source: GEOFENCE_SOURCE,
            paint: {
                'line-color': '#3b82f6',
                'line-width': 2,
                'line-dasharray': [2, 2],
            },
        });
    }

    /**
     * Convert a circle geofence into a GeoJSON polygon (64-sided).
     *
     * @param {number} centerLng
     * @param {number} centerLat
     * @param {number} radiusMeters
     * @returns {number[][]} Ring of [lng, lat] pairs.
     */
    function circleToPolygon(centerLng, centerLat, radiusMeters) {
        var STEPS = 64;
        var coords = [];
        for (var i = 0; i <= STEPS; i++) {
            var angle = (i / STEPS) * 2 * Math.PI;
            var dx = radiusMeters * Math.cos(angle);
            var dy = radiusMeters * Math.sin(angle);
            // Approximate metres to degrees.
            var lng = centerLng + (dx / (111320 * Math.cos((centerLat * Math.PI) / 180)));
            var lat = centerLat + (dy / 110540);
            coords.push([lng, lat]);
        }
        return coords;
    }

    /**
     * Build a GeoJSON Feature for a geofence.
     *
     * @param {Object} geofence - { id, type:'circle'|'polygon', center, radius, polygon }
     * @returns {Object|null} GeoJSON Feature or null.
     */
    function geofenceToFeature(geofence) {
        if (geofence.type === 'circle' && geofence.center && geofence.radius) {
            var ring = circleToPolygon(
                parseFloat(geofence.center.lng || geofence.center.longitude),
                parseFloat(geofence.center.lat || geofence.center.latitude),
                parseFloat(geofence.radius)
            );
            return {
                type: 'Feature',
                properties: { id: geofence.id, name: geofence.name || '' },
                geometry: { type: 'Polygon', coordinates: [ring] },
            };
        }

        if (geofence.type === 'polygon' && geofence.polygon) {
            return {
                type: 'Feature',
                properties: { id: geofence.id, name: geofence.name || '' },
                geometry: { type: 'Polygon', coordinates: geofence.polygon },
            };
        }

        return null;
    }

    /**
     * Draw a single geofence on the map (additive).
     *
     * @param {Object} geofence
     */
    function drawGeofence(geofence) {
        if (!map || !map.getSource(GEOFENCE_SOURCE)) return;

        var source = map.getSource(GEOFENCE_SOURCE);
        var data = source._data || { type: 'FeatureCollection', features: [] };
        var feature = geofenceToFeature(geofence);
        if (feature) {
            data.features.push(feature);
            source.setData(data);
        }
    }

    /**
     * Remove all geofences from the map.
     */
    function clearGeofences() {
        if (!map || !map.getSource(GEOFENCE_SOURCE)) return;
        map.getSource(GEOFENCE_SOURCE).setData({
            type: 'FeatureCollection',
            features: [],
        });
    }

    /**
     * Draw a list of geofences, replacing any existing ones.
     *
     * @param {Object[]} list
     */
    function drawAllGeofences(list) {
        if (!map || !map.getSource(GEOFENCE_SOURCE)) return;

        var features = [];
        for (var i = 0; i < list.length; i++) {
            var feat = geofenceToFeature(list[i]);
            if (feat) features.push(feat);
        }

        map.getSource(GEOFENCE_SOURCE).setData({
            type: 'FeatureCollection',
            features: features,
        });
    }

    // -----------------------------------------------------------------------
    // Route
    // -----------------------------------------------------------------------

    /**
     * Pre-create the GeoJSON source and layer for the route line.
     */
    function addRouteSourceAndLayer() {
        if (!map) return;

        map.addSource(ROUTE_SOURCE, {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] },
        });

        map.addLayer({
            id: ROUTE_LAYER,
            type: 'line',
            source: ROUTE_SOURCE,
            layout: {
                'line-join': 'round',
                'line-cap': 'round',
            },
            paint: {
                'line-color': '#6366f1',
                'line-width': 5,
                'line-opacity': 0.8,
            },
        });
    }

    /**
     * Draw a GeoJSON LineString or full geometry on the map.
     *
     * @param {Object} geometry - A GeoJSON geometry (LineString) or a full
     *                            GeoJSON Feature/FeatureCollection.
     */
    function drawRoute(geometry) {
        if (!map || !map.getSource(ROUTE_SOURCE)) return;

        var geojson;
        if (geometry.type === 'FeatureCollection') {
            geojson = geometry;
        } else if (geometry.type === 'Feature') {
            geojson = { type: 'FeatureCollection', features: [geometry] };
        } else {
            // Assume raw geometry (LineString, etc.)
            geojson = {
                type: 'FeatureCollection',
                features: [{
                    type: 'Feature',
                    properties: {},
                    geometry: geometry,
                }],
            };
        }

        map.getSource(ROUTE_SOURCE).setData(geojson);
    }

    /**
     * Remove the route line from the map.
     */
    function clearRoute() {
        if (!map || !map.getSource(ROUTE_SOURCE)) return;
        map.getSource(ROUTE_SOURCE).setData({
            type: 'FeatureCollection',
            features: [],
        });
    }

    // -----------------------------------------------------------------------
    // Camera helpers
    // -----------------------------------------------------------------------

    /**
     * Fit the map viewport to show all current member markers.
     */
    function fitToMembers() {
        if (!map) return;

        var ids = Object.keys(markers);
        if (ids.length === 0) return;

        var bounds = new mapboxgl.LngLatBounds();
        for (var i = 0; i < ids.length; i++) {
            bounds.extend(markers[ids[i]].getLngLat());
        }

        map.fitBounds(bounds, { padding: 60, maxZoom: 14 });
    }

    /**
     * Fly the camera to a specific location.
     *
     * @param {number} lat
     * @param {number} lng
     * @param {number} [zoom=14]
     */
    function flyToMember(lat, lng, zoom) {
        if (!map) return;
        map.flyTo({
            center: [lng, lat],
            zoom: zoom || 14,
            essential: true,
        });
    }

    /**
     * Change the map style (e.g. to a dark mode style).
     *
     * @param {string} styleUrl - Full Mapbox style URL.
     */
    function setStyle(styleUrl) {
        if (!map) return;
        map.setStyle(styleUrl);
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.map = {
        init: init,
        getMap: getMap,
        addMemberMarker: addMemberMarker,
        updateMemberMarkers: updateMemberMarkers,
        drawGeofence: drawGeofence,
        clearGeofences: clearGeofences,
        drawAllGeofences: drawAllGeofences,
        drawRoute: drawRoute,
        clearRoute: clearRoute,
        fitToMembers: fitToMembers,
        flyToMember: flyToMember,
        setStyle: setStyle,
    };
})();
