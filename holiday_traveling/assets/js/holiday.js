/**
 * Holiday Traveling Module - Main JavaScript
 * Core functionality for the travel planning module
 */
(function() {
    'use strict';

    // Initialize namespace
    window.HT = window.HT || {};

    /**
     * API Helper - Standardized API calls
     */
    HT.API = {
        /**
         * Make API request
         * @param {string} endpoint - API endpoint path
         * @param {object} options - Fetch options
         * @returns {Promise<object>} Response data
         */
        async request(endpoint, options = {}) {
            const defaults = {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': HT.csrfToken || ''
                }
            };

            const config = { ...defaults, ...options };
            if (config.body && typeof config.body === 'object') {
                config.body = JSON.stringify(config.body);
            }

            try {
                const response = await fetch(`/holiday_traveling/api/${endpoint}`, config);
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Request failed');
                }

                return data;
            } catch (error) {
                console.error('API Error:', error);
                throw error;
            }
        },

        async get(endpoint) {
            return this.request(endpoint, { method: 'GET' });
        },

        async post(endpoint, body) {
            return this.request(endpoint, { method: 'POST', body });
        },

        async put(endpoint, body) {
            return this.request(endpoint, { method: 'PUT', body });
        },

        async delete(endpoint) {
            return this.request(endpoint, { method: 'DELETE' });
        }
    };

    /**
     * Toast Notifications
     */
    HT.Toast = {
        show(message, type = 'info', duration = 3000) {
            // Use global Toast if available
            if (window.Toast && typeof window.Toast[type] === 'function') {
                window.Toast[type](message, duration);
                return;
            }

            // Fallback implementation
            const toast = document.createElement('div');
            toast.className = `ht-toast ht-toast-${type}`;
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                bottom: 100px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 24px;
                background: rgba(30, 30, 50, 0.95);
                border-radius: 8px;
                color: white;
                font-size: 14px;
                z-index: 9999;
                animation: htToastIn 0.3s ease;
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'htToastOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        },

        success(message) { this.show(message, 'success'); },
        error(message) { this.show(message, 'error', 5000); },
        warning(message) { this.show(message, 'warning'); },
        info(message) { this.show(message, 'info'); }
    };

    /**
     * Form Utilities
     */
    HT.Form = {
        /**
         * Get form data as object
         */
        getData(form) {
            const formData = new FormData(form);
            const data = {};

            for (const [key, value] of formData.entries()) {
                if (data[key]) {
                    if (!Array.isArray(data[key])) {
                        data[key] = [data[key]];
                    }
                    data[key].push(value);
                } else {
                    data[key] = value;
                }
            }

            return data;
        },

        /**
         * Set form field errors
         */
        setErrors(form, errors) {
            // Clear existing errors
            form.querySelectorAll('.ht-field-error').forEach(el => el.remove());
            form.querySelectorAll('.ht-input-error').forEach(el => el.classList.remove('ht-input-error'));

            // Add new errors
            for (const [field, message] of Object.entries(errors)) {
                const input = form.querySelector(`[name="${field}"]`);
                if (input) {
                    input.classList.add('ht-input-error');
                    const errorEl = document.createElement('span');
                    errorEl.className = 'ht-field-error';
                    errorEl.textContent = message;
                    errorEl.style.cssText = 'display: block; color: #ff416c; font-size: 12px; margin-top: 4px;';
                    input.parentNode.appendChild(errorEl);
                }
            }
        },

        /**
         * Clear form errors
         */
        clearErrors(form) {
            form.querySelectorAll('.ht-field-error').forEach(el => el.remove());
            form.querySelectorAll('.ht-input-error').forEach(el => el.classList.remove('ht-input-error'));
        }
    };

    /**
     * Initialize accordion components
     */
    function initAccordions() {
        document.querySelectorAll('.ht-accordion-trigger').forEach(trigger => {
            trigger.addEventListener('click', function() {
                const expanded = this.getAttribute('aria-expanded') === 'true';
                const content = this.nextElementSibling;

                this.setAttribute('aria-expanded', !expanded);
                content.hidden = expanded;
            });
        });
    }

    /**
     * Initialize chip group selection
     */
    function initChipGroups() {
        document.querySelectorAll('.ht-chip-group').forEach(group => {
            const isMulti = group.classList.contains('ht-chip-group-multi');
            const hiddenInput = group.nextElementSibling;
            const chips = group.querySelectorAll('.ht-chip');

            chips.forEach(chip => {
                chip.addEventListener('click', function() {
                    if (isMulti) {
                        // Multi-select: toggle selection
                        this.classList.toggle('ht-chip-selected');

                        // Update hidden input with selected values
                        const selected = Array.from(group.querySelectorAll('.ht-chip-selected'))
                            .map(c => c.dataset.value);
                        if (hiddenInput) {
                            hiddenInput.value = selected.join(',');
                        }
                    } else {
                        // Single select: exclusive selection
                        chips.forEach(c => c.classList.remove('ht-chip-selected'));
                        this.classList.add('ht-chip-selected');

                        if (hiddenInput) {
                            hiddenInput.value = this.dataset.value;
                        }
                    }
                });
            });
        });
    }

    /**
     * Initialize counter inputs
     */
    function initCounters() {
        document.querySelectorAll('.ht-counter').forEach(counter => {
            const input = counter.querySelector('.ht-counter-input');
            const decrementBtn = counter.querySelector('[data-action="decrement"]');
            const incrementBtn = counter.querySelector('[data-action="increment"]');

            decrementBtn?.addEventListener('click', () => {
                const min = parseInt(input.min) || 1;
                const current = parseInt(input.value) || min;
                if (current > min) {
                    input.value = current - 1;
                    input.dispatchEvent(new Event('change'));
                }
            });

            incrementBtn?.addEventListener('click', () => {
                const max = parseInt(input.max) || 50;
                const current = parseInt(input.value) || 1;
                if (current < max) {
                    input.value = current + 1;
                    input.dispatchEvent(new Event('change'));
                }
            });
        });
    }

    /**
     * Initialize example prompt chips
     */
    function initExampleChips() {
        document.querySelectorAll('.ht-example-chip').forEach(chip => {
            chip.addEventListener('click', function() {
                const prompt = this.dataset.prompt;
                const textarea = document.getElementById('quickPrompt');
                if (textarea && prompt) {
                    textarea.value = prompt;
                    textarea.focus();
                }
            });
        });
    }

    /**
     * Initialize delete trip functionality
     */
    function initDeleteTrip() {
        let tripIdToDelete = null;
        const modal = document.getElementById('deleteModal');

        if (!modal) return;

        // Open modal on delete button click
        document.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                tripIdToDelete = this.dataset.tripId;
                modal.style.display = 'flex';
            });
        });

        // Cancel button
        modal.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
            modal.style.display = 'none';
            tripIdToDelete = null;
        });

        // Backdrop click
        modal.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
            modal.style.display = 'none';
            tripIdToDelete = null;
        });

        // Confirm delete
        modal.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            if (!tripIdToDelete) return;

            try {
                await HT.API.delete(`trips_delete.php?id=${tripIdToDelete}`);
                HT.Toast.success('Trip deleted successfully');

                // Remove trip card from DOM
                const card = document.querySelector(`[data-trip-id="${tripIdToDelete}"]`);
                if (card) {
                    card.style.animation = 'htFadeOut 0.3s ease';
                    setTimeout(() => card.remove(), 300);
                }

                modal.style.display = 'none';
                tripIdToDelete = null;
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to delete trip');
            }
        });
    }

    /**
     * Initialize create trip form
     */
    function initCreateTripForm() {
        const form = document.getElementById('createTripForm');
        const quickPrompt = document.getElementById('quickPrompt');
        const saveDraftBtn = document.getElementById('saveDraftBtn');
        const generateBtn = document.getElementById('generatePlanBtn');
        const loadingState = document.getElementById('aiLoadingState');

        if (!form) return;

        // Set minimum dates
        const today = new Date().toISOString().split('T')[0];
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');

        if (startDateInput) startDateInput.min = today;
        if (endDateInput) endDateInput.min = today;

        // Update end date min when start date changes
        startDateInput?.addEventListener('change', function() {
            if (endDateInput) {
                endDateInput.min = this.value;
                if (endDateInput.value && endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            }
        });

        // Save as draft
        saveDraftBtn?.addEventListener('click', async () => {
            try {
                saveDraftBtn.disabled = true;
                saveDraftBtn.textContent = 'Saving...';

                const data = HT.Form.getData(form);
                data.quick_prompt = quickPrompt?.value || '';
                data.status = 'draft';

                const response = await HT.API.post('trips_create.php', data);

                HT.Toast.success('Trip saved as draft');
                window.location.href = `/holiday_traveling/trip_view.php?id=${response.data.id}`;
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to save draft');
            } finally {
                saveDraftBtn.disabled = false;
                saveDraftBtn.textContent = 'Save as Draft';
            }
        });

        // Generate AI plan
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            try {
                HT.Form.clearErrors(form);
                generateBtn.disabled = true;

                // Show loading state
                if (loadingState) {
                    loadingState.style.display = 'flex';
                    animateLoadingSteps();
                }

                const data = HT.Form.getData(form);
                data.quick_prompt = quickPrompt?.value || '';
                data.generate_plan = true;

                const response = await HT.API.post('trips_create.php', data);

                HT.Toast.success('Trip created with AI plan!');
                window.location.href = `/holiday_traveling/trip_view.php?id=${response.data.id}`;
            } catch (error) {
                if (loadingState) {
                    loadingState.style.display = 'none';
                }

                if (error.validation_errors) {
                    HT.Form.setErrors(form, error.validation_errors);
                }

                HT.Toast.error(error.message || 'Failed to create trip');
            } finally {
                generateBtn.disabled = false;
            }
        });
    }

    /**
     * Animate loading steps
     */
    function animateLoadingSteps() {
        const steps = document.querySelectorAll('.ht-loading-step');
        let currentStep = 0;

        const interval = setInterval(() => {
            if (currentStep > 0) {
                steps[currentStep - 1]?.classList.remove('active');
                steps[currentStep - 1]?.classList.add('done');
            }

            if (currentStep < steps.length) {
                steps[currentStep]?.classList.add('active');
                currentStep++;
            } else {
                clearInterval(interval);
            }
        }, 1500);
    }

    /**
     * Initialize all components
     */
    function init() {
        initAccordions();
        initChipGroups();
        initCounters();
        initExampleChips();
        initDeleteTrip();
        initCreateTripForm();

        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes htToastIn {
                from { opacity: 0; transform: translate(-50%, 20px); }
                to { opacity: 1; transform: translate(-50%, 0); }
            }
            @keyframes htToastOut {
                from { opacity: 1; transform: translate(-50%, 0); }
                to { opacity: 0; transform: translate(-50%, -20px); }
            }
            @keyframes htFadeOut {
                from { opacity: 1; transform: scale(1); }
                to { opacity: 0; transform: scale(0.95); }
            }
        `;
        document.head.appendChild(style);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
