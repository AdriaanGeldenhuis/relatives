/**
 * Holiday Traveling - Expenses JavaScript
 * Full implementation - Phase 6
 */
(function() {
    'use strict';

    window.HT = window.HT || {};

    const CACHE_KEY = 'ht_expenses_';
    const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

    /**
     * Expenses UI Manager
     */
    HT.ExpensesUI = {
        tripId: null,
        currency: 'USD',
        members: [],
        editingExpenseId: null,

        /**
         * Initialize the expenses UI
         */
        init(tripId, currency = 'USD') {
            this.tripId = tripId;
            this.currency = currency;
            this.setupEventListeners();
            this.loadMembers();
        },

        /**
         * Load trip members for split selection
         */
        async loadMembers() {
            try {
                const response = await fetch(`/holiday_traveling/api/trip_members.php?trip_id=${this.tripId}`);
                const result = await response.json();
                if (result.success && result.data?.members) {
                    this.members = result.data.members;
                    this.populateMemberSelects();
                }
            } catch (error) {
                console.error('Failed to load members:', error);
            }
        },

        /**
         * Populate member select dropdowns
         */
        populateMemberSelects() {
            const paidBySelect = document.querySelector('#expenseForm select[name="paid_by"]');
            const splitWithContainer = document.getElementById('splitWithContainer');

            if (paidBySelect && this.members.length > 0) {
                paidBySelect.innerHTML = this.members.map(m =>
                    `<option value="${m.id}">${this.escapeHtml(m.name)}</option>`
                ).join('');
            }

            if (splitWithContainer && this.members.length > 0) {
                splitWithContainer.innerHTML = this.members.map(m => `
                    <label class="ht-checkbox-label ht-split-member">
                        <input type="checkbox" name="split_with[]" value="${m.id}" checked>
                        <span>${this.escapeHtml(m.name)}</span>
                    </label>
                `).join('');
            }
        },

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            const addBtn = document.getElementById('addExpenseBtn');
            const modal = document.getElementById('expenseModal');
            const form = document.getElementById('expenseForm');
            const calculateBtn = document.getElementById('calculateSplitBtn');
            const settlementModal = document.getElementById('settlementModal');

            // Open add modal
            addBtn?.addEventListener('click', () => {
                this.editingExpenseId = null;
                this.resetForm();
                document.querySelector('#expenseModal .ht-modal-title').textContent = 'Add Expense';
                modal.style.display = 'flex';
            });

            // Close modals
            modal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
                modal.style.display = 'none';
            });
            modal?.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
                modal.style.display = 'none';
            });

            settlementModal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
                settlementModal.style.display = 'none';
            });
            settlementModal?.querySelector('[data-action="close"]')?.addEventListener('click', () => {
                settlementModal.style.display = 'none';
            });

            // Form submission
            form?.addEventListener('submit', (e) => this.handleFormSubmit(e));

            // Calculate settlement
            calculateBtn?.addEventListener('click', () => this.calculateSettlement());

            // Expense item click for edit/delete
            document.querySelectorAll('.ht-expense-item').forEach(item => {
                item.addEventListener('click', () => this.showExpenseOptions(item.dataset.expenseId));
            });

            // Split type toggle
            document.querySelectorAll('input[name="split_type"]')?.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    const customSplit = document.getElementById('customSplitSection');
                    if (customSplit) {
                        customSplit.style.display = e.target.value === 'custom' ? 'block' : 'none';
                    }
                });
            });
        },

        /**
         * Reset the expense form
         */
        resetForm() {
            const form = document.getElementById('expenseForm');
            if (form) {
                form.reset();
                form.querySelector('input[name="expense_date"]').value = new Date().toISOString().split('T')[0];
                // Check all split members by default
                form.querySelectorAll('input[name="split_with[]"]').forEach(cb => cb.checked = true);
                // Set split type to everyone
                const everyoneRadio = form.querySelector('input[name="split_type"][value="everyone"]');
                if (everyoneRadio) everyoneRadio.checked = true;
                const customSection = document.getElementById('customSplitSection');
                if (customSection) customSection.style.display = 'none';
            }
        },

        /**
         * Handle form submission
         */
        async handleFormSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');

            // Get split_with array
            const splitType = formData.get('split_type');
            let splitWith = null;
            if (splitType === 'custom') {
                splitWith = Array.from(form.querySelectorAll('input[name="split_with[]"]:checked'))
                    .map(cb => parseInt(cb.value));
            }

            const data = {
                trip_id: this.tripId,
                category: formData.get('category'),
                description: formData.get('description'),
                amount: parseFloat(formData.get('amount')),
                currency: this.currency,
                expense_date: formData.get('expense_date'),
                paid_by: parseInt(formData.get('paid_by')),
                split_with: splitWith,
                notes: formData.get('notes') || null
            };

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            try {
                const url = this.editingExpenseId
                    ? '/holiday_traveling/api/expenses_update.php'
                    : '/holiday_traveling/api/expenses_add.php';

                if (this.editingExpenseId) {
                    data.expense_id = this.editingExpenseId;
                }

                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success(this.editingExpenseId ? 'Expense updated!' : 'Expense added!');
                    document.getElementById('expenseModal').style.display = 'none';
                    this.clearCache();
                    // Reload page to show updated expenses
                    window.location.reload();
                } else {
                    HT.Toast.error(result.error || 'Failed to save expense');
                }
            } catch (error) {
                console.error('Error saving expense:', error);
                HT.Toast.error('Network error. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = this.editingExpenseId ? 'Update Expense' : 'Add Expense';
            }
        },

        /**
         * Show expense options (edit/delete)
         */
        async showExpenseOptions(expenseId) {
            const action = await this.showActionDialog([
                { id: 'edit', label: 'Edit Expense', icon: '✏️' },
                { id: 'delete', label: 'Delete Expense', icon: '🗑️', danger: true }
            ]);

            if (action === 'edit') {
                this.editExpense(expenseId);
            } else if (action === 'delete') {
                this.deleteExpense(expenseId);
            }
        },

        /**
         * Show action dialog
         */
        showActionDialog(actions) {
            return new Promise((resolve) => {
                const dialog = document.createElement('div');
                dialog.className = 'ht-action-dialog';
                dialog.innerHTML = `
                    <div class="ht-action-backdrop"></div>
                    <div class="ht-action-content">
                        ${actions.map(a => `
                            <button class="ht-action-btn ${a.danger ? 'danger' : ''}" data-action="${a.id}">
                                <span class="ht-action-icon">${a.icon}</span>
                                ${a.label}
                            </button>
                        `).join('')}
                        <button class="ht-action-btn cancel" data-action="cancel">Cancel</button>
                    </div>
                `;
                document.body.appendChild(dialog);

                const handleClick = (e) => {
                    const btn = e.target.closest('[data-action]');
                    if (btn) {
                        dialog.remove();
                        resolve(btn.dataset.action === 'cancel' ? null : btn.dataset.action);
                    }
                };

                dialog.addEventListener('click', handleClick);
                dialog.querySelector('.ht-action-backdrop').addEventListener('click', () => {
                    dialog.remove();
                    resolve(null);
                });
            });
        },

        /**
         * Edit expense
         */
        async editExpense(expenseId) {
            try {
                const response = await fetch(`/holiday_traveling/api/expenses_list.php?trip_id=${this.tripId}`);
                const result = await response.json();

                if (result.success) {
                    const expense = result.data.expenses.find(e => e.id == expenseId);
                    if (expense) {
                        this.editingExpenseId = expenseId;
                        this.populateFormWithExpense(expense);
                        document.querySelector('#expenseModal .ht-modal-title').textContent = 'Edit Expense';
                        document.getElementById('expenseModal').style.display = 'flex';
                    }
                }
            } catch (error) {
                console.error('Error loading expense:', error);
                HT.Toast.error('Failed to load expense');
            }
        },

        /**
         * Populate form with expense data
         */
        populateFormWithExpense(expense) {
            const form = document.getElementById('expenseForm');
            if (!form) return;

            form.querySelector('[name="category"]').value = expense.category;
            form.querySelector('[name="description"]').value = expense.description;
            form.querySelector('[name="amount"]').value = expense.amount;
            form.querySelector('[name="expense_date"]').value = expense.expense_date;
            form.querySelector('[name="paid_by"]').value = expense.paid_by;
            form.querySelector('[name="notes"]').value = expense.notes || '';

            // Handle split_with
            const splitWith = expense.split_with_json ? JSON.parse(expense.split_with_json) : null;
            if (splitWith && splitWith.length > 0) {
                form.querySelector('input[name="split_type"][value="custom"]').checked = true;
                document.getElementById('customSplitSection').style.display = 'block';
                form.querySelectorAll('input[name="split_with[]"]').forEach(cb => {
                    cb.checked = splitWith.includes(parseInt(cb.value));
                });
            } else {
                form.querySelector('input[name="split_type"][value="everyone"]').checked = true;
                document.getElementById('customSplitSection').style.display = 'none';
            }

            form.querySelector('button[type="submit"]').textContent = 'Update Expense';
        },

        /**
         * Delete expense
         */
        async deleteExpense(expenseId) {
            if (!confirm('Are you sure you want to delete this expense?')) {
                return;
            }

            try {
                const response = await fetch('/holiday_traveling/api/expenses_delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({ expense_id: parseInt(expenseId) })
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Expense deleted!');
                    this.clearCache();
                    window.location.reload();
                } else {
                    HT.Toast.error(result.error || 'Failed to delete expense');
                }
            } catch (error) {
                console.error('Error deleting expense:', error);
                HT.Toast.error('Network error. Please try again.');
            }
        },

        /**
         * Calculate and show settlement
         */
        async calculateSettlement() {
            const btn = document.getElementById('calculateSplitBtn');
            btn.disabled = true;
            btn.textContent = 'Calculating...';

            try {
                const response = await fetch(`/holiday_traveling/api/expenses_settlement.php?trip_id=${this.tripId}`);
                const result = await response.json();

                if (result.success) {
                    this.showSettlementModal(result.data);
                } else {
                    HT.Toast.error(result.error || 'Failed to calculate settlement');
                }
            } catch (error) {
                console.error('Error calculating settlement:', error);
                HT.Toast.error('Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Calculate Settlement';
            }
        },

        /**
         * Show settlement modal with results
         */
        showSettlementModal(data) {
            const modal = document.getElementById('settlementModal');
            const content = document.getElementById('settlementContent');

            if (!modal || !content) {
                // Create modal if it doesn't exist
                this.createSettlementModal(data);
                return;
            }

            content.innerHTML = this.renderSettlementContent(data);
            modal.style.display = 'flex';
        },

        /**
         * Create settlement modal dynamically
         */
        createSettlementModal(data) {
            const modal = document.createElement('div');
            modal.id = 'settlementModal';
            modal.className = 'ht-modal';
            modal.innerHTML = `
                <div class="ht-modal-backdrop"></div>
                <div class="ht-modal-content ht-modal-lg">
                    <div class="ht-modal-header">
                        <h3 class="ht-modal-title">Settlement Summary</h3>
                        <button class="ht-modal-close" data-action="close">&times;</button>
                    </div>
                    <div class="ht-modal-body" id="settlementContent">
                        ${this.renderSettlementContent(data)}
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            modal.querySelector('.ht-modal-backdrop').addEventListener('click', () => {
                modal.style.display = 'none';
            });
            modal.querySelector('[data-action="close"]').addEventListener('click', () => {
                modal.style.display = 'none';
            });

            modal.style.display = 'flex';
        },

        /**
         * Render settlement content HTML
         */
        renderSettlementContent(data) {
            const { currency, total, per_person, member_count, members, settlements } = data;

            let html = `
                <div class="ht-settlement-summary">
                    <div class="ht-settlement-stat">
                        <span class="ht-settlement-stat-label">Total Expenses</span>
                        <span class="ht-settlement-stat-value">${this.formatCurrency(total, currency)}</span>
                    </div>
                    <div class="ht-settlement-stat">
                        <span class="ht-settlement-stat-label">Per Person</span>
                        <span class="ht-settlement-stat-value">${this.formatCurrency(per_person, currency)}</span>
                    </div>
                    <div class="ht-settlement-stat">
                        <span class="ht-settlement-stat-label">Members</span>
                        <span class="ht-settlement-stat-value">${member_count}</span>
                    </div>
                </div>
            `;

            // Member balances
            if (members && members.length > 0) {
                html += `
                    <div class="ht-settlement-section">
                        <h4 class="ht-settlement-section-title">Individual Balances</h4>
                        <div class="ht-member-balances">
                            ${members.map(m => `
                                <div class="ht-member-balance ${m.balance > 0 ? 'positive' : (m.balance < 0 ? 'negative' : '')}">
                                    <span class="ht-member-name">${this.escapeHtml(m.name)}</span>
                                    <div class="ht-member-details">
                                        <span class="ht-member-paid">Paid: ${this.formatCurrency(m.paid, currency)}</span>
                                        <span class="ht-member-share">Share: ${this.formatCurrency(m.share, currency)}</span>
                                    </div>
                                    <span class="ht-member-balance-amount">
                                        ${m.balance > 0 ? '+' : ''}${this.formatCurrency(m.balance, currency)}
                                    </span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            // Settlements (who pays whom)
            if (settlements && settlements.length > 0) {
                html += `
                    <div class="ht-settlement-section">
                        <h4 class="ht-settlement-section-title">Payments to Settle</h4>
                        <div class="ht-settlements-list">
                            ${settlements.map(s => `
                                <div class="ht-settlement-item">
                                    <span class="ht-settlement-from">${this.escapeHtml(s.from_name)}</span>
                                    <span class="ht-settlement-arrow">→</span>
                                    <span class="ht-settlement-to">${this.escapeHtml(s.to_name)}</span>
                                    <span class="ht-settlement-amount">${this.formatCurrency(s.amount, currency)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else if (total > 0) {
                html += `
                    <div class="ht-settlement-section">
                        <div class="ht-settlement-balanced">
                            <span class="ht-balanced-icon">✓</span>
                            <span>All settled! No payments needed.</span>
                        </div>
                    </div>
                `;
            }

            return html;
        },

        /**
         * Format currency
         */
        formatCurrency(amount, currency = 'USD') {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency
            }).format(amount);
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Clear cached data
         */
        clearCache() {
            const keys = Object.keys(localStorage).filter(k => k.startsWith(CACHE_KEY));
            keys.forEach(k => localStorage.removeItem(k));
        }
    };

    /**
     * Legacy Expenses namespace for backwards compatibility
     */
    HT.Expenses = {
        calculateSettlement(expenses, members) {
            // Use server-side calculation instead
            return {
                total: 0,
                perPerson: 0,
                settlements: []
            };
        }
    };

})();
