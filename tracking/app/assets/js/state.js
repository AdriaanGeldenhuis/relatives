/**
 * Tracking Module - State Manager & Map Controller
 */
var Tracking = (function() {
    'use strict';

    var state = {
        map: null,
        markers: {},
        members: [],
        polling: null,
        pollingInterval: 10000,
        followingUserId: null,
        directionsLayer: null,
        mapReady: false
    };

    var mapStyles = {
        streets: 'mapbox://styles/mapbox/streets-v12',
        satellite: 'mapbox://styles/mapbox/satellite-streets-v12',
        dark: 'mapbox://styles/mapbox/dark-v11',
        light: 'mapbox://styles/mapbox/light-v11'
    };

    function init() {
        if (!window.MAPBOX_TOKEN) {
            console.error('Tracking: No Mapbox token');
            return;
        }

        mapboxgl.accessToken = window.MAPBOX_TOKEN;

        state.map = new mapboxgl.Map({
            container: 'trackingMap',
            style: mapStyles.streets,
            center: [28.0473, -26.2041], // Johannesburg default
            zoom: 12,
            attributionControl: false
        });

        state.map.addControl(new mapboxgl.NavigationControl(), 'top-right');
        state.map.addControl(new mapboxgl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            trackUserLocation: true,
            showUserHeading: true
        }), 'top-right');

        state.map.on('load', function() {
            state.mapReady = true;
            loadMembers();
            startPolling();
        });
    }

    function loadMembers() {
        fetch('/tracking/api/current.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.data) {
                    state.members = data.data;
                    updateMarkers(data.data);
                    updatePanel(data.data);
                    fitBounds(data.data);
                }
            })
            .catch(function(err) {
                console.error('Tracking: loadMembers error', err);
            });
    }

    function updateMarkers(members) {
        var activeIds = {};

        members.forEach(function(m) {
            if (!m.lat || !m.lng) return;
            activeIds[m.user_id] = true;

            var el;
            if (state.markers[m.user_id]) {
                // Update existing marker position
                state.markers[m.user_id].marker.setLngLat([m.lng, m.lat]);
                el = state.markers[m.user_id].el;
            } else {
                // Create new marker
                el = document.createElement('div');
                el.className = 'tracking-marker' + (m.is_moving ? ' moving' : '');
                el.style.background = m.avatar_color || '#667eea';
                el.textContent = (m.name || '?').charAt(0).toUpperCase();
                el.title = m.name || 'Unknown';

                el.addEventListener('click', function() {
                    showMemberPopup(m);
                });

                var marker = new mapboxgl.Marker({ element: el })
                    .setLngLat([m.lng, m.lat])
                    .addTo(state.map);

                state.markers[m.user_id] = { marker: marker, el: el };
            }

            // Update moving state
            if (m.is_moving) {
                el.classList.add('moving');
            } else {
                el.classList.remove('moving');
            }

            // Follow mode
            if (state.followingUserId === m.user_id) {
                state.map.panTo([m.lng, m.lat]);
            }
        });

        // Remove markers for members no longer present
        Object.keys(state.markers).forEach(function(uid) {
            if (!activeIds[uid]) {
                state.markers[uid].marker.remove();
                delete state.markers[uid];
            }
        });
    }

    function showMemberPopup(member) {
        var speedKmh = ((member.speed || 0) * 3.6).toFixed(1);
        var updatedAgo = member.updated_at ? timeAgo(member.updated_at) : 'Unknown';

        var html = '<div style="min-width:180px">' +
            '<div style="font-weight:700;font-size:15px;margin-bottom:6px">' + (member.name || 'Unknown') + '</div>' +
            '<div style="font-size:12px;opacity:0.8;margin-bottom:4px">Updated: ' + updatedAgo + '</div>';

        if (member.speed !== null) {
            html += '<div style="font-size:12px;opacity:0.8;margin-bottom:4px">Speed: ' + speedKmh + ' km/h</div>';
        }
        if (member.battery !== null && member.battery > 0) {
            html += '<div style="font-size:12px;opacity:0.8;margin-bottom:4px">Battery: ' + member.battery + '%</div>';
        }

        html += '<div style="margin-top:8px;display:flex;gap:6px">' +
            '<button onclick="Tracking.followUser(' + member.user_id + ')" style="padding:4px 10px;border-radius:6px;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.3);color:white;cursor:pointer;font-size:11px">Follow</button>' +
            '<button onclick="Tracking.getDirectionsTo(' + member.lat + ',' + member.lng + ')" style="padding:4px 10px;border-radius:6px;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.3);color:white;cursor:pointer;font-size:11px">Directions</button>' +
            '</div></div>';

        new mapboxgl.Popup({ offset: 25, closeButton: true })
            .setLngLat([member.lng, member.lat])
            .setHTML(html)
            .addTo(state.map);
    }

    function updatePanel(members) {
        var container = document.getElementById('panelMembers');
        var countEl = document.getElementById('memberCount');
        if (!container) return;

        var located = members.filter(function(m) { return m.lat && m.lng; });
        if (countEl) countEl.textContent = located.length + '/' + members.length;

        container.innerHTML = '';
        members.forEach(function(m) {
            var row = document.createElement('div');
            row.className = 'member-row';
            row.onclick = function() {
                if (m.lat && m.lng) {
                    state.map.flyTo({ center: [m.lng, m.lat], zoom: 16 });
                    showMemberPopup(m);
                }
            };

            var updatedText = m.updated_at ? timeAgo(m.updated_at) : 'No location';
            var batteryText = (m.battery !== null && m.battery > 0) ? m.battery + '%' : '';
            var statusText = m.is_moving ? 'Moving' : (m.lat ? 'Stationary' : 'Offline');

            row.innerHTML = '<div class="avatar" style="background:' + (m.avatar_color || '#667eea') + '">' +
                (m.name || '?').charAt(0).toUpperCase() + '</div>' +
                '<div class="member-details">' +
                    '<div class="member-name">' + (m.name || 'Unknown') + '</div>' +
                    '<div class="member-status-text">' + statusText + ' &middot; ' + updatedText + '</div>' +
                '</div>' +
                (batteryText ? '<div class="member-battery">🔋 ' + batteryText + '</div>' : '');

            container.appendChild(row);
        });
    }

    function fitBounds(members) {
        var located = members.filter(function(m) { return m.lat && m.lng; });
        if (located.length === 0) return;

        if (located.length === 1) {
            state.map.flyTo({ center: [located[0].lng, located[0].lat], zoom: 14 });
            return;
        }

        var bounds = new mapboxgl.LngLatBounds();
        located.forEach(function(m) { bounds.extend([m.lng, m.lat]); });
        state.map.fitBounds(bounds, { padding: 80, maxZoom: 16 });
    }

    function startPolling() {
        if (state.polling) clearInterval(state.polling);
        state.polling = setInterval(loadMembers, state.pollingInterval);
    }

    function stopPolling() {
        if (state.polling) {
            clearInterval(state.polling);
            state.polling = null;
        }
    }

    function followUser(userId) {
        state.followingUserId = (state.followingUserId === userId) ? null : userId;
    }

    function getDirectionsTo(lat, lng) {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function(pos) {
            var url = '/tracking/api/directions.php?from_lat=' + pos.coords.latitude +
                '&from_lng=' + pos.coords.longitude + '&to_lat=' + lat + '&to_lng=' + lng;

            fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        showDirections(data.data);
                    }
                });
        });
    }

    function showDirections(route) {
        clearDirections();

        var distKm = (route.distance_m / 1000).toFixed(1);
        var durMin = Math.round(route.duration_s / 60);

        document.getElementById('directionsDistance').textContent = distKm + ' km';
        document.getElementById('directionsDuration').textContent = durMin + ' min';
        document.getElementById('directionsBar').style.display = 'flex';

        if (route.geometry && state.map.getSource('directions')) {
            state.map.getSource('directions').setData(route.geometry);
        } else if (route.geometry) {
            state.map.addSource('directions', { type: 'geojson', data: route.geometry });
            state.map.addLayer({
                id: 'directions-line',
                type: 'line',
                source: 'directions',
                paint: {
                    'line-color': '#667eea',
                    'line-width': 5,
                    'line-opacity': 0.8
                }
            });
            state.directionsLayer = true;
        }
    }

    function clearDirections() {
        if (state.directionsLayer && state.map.getLayer('directions-line')) {
            state.map.removeLayer('directions-line');
            state.map.removeSource('directions');
            state.directionsLayer = null;
        }
        document.getElementById('directionsBar').style.display = 'none';
    }

    function timeAgo(datetime) {
        var ts = new Date(datetime).getTime();
        var diff = Math.floor((Date.now() - ts) / 1000);
        if (diff < 0 || isNaN(diff)) return 'just now';
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function setMapStyle(style) {
        var url = mapStyles[style] || mapStyles.streets;
        state.map.setStyle(url);
    }

    // Auto-init when DOM is ready and Mapbox is loaded
    function waitForMapbox() {
        if (typeof mapboxgl !== 'undefined') {
            init();
        } else {
            // Load Mapbox GL JS
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css';
            document.head.appendChild(link);

            var script = document.createElement('script');
            script.src = 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js';
            script.onload = init;
            document.head.appendChild(script);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForMapbox);
    } else {
        waitForMapbox();
    }

    // Pause polling when tab is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            loadMembers();
            startPolling();
        }
    });

    // Public API
    return {
        getState: function() { return state; },
        loadMembers: loadMembers,
        startPolling: startPolling,
        stopPolling: stopPolling,
        followUser: followUser,
        getDirectionsTo: getDirectionsTo,
        clearDirections: clearDirections,
        setMapStyle: setMapStyle
    };
})();
