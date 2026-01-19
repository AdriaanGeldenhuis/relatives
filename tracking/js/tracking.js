/**
 * ============================================
 * RELATIVES TRACKING MAP - v5.0 OPTIMIZED
 * ============================================
 *
 * Changes from v4.0:
 * - Smart polling: foreground (5-10s) vs background (stopped)
 * - Optional Mapbox tiles with fallback to OSM
 * - Configurable polling intervals from server
 * - Better visibility change handling
 */

console.log('Tracking Map v5.0 loading...');

class TrackingMapProfessional {
    constructor() {
        this.map = null;
        this.markers = new Map();
        this.balloonMarkers = new Map();
        this.tetherLines = new Map();
        this.accuracyCircles = new Map();
        this.historyPolylines = [];
        this.zones = new Map();
        this.config = window.TrackingConfig || {};
        this.updateInterval = null;
        this.zonesVisible = true;
        this.currentMapStyle = 'light';
        this.currentTileLayer = null;
        this.isLoading = false;
        this.isSidebarOpen = false;
        this.lastUpdateTime = 0;
        this.isViewingMember = false; // Track if user is actively viewing someone

        // Polling configuration (from server or defaults)
        this.pollingConfig = {
            foreground: Math.max(5, this.config.pollingIntervalViewing || 10), // Fast when viewing
            default: Math.max(10, this.config.pollingIntervalDefault || 30),   // Normal
            min: Math.max(5, this.config.pollingIntervalMin || 5)              // Minimum allowed
        };

        // Map styles - Mapbox if token available, else free tiles
        this.mapStyles = this.buildMapStyles();

        this.init();
    }

    buildMapStyles() {
        const mapboxToken = this.config.mapboxToken;

        if (mapboxToken) {
            // Mapbox styles (better quality)
            return {
                light: {
                    // Streets style is more colorful and vibrant than light-v11
                    url: `https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    attribution: '&copy; <a href="https://www.mapbox.com/">Mapbox</a>',
                    name: 'Streets',
                    tileSize: 512,
                    zoomOffset: -1
                },
                dark: {
                    // dark-v11 has full street names and labels
                    url: `https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    attribution: '&copy; <a href="https://www.mapbox.com/">Mapbox</a>',
                    name: 'Dark',
                    tileSize: 512,
                    zoomOffset: -1
                },
                satellite: {
                    url: `https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    attribution: '&copy; <a href="https://www.mapbox.com/">Mapbox</a>',
                    name: 'Satellite',
                    tileSize: 512,
                    zoomOffset: -1
                }
            };
        } else {
            // Free tile providers (fallback) - using more colorful options
            return {
                light: {
                    // CartoDB Voyager is colorful and modern looking
                    url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> | <a href="https://carto.com/attributions">CARTO</a>',
                    name: 'Voyager'
                },
                dark: {
                    url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> | <a href="https://carto.com/attributions">CARTO</a>',
                    name: 'Dark'
                },
                satellite: {
                    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                    attribution: 'Tiles &copy; Esri',
                    name: 'Satellite'
                }
            };
        }
    }

    // ============================================
    // INITIALIZATION
    // ============================================

    init() {
        console.log('Initializing tracking map...');

        try {
            if (typeof L === 'undefined') {
                throw new Error('Leaflet library not loaded');
            }

            this.initMap();
            this.setupEventListeners();
            this.loadZones();
            this.fetchCurrentLocations();
            this.startPolling();
            this.loadSettings();
            this.detectThemePreference();
            this.notifyAndroidTrackingVisible();
            this.requestLocationBoost(5);

            setTimeout(() => {
                if (this.markers.size > 0) {
                    this.fitAllMembers();
                } else {
                    this.centerOnMyLocation(true);
                }
            }, 2000);

            console.log('Map initialized successfully');
            console.log(`Polling config: foreground=${this.pollingConfig.foreground}s, default=${this.pollingConfig.default}s`);

        } catch (error) {
            console.error('Map initialization failed:', error);
            this.showToast('Failed to initialize map: ' + error.message, 'error');
        }
    }

    initMap() {
        const mapElement = document.getElementById('trackingMap');

        if (!mapElement) {
            throw new Error('Map container not found');
        }

        this.map = L.map('trackingMap', {
            zoomControl: true,
            attributionControl: true,
            minZoom: 3,
            maxZoom: 19,
            zoomAnimation: true,
            fadeAnimation: true,
            markerZoomAnimation: true,
            preferCanvas: false,
            worldCopyJump: true,
            tap: true,
            tapTolerance: 15
        }).setView(this.config.defaultCenter || [-26.2041, 28.0473], this.config.defaultZoom || 12);

        this.setMapStyle('light');

        this.map.zoomControl.setPosition('bottomright');

        L.control.scale({
            position: 'bottomleft',
            imperial: false,
            metric: true
        }).addTo(this.map);

        this.map.on('zoomend', () => {
            this.updateMarkerSizes();
        });
    }

    setMapStyle(style = 'light') {
        if (this.currentTileLayer) {
            this.map.removeLayer(this.currentTileLayer);
        }

        const styleConfig = this.mapStyles[style] || this.mapStyles.light;

        const tileOptions = {
            attribution: styleConfig.attribution,
            maxZoom: 19,
            minZoom: 3,
            crossOrigin: true,
            updateWhenIdle: false,
            updateWhenZooming: true,
            keepBuffer: 2
        };

        // Add Mapbox-specific options if present
        if (styleConfig.tileSize) {
            tileOptions.tileSize = styleConfig.tileSize;
        }
        if (styleConfig.zoomOffset !== undefined) {
            tileOptions.zoomOffset = styleConfig.zoomOffset;
        }

        this.currentTileLayer = L.tileLayer(styleConfig.url, tileOptions).addTo(this.map);

        this.currentMapStyle = style;

        // Set theme attribute for CSS
        document.documentElement.setAttribute('data-theme', style === 'light' ? 'light' : 'dark');

        localStorage.setItem('mapStyle', style);

        console.log(`Map style: ${styleConfig.name}${this.config.mapboxToken ? ' (Mapbox)' : ' (OSM)'}`);
    }

    detectThemePreference() {
        const savedStyle = localStorage.getItem('mapStyle');
        if (savedStyle && this.mapStyles[savedStyle]) {
            this.setMapStyle(savedStyle);
        } else {
            this.setMapStyle('light');
        }
    }

    // ============================================
    // EVENT LISTENERS
    // ============================================

    setupEventListeners() {
        // Toolbar buttons
        this.addClickListener('myLocationBtn', () => this.centerOnMyLocation());
        this.addClickListener('familyViewBtn', () => this.fitAllMembers());
        this.addClickListener('zonesToggleBtn', () => this.toggleZonesVisibility());
        this.addClickListener('historyBtn', () => this.openHistoryModal());
        this.addClickListener('mapStyleBtn', () => this.cycleMapStyle());
        this.addClickListener('settingsBtn', () => this.openSettingsModal());

        // Sidebar
        this.addClickListener('sidebarToggleMobile', () => this.toggleSidebar());
        this.addClickListener('sidebarClose', () => this.toggleSidebar());

        // Search
        const searchInput = document.getElementById('memberSearch');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => this.filterMembers(e.target.value));
        }

        // Settings form
        const settingsForm = document.getElementById('settingsForm');
        if (settingsForm) {
            settingsForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettings();
            });
        }

        // Member cards - auto-close sidebar and boost polling
        document.querySelectorAll('.member-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (!e.target.closest('.member-actions')) {
                    const userId = parseInt(card.dataset.userId);

                    // Mark as viewing a member (boosts polling)
                    this.isViewingMember = true;
                    this.restartPolling();

                    // Close sidebar
                    if (this.isSidebarOpen) {
                        this.toggleSidebar();
                    }

                    setTimeout(() => {
                        this.centerOnMember(userId);
                    }, 300);
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeAllModals();
                if (this.isSidebarOpen) {
                    this.toggleSidebar();
                }
            }
        });

        // Modal backdrop clicks
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', () => {
                backdrop.closest('.modal')?.classList.remove('active');
            });
        });

        // ========== VISIBILITY CHANGE - Smart polling ==========
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Tab hidden - stop polling completely
                this.notifyAndroidTrackingHidden();
                this.stopPolling();
                console.log('Tab hidden - polling stopped');
            } else {
                // Tab visible - restart polling
                this.notifyAndroidTrackingVisible();
                this.requestLocationBoost(5);
                this.startPolling();
                this.fetchCurrentLocations(); // Immediate fetch
                console.log('Tab visible - polling resumed');
            }
        });

        window.addEventListener('focus', () => {
            this.notifyAndroidTrackingVisible();
            this.requestLocationBoost(5);
            if (!this.updateInterval && !document.hidden) {
                this.startPolling();
                this.fetchCurrentLocations();
            }
        });

        window.addEventListener('blur', () => {
            this.notifyAndroidTrackingHidden();
            this.isViewingMember = false; // Reset viewing state
        });

        window.addEventListener('beforeunload', () => {
            this.notifyAndroidTrackingHidden();
            this.stopPolling();
        });

        window.addEventListener('resize', () => {
            this.map.invalidateSize();
        });

        // Reset viewing state after some idle time
        let idleTimer;
        const resetViewing = () => {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                if (this.isViewingMember) {
                    this.isViewingMember = false;
                    this.restartPolling();
                    console.log('Idle - reverting to default polling');
                }
            }, 60000); // 1 minute idle
        };

        ['mousemove', 'touchstart', 'click'].forEach(event => {
            document.addEventListener(event, resetViewing, { passive: true });
        });
    }

    addClickListener(id, handler) {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                handler();
            });

            element.addEventListener('touchend', (e) => {
                e.preventDefault();
                e.stopPropagation();
                handler();
            });
        }
    }

    // ============================================
    // POLLING - Smart foreground/background
    // ============================================

    startPolling() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
        }

        // Don't poll if tab is hidden
        if (document.hidden) {
            console.log('Tab hidden - not starting polling');
            return;
        }

        // Determine polling interval based on state
        let intervalSeconds;
        if (this.isViewingMember) {
            intervalSeconds = this.pollingConfig.foreground;
            console.log(`Viewing member - fast polling (${intervalSeconds}s)`);
        } else {
            intervalSeconds = this.pollingConfig.default;
            console.log(`Normal polling (${intervalSeconds}s)`);
        }

        const intervalMs = intervalSeconds * 1000;

        this.updateInterval = setInterval(() => {
            if (!document.hidden) {
                this.fetchCurrentLocations();
            }
        }, intervalMs);
    }

    stopPolling() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
    }

    restartPolling() {
        this.stopPolling();
        this.startPolling();
    }

    // ============================================
    // LOCATION FETCHING
    // ============================================

    async fetchCurrentLocations() {
        if (this.isLoading) return;

        this.isLoading = true;
        const fetchStartTime = performance.now();

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);

            const response = await fetch('/tracking/api/get_current_locations.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to fetch locations');
            }

            this.updateMarkers(data.members || []);
            this.updateMemberList(data.members || []);
            this.updateLastUpdateTime();

            const fetchEndTime = performance.now();
            const cacheHit = data.cache_hit ? ' (cache)' : '';
            console.log(`Fetch: ${(fetchEndTime - fetchStartTime).toFixed(0)}ms${cacheHit}`);

        } catch (error) {
            if (error.name === 'AbortError') {
                console.error('Fetch timeout');
            } else {
                console.error('Fetch error:', error);
            }
        } finally {
            this.isLoading = false;
        }
    }

    updateMarkers(members) {
        const existingIds = new Set(this.markers.keys());
        const currentIds = new Set();

        // Group members by proximity (within ~100 meters) to detect clustering
        const clusters = this.clusterMembersByProximity(members, 0.001); // ~100m threshold

        members.forEach(member => {
            currentIds.add(member.user_id);

            if (!member.location || !member.location.lat || !member.location.lng) {
                return;
            }

            const position = [member.location.lat, member.location.lng];

            // Find which cluster this member belongs to
            const cluster = clusters.find(c => c.members.some(m => m.user_id === member.user_id));
            const membersAtLocation = cluster ? cluster.members : [member];
            const isClustered = membersAtLocation.length > 1;

            if (this.markers.has(member.user_id)) {
                const marker = this.markers.get(member.user_id);

                if (this.balloonMarkers.has(member.user_id)) {
                    // Update dot marker at actual position
                    this.animateMarker(marker, position);

                    // Update balloon marker with spiral offset
                    const balloonMarker = this.balloonMarkers.get(member.user_id);
                    const memberIndex = membersAtLocation.findIndex(m => m.user_id === member.user_id);
                    const { lat: balloonLat, lng: balloonLng } = this.calculateSpiralOffset(
                        position[0], position[1], memberIndex, membersAtLocation.length
                    );
                    this.animateMarker(balloonMarker, [balloonLat, balloonLng]);

                    // Update tether line
                    if (this.tetherLines.has(member.user_id)) {
                        const tetherLine = this.tetherLines.get(member.user_id);
                        tetherLine.setLatLngs([position, [balloonLat, balloonLng]]);
                    }
                } else {
                    this.animateMarker(marker, position);
                }

                if (this.accuracyCircles.has(member.user_id)) {
                    const circle = this.accuracyCircles.get(member.user_id);
                    circle.setLatLng(position);
                    circle.setRadius(member.location.accuracy_m || 20);
                }

                const popup = marker.getPopup();
                if (popup) {
                    popup.setContent(this.createPopupContent(member));
                }

            } else {
                this.createMarker(member, position, isClustered, membersAtLocation);
            }
        });

        existingIds.forEach(id => {
            if (!currentIds.has(id)) {
                this.removeMarker(id);
            }
        });
    }

    createMarker(member, position, isClustered = false, membersAtLocation = []) {
        const isMe = member.user_id === this.config.userId;
        const color = member.avatar_color || '#667eea';

        if (isClustered) {
            // Create small dot marker at actual position
            const dotHtml = `
                <div class="location-dot" style="
                    width: 14px;
                    height: 14px;
                    border-radius: 50%;
                    background: ${color};
                    border: 3px solid white;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.4);
                "></div>
            `;

            const dotIcon = L.divIcon({
                html: dotHtml,
                className: 'location-dot-container',
                iconSize: [14, 14],
                iconAnchor: [7, 7]
            });

            const dotMarker = L.marker(position, {
                icon: dotIcon,
                zIndexOffset: isMe ? 500 : 0
            }).addTo(this.map);

            this.markers.set(member.user_id, dotMarker);

            // Create balloon avatar marker with spiral offset
            let markerContent;
            if (member.has_avatar && member.avatar_url) {
                markerContent = `<img src="${member.avatar_url}" alt="${member.name}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
            } else {
                markerContent = member.name ? member.name.charAt(0).toUpperCase() : '?';
            }

            const memberIndex = membersAtLocation.findIndex(m => m.user_id === member.user_id);
            const { lat: balloonLat, lng: balloonLng } = this.calculateSpiralOffset(
                position[0], position[1], memberIndex, membersAtLocation.length
            );
            const balloonPosition = [balloonLat, balloonLng];

            // Create tether line with gradient effect
            const tetherLine = L.polyline([position, balloonPosition], {
                color: color,
                weight: 2,
                opacity: 0.7,
                dashArray: '6, 4',
                className: 'tether-line'
            }).addTo(this.map);

            this.tetherLines.set(member.user_id, tetherLine);

            const avatarSize = isMe ? 56 : 50;
            const borderWidth = isMe ? 4 : 3;
            const totalSize = avatarSize + (borderWidth * 2);

            // Avatar centered in container, line connects to bottom center
            const balloonHtml = `
                <div class="balloon-marker-container" style="
                    position: relative;
                    width: ${totalSize}px;
                    height: ${totalSize}px;
                ">
                    <div class="balloon-avatar ${isMe ? 'is-me' : ''}" style="
                        width: ${avatarSize}px;
                        height: ${avatarSize}px;
                        border-radius: 50%;
                        overflow: hidden;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: ${isMe ? 26 : 22}px;
                        font-weight: 900;
                        color: white;
                        background: ${color};
                        border: ${borderWidth}px solid white;
                        box-shadow: 0 4px 16px rgba(0,0,0,0.35);
                    ">
                        ${markerContent}
                    </div>
                    ${member.status === 'online' || member.status === 'stale' ? `
                        <div style="
                            position: absolute;
                            bottom: 0;
                            right: 0;
                            width: 16px;
                            height: 16px;
                            background: ${member.status === 'online' ? '#43e97b' : '#ffa502'};
                            border: 3px solid white;
                            border-radius: 50%;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
                        "></div>
                    ` : ''}
                    ${isMe ? `
                        <div style="
                            position: absolute;
                            top: -20px;
                            left: 50%;
                            transform: translateX(-50%);
                            background: linear-gradient(135deg, #667eea, #764ba2);
                            color: white;
                            padding: 3px 10px;
                            border-radius: 10px;
                            font-size: 10px;
                            font-weight: 800;
                            white-space: nowrap;
                            box-shadow: 0 2px 8px rgba(102,126,234,0.4);
                        ">YOU</div>
                    ` : ''}
                </div>
            `;

            // Anchor at bottom center of avatar - this is where line connects
            const balloonIcon = L.divIcon({
                html: balloonHtml,
                className: `balloon-marker ${isMe ? 'is-me' : ''}`,
                iconSize: [totalSize, totalSize],
                iconAnchor: [totalSize / 2, totalSize], // Bottom center
                popupAnchor: [0, -totalSize]
            });

            const balloonMarker = L.marker(balloonPosition, {
                icon: balloonIcon,
                zIndexOffset: isMe ? 1000 : 100,
                riseOnHover: true
            }).addTo(this.map);

            balloonMarker.bindPopup(this.createPopupContent(member), {
                closeButton: true,
                maxWidth: this.getResponsivePopupWidth(),
                minWidth: 240,
                className: 'custom-popup',
                autoPan: true,
                autoPanPadding: [50, 50]
            });

            balloonMarker.on('click', () => {
                this.isViewingMember = true;
                this.restartPolling();
                setTimeout(() => this.centerOnMember(member.user_id), 100);
            });

            this.balloonMarkers.set(member.user_id, balloonMarker);

        } else {
            // Standard marker
            let markerContent;
            if (member.has_avatar && member.avatar_url) {
                markerContent = `<img src="${member.avatar_url}" alt="${member.name}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
            } else {
                markerContent = member.name ? member.name.charAt(0).toUpperCase() : '?';
            }

            const iconHtml = `
                <div class="custom-marker ${isMe ? 'is-me' : ''}" style="
                    width: ${isMe ? '64px' : '56px'};
                    height: ${isMe ? '64px' : '56px'};
                    border-radius: 50%;
                    background: ${color};
                    border: ${isMe ? '4px' : '3px'} solid white;
                    box-shadow: 0 6px 20px rgba(0,0,0,0.4);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: ${isMe ? '28px' : '24px'};
                    font-weight: 900;
                    color: white;
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    position: relative;
                    overflow: hidden;
                ">
                    ${markerContent}
                    ${member.status === 'online' || member.status === 'stale' ? `
                        <div style="
                            position: absolute;
                            bottom: 0;
                            right: 0;
                            width: 18px;
                            height: 18px;
                            background: ${member.status === 'online' ? '#43e97b' : '#ffa502'};
                            border: 3px solid white;
                            border-radius: 50%;
                        "></div>
                    ` : ''}
                </div>
            `;

            const icon = L.divIcon({
                html: iconHtml,
                className: 'member-marker-container',
                iconSize: [isMe ? 64 : 56, isMe ? 64 : 56],
                iconAnchor: [isMe ? 32 : 28, isMe ? 32 : 28],
                popupAnchor: [0, isMe ? -32 : -28]
            });

            const marker = L.marker(position, {
                icon,
                zIndexOffset: isMe ? 1000 : 0,
                riseOnHover: true
            }).addTo(this.map);

            marker.bindPopup(this.createPopupContent(member), {
                closeButton: true,
                maxWidth: this.getResponsivePopupWidth(),
                minWidth: 240,
                className: 'custom-popup',
                autoPan: true,
                autoPanPadding: [50, 50]
            });

            marker.on('click', () => {
                this.isViewingMember = true;
                this.restartPolling();
                setTimeout(() => this.centerOnMember(member.user_id), 100);
            });

            this.markers.set(member.user_id, marker);
        }

        // Accuracy circle
        const circle = L.circle(position, {
            radius: member.location.accuracy_m || 20,
            color: color,
            fillColor: color,
            fillOpacity: 0.08,
            weight: 1.5,
            opacity: 0.4
        }).addTo(this.map);

        this.accuracyCircles.set(member.user_id, circle);

        this.updateMemberCardStatus(member.user_id, true);
    }

    getResponsivePopupWidth() {
        const width = window.innerWidth;
        if (width < 480) return 260;
        if (width < 768) return 280;
        return 320;
    }

    removeMarker(userId) {
        const marker = this.markers.get(userId);
        const balloonMarker = this.balloonMarkers.get(userId);
        const circle = this.accuracyCircles.get(userId);
        const tetherLine = this.tetherLines.get(userId);

        if (marker) {
            this.map.removeLayer(marker);
            this.markers.delete(userId);
        }

        if (balloonMarker) {
            this.map.removeLayer(balloonMarker);
            this.balloonMarkers.delete(userId);
        }

        if (circle) {
            this.map.removeLayer(circle);
            this.accuracyCircles.delete(userId);
        }

        if (tetherLine) {
            this.map.removeLayer(tetherLine);
            this.tetherLines.delete(userId);
        }

        this.updateMemberCardStatus(userId, false);
    }

    updateMemberCardStatus(userId, isTracking) {
        const memberCard = document.querySelector(`.member-card[data-user-id="${userId}"]`);
        if (memberCard) {
            memberCard.setAttribute('data-tracking', isTracking ? 'true' : 'false');
        }
    }

    createPopupContent(member) {
        const lastSeen = member.last_seen ? this.formatRelativeTime(new Date(member.last_seen)) : 'Unknown';
        const speed = member.location?.speed_kmh || 0;
        const battery = member.location?.battery_level || 0;
        const accuracy = member.location?.accuracy_m || 0;
        const isMe = member.user_id === this.config.userId;

        const popupWidth = this.getResponsivePopupWidth();
        const isMobile = window.innerWidth < 768;

        return `
            <div style="padding: ${isMobile ? '16px' : '20px'}; min-width: ${popupWidth - 40}px; background: var(--bg-secondary); color: var(--text-primary);">
                <div style="display: flex; align-items: center; gap: ${isMobile ? '12px' : '16px'}; margin-bottom: ${isMobile ? '14px' : '18px'};">
                    <div style="
                        width: ${isMobile ? '48px' : '56px'};
                        height: ${isMobile ? '48px' : '56px'};
                        border-radius: 50%;
                        background: ${member.avatar_color || '#667eea'};
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: ${isMobile ? '22px' : '26px'};
                        font-weight: 900;
                        color: white;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.25);
                        position: relative;
                        overflow: hidden;
                        flex-shrink: 0;
                    ">
                        ${member.has_avatar && member.avatar_url ? `
                            <img src="${member.avatar_url}" alt="${member.name}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        ` : (member.name ? member.name.charAt(0).toUpperCase() : '?')}
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <span style="font-weight: 900; font-size: ${isMobile ? '16px' : '18px'}; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                ${member.name || 'Unknown'}
                            </span>
                            ${isMe ? `<span style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 800;">You</span>` : ''}
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="${member.status === 'online' ? '#43e97b' : member.status === 'stale' ? '#ffa502' : '#6c757d'}">
                                <circle cx="12" cy="12" r="10"></circle>
                            </svg>
                            <span style="font-size: ${isMobile ? '12px' : '13px'}; font-weight: 700; color: ${member.status === 'online' ? '#43e97b' : member.status === 'stale' ? '#ffa502' : '#6c757d'};">
                                ${member.status === 'online' ? 'Tracking' : member.status === 'stale' ? `Stale (${Math.floor((member.seconds_ago || 0) / 60)}m ago)` : member.status === 'no_location' ? 'No location yet' : 'Offline'}
                            </span>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: ${speed > 0 || battery > 0 ? '1fr 1fr' : '1fr'}; gap: ${isMobile ? '10px' : '12px'}; padding: ${isMobile ? '10px' : '12px'}; background: var(--bg-tertiary); border-radius: 12px; margin-bottom: ${isMobile ? '14px' : '16px'};">
                    <div>
                        <div style="font-size: 10px; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Last Seen</div>
                        <div style="font-size: ${isMobile ? '13px' : '14px'}; font-weight: 800; color: var(--text-primary);">${lastSeen}</div>
                    </div>
                    ${speed > 0 ? `<div><div style="font-size: 10px; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Speed</div><div style="font-size: ${isMobile ? '13px' : '14px'}; font-weight: 800; color: var(--text-primary);">${speed.toFixed(1)} km/h</div></div>` : ''}
                    ${battery > 0 ? `<div><div style="font-size: 10px; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Battery</div><div style="font-size: ${isMobile ? '13px' : '14px'}; font-weight: 800; color: ${battery < 20 ? '#ff4757' : 'var(--text-primary)'};">${battery}%</div></div>` : ''}
                    ${accuracy > 0 ? `<div><div style="font-size: 10px; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">Accuracy</div><div style="font-size: ${isMobile ? '13px' : '14px'}; font-weight: 800; color: var(--text-primary);">±${accuracy}m</div></div>` : ''}
                </div>

                <div style="display: flex; gap: 8px;">
                    ${!isMe ? `<button onclick="window.TrackingMap.centerOnMember(${member.user_id}); return false;" style="flex: 1; padding: ${isMobile ? '10px' : '12px'}; background: linear-gradient(135deg, #667eea, #764ba2); border: none; border-radius: 10px; color: white; font-weight: 800; cursor: pointer; font-size: ${isMobile ? '12px' : '13px'}; display: flex; align-items: center; justify-content: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="10" r="3"></circle><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"></path></svg>Center</button>` : ''}
                    <button onclick="window.TrackingMap.showMemberHistory(${member.user_id}); return false;" style="flex: 1; padding: ${isMobile ? '10px' : '12px'}; background: var(--bg-tertiary); border: 1px solid var(--glass-border); border-radius: 10px; color: var(--text-primary); font-weight: 800; cursor: pointer; font-size: ${isMobile ? '12px' : '13px'}; display: flex; align-items: center; justify-content: center; gap: 6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>History</button>
                </div>
            </div>
        `;
    }

    animateMarker(marker, newPosition, duration = 1000) {
        const currentPosition = marker.getLatLng();
        const startTime = Date.now();
        const startLat = currentPosition.lat;
        const startLng = currentPosition.lng;
        const deltaLat = newPosition[0] - startLat;
        const deltaLng = newPosition[1] - startLng;

        const animate = () => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);

            const lat = startLat + (deltaLat * eased);
            const lng = startLng + (deltaLng * eased);

            marker.setLatLng([lat, lng]);

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        animate();
    }

    updateMemberList(members) {
        if (!Array.isArray(members)) return;

        members.forEach(member => {
            try {
                if (!member || typeof member.user_id === 'undefined') return;

                const memberCard = document.querySelector(`.member-card[data-user-id="${member.user_id}"]`);
                if (!memberCard) return;

                const lastSeenEl = memberCard.querySelector('.last-seen-time');
                if (lastSeenEl) {
                    if (member.last_seen) {
                        try {
                            const date = new Date(member.last_seen);
                            if (!isNaN(date.getTime())) {
                                lastSeenEl.textContent = this.formatRelativeTime(date);
                            } else {
                                lastSeenEl.textContent = '--';
                            }
                        } catch (e) {
                            lastSeenEl.textContent = '--';
                        }
                    } else {
                        lastSeenEl.textContent = '--';
                    }
                }

                const speedEl = memberCard.querySelector('.member-speed');
                if (speedEl) {
                    const hasSpeed = member.location?.speed_kmh != null && !isNaN(member.location.speed_kmh);
                    speedEl.textContent = hasSpeed ? `${member.location.speed_kmh.toFixed(1)} km/h` : '-- km/h';
                }

                const batteryEl = memberCard.querySelector('.member-battery');
                if (batteryEl) {
                    const hasBattery = member.location?.battery_level != null && !isNaN(member.location.battery_level);
                    if (hasBattery) {
                        batteryEl.textContent = `${member.location.battery_level}%`;
                        batteryEl.style.color = member.location.battery_level < 20 ? '#ff4757' : '';
                    } else {
                        batteryEl.textContent = '--%';
                        batteryEl.style.color = '';
                    }
                }

                const status = member.status;
                const isTracking = status === 'online';
                const isStale = status === 'stale';
                const hasNoLocation = status === 'no_location';

                memberCard.setAttribute('data-tracking', isTracking ? 'true' : 'false');
                memberCard.setAttribute('data-status', status);

                const statusDot = memberCard.querySelector('.member-status-dot');
                if (statusDot) {
                    if (isTracking) {
                        statusDot.style.background = '#43e97b';
                        statusDot.style.boxShadow = '0 0 12px rgba(67, 233, 123, 0.6)';
                    } else if (isStale) {
                        statusDot.style.background = '#ffa502';
                        statusDot.style.boxShadow = '0 0 12px rgba(255, 165, 2, 0.4)';
                    } else {
                        statusDot.style.background = '#6c757d';
                        statusDot.style.boxShadow = 'none';
                    }
                }

                const statusText = memberCard.querySelector('.member-status span');
                if (statusText) {
                    if (isTracking) {
                        statusText.textContent = 'Tracking';
                    } else if (isStale) {
                        const mins = Math.floor((member.seconds_ago || 0) / 60);
                        statusText.textContent = `Stale (${mins}m ago)`;
                    } else if (hasNoLocation) {
                        statusText.textContent = 'No location yet';
                    } else {
                        const hours = Math.floor((member.seconds_ago || 0) / 3600);
                        statusText.textContent = hours > 0 ? `Offline (${hours}h)` : 'Offline';
                    }
                }

            } catch (error) {
                console.error(`Error updating member ${member.user_id}:`, error);
            }
        });
    }

    updateLastUpdateTime() {
        const lastUpdateEl = document.getElementById('lastUpdate');
        if (lastUpdateEl) {
            lastUpdateEl.textContent = 'Just now';
        }
        this.lastUpdateTime = Date.now();
    }

    updateMarkerSizes() {
        const zoom = this.map.getZoom();
        const scale = Math.max(0.6, Math.min(1.4, zoom / 14));

        this.markers.forEach((marker) => {
            const element = marker.getElement();
            if (element) {
                const markerDiv = element.querySelector('.custom-marker');
                if (markerDiv) {
                    markerDiv.style.transform = `scale(${scale})`;
                }
            }
        });
    }

    // ============================================
    // ZONES
    // ============================================

    loadZones() {
        if (!this.config.zones || this.config.zones.length === 0) {
            return;
        }

        this.config.zones.forEach(zone => {
            try {
                let layer;

                if (zone.type === 'circle' && zone.center_lat && zone.center_lng && zone.radius_m) {
                    layer = L.circle([zone.center_lat, zone.center_lng], {
                        radius: zone.radius_m,
                        color: zone.color || '#667eea',
                        fillColor: zone.color || '#667eea',
                        fillOpacity: 0.12,
                        weight: 2.5,
                        opacity: 0.7
                    }).addTo(this.map);

                } else if (zone.type === 'polygon' && zone.polygon_json) {
                    const coordinates = JSON.parse(zone.polygon_json);
                    layer = L.polygon(coordinates, {
                        color: zone.color || '#667eea',
                        fillColor: zone.color || '#667eea',
                        fillOpacity: 0.12,
                        weight: 2.5,
                        opacity: 0.7
                    }).addTo(this.map);
                }

                if (layer) {
                    layer.bindPopup(`
                        <div style="padding: 18px; text-align: center; background: var(--bg-secondary); color: var(--text-primary);">
                            <div style="font-size: 40px; margin-bottom: 10px;">${zone.icon || '📍'}</div>
                            <div style="font-weight: 900; font-size: 18px; color: var(--text-primary); margin-bottom: 6px;">${zone.name}</div>
                            ${zone.radius_m ? `<div style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Radius: ${zone.radius_m}m</div>` : ''}
                        </div>
                    `);

                    this.zones.set(zone.id, layer);
                }
            } catch (error) {
                console.error(`Failed to load zone ${zone.id}:`, error);
            }
        });

        console.log(`Loaded ${this.zones.size} zones`);
    }

    toggleZonesVisibility() {
        this.zonesVisible = !this.zonesVisible;
        const btn = document.getElementById('zonesToggleBtn');

        this.zones.forEach(layer => {
            if (this.zonesVisible) {
                if (!this.map.hasLayer(layer)) layer.addTo(this.map);
            } else {
                if (this.map.hasLayer(layer)) this.map.removeLayer(layer);
            }
        });

        if (btn) btn.classList.toggle('active', this.zonesVisible);
        this.showToast(`Zones ${this.zonesVisible ? 'shown' : 'hidden'}`, 'info');
    }

    toggleZone(zoneId) {
        const layer = this.zones.get(zoneId);
        if (!layer) return;

        const zoneCard = document.querySelector(`.zone-card[data-zone-id="${zoneId}"]`);
        const toggleBtn = zoneCard?.querySelector('.zone-toggle');

        if (this.map.hasLayer(layer)) {
            this.map.removeLayer(layer);
            toggleBtn?.classList.add('hidden');
        } else {
            layer.addTo(this.map);
            toggleBtn?.classList.remove('hidden');
        }
    }

    openZoneCreator() {
        this.showToast('Zone creator coming soon!', 'info');
    }

    // ============================================
    // MAP CONTROLS
    // ============================================

    centerOnMyLocation(smooth = false) {
        const myMarker = this.markers.get(this.config.userId);

        if (myMarker) {
            const position = myMarker.getLatLng();

            if (smooth) {
                this.map.flyTo(position, 16, { duration: 1.5, easeLinearity: 0.25 });
            } else {
                this.map.setView(position, 16);
            }

            setTimeout(() => myMarker.openPopup(), smooth ? 1500 : 100);
        } else {
            this.showToast('Your location not available yet', 'warning');
        }
    }

    centerOnMember(userId) {
        const marker = this.markers.get(userId);
        const balloonMarker = this.balloonMarkers.get(userId);
        const targetMarker = balloonMarker || marker;

        if (targetMarker) {
            const position = targetMarker.getLatLng();

            this.map.flyTo(position, 17, { duration: 1.2, easeLinearity: 0.25 });
            setTimeout(() => targetMarker.openPopup(), 1200);
        } else {
            this.showToast('Member location not available', 'warning');
        }
    }

    fitAllMembers() {
        if (this.markers.size === 0) {
            this.showToast('No locations available', 'warning');
            return;
        }

        const bounds = L.latLngBounds();
        this.markers.forEach(marker => bounds.extend(marker.getLatLng()));

        this.map.flyToBounds(bounds, {
            padding: [60, 60],
            duration: 1.2,
            easeLinearity: 0.25,
            maxZoom: 16
        });
    }

    cycleMapStyle() {
        const styles = ['light', 'dark', 'satellite'];
        const currentIndex = styles.indexOf(this.currentMapStyle);
        const nextIndex = (currentIndex + 1) % styles.length;
        const nextStyle = styles[nextIndex];

        this.setMapStyle(nextStyle);
        this.showToast(`Map: ${this.mapStyles[nextStyle].name}`, 'info');
    }

    // ============================================
    // SIDEBAR
    // ============================================

    toggleSidebar() {
        const sidebar = document.getElementById('trackingSidebar');
        if (sidebar) {
            this.isSidebarOpen = !this.isSidebarOpen;
            sidebar.classList.toggle('active', this.isSidebarOpen);
        }
    }

    filterMembers(query) {
        const searchQuery = query.toLowerCase().trim();
        const memberCards = document.querySelectorAll('.member-card');

        memberCards.forEach(card => {
            const name = card.dataset.name || '';
            card.style.display = name.includes(searchQuery) ? 'flex' : 'none';
        });
    }

    // ============================================
    // HISTORY MODAL
    // ============================================

    openHistoryModal() {
        const modal = document.getElementById('historyModal');
        if (modal) modal.classList.add('active');
    }

    closeHistoryModal() {
        const modal = document.getElementById('historyModal');
        if (modal) modal.classList.remove('active');
        this.clearHistory();
    }

    async loadHistory() {
        const memberSelect = document.getElementById('historyMemberSelect');
        const dateSelect = document.getElementById('historyDateSelect');
        const resultsDiv = document.getElementById('historyResults');

        if (!memberSelect || !dateSelect || !resultsDiv) return;

        const userId = memberSelect.value;
        const date = dateSelect.value;

        if (!userId || !date) {
            this.showToast('Please select a member and date', 'warning');
            return;
        }

        resultsDiv.innerHTML = `<div class="empty-state"><div class="loading-spinner"></div><p style="margin-top: 20px; color: var(--text-secondary);">Loading history...</p></div>`;

        try {
            const response = await fetch(`/tracking/api/get_location_history.php?user_id=${userId}&date=${date}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to load history');

            this.displayHistory(data.points, data.stops, data.user_name);

        } catch (error) {
            console.error('Failed to load history:', error);
            resultsDiv.innerHTML = `<div class="empty-state"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg><p style="color: #ff6b6b; margin-top: 20px; font-weight: 700;">Failed to load history</p></div>`;
            this.showToast('Failed to load history', 'error');
        }
    }

    displayHistory(points, stops, userName) {
        const resultsDiv = document.getElementById('historyResults');
        if (!resultsDiv) return;

        this.clearHistory();

        if (!points || points.length === 0) {
            resultsDiv.innerHTML = `<div class="empty-state"><svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><p style="margin-top: 20px; color: var(--text-secondary); font-weight: 700;">No location data for this date</p></div>`;
            return;
        }

        const coordinates = points.map(p => [p.latitude, p.longitude]);
        const polyline = L.polyline(coordinates, {
            color: '#667eea',
            weight: 4,
            opacity: 0.8,
            smoothFactor: 1.5,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(this.map);

        this.historyPolylines.push(polyline);

        // Start marker
        const startMarker = L.circleMarker(coordinates[0], {
            radius: 8,
            fillColor: '#43e97b',
            color: 'white',
            weight: 3,
            opacity: 1,
            fillOpacity: 1
        }).addTo(this.map);

        startMarker.bindPopup(`<div style="padding: 14px; text-align: center; background: var(--bg-secondary); color: var(--text-primary);"><div style="font-size: 28px; margin-bottom: 8px;">🚀</div><div style="font-weight: 800;">Start</div><div style="font-size: 12px; color: var(--text-secondary);">${points[0].timestamp ? new Date(points[0].timestamp).toLocaleTimeString() : ''}</div></div>`);
        this.historyPolylines.push(startMarker);

        // End marker
        const endMarker = L.circleMarker(coordinates[coordinates.length - 1], {
            radius: 8,
            fillColor: '#ff4757',
            color: 'white',
            weight: 3,
            opacity: 1,
            fillOpacity: 1
        }).addTo(this.map);

        endMarker.bindPopup(`<div style="padding: 14px; text-align: center; background: var(--bg-secondary); color: var(--text-primary);"><div style="font-size: 28px; margin-bottom: 8px;">🏁</div><div style="font-weight: 800;">End</div><div style="font-size: 12px; color: var(--text-secondary);">${points[points.length - 1].timestamp ? new Date(points[points.length - 1].timestamp).toLocaleTimeString() : ''}</div></div>`);
        this.historyPolylines.push(endMarker);

        // Stop markers
        if (stops && stops.length > 0) {
            stops.forEach(stop => {
                const stopMarker = L.circleMarker([stop.latitude, stop.longitude], {
                    radius: 10,
                    fillColor: '#ffa502',
                    color: 'white',
                    weight: 3,
                    opacity: 1,
                    fillOpacity: 0.9
                }).addTo(this.map);

                stopMarker.bindPopup(`<div style="padding: 16px; text-align: center; min-width: 200px; background: var(--bg-secondary); color: var(--text-primary);"><div style="font-size: 32px; margin-bottom: 10px;">⏸️</div><div style="font-weight: 900; margin-bottom: 10px;">Stop</div><div style="background: var(--bg-tertiary); border-radius: 10px; padding: 10px; margin-bottom: 8px;"><div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px; font-weight: 700;">Duration</div><div style="font-size: 18px; font-weight: 900; color: #ffa502;">${this.formatDuration(stop.duration_minutes)}</div></div><div style="font-size: 12px; color: var(--text-secondary);">${stop.start_time} - ${stop.end_time}</div></div>`);
                this.historyPolylines.push(stopMarker);
            });
        }

        this.map.flyToBounds(polyline.getBounds(), { padding: [60, 60], duration: 1.2 });

        const distance = this.calculateDistance(coordinates);
        const totalDuration = stops?.length > 0 ? stops.reduce((sum, s) => sum + s.duration_minutes, 0) : 0;

        resultsDiv.innerHTML = `<div style="background: var(--bg-tertiary); border: 1px solid var(--glass-border); border-radius: 16px; padding: 24px; margin-top: 20px;"><div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg><h3 style="font-size: 18px; font-weight: 900; color: var(--text-primary);">Route Summary - ${userName}</h3></div><div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px;"><div style="background: var(--bg-secondary); border-radius: 12px; padding: 16px; text-align: center;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700;">📍 Points</div><div style="font-size: 28px; font-weight: 900; color: var(--text-primary);">${points.length}</div></div><div style="background: var(--bg-secondary); border-radius: 12px; padding: 16px; text-align: center;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700;">⏸️ Stops</div><div style="font-size: 28px; font-weight: 900; color: #ffa502;">${stops?.length || 0}</div></div><div style="background: var(--bg-secondary); border-radius: 12px; padding: 16px; text-align: center;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700;">🛣️ Distance</div><div style="font-size: 28px; font-weight: 900; color: #667eea;">${distance.toFixed(1)} km</div></div>${totalDuration > 0 ? `<div style="background: var(--bg-secondary); border-radius: 12px; padding: 16px; text-align: center;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700;">⏱️ Stop Time</div><div style="font-size: 28px; font-weight: 900; color: var(--text-primary);">${this.formatDuration(totalDuration)}</div></div>` : ''}</div></div>`;
    }

    clearHistory() {
        this.historyPolylines.forEach(layer => this.map.removeLayer(layer));
        this.historyPolylines = [];
    }

    showMemberHistory(userId) {
        const memberSelect = document.getElementById('historyMemberSelect');
        if (memberSelect) memberSelect.value = userId;
        this.openHistoryModal();
    }

    // ============================================
    // SETTINGS MODAL
    // ============================================

    openSettingsModal() {
        const modal = document.getElementById('settingsModal');
        if (modal) {
            modal.classList.add('active');
            this.loadSettings();
        }
    }

    closeSettingsModal() {
        const modal = document.getElementById('settingsModal');
        if (modal) modal.classList.remove('active');
    }

    async loadSettings() {
        try {
            const response = await fetch('/tracking/api/get_settings.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) return;

            const data = await response.json();
            if (!data.success || !data.settings) return;

            const form = document.getElementById('settingsForm');
            if (!form) return;

            const settings = data.settings;

            const updateIntervalEl = form.querySelector('[name="update_interval_seconds"]');
            if (updateIntervalEl) updateIntervalEl.value = settings.update_interval_seconds || 10;

            const isTrackingEl = form.querySelector('[name="is_tracking_enabled"]');
            if (isTrackingEl) isTrackingEl.checked = settings.is_tracking_enabled !== false;

            const highAccuracyEl = form.querySelector('[name="high_accuracy_mode"]');
            if (highAccuracyEl) highAccuracyEl.checked = settings.high_accuracy_mode !== false;

            const backgroundEl = form.querySelector('[name="background_tracking"]');
            if (backgroundEl) backgroundEl.checked = settings.background_tracking !== false;

            const showSpeedEl = form.querySelector('[name="show_speed"]');
            if (showSpeedEl) showSpeedEl.checked = settings.show_speed !== false;

            const showBatteryEl = form.querySelector('[name="show_battery"]');
            if (showBatteryEl) showBatteryEl.checked = settings.show_battery !== false;

            const historyRetentionEl = form.querySelector('[name="history_retention_days"]');
            if (historyRetentionEl) historyRetentionEl.value = settings.history_retention_days || 30;

        } catch (error) {
            console.error('Failed to load settings:', error);
        }
    }

    async saveSettings() {
        const form = document.getElementById('settingsForm');
        if (!form) return;

        const formData = new FormData(form);
        const settings = {};

        formData.forEach((value, key) => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input?.type === 'checkbox') {
                settings[key] = input.checked ? 1 : 0;
            } else {
                settings[key] = value;
            }
        });

        this.showLoading(true);

        try {
            const response = await fetch('/tracking/api/save_settings.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(settings)
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to save settings');

            this.showToast('Settings saved!', 'success');
            this.closeSettingsModal();

            // Update Android settings
            if (window.Android && typeof window.Android.updateTrackingSettings === 'function') {
                window.Android.updateTrackingSettings(
                    parseInt(settings.update_interval_seconds),
                    settings.high_accuracy_mode === 1
                );
            }

            // Update polling config
            const newIntervalSeconds = parseInt(settings.update_interval_seconds);
            if (newIntervalSeconds && newIntervalSeconds !== this.pollingConfig.default) {
                this.pollingConfig.default = newIntervalSeconds;
                this.restartPolling();
            }

        } catch (error) {
            console.error('Failed to save settings:', error);
            this.showToast('Failed to save settings: ' + error.message, 'error');
        } finally {
            this.showLoading(false);
        }
    }

    // ============================================
    // UI HELPERS
    // ============================================

    closeAllModals() {
        document.querySelectorAll('.modal.active').forEach(modal => modal.classList.remove('active'));
        this.clearHistory();
    }

    showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.style.display = show ? 'flex' : 'none';
    }

    showToast(message, type = 'info') {
        document.querySelectorAll('.toast-notification').forEach(t => t.remove());

        const colors = { success: '#43e97b', error: '#ff6b6b', warning: '#ffa502', info: '#4facfe' };
        const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };

        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.innerHTML = `<div style="position: fixed; bottom: 30px; right: 30px; background: rgba(26, 29, 46, 0.98); backdrop-filter: blur(40px) saturate(180%); color: white; padding: 18px 26px; border-radius: 14px; font-weight: 700; font-size: 14px; box-shadow: 0 20px 60px rgba(0,0,0,0.6); z-index: 10001; border-left: 4px solid ${colors[type]}; display: flex; align-items: center; gap: 14px; animation: slideInRight 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); max-width: 420px; font-family: 'Plus Jakarta Sans', sans-serif;"><div style="width: 36px; height: 36px; border-radius: 50%; background: ${colors[type]}; color: white; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; font-weight: 900; box-shadow: 0 4px 12px ${colors[type]}40;">${icons[type]}</div><span>${message}</span></div>`;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    // ============================================
    // ANDROID BRIDGE
    // ============================================

    notifyAndroidTrackingVisible() {
        try {
            if (window.Android?.onTrackingScreenVisible) {
                window.Android.onTrackingScreenVisible();
            }
        } catch (e) {}
    }

    notifyAndroidTrackingHidden() {
        try {
            if (window.Android?.onTrackingScreenHidden) {
                window.Android.onTrackingScreenHidden();
            }
        } catch (e) {}
    }

    requestLocationBoost(intervalSeconds = 5) {
        try {
            if (window.Android?.requestLocationBoost) {
                window.Android.requestLocationBoost(intervalSeconds);
            }
        } catch (e) {}
    }

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================

    formatRelativeTime(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;

        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return `${diffHours}h ago`;

        const diffDays = Math.floor(diffHours / 24);
        if (diffDays < 7) return `${diffDays}d ago`;

        return date.toLocaleDateString();
    }

    formatDuration(minutes) {
        if (minutes < 60) return `${Math.round(minutes)}m`;
        const hours = Math.floor(minutes / 60);
        const mins = Math.round(minutes % 60);
        return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
    }

    calculateDistance(coordinates) {
        let distance = 0;

        for (let i = 1; i < coordinates.length; i++) {
            const [lat1, lon1] = coordinates[i - 1];
            const [lat2, lon2] = coordinates[i];

            const R = 6371;
            const dLat = this.toRad(lat2 - lat1);
            const dLon = this.toRad(lon2 - lon1);

            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(this.toRad(lat1)) * Math.cos(this.toRad(lat2)) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);

            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            distance += R * c;
        }

        return distance;
    }

    toRad(degrees) {
        return degrees * (Math.PI / 180);
    }

    // ============================================
    // CLUSTERING & SPREADING ALGORITHMS
    // ============================================

    /**
     * Cluster members by proximity threshold
     * @param {Array} members - Array of member objects with location
     * @param {number} threshold - Distance threshold in degrees (~0.001 = 100m)
     * @returns {Array} Array of cluster objects with center and members
     */
    clusterMembersByProximity(members, threshold = 0.001) {
        const membersWithLocation = members.filter(m =>
            m.location && m.location.lat && m.location.lng
        );

        const clusters = [];
        const assigned = new Set();

        membersWithLocation.forEach(member => {
            if (assigned.has(member.user_id)) return;

            // Start a new cluster with this member
            const cluster = {
                center: { lat: member.location.lat, lng: member.location.lng },
                members: [member]
            };

            // Find all nearby members
            membersWithLocation.forEach(other => {
                if (other.user_id === member.user_id || assigned.has(other.user_id)) return;

                const distance = Math.sqrt(
                    Math.pow(member.location.lat - other.location.lat, 2) +
                    Math.pow(member.location.lng - other.location.lng, 2)
                );

                if (distance <= threshold) {
                    cluster.members.push(other);
                    assigned.add(other.user_id);
                }
            });

            // Calculate cluster center as average
            if (cluster.members.length > 1) {
                const sumLat = cluster.members.reduce((sum, m) => sum + m.location.lat, 0);
                const sumLng = cluster.members.reduce((sum, m) => sum + m.location.lng, 0);
                cluster.center = {
                    lat: sumLat / cluster.members.length,
                    lng: sumLng / cluster.members.length
                };
            }

            assigned.add(member.user_id);
            clusters.push(cluster);
        });

        return clusters;
    }

    /**
     * Calculate spiral offset position for clustered markers
     * Spreads markers in a spiral pattern around the center point
     * @param {number} centerLat - Center latitude
     * @param {number} centerLng - Center longitude
     * @param {number} index - Member index in cluster (0-based)
     * @param {number} total - Total members in cluster
     * @returns {Object} { lat, lng } of offset position
     */
    calculateSpiralOffset(centerLat, centerLng, index, total) {
        if (total <= 1) {
            return { lat: centerLat, lng: centerLng };
        }

        // Small offset - just enough to see each avatar (~50-70 meters)
        // 0.0005 degrees ≈ 55 meters
        const baseOffset = 0.0005;

        // Distribute evenly in a circle
        const angleStep = (2 * Math.PI) / total;
        const startAngle = -Math.PI / 2; // Start from top
        const angle = startAngle + (index * angleStep);

        // Calculate offset position
        const lat = centerLat + Math.sin(angle) * baseOffset;
        const lng = centerLng + Math.cos(angle) * baseOffset * 1.2;

        return { lat, lng };
    }
}

// ============================================
// GLOBAL INSTANCE & INITIALIZATION
// ============================================

let TrackingMap;

document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM loaded, initializing tracking map...');

    try {
        TrackingMap = new TrackingMapProfessional();
        window.TrackingMap = TrackingMap;
        console.log('Tracking map instance created');
    } catch (error) {
        console.error('Failed to create tracking map:', error);

        const mapContainer = document.getElementById('trackingMap');
        if (mapContainer) {
            mapContainer.innerHTML = `<div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; padding: 40px; text-align: center; background: linear-gradient(135deg, #667eea, #764ba2);"><svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="margin-bottom: 24px; opacity: 0.8;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg><h2 style="font-size: 28px; font-weight: 900; margin-bottom: 14px; color: white;">Failed to load map</h2><p style="font-size: 15px; color: rgba(255,255,255,0.7); margin-bottom: 24px; max-width: 400px; font-weight: 600;">${error.message}</p><button onclick="location.reload()" style="padding: 14px 32px; background: white; border: none; border-radius: 14px; color: #667eea; font-weight: 800; cursor: pointer; font-size: 15px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">🔄 Reload Page</button></div>`;
        }
    }
});

// Inject styles
const style = document.createElement('style');
style.textContent = `
    /* Toast animations */
    @keyframes slideInRight { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    @keyframes slideOutRight { to { transform: translateX(400px); opacity: 0; } }

    /* Standard marker styles */
    .custom-marker { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    .custom-marker.is-me { animation: pulseMarker 2.5s ease-in-out infinite; }
    @keyframes pulseMarker { 0%, 100% { box-shadow: 0 6px 20px rgba(0,0,0,0.4); } 50% { box-shadow: 0 6px 20px rgba(0,0,0,0.4), 0 0 0 15px rgba(102, 126, 234, 0.15); } }

    /* Balloon marker styles */
    .balloon-marker { cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    .balloon-marker:hover { transform: scale(1.08) translateY(-4px); z-index: 10000 !important; }
    .balloon-marker.is-me .balloon-avatar { animation: pulseAvatar 2.5s ease-in-out infinite; }
    @keyframes pulseAvatar { 0%, 100% { box-shadow: 0 4px 16px rgba(0,0,0,0.35); } 50% { box-shadow: 0 4px 16px rgba(0,0,0,0.35), 0 0 0 12px rgba(102, 126, 234, 0.2); } }

    /* Location dot styles */
    .location-dot-container { z-index: 1 !important; }
    .location-dot { animation: dotPulse 2s ease-in-out infinite; transition: all 0.3s ease; }
    @keyframes dotPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.2); } }

    /* Tether line animation */
    .tether-line { pointer-events: none; }

    /* Popup responsive */
    .leaflet-popup-content-wrapper { max-width: 95vw !important; }
    @media (max-width: 480px) { .leaflet-popup-content-wrapper { max-width: 90vw !important; } }

    /* Member marker container positioning fix */
    .member-marker-container { overflow: visible !important; }
    .balloon-marker-container { overflow: visible !important; }
`;
document.head.appendChild(style);

console.log('Tracking JavaScript v5.0 loaded');
