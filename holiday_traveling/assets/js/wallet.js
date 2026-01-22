/**
 * Holiday Traveling - Wallet JavaScript
 * Full implementation with offline support via localStorage
 */
(function() {
    'use strict';

    window.HT = window.HT || {};

    /**
     * Wallet Manager - handles CRUD and offline caching
     */
    HT.Wallet = {
        cacheKey: 'ht_wallet_cache',
        pendingKey: 'ht_wallet_pending',
        tripId: null,

        /**
         * Initialize wallet for a trip
         */
        init(tripId) {
            this.tripId = tripId;
            this.syncFromServer();
            this.setupOfflineDetection();
            this.processPendingChanges();
        },

        /**
         * Sync items from server to local cache
         */
        async syncFromServer() {
            if (!navigator.onLine) {
                this.updateSyncStatus('offline');
                return this.getCachedItems();
            }

            try {
                const response = await HT.API.get(`wallet_list.php?trip_id=${this.tripId}`);
                const items = response.data || [];

                this.saveToCache(items);
                this.updateSyncStatus('synced');

                return items;
            } catch (error) {
                console.error('Wallet sync failed:', error);
                this.updateSyncStatus('error');
                return this.getCachedItems();
            }
        },

        /**
         * Save items to local cache
         */
        saveToCache(items) {
            const cache = this.getFullCache();
            cache[this.tripId] = {
                items: items,
                synced_at: new Date().toISOString()
            };
            localStorage.setItem(this.cacheKey, JSON.stringify(cache));
        },

        /**
         * Get full cache object
         */
        getFullCache() {
            try {
                return JSON.parse(localStorage.getItem(this.cacheKey) || '{}');
            } catch {
                return {};
            }
        },

        /**
         * Get cached items for current trip
         */
        getCachedItems() {
            const cache = this.getFullCache();
            return cache[this.tripId]?.items || [];
        },

        /**
         * Get last sync time
         */
        getLastSyncTime() {
            const cache = this.getFullCache();
            const syncedAt = cache[this.tripId]?.synced_at;
            if (!syncedAt) return 'Never';

            const date = new Date(syncedAt);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);

            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return `${diffMins} min ago`;
            if (diffMins < 1440) return `${Math.floor(diffMins / 60)} hours ago`;
            return date.toLocaleDateString();
        },

        /**
         * Update sync status display
         */
        updateSyncStatus(status) {
            const timeEl = document.getElementById('lastSyncTime');
            const noticeEl = document.querySelector('.ht-offline-notice');

            if (timeEl) {
                timeEl.textContent = this.getLastSyncTime();
            }

            if (noticeEl) {
                noticeEl.classList.remove('ht-status-offline', 'ht-status-synced', 'ht-status-error');
                noticeEl.classList.add(`ht-status-${status}`);
            }
        },

        /**
         * Setup offline detection
         */
        setupOfflineDetection() {
            window.addEventListener('online', () => {
                this.updateSyncStatus('syncing');
                this.processPendingChanges();
                this.syncFromServer();
            });

            window.addEventListener('offline', () => {
                this.updateSyncStatus('offline');
            });
        },

        /**
         * Add a wallet item
         */
        async addItem(data) {
            data.trip_id = this.tripId;

            if (!navigator.onLine) {
                return this.queuePendingChange('add', data);
            }

            try {
                const response = await HT.API.post('wallet_add.php', data);
                await this.syncFromServer();
                return response.data;
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to add item');
                throw error;
            }
        },

        /**
         * Update a wallet item
         */
        async updateItem(itemId, data) {
            data.item_id = itemId;

            if (!navigator.onLine) {
                return this.queuePendingChange('update', data);
            }

            try {
                const response = await HT.API.post('wallet_update.php', data);
                await this.syncFromServer();
                return response.data;
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to update item');
                throw error;
            }
        },

        /**
         * Delete a wallet item
         */
        async deleteItem(itemId) {
            if (!navigator.onLine) {
                return this.queuePendingChange('delete', { item_id: itemId });
            }

            try {
                const response = await HT.API.post('wallet_delete.php', { item_id: itemId });
                await this.syncFromServer();
                return response.data;
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to delete item');
                throw error;
            }
        },

        /**
         * Reorder wallet items
         */
        async reorderItems(order) {
            if (!navigator.onLine) {
                return this.queuePendingChange('reorder', { trip_id: this.tripId, order });
            }

            try {
                const response = await HT.API.post('wallet_reorder.php', {
                    trip_id: this.tripId,
                    order: order
                });
                await this.syncFromServer();
                return response.data;
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to reorder items');
                throw error;
            }
        },

        /**
         * Queue a pending change for offline support
         */
        queuePendingChange(action, data) {
            const pending = this.getPendingChanges();
            pending.push({
                action,
                data,
                timestamp: Date.now()
            });
            localStorage.setItem(this.pendingKey, JSON.stringify(pending));

            HT.Toast.info('Saved offline. Will sync when online.');

            // Optimistically update local cache
            this.updateLocalCache(action, data);

            return { offline: true };
        },

        /**
         * Get pending changes
         */
        getPendingChanges() {
            try {
                return JSON.parse(localStorage.getItem(this.pendingKey) || '[]');
            } catch {
                return [];
            }
        },

        /**
         * Process pending changes when back online
         */
        async processPendingChanges() {
            if (!navigator.onLine) return;

            const pending = this.getPendingChanges();
            if (pending.length === 0) return;

            let processed = 0;

            for (const change of pending) {
                try {
                    switch (change.action) {
                        case 'add':
                            await HT.API.post('wallet_add.php', change.data);
                            break;
                        case 'update':
                            await HT.API.post('wallet_update.php', change.data);
                            break;
                        case 'delete':
                            await HT.API.post('wallet_delete.php', change.data);
                            break;
                        case 'reorder':
                            await HT.API.post('wallet_reorder.php', change.data);
                            break;
                    }
                    processed++;
                } catch (error) {
                    console.error('Failed to process pending change:', error);
                }
            }

            // Clear pending queue
            localStorage.removeItem(this.pendingKey);

            if (processed > 0) {
                HT.Toast.success(`Synced ${processed} offline change${processed > 1 ? 's' : ''}`);
            }
        },

        /**
         * Update local cache optimistically
         */
        updateLocalCache(action, data) {
            const items = this.getCachedItems();

            switch (action) {
                case 'add':
                    items.push({
                        id: `temp_${Date.now()}`,
                        ...data,
                        _pending: true
                    });
                    break;
                case 'update':
                    const updateIdx = items.findIndex(i => i.id === data.item_id);
                    if (updateIdx !== -1) {
                        items[updateIdx] = { ...items[updateIdx], ...data, _pending: true };
                    }
                    break;
                case 'delete':
                    const deleteIdx = items.findIndex(i => i.id === data.item_id);
                    if (deleteIdx !== -1) {
                        items.splice(deleteIdx, 1);
                    }
                    break;
            }

            this.saveToCache(items);
        }
    };

    /**
     * Wallet UI Controller
     */
    HT.WalletUI = {
        modal: null,
        viewModal: null,
        currentItem: null,

        /**
         * Initialize UI
         */
        init(tripId) {
            HT.Wallet.init(tripId);

            this.modal = document.getElementById('walletItemModal');
            this.viewModal = document.getElementById('walletViewModal');

            this.bindEvents();
            this.updateLastSyncDisplay();
        },

        /**
         * Bind event handlers
         */
        bindEvents() {
            // Add button
            document.getElementById('addWalletItemBtn')?.addEventListener('click', () => {
                this.openAddModal();
            });

            // Form submission
            document.getElementById('walletItemForm')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleFormSubmit(e.target);
            });

            // Modal close handlers
            this.modal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
                this.closeModal();
            });
            this.modal?.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
                this.closeModal();
            });

            // View modal close handlers
            this.viewModal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
                this.closeViewModal();
            });
            this.viewModal?.querySelector('[data-action="close"]')?.addEventListener('click', () => {
                this.closeViewModal();
            });

            // View buttons
            document.querySelectorAll('.ht-wallet-view-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const itemId = e.target.dataset.itemId;
                    this.viewItem(itemId);
                });
            });

            // Card clicks
            document.querySelectorAll('.ht-wallet-card').forEach(card => {
                card.addEventListener('click', (e) => {
                    if (!e.target.closest('.ht-wallet-menu-btn')) {
                        const itemId = card.dataset.itemId;
                        this.viewItem(itemId);
                    }
                });
            });

            // Menu buttons
            document.querySelectorAll('.ht-wallet-menu-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const itemId = btn.dataset.itemId;
                    this.showItemMenu(btn, itemId);
                });
            });

            // Item row clicks
            document.querySelectorAll('.ht-wallet-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    if (!e.target.closest('button')) {
                        const itemId = item.dataset.itemId;
                        this.viewItem(itemId);
                    }
                });
            });
        },

        /**
         * Open add modal
         */
        openAddModal() {
            this.currentItem = null;
            const form = document.getElementById('walletItemForm');
            form?.reset();

            const title = this.modal?.querySelector('.ht-modal-title');
            if (title) title.textContent = 'Add Wallet Item';

            this.modal.style.display = 'flex';
        },

        /**
         * Open edit modal
         */
        openEditModal(item) {
            this.currentItem = item;
            const form = document.getElementById('walletItemForm');

            if (form) {
                form.querySelector('[name="type"]').value = item.type;
                form.querySelector('[name="label"]').value = item.label;
                form.querySelector('[name="content"]').value = item.content || '';
                form.querySelector('[name="is_essential"]').checked = !!item.is_essential;
            }

            const title = this.modal?.querySelector('.ht-modal-title');
            if (title) title.textContent = 'Edit Wallet Item';

            this.modal.style.display = 'flex';
        },

        /**
         * Close modal
         */
        closeModal() {
            if (this.modal) {
                this.modal.style.display = 'none';
            }
            this.currentItem = null;
        },

        /**
         * Close view modal
         */
        closeViewModal() {
            if (this.viewModal) {
                this.viewModal.style.display = 'none';
            }
        },

        /**
         * Handle form submission
         */
        async handleFormSubmit(form) {
            const formData = new FormData(form);
            const data = {
                type: formData.get('type'),
                label: formData.get('label'),
                content: formData.get('content') || null,
                is_essential: formData.get('is_essential') ? 1 : 0
            };

            const submitBtn = form.querySelector('[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            try {
                if (this.currentItem) {
                    await HT.Wallet.updateItem(this.currentItem.id, data);
                    HT.Toast.success('Item updated');
                } else {
                    await HT.Wallet.addItem(data);
                    HT.Toast.success('Item added');
                }

                this.closeModal();
                window.location.reload();
            } catch (error) {
                // Error already shown by Wallet
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Item';
            }
        },

        /**
         * View item details
         */
        async viewItem(itemId) {
            const items = HT.Wallet.getCachedItems();
            const item = items.find(i => i.id == itemId);

            if (!item) {
                HT.Toast.error('Item not found');
                return;
            }

            const content = document.getElementById('walletViewContent');
            if (!content) return;

            const typeLabels = {
                ticket: '🎫 Ticket', booking: '🏨 Booking', doc: '📄 Document',
                note: '📝 Note', qr: '📱 QR Code', link: '🔗 Link',
                contact: '👤 Contact', insurance: '🛡️ Insurance', visa: '🛂 Visa'
            };

            let html = `
                <div class="ht-wallet-view-header">
                    <span class="ht-wallet-view-type">${typeLabels[item.type] || item.type}</span>
                    ${item.is_essential ? '<span class="ht-essential-badge">⭐ Essential</span>' : ''}
                </div>
                <h3 class="ht-wallet-view-title">${this.escapeHtml(item.label)}</h3>
            `;

            if (item.content) {
                // Check if content looks like a URL
                if (item.type === 'link' && item.content.match(/^https?:\/\//)) {
                    html += `<p><a href="${this.escapeHtml(item.content)}" target="_blank" class="ht-wallet-link">${this.escapeHtml(item.content)}</a></p>`;
                } else if (item.type === 'qr') {
                    // For QR codes, show as text (real implementation would show actual QR image)
                    html += `<div class="ht-wallet-qr-content"><code>${this.escapeHtml(item.content)}</code></div>`;
                } else {
                    html += `<div class="ht-wallet-content-text">${this.escapeHtml(item.content).replace(/\n/g, '<br>')}</div>`;
                }
            }

            html += `
                <div class="ht-wallet-view-actions">
                    <button class="ht-btn ht-btn-secondary ht-btn-sm" onclick="HT.WalletUI.editFromView(${item.id})">
                        <span class="ht-btn-icon">✏️</span> Edit
                    </button>
                    <button class="ht-btn ht-btn-outline ht-btn-sm ht-btn-danger" onclick="HT.WalletUI.deleteFromView(${item.id})">
                        <span class="ht-btn-icon">🗑️</span> Delete
                    </button>
                </div>
            `;

            content.innerHTML = html;
            this.viewModal.style.display = 'flex';
        },

        /**
         * Edit from view modal
         */
        editFromView(itemId) {
            const items = HT.Wallet.getCachedItems();
            const item = items.find(i => i.id == itemId);

            if (item) {
                this.closeViewModal();
                this.openEditModal(item);
            }
        },

        /**
         * Delete from view modal
         */
        async deleteFromView(itemId) {
            if (!confirm('Delete this wallet item?')) return;

            try {
                await HT.Wallet.deleteItem(itemId);
                HT.Toast.success('Item deleted');
                this.closeViewModal();
                window.location.reload();
            } catch (error) {
                // Error already shown
            }
        },

        /**
         * Show item menu (for card view)
         */
        showItemMenu(btn, itemId) {
            // Simple dropdown menu
            const existing = document.querySelector('.ht-wallet-dropdown');
            if (existing) existing.remove();

            const items = HT.Wallet.getCachedItems();
            const item = items.find(i => i.id == itemId);

            const menu = document.createElement('div');
            menu.className = 'ht-wallet-dropdown';
            menu.innerHTML = `
                <button class="ht-wallet-dropdown-item" data-action="view">View</button>
                <button class="ht-wallet-dropdown-item" data-action="edit">Edit</button>
                <button class="ht-wallet-dropdown-item" data-action="toggle-essential">
                    ${item?.is_essential ? 'Remove from essentials' : 'Mark as essential'}
                </button>
                <button class="ht-wallet-dropdown-item ht-text-danger" data-action="delete">Delete</button>
            `;

            const rect = btn.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = `${rect.bottom + 4}px`;
            menu.style.right = `${window.innerWidth - rect.right}px`;

            document.body.appendChild(menu);

            // Handle menu clicks
            menu.addEventListener('click', async (e) => {
                const action = e.target.dataset.action;
                menu.remove();

                switch (action) {
                    case 'view':
                        this.viewItem(itemId);
                        break;
                    case 'edit':
                        if (item) this.openEditModal(item);
                        break;
                    case 'toggle-essential':
                        await HT.Wallet.updateItem(itemId, { is_essential: item?.is_essential ? 0 : 1 });
                        window.location.reload();
                        break;
                    case 'delete':
                        if (confirm('Delete this item?')) {
                            await HT.Wallet.deleteItem(itemId);
                            window.location.reload();
                        }
                        break;
                }
            });

            // Close on click outside
            setTimeout(() => {
                document.addEventListener('click', function closeMenu(e) {
                    if (!menu.contains(e.target)) {
                        menu.remove();
                        document.removeEventListener('click', closeMenu);
                    }
                });
            }, 0);
        },

        /**
         * Update last sync display
         */
        updateLastSyncDisplay() {
            const timeEl = document.getElementById('lastSyncTime');
            if (timeEl) {
                timeEl.textContent = HT.Wallet.getLastSyncTime();
            }
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    };

})();
