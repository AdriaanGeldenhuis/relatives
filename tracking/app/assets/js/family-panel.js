/**
 * Family Panel Module
 */

window.FamilyPanel = {
    panel: null,
    list: null,
    isCollapsed: false,

    init() {
        this.panel = document.getElementById('family-panel');
        this.list = document.getElementById('family-list');
        const toggle = document.getElementById('family-panel-toggle');

        // Toggle collapse
        toggle.addEventListener('click', () => this.toggleCollapse());

        // Listen for state changes
        TrackingState.on('members:updated', (members) => this.render(members));
        TrackingState.on('member:selected', (userId) => this.highlightMember(userId));
    },

    toggleCollapse() {
        this.isCollapsed = !this.isCollapsed;
        this.panel.classList.toggle('collapsed', this.isCollapsed);
    },

    render(members) {
        this.list.innerHTML = members.map(member => `
            <div class="member-item ${member.user_id === TrackingState.selectedMember ? 'active' : ''}"
                 data-user-id="${member.user_id}">
                <div class="member-avatar" style="background-color: ${member.avatar_color}">
                    ${Format.initials(member.name)}
                </div>
                <div class="member-info">
                    <div class="member-name">${this.escapeHtml(member.name)}</div>
                    <div class="member-status">
                        <span class="status-dot ${this.getStatusClass(member)}"></span>
                        ${Format.statusText(member.status, member.motion_state)}
                        ${member.updated_at ? ' - ' + Format.timeAgo(member.updated_at) : ''}
                    </div>
                </div>
            </div>
        `).join('');

        // Add click handlers
        this.list.querySelectorAll('.member-item').forEach(item => {
            item.addEventListener('click', () => {
                const userId = parseInt(item.dataset.userId);
                TrackingState.selectMember(userId);

                const member = TrackingState.getMember(userId);
                if (member) {
                    TrackingMap.panTo(member.lat, member.lng, 15);
                }
            });
        });
    },

    getStatusClass(member) {
        if (member.status === 'offline') return 'offline';
        if (member.status === 'stale') return 'stale';
        if (member.motion_state === 'moving') return 'moving';
        return 'active';
    },

    highlightMember(userId) {
        this.list.querySelectorAll('.member-item').forEach(item => {
            const itemUserId = parseInt(item.dataset.userId);
            item.classList.toggle('active', itemUserId === userId);
        });
    },

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
