/**
 * Format Utilities
 */

window.Format = {
    /**
     * Format time ago
     */
    timeAgo(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
        if (seconds < 604800) return Math.floor(seconds / 86400) + ' days ago';

        return date.toLocaleDateString();
    },

    /**
     * Format distance
     */
    distance(meters, units = 'metric') {
        if (units === 'imperial') {
            const feet = meters * 3.28084;
            if (feet < 1000) return Math.round(feet) + ' ft';
            return (feet / 5280).toFixed(1) + ' mi';
        }

        if (meters < 1000) return Math.round(meters) + ' m';
        return (meters / 1000).toFixed(1) + ' km';
    },

    /**
     * Format duration
     */
    duration(seconds) {
        if (seconds < 60) return 'Less than 1 min';

        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return minutes + ' min';

        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;

        if (mins === 0) return hours + ' hr';
        return hours + ' hr ' + mins + ' min';
    },

    /**
     * Format speed
     */
    speed(mps, units = 'metric') {
        if (!mps) return null;

        if (units === 'imperial') {
            return (mps * 2.237).toFixed(1) + ' mph';
        }

        return (mps * 3.6).toFixed(1) + ' km/h';
    },

    /**
     * Get initials from name
     */
    initials(name) {
        if (!name) return '?';
        return name.split(' ')
            .map(part => part[0])
            .join('')
            .toUpperCase()
            .substring(0, 2);
    },

    /**
     * Format date/time
     */
    dateTime(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleString();
    },

    /**
     * Get status text
     */
    statusText(status, motionState) {
        if (status === 'offline') return 'Offline';
        if (status === 'stale') return 'Last seen recently';
        if (motionState === 'moving') return 'Moving';
        if (motionState === 'idle') return 'Stationary';
        return 'Active';
    }
};
