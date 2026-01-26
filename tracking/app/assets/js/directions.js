/**
 * Directions Module
 */

window.Directions = {
    infoPanel: null,

    init() {
        // Create directions info panel if not exists
        if (!document.querySelector('.directions-info')) {
            const panel = document.createElement('div');
            panel.className = 'directions-info';
            panel.innerHTML = `
                <div class="directions-header">
                    <div class="directions-stats">
                        <div class="directions-stat">
                            <div class="stat-value" id="dir-distance">--</div>
                            <div class="stat-label">Distance</div>
                        </div>
                        <div class="directions-stat">
                            <div class="stat-value" id="dir-duration">--</div>
                            <div class="stat-label">Duration</div>
                        </div>
                    </div>
                    <button class="directions-close" id="dir-close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="directions-destination" id="dir-destination"></div>
            `;
            document.body.appendChild(panel);
        }

        this.infoPanel = document.querySelector('.directions-info');

        // Close button
        document.getElementById('dir-close').addEventListener('click', () => {
            this.hide();
        });

        // Listen for state changes
        TrackingState.on('directions:cleared', () => {
            this.infoPanel.classList.remove('active');
        });
    },

    show(route) {
        document.getElementById('dir-distance').textContent = route.distance_text;
        document.getElementById('dir-duration').textContent = route.duration_text;

        const dest = document.getElementById('dir-destination');
        if (route.destination_name) {
            dest.textContent = 'To: ' + route.destination_name;
            dest.style.display = 'block';
        } else {
            dest.style.display = 'none';
        }

        this.infoPanel.classList.add('active');
    },

    hide() {
        this.infoPanel.classList.remove('active');
        TrackingState.clearDirections();
    }
};
