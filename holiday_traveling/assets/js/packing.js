/**
 * Holiday Traveling - Packing List JavaScript
 * Full implementation - Phase 8
 */
(function() {
    'use strict';

    window.HT = window.HT || {};

    const CATEGORIES = {
        essentials: { icon: '🎒', label: 'Essentials' },
        clothing: { icon: '👕', label: 'Clothing' },
        toiletries: { icon: '🧴', label: 'Toiletries' },
        electronics: { icon: '📱', label: 'Electronics' },
        documents: { icon: '📄', label: 'Documents' },
        medicine: { icon: '💊', label: 'Medicine' },
        entertainment: { icon: '🎮', label: 'Entertainment' },
        kids: { icon: '👶', label: 'Kids' },
        weather: { icon: '🌦️', label: 'Weather Gear' },
        other: { icon: '📦', label: 'Other' }
    };

    /**
     * Packing List UI Manager
     */
    HT.PackingUI = {
        tripId: null,
        items: [],
        byCategory: {},

        /**
         * Initialize the packing UI
         */
        init(tripId) {
            this.tripId = tripId;
            this.setupEventListeners();
            this.loadItems();
        },

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            const addBtn = document.getElementById('addItemBtn');
            const generateBtn = document.getElementById('generateListBtn');
            const modal = document.getElementById('packingModal');
            const form = document.getElementById('packingForm');

            // Open add modal
            addBtn?.addEventListener('click', () => {
                form.reset();
                document.querySelector('#packingModal .ht-modal-title').textContent = 'Add Item';
                modal.style.display = 'flex';
            });

            // Generate suggestions
            generateBtn?.addEventListener('click', () => this.generateSuggestions());

            // Close modal
            modal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
                modal.style.display = 'none';
            });
            modal?.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
                modal.style.display = 'none';
            });

            // Form submission
            form?.addEventListener('submit', (e) => this.handleFormSubmit(e));
        },

        /**
         * Load packing items from API
         */
        async loadItems() {
            const container = document.getElementById('packingContainer');
            if (!container) return;

            try {
                const response = await fetch(`/holiday_traveling/api/packing_list.php?trip_id=${this.tripId}`);
                const result = await response.json();

                if (result.success) {
                    this.items = result.data.items;
                    this.byCategory = result.data.by_category;
                    this.updateStats(result.data.stats);
                    this.renderItems();
                }
            } catch (error) {
                console.error('Failed to load packing list:', error);
                container.innerHTML = `
                    <div class="ht-error-state">
                        <p>Failed to load packing list. Please refresh the page.</p>
                    </div>
                `;
            }
        },

        /**
         * Update progress stats display
         */
        updateStats(stats) {
            const statsEl = document.getElementById('packingStats');
            if (!statsEl) return;

            statsEl.innerHTML = `
                <div class="ht-packing-progress">
                    <div class="ht-progress-bar">
                        <div class="ht-progress-fill" style="width: ${stats.percent}%"></div>
                    </div>
                    <div class="ht-progress-text">
                        <span class="ht-packed-count">${stats.packed}</span> of
                        <span class="ht-total-count">${stats.total}</span> items packed
                        <span class="ht-percent">(${stats.percent}%)</span>
                    </div>
                </div>
            `;
        },

        /**
         * Render packing items by category
         */
        renderItems() {
            const container = document.getElementById('packingContainer');
            if (!container) return;

            if (this.items.length === 0) {
                container.innerHTML = `
                    <div class="ht-empty-state">
                        <div class="ht-empty-icon">🎒</div>
                        <h3 class="ht-empty-title">No packing items yet</h3>
                        <p class="ht-empty-description">
                            Add items manually or generate a suggested packing list based on your trip.
                        </p>
                        <button class="ht-btn ht-btn-primary" onclick="HT.PackingUI.generateSuggestions()">
                            Generate Suggestions
                        </button>
                    </div>
                `;
                return;
            }

            // Render by category
            let html = '';
            for (const [category, items] of Object.entries(this.byCategory)) {
                const catInfo = CATEGORIES[category] || { icon: '📦', label: category };
                const packedCount = items.filter(i => i.is_packed).length;

                html += `
                    <div class="ht-packing-category" data-category="${category}">
                        <div class="ht-category-header">
                            <span class="ht-category-icon">${catInfo.icon}</span>
                            <span class="ht-category-name">${catInfo.label}</span>
                            <span class="ht-category-count">${packedCount}/${items.length}</span>
                        </div>
                        <div class="ht-packing-items">
                            ${items.map(item => this.renderItem(item)).join('')}
                        </div>
                    </div>
                `;
            }

            container.innerHTML = html;

            // Setup item event listeners
            container.querySelectorAll('.ht-packing-item').forEach(el => {
                const itemId = el.dataset.itemId;

                // Toggle packed status
                el.querySelector('.ht-pack-checkbox')?.addEventListener('change', () => {
                    this.toggleItem(itemId);
                });

                // Delete button
                el.querySelector('.ht-delete-item-btn')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.deleteItem(itemId);
                });
            });
        },

        /**
         * Render a single packing item
         */
        renderItem(item) {
            return `
                <div class="ht-packing-item ${item.is_packed ? 'packed' : ''}" data-item-id="${item.id}">
                    <label class="ht-pack-checkbox-label">
                        <input type="checkbox" class="ht-pack-checkbox" ${item.is_packed ? 'checked' : ''}>
                        <span class="ht-pack-checkmark"></span>
                    </label>
                    <span class="ht-item-name">${this.escapeHtml(item.name)}</span>
                    ${item.quantity > 1 ? `<span class="ht-item-qty">×${item.quantity}</span>` : ''}
                    <button class="ht-delete-item-btn" title="Remove">×</button>
                </div>
            `;
        },

        /**
         * Handle add item form submission
         */
        async handleFormSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');

            const data = {
                trip_id: this.tripId,
                category: formData.get('category'),
                item_name: formData.get('item_name'),
                quantity: parseInt(formData.get('quantity')) || 1
            };

            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding...';

            try {
                const response = await fetch('/holiday_traveling/api/packing_add.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Item added!');
                    document.getElementById('packingModal').style.display = 'none';
                    this.loadItems();
                } else {
                    HT.Toast.error(result.error || 'Failed to add item');
                }
            } catch (error) {
                console.error('Error adding item:', error);
                HT.Toast.error('Network error. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Item';
            }
        },

        /**
         * Toggle item packed status
         */
        async toggleItem(itemId) {
            try {
                const response = await fetch('/holiday_traveling/api/packing_toggle.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({ item_id: parseInt(itemId) })
                });

                const result = await response.json();

                if (result.success) {
                    // Update UI without full reload
                    const itemEl = document.querySelector(`.ht-packing-item[data-item-id="${itemId}"]`);
                    if (itemEl) {
                        itemEl.classList.toggle('packed', result.data.is_packed);
                    }
                    this.loadItems(); // Reload to update counts
                } else {
                    HT.Toast.error(result.error || 'Failed to update item');
                }
            } catch (error) {
                console.error('Error toggling item:', error);
                HT.Toast.error('Network error. Please try again.');
            }
        },

        /**
         * Delete a packing item
         */
        async deleteItem(itemId) {
            if (!confirm('Remove this item from your packing list?')) {
                return;
            }

            try {
                const response = await fetch('/holiday_traveling/api/packing_delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({ item_id: parseInt(itemId) })
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Item removed!');
                    this.loadItems();
                } else {
                    HT.Toast.error(result.error || 'Failed to delete item');
                }
            } catch (error) {
                console.error('Error deleting item:', error);
                HT.Toast.error('Network error. Please try again.');
            }
        },

        /**
         * Generate packing suggestions
         */
        async generateSuggestions() {
            const btn = document.getElementById('generateListBtn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Generating...';
            }

            try {
                const response = await fetch('/holiday_traveling/api/packing_generate.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({ trip_id: this.tripId })
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success(result.data.message);
                    this.loadItems();
                } else {
                    HT.Toast.error(result.error || 'Failed to generate suggestions');
                }
            } catch (error) {
                console.error('Error generating suggestions:', error);
                HT.Toast.error('Network error. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Generate Suggestions';
                }
            }
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

})();
