/**
 * Holiday Traveling - AI Worker JavaScript
 * Handles AI plan generation, refinement, and late mode
 */
(function() {
    'use strict';

    window.HT = window.HT || {};

    /**
     * AI Worker - Handles all AI interactions
     */
    HT.AIWorker = {
        /**
         * Generate a new plan for a trip
         */
        async generatePlan(tripId) {
            return this._callAI('ai_generate_plan.php', { trip_id: tripId }, {
                title: 'Generating Plan...',
                text: 'AI is creating your personalized travel itinerary'
            });
        },

        /**
         * Refine existing plan with instruction
         */
        async refinePlan(tripId, instruction) {
            return this._callAI('ai_refine_plan.php', {
                trip_id: tripId,
                instruction: instruction
            }, {
                title: 'Refining Plan...',
                text: 'AI is adjusting your itinerary based on your feedback'
            });
        },

        /**
         * Late mode - adjust today's schedule
         */
        async lateMode(tripId, options) {
            return this._callAI('ai_late_mode.php', {
                trip_id: tripId,
                late_minutes: options.lateMinutes || 30,
                energy: options.energy || 'ok',
                keep_dinner: options.keepDinner !== false
            }, {
                title: 'Adjusting Schedule...',
                text: 'AI is reorganizing today\'s activities'
            });
        },

        /**
         * Generate packing list
         */
        async generatePackingList(tripId) {
            return this._callAI('ai_packing_list.php', { trip_id: tripId }, {
                title: 'Creating Packing List...',
                text: 'AI is generating a customized packing list for your trip'
            });
        },

        /**
         * Get safety brief for destination
         */
        async getSafetyBrief(tripId) {
            return this._callAI('ai_safety_brief.php', { trip_id: tripId }, {
                title: 'Loading Safety Info...',
                text: 'AI is gathering destination-specific safety information'
            });
        },

        /**
         * Internal: Call AI endpoint with loading state
         */
        async _callAI(endpoint, data, loadingInfo) {
            const overlay = document.getElementById('aiLoadingOverlay');
            const titleEl = document.getElementById('aiLoadingTitle');
            const textEl = document.getElementById('aiLoadingText');

            try {
                // Show loading overlay
                if (overlay) {
                    if (titleEl) titleEl.textContent = loadingInfo.title;
                    if (textEl) textEl.textContent = loadingInfo.text;
                    overlay.style.display = 'flex';
                }

                const response = await HT.API.post(endpoint, data);

                return response;
            } catch (error) {
                HT.Toast.error(error.message || 'AI request failed');
                throw error;
            } finally {
                if (overlay) {
                    overlay.style.display = 'none';
                }
            }
        }
    };

    /**
     * Initialize trip view page interactions
     */
    function initTripView() {
        if (!document.querySelector('.ht-trip-view')) return;

        initTabs();
        initVersionSelect();
        initPlanActions();
        loadWalletPreview();
        loadExpensesPreview();
        loadGoogleCalendarStatus();
    }

    /**
     * Initialize tab switching
     */
    function initTabs() {
        const tabBtns = document.querySelectorAll('.ht-tab-btn');
        const tabContents = document.querySelectorAll('.ht-tab-content');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const tabId = this.dataset.tab;

                // Update buttons
                tabBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                // Update content
                tabContents.forEach(c => {
                    c.classList.remove('active');
                    if (c.dataset.tab === tabId) {
                        c.classList.add('active');
                    }
                });
            });
        });
    }

    /**
     * Initialize version select dropdown
     */
    function initVersionSelect() {
        const versionSelect = document.getElementById('planVersionSelect');
        const restoreBtn = document.getElementById('restoreVersionBtn');

        if (!versionSelect) return;

        const originalValue = versionSelect.value;

        versionSelect.addEventListener('change', function() {
            if (this.value !== originalValue && restoreBtn) {
                restoreBtn.style.display = 'inline-flex';
            } else if (restoreBtn) {
                restoreBtn.style.display = 'none';
            }
        });

        if (restoreBtn) {
            restoreBtn.addEventListener('click', async function() {
                const versionNumber = versionSelect.value;
                if (!confirm('Restore this plan version? A new version will be created from the selected one.')) {
                    return;
                }
                try {
                    await HT.API.post('trips_restore_version.php', {
                        trip_id: HT.tripId,
                        version_number: parseInt(versionNumber)
                    });
                    HT.Toast.success('Plan version restored');
                    window.location.reload();
                } catch (error) {
                    HT.Toast.error(error.message || 'Failed to restore version');
                }
            });
        }
    }

    /**
     * Initialize plan action buttons
     */
    function initPlanActions() {
        // Generate Plan Button
        const generateBtn = document.getElementById('generatePlanBtn');
        if (generateBtn) {
            generateBtn.addEventListener('click', async function() {
                try {
                    await HT.AIWorker.generatePlan(HT.tripId);
                    HT.Toast.success('Plan generated successfully!');
                    window.location.reload();
                } catch (error) {
                    // Error already shown by AIWorker
                }
            });
        }

        // Google Calendar Button
        const googleCalBtn = document.getElementById('googleCalendarBtn');
        if (googleCalBtn) {
            googleCalBtn.addEventListener('click', function() {
                window.location.href = `/holiday_traveling/api/calendar_google_oauth_start.php?trip_id=${HT.tripId}`;
            });
        }

    }

    /**
     * Load wallet preview (stub for now)
     */
    async function loadWalletPreview() {
        const container = document.getElementById('walletPreview');
        if (!container || !HT.tripId) return;

        try {
            const response = await HT.API.get(`wallet_list.php?trip_id=${HT.tripId}&limit=5`);
            const items = response.data || [];

            if (items.length === 0) {
                container.innerHTML = `
                    <div class="ht-empty-preview">
                        <p>No wallet items yet</p>
                    </div>
                `;
            } else {
                container.innerHTML = items.map(item => `
                    <div class="ht-wallet-item-preview">
                        <span class="ht-wallet-type">${getWalletIcon(item.type)}</span>
                        <span class="ht-wallet-label">${escapeHtml(item.label)}</span>
                    </div>
                `).join('');
            }
        } catch (error) {
            container.innerHTML = '<p class="ht-error">Failed to load wallet items</p>';
        }
    }

    /**
     * Load expenses preview (stub for now)
     */
    async function loadExpensesPreview() {
        const container = document.getElementById('expensesPreview');
        if (!container || !HT.tripId) return;

        try {
            const response = await HT.API.get(`expenses_summary.php?trip_id=${HT.tripId}`);
            const summary = response.data;

            if (!summary || summary.total === 0) {
                container.innerHTML = `
                    <div class="ht-empty-preview">
                        <p>No expenses recorded yet</p>
                    </div>
                `;
            } else {
                container.innerHTML = `
                    <div class="ht-expenses-summary-preview">
                        <div class="ht-expense-total">
                            <span class="ht-expense-total-label">Total Spent</span>
                            <span class="ht-expense-total-value">${summary.currency} ${summary.total.toFixed(2)}</span>
                        </div>
                        <div class="ht-expense-count">${summary.count} expense${summary.count !== 1 ? 's' : ''}</div>
                    </div>
                `;
            }
        } catch (error) {
            container.innerHTML = '<p class="ht-error">Failed to load expenses</p>';
        }
    }

    /**
     * Load Google Calendar connection status
     */
    async function loadGoogleCalendarStatus() {
        const container = document.getElementById('googleCalendarStatus');
        if (!container || !HT.tripId) return;

        try {
            const response = await HT.API.get('calendar_google_status.php');
            const isConnected = response.data?.connected;

            if (isConnected) {
                container.innerHTML = `
                    <p class="ht-calendar-section-desc ht-calendar-connected">
                        <span class="ht-status-icon">✅</span>
                        Google Calendar connected
                    </p>
                    <div class="ht-calendar-actions">
                        <button id="syncGoogleCalBtn" class="ht-btn ht-btn-primary ht-btn-sm">
                            <span class="ht-btn-icon">🔄</span>
                            Sync to Calendar
                        </button>
                        <button id="disconnectGoogleBtn" class="ht-btn ht-btn-outline ht-btn-sm">
                            Disconnect
                        </button>
                    </div>
                `;

                // Bind sync button
                document.getElementById('syncGoogleCalBtn')?.addEventListener('click', async function() {
                    this.disabled = true;
                    this.innerHTML = '<span class="ht-btn-icon">⏳</span> Syncing...';
                    try {
                        const result = await HT.API.post('calendar_google_push.php', { trip_id: HT.tripId });
                        HT.Toast.success(result.data?.message || 'Events synced!');
                    } catch (error) {
                        HT.Toast.error(error.message || 'Failed to sync');
                    } finally {
                        this.disabled = false;
                        this.innerHTML = '<span class="ht-btn-icon">🔄</span> Sync to Calendar';
                    }
                });

                // Bind disconnect button
                document.getElementById('disconnectGoogleBtn')?.addEventListener('click', async function() {
                    if (!confirm('Disconnect Google Calendar?')) return;
                    try {
                        await HT.API.post('calendar_google_disconnect.php', {});
                        HT.Toast.success('Google Calendar disconnected');
                        loadGoogleCalendarStatus(); // Reload status
                    } catch (error) {
                        HT.Toast.error(error.message || 'Failed to disconnect');
                    }
                });
            } else {
                container.innerHTML = `
                    <p class="ht-calendar-section-desc">
                        Connect your Google account to automatically sync events.
                    </p>
                    <a href="/holiday_traveling/api/calendar_google_oauth_start.php?trip_id=${HT.tripId}" class="ht-btn ht-btn-outline">
                        <span class="ht-btn-icon">🔗</span>
                        Connect Google Calendar
                    </a>
                `;
            }
        } catch (error) {
            // Google OAuth might not be configured
            container.innerHTML = `
                <p class="ht-calendar-section-desc ht-calendar-unavailable">
                    Google Calendar integration is not available.
                    <br><small>Use ICS download instead.</small>
                </p>
            `;
        }
    }

    /**
     * Get wallet item icon by type
     */
    function getWalletIcon(type) {
        const icons = {
            ticket: '🎫',
            booking: '🏨',
            doc: '📄',
            note: '📝',
            qr: '📱',
            link: '🔗',
            contact: '👤',
            insurance: '🛡️',
            visa: '🛂'
        };
        return icons[type] || '📎';
    }

    /**
     * Escape HTML for safe rendering
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTripView);
    } else {
        initTripView();
    }

})();
