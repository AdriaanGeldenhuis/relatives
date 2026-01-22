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
        initModals();
        loadWalletPreview();
        loadExpensesPreview();
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
                const versionId = versionSelect.value;
                try {
                    await HT.API.post('trips_restore_version.php', {
                        trip_id: HT.tripId,
                        version_id: versionId
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

        // Refine Plan Button
        const refineBtn = document.getElementById('refinePlanBtn');
        if (refineBtn) {
            refineBtn.addEventListener('click', function() {
                openModal('refinePlanModal');
            });
        }

        // Late Mode Button
        const lateModeBtn = document.getElementById('lateModeBtn');
        if (lateModeBtn) {
            lateModeBtn.addEventListener('click', function() {
                openModal('lateModeModal');
            });
        }

        // Packing List Button
        const packingBtn = document.getElementById('packingListBtn');
        if (packingBtn) {
            packingBtn.addEventListener('click', async function() {
                try {
                    const response = await HT.AIWorker.generatePackingList(HT.tripId);
                    // Could open a modal with packing list or redirect
                    HT.Toast.success('Packing list generated!');
                    window.location.reload();
                } catch (error) {
                    // Error already shown
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
     * Initialize modals
     */
    function initModals() {
        // Refine Plan Modal
        initModal('refinePlanModal', async (modal) => {
            const instruction = document.getElementById('refineInstruction')?.value?.trim();
            if (!instruction) {
                HT.Toast.error('Please enter refinement instructions');
                return false;
            }

            try {
                await HT.AIWorker.refinePlan(HT.tripId, instruction);
                HT.Toast.success('Plan refined successfully!');
                window.location.reload();
            } catch (error) {
                return false;
            }
        });

        // Late Mode Modal
        initModal('lateModeModal', async (modal) => {
            const lateMinutes = modal.querySelector('.ht-chip-group[data-name="late_minutes"] .ht-chip-selected')?.dataset.value || '30';
            const energy = modal.querySelector('.ht-chip-group[data-name="energy"] .ht-chip-selected')?.dataset.value || 'ok';
            const keepDinner = modal.querySelector('.ht-chip-group[data-name="keep_dinner"] .ht-chip-selected')?.dataset.value === 'yes';

            try {
                await HT.AIWorker.lateMode(HT.tripId, {
                    lateMinutes: parseInt(lateMinutes),
                    energy: energy,
                    keepDinner: keepDinner
                });
                HT.Toast.success('Schedule adjusted!');
                window.location.reload();
            } catch (error) {
                return false;
            }
        });

        // Initialize chip groups in modals
        document.querySelectorAll('.ht-modal .ht-chip-group').forEach(group => {
            const chips = group.querySelectorAll('.ht-chip');
            chips.forEach(chip => {
                chip.addEventListener('click', function() {
                    chips.forEach(c => c.classList.remove('ht-chip-selected'));
                    this.classList.add('ht-chip-selected');
                });
            });
        });
    }

    /**
     * Initialize a modal with confirm/cancel handlers
     */
    function initModal(modalId, onConfirm) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const backdrop = modal.querySelector('.ht-modal-backdrop');
        const cancelBtn = modal.querySelector('[data-action="cancel"]');
        const confirmBtn = modal.querySelector('[data-action="confirm"]');

        const closeModal = () => {
            modal.style.display = 'none';
        };

        backdrop?.addEventListener('click', closeModal);
        cancelBtn?.addEventListener('click', closeModal);

        confirmBtn?.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Processing...';

            const result = await onConfirm(modal);

            if (result !== false) {
                closeModal();
            }

            confirmBtn.disabled = false;
            confirmBtn.textContent = confirmBtn.dataset.originalText || 'Confirm';
        });

        // Store original text
        if (confirmBtn) {
            confirmBtn.dataset.originalText = confirmBtn.textContent;
        }
    }

    /**
     * Open a modal by ID
     */
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'flex';
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
