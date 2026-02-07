/**
 * Tracking App - Family Members List Panel
 *
 * Renders a scrollable list of family members with avatars, status indicators,
 * speed, and last-seen timestamps. Clicking a row flies the map to that
 * member's location.
 *
 * Requires: Tracking.format, Tracking.map, Tracking.setState
 *
 * Usage:
 *   Tracking.familyPanel.init('family-panel');
 *   Tracking.familyPanel.render(membersArray);
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    var ONLINE_MS  =  5 * 60 * 1000; // < 5 min  -> online  (green)
    var IDLE_MS    = 60 * 60 * 1000;  // < 60 min -> idle    (yellow)
    // > 60 min -> offline (gray)

    /** @type {HTMLElement|null} */
    var container = null;

    // -----------------------------------------------------------------------
    // Initialisation
    // -----------------------------------------------------------------------

    /**
     * Set up the family panel.
     *
     * @param {string} containerId - The DOM id of the panel container element.
     */
    function init(containerId) {
        container = document.getElementById(containerId);
        if (!container) {
            console.warn('[FamilyPanel] Container "' + containerId + '" not found.');
        }
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * Determine the status label and dot colour for a member.
     *
     * @param {string|Date} updatedAt
     * @returns {{ label: string, color: string }}
     */
    function memberStatus(updatedAt) {
        if (!updatedAt) {
            return { label: 'Offline', color: '#9ca3af' };
        }
        var diff = Date.now() - new Date(updatedAt).getTime();
        if (diff < ONLINE_MS) {
            return { label: 'Online', color: '#22c55e' };
        }
        if (diff < IDLE_MS) {
            return { label: 'Idle', color: '#eab308' };
        }
        return { label: 'Offline', color: '#9ca3af' };
    }

    /**
     * Derive a consistent colour from a member (same logic as map markers).
     *
     * @param {Object} member
     * @returns {string} Hex colour.
     */
    function avatarColor(member) {
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

    /**
     * Escape HTML entities for safe insertion into innerHTML.
     * @param {string} str
     * @returns {string}
     */
    function esc(str) {
        var el = document.createElement('span');
        el.appendChild(document.createTextNode(String(str)));
        return el.innerHTML;
    }

    /**
     * Render the full member list.
     *
     * @param {Object[]} members - Array of member objects. Each should include
     *   id, name, latitude, longitude, speed, motion_state, updated_at.
     */
    function render(members) {
        if (!container) return;

        if (!members || members.length === 0) {
            container.innerHTML =
                '<div style="padding:16px;color:#888;text-align:center;">' +
                'No family members found.' +
                '</div>';
            return;
        }

        var fmt = Tracking.format || {};
        var html = '';

        for (var i = 0; i < members.length; i++) {
            var m = members[i];
            var status = memberStatus(m.updated_at);
            var initial = (m.name || '?').charAt(0).toUpperCase();
            var color = avatarColor(m);
            var speedText = fmt.speed ? fmt.speed(m.speed) : '--';
            var agoText = fmt.timeAgo ? fmt.timeAgo(m.updated_at) : '--';
            var motionEmoji = fmt.motionIcon ? fmt.motionIcon(m.motion_state) : '';

            html +=
                '<div class="family-member-row" data-member-id="' + esc(m.id) + '" ' +
                    'data-lat="' + (m.latitude || '') + '" data-lng="' + (m.longitude || '') + '" ' +
                    'style="display:flex;align-items:center;padding:10px 12px;' +
                    'cursor:pointer;border-bottom:1px solid #e5e7eb;transition:background .15s;" ' +
                    'onmouseenter="this.style.background=\'#f3f4f6\'" ' +
                    'onmouseleave="this.style.background=\'transparent\'">' +

                    // Avatar circle
                    '<div style="width:38px;height:38px;border-radius:50%;background:' + color +
                    ';color:#fff;display:flex;align-items:center;justify-content:center;' +
                    'font-weight:700;font-size:16px;flex-shrink:0;">' +
                        esc(initial) +
                    '</div>' +

                    // Info block
                    '<div style="flex:1;min-width:0;margin-left:10px;">' +
                        '<div style="display:flex;align-items:center;gap:6px;">' +
                            // Status dot
                            '<span style="width:8px;height:8px;border-radius:50%;' +
                            'background:' + status.color + ';display:inline-block;flex-shrink:0;"></span>' +
                            '<span style="font-weight:600;white-space:nowrap;overflow:hidden;' +
                            'text-overflow:ellipsis;">' + esc(m.name || 'Unknown') + '</span>' +
                        '</div>' +
                        '<div style="font-size:12px;color:#6b7280;margin-top:2px;">' +
                            motionEmoji + ' ' + esc(speedText) +
                            ' &middot; ' + esc(agoText) +
                        '</div>' +
                    '</div>' +

                    // Status label
                    '<div style="font-size:11px;color:' + status.color +
                    ';font-weight:600;flex-shrink:0;margin-left:8px;">' +
                        esc(status.label) +
                    '</div>' +

                '</div>';
        }

        container.innerHTML = html;
        bindRowClicks();
    }

    /**
     * Attach click handlers to each member row.
     */
    function bindRowClicks() {
        if (!container) return;

        var rows = container.querySelectorAll('.family-member-row');
        for (var i = 0; i < rows.length; i++) {
            rows[i].addEventListener('click', onRowClick);
        }
    }

    /**
     * Handle a click on a member row: fly the map to their location and
     * set them as the selected member.
     *
     * @param {Event} e
     */
    function onRowClick(e) {
        var row = e.currentTarget;
        var lat = parseFloat(row.getAttribute('data-lat'));
        var lng = parseFloat(row.getAttribute('data-lng'));
        var memberId = row.getAttribute('data-member-id');

        if (!isNaN(lat) && !isNaN(lng) && Tracking.map) {
            Tracking.map.flyToMember(lat, lng);
        }

        if (memberId) {
            Tracking.setState('selectedMember', memberId);
        }
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.familyPanel = {
        init: init,
        render: render,
    };
})();
