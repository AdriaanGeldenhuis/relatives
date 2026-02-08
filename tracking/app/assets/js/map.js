/**
 * Tracking App — MapboxController
 *
 * Only responsible for:
 *   - Map initialization
 *   - Marker add / update / remove (from cached data)
 *   - Camera logic (fit to members, fly to member)
 *
 * Reads from cached member data passed to it. Never calls the network.
 * Never triggers permissions. The map loads with no permission required.
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    var DEFAULT_CENTER = [25.5, -28.5]; // South Africa [lng, lat]
    var DEFAULT_ZOOM = 5;

    /** @type {mapboxgl.Map|null} */
    var map = null;

    /** Marker cache keyed by member id — prevents flicker on re-render. */
    var markers = {};

    /** Track the data hash of each marker to skip no-op updates. */
    var markerHashes = {};

    // ── Initialization ──────────────────────────────────────────────────

    function init(containerId) {
        if (map) return map;

        mapboxgl.accessToken = window.MAPBOX_TOKEN;

        map = new mapboxgl.Map({
            container: containerId,
            style: 'mapbox://styles/mapbox/streets-v12',
            center: DEFAULT_CENTER,
            zoom: DEFAULT_ZOOM,
            attributionControl: true,
        });

        map.addControl(new mapboxgl.NavigationControl(), 'top-right');
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
        });

        return map;
    }

    function getMap() {
        return map;
    }

    // ── Marker rendering (cache-aware) ──────────────────────────────────

    /**
     * Hash a member's location data so we can skip no-op marker updates.
     * This is the "map render cache" — we only touch the DOM when data changed.
     */
    function memberHash(member) {
        return member.latitude + ',' + member.longitude + ',' +
               (member.speed || '') + ',' + (member.updated_at || '');
    }

    function memberColor(member) {
        if (member.color) return member.color;
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

    function buildPopupHTML(member) {
        var name = escapeHtml(member.name || 'Unknown');
        var speed = member.speed != null ? (member.speed * 3.6).toFixed(1) + ' km/h' : '--';
        var ago = member.updated_at || '--';

        return '<div style="min-width:140px">' +
            '<strong>' + name + '</strong>' +
            '<div style="font-size:13px;margin-top:4px;">' + escapeHtml(speed) + '</div>' +
            '<div style="font-size:12px;color:#666;margin-top:2px;">Updated: ' + escapeHtml(ago) + '</div>' +
            '</div>';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * Update all member markers from a members array.
     * Uses hash comparison to skip no-op updates (prevents flicker).
     * Removes markers for members no longer present.
     */
    function updateMembers(members) {
        if (!map) return;

        var activeIds = {};

        for (var i = 0; i < members.length; i++) {
            var m = members[i];
            if (m.latitude == null || m.longitude == null) continue;

            activeIds[m.id] = true;
            var lngLat = [parseFloat(m.longitude), parseFloat(m.latitude)];
            var hash = memberHash(m);

            // Skip if marker exists and data hasn't changed (render cache hit)
            if (markers[m.id] && markerHashes[m.id] === hash) {
                continue;
            }

            if (markers[m.id]) {
                // Update existing marker
                markers[m.id].setLngLat(lngLat);
                markers[m.id].getPopup().setHTML(buildPopupHTML(m));
            } else {
                // Create new marker
                var el = buildMarkerElement(m);
                var popup = new mapboxgl.Popup({ offset: 20, closeButton: false })
                    .setHTML(buildPopupHTML(m));

                markers[m.id] = new mapboxgl.Marker({ element: el })
                    .setLngLat(lngLat)
                    .setPopup(popup)
                    .addTo(map);
            }
            markerHashes[m.id] = hash;
        }

        // Remove stale markers
        var ids = Object.keys(markers);
        for (var j = 0; j < ids.length; j++) {
            if (!activeIds[ids[j]]) {
                markers[ids[j]].remove();
                delete markers[ids[j]];
                delete markerHashes[ids[j]];
            }
        }
    }

    // ── Camera ──────────────────────────────────────────────────────────

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

    function flyTo(lat, lng, zoom) {
        if (!map) return;
        map.flyTo({ center: [lng, lat], zoom: zoom || 14, essential: true });
    }

    // ── Public interface ────────────────────────────────────────────────

    Tracking.map = {
        init: init,
        getMap: getMap,
        updateMembers: updateMembers,
        fitToMembers: fitToMembers,
        flyTo: flyTo,
    };
})();
