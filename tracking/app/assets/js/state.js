/**
 * State Management
 */

window.TrackingState = {
    // Current data
    members: [],
    session: null,
    settings: null,
    geofences: [],
    places: [],

    // UI state
    selectedMember: null,
    followingMember: null,
    showGeofences: false,
    directionsRoute: null,

    // Listeners
    listeners: {},

    /**
     * Subscribe to state changes
     */
    on(event, callback) {
        if (!this.listeners[event]) {
            this.listeners[event] = [];
        }
        this.listeners[event].push(callback);
    },

    /**
     * Emit event
     */
    emit(event, data) {
        if (this.listeners[event]) {
            this.listeners[event].forEach(cb => cb(data));
        }
    },

    /**
     * Update members
     */
    setMembers(members) {
        this.members = members;
        this.emit('members:updated', members);
    },

    /**
     * Get member by ID
     */
    getMember(userId) {
        // Use loose equality to handle both string and integer IDs
        return this.members.find(m => m.user_id == userId);
    },

    /**
     * Update session state
     */
    setSession(session) {
        this.session = session;
        this.emit('session:updated', session);
    },

    /**
     * Update settings
     */
    setSettings(settings) {
        this.settings = settings;
        this.emit('settings:updated', settings);
    },

    /**
     * Update geofences
     */
    setGeofences(geofences) {
        this.geofences = geofences;
        this.emit('geofences:updated', geofences);
    },

    /**
     * Update places
     */
    setPlaces(places) {
        this.places = places;
        this.emit('places:updated', places);
    },

    /**
     * Select a member
     */
    selectMember(userId) {
        this.selectedMember = userId;
        this.emit('member:selected', userId);
    },

    /**
     * Clear selection
     */
    clearSelection() {
        this.selectedMember = null;
        this.emit('member:selected', null);
    },

    /**
     * Start following a member
     */
    startFollowing(userId) {
        this.followingMember = userId;
        this.emit('follow:started', userId);
    },

    /**
     * Stop following
     */
    stopFollowing() {
        this.followingMember = null;
        this.emit('follow:stopped');
    },

    /**
     * Toggle geofences visibility
     */
    toggleGeofences() {
        this.showGeofences = !this.showGeofences;
        this.emit('geofences:toggled', this.showGeofences);
    },

    /**
     * Set directions route
     */
    setDirectionsRoute(route) {
        this.directionsRoute = route;
        this.emit('directions:updated', route);
    },

    /**
     * Clear directions
     */
    clearDirections() {
        this.directionsRoute = null;
        this.emit('directions:cleared');
    }
};
