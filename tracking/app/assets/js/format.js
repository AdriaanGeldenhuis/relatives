/**
 * Tracking App - Formatting Utilities
 *
 * Pure functions for formatting speed, distance, time, coordinates, and
 * status indicators used throughout the UI.
 *
 * Usage:
 *   Tracking.format.speed(12.5, 'metric');  // "45.0 km/h"
 *   Tracking.format.timeAgo('2026-02-07T10:00:00Z'); // "3 min ago"
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Thresholds (milliseconds)
    // -----------------------------------------------------------------------
    var ONLINE_THRESHOLD  =  5 * 60 * 1000; // 5 minutes
    var IDLE_THRESHOLD    = 60 * 60 * 1000;  // 60 minutes

    // -----------------------------------------------------------------------
    // Speed
    // -----------------------------------------------------------------------

    /**
     * Format a speed value.
     *
     * @param {number} mps   - Speed in metres per second.
     * @param {string} [units='metric'] - 'metric' (km/h) or 'imperial' (mph).
     * @returns {string} Formatted speed string, e.g. "45.0 km/h".
     */
    function speed(mps, units) {
        if (mps === null || mps === undefined || isNaN(mps)) {
            return '--';
        }
        if (units === 'imperial') {
            return (mps * 2.23694).toFixed(1) + ' mph';
        }
        return (mps * 3.6).toFixed(1) + ' km/h';
    }

    // -----------------------------------------------------------------------
    // Distance
    // -----------------------------------------------------------------------

    /**
     * Format a distance value.
     *
     * @param {number} meters
     * @param {string} [units='metric'] - 'metric' (km/m) or 'imperial' (mi/ft).
     * @returns {string} e.g. "1.2 km" or "850 m".
     */
    function distance(meters, units) {
        if (meters === null || meters === undefined || isNaN(meters)) {
            return '--';
        }
        if (units === 'imperial') {
            var miles = meters / 1609.344;
            if (miles >= 0.1) {
                return miles.toFixed(1) + ' mi';
            }
            return Math.round(meters * 3.28084) + ' ft';
        }
        if (meters >= 1000) {
            return (meters / 1000).toFixed(1) + ' km';
        }
        return Math.round(meters) + ' m';
    }

    // -----------------------------------------------------------------------
    // Time ago
    // -----------------------------------------------------------------------

    /**
     * Return a human-readable "time ago" string.
     *
     * @param {string|Date} dateString - An ISO-8601 date string or Date object.
     * @returns {string} e.g. "just now", "2 min ago", "3 hours ago".
     */
    function timeAgo(dateString) {
        if (!dateString) {
            return 'never';
        }
        var date = dateString instanceof Date ? dateString : new Date(dateString);
        var now = Date.now();
        var diffMs = now - date.getTime();

        if (diffMs < 0) {
            return 'just now';
        }

        var seconds = Math.floor(diffMs / 1000);
        if (seconds < 60) {
            return 'just now';
        }

        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return minutes + ' min ago';
        }

        var hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return hours + (hours === 1 ? ' hour ago' : ' hours ago');
        }

        var days = Math.floor(hours / 24);
        if (days < 30) {
            return days + (days === 1 ? ' day ago' : ' days ago');
        }

        var months = Math.floor(days / 30);
        return months + (months === 1 ? ' month ago' : ' months ago');
    }

    // -----------------------------------------------------------------------
    // Coordinates
    // -----------------------------------------------------------------------

    /**
     * Format latitude/longitude for display.
     *
     * @param {number} lat
     * @param {number} lng
     * @returns {string} e.g. "-28.50000, 25.50000"
     */
    function coords(lat, lng) {
        if (lat === null || lat === undefined || lng === null || lng === undefined) {
            return '--';
        }
        return parseFloat(lat).toFixed(5) + ', ' + parseFloat(lng).toFixed(5);
    }

    // -----------------------------------------------------------------------
    // Motion icon
    // -----------------------------------------------------------------------

    /**
     * Return an emoji/icon representing the member's motion state.
     *
     * @param {string} state - 'walking', 'running', 'driving', 'cycling',
     *                         'stationary', or any other string.
     * @returns {string} An emoji character.
     */
    function motionIcon(state) {
        switch (state) {
            case 'walking':     return '\uD83D\uDEB6'; // person walking
            case 'running':     return '\uD83C\uDFC3'; // person running
            case 'driving':     return '\uD83D\uDE97'; // car
            case 'cycling':     return '\uD83D\uDEB2'; // bicycle
            case 'stationary':  return '\uD83D\uDCCD'; // pin / stationary
            default:            return '\uD83D\uDCCD'; // pin
        }
    }

    // -----------------------------------------------------------------------
    // Status colour
    // -----------------------------------------------------------------------

    /**
     * Determine a colour based on how recently a member was seen.
     *
     * @param {string|Date} updatedAt - Last-updated timestamp.
     * @returns {string} CSS colour: '#22c55e' (green), '#eab308' (yellow),
     *                   or '#9ca3af' (gray).
     */
    function statusColor(updatedAt) {
        if (!updatedAt) {
            return '#9ca3af'; // gray / unknown
        }
        var date = updatedAt instanceof Date ? updatedAt : new Date(updatedAt);
        var diffMs = Date.now() - date.getTime();

        if (diffMs < ONLINE_THRESHOLD) {
            return '#22c55e'; // green - online
        }
        if (diffMs < IDLE_THRESHOLD) {
            return '#eab308'; // yellow - idle
        }
        return '#9ca3af'; // gray - offline
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.format = {
        speed: speed,
        distance: distance,
        timeAgo: timeAgo,
        coords: coords,
        motionIcon: motionIcon,
        statusColor: statusColor,
    };
})();
