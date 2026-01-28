/**
 * Family Panel Module
 */

window.FamilyPanel = {
    panel: null,
    list: null,
    toggleBtn: null,
    isOpen: false,

    init() {
        this.panel = document.getElementById('family-panel');
        this.list = document.getElementById('family-list');
        this.toggleBtn = document.getElementById('btn-family');
        const closeBtn = document.getElementById('family-panel-close');

        // Toggle panel with button
        this.toggleBtn.addEventListener('click', () => this.toggle());

        // Close panel with close button
        closeBtn.addEventListener('click', () => this.close());

        // Close panel when clicking outside
        document.addEventListener('click', (e) => {
            if (this.isOpen &&
                !this.panel.contains(e.target) &&
                !this.toggleBtn.contains(e.target)) {
                this.close();
            }
        });

        // Listen for state changes
        TrackingState.on('members:updated', (members) => this.render(members));
        TrackingState.on('member:selected', (userId) => this.highlightMember(userId));
    },

    toggle() {
        this.isOpen = !this.isOpen;
        this.panel.classList.toggle('open', this.isOpen);
        this.toggleBtn.classList.toggle('active', this.isOpen);
    },

    open() {
        this.isOpen = true;
        this.panel.classList.add('open');
        this.toggleBtn.classList.add('active');
    },

    close() {
        this.isOpen = false;
        this.panel.classList.remove('open');
        this.toggleBtn.classList.remove('active');
    },

    render(members) {
        this.list.innerHTML = members.map(member => `
            <div class="member-item ${member.user_id === TrackingState.selectedMember ? 'active' : ''}"
                 data-user-id="${member.user_id}">
                <div class="member-avatar" style="background-color: ${member.avatar_color}">
                    <img src="${member.avatar_url}"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                         style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                    <span class="avatar-fallback" style="display:none; width:100%; height:100%; align-items:center; justify-content:center;">${Format.initials(member.name)}</span>
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

                // Close the family panel after selecting a member
                this.close();
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
