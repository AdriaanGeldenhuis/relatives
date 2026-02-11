<?php
/**
 * Holiday Traveling - Expenses View
 * Full implementation - Phase 6
 */
$categories = [
    'food' => ['icon' => '🍽️', 'label' => 'Food & Drinks'],
    'fuel' => ['icon' => '⛽', 'label' => 'Fuel'],
    'transport' => ['icon' => '🚗', 'label' => 'Transport'],
    'stay' => ['icon' => '🏨', 'label' => 'Accommodation'],
    'activity' => ['icon' => '🎯', 'label' => 'Activities'],
    'shopping' => ['icon' => '🛍️', 'label' => 'Shopping'],
    'tips' => ['icon' => '💵', 'label' => 'Tips'],
    'other' => ['icon' => '📦', 'label' => 'Other']
];
?>

<div class="ht-expenses-page">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-back-link">← Back to Trip</a>
        <div class="ht-page-header-content">
            <h1 class="ht-page-title">
                <span class="ht-title-icon">🧾</span>
                Expense Tracking
            </h1>
            <p class="ht-page-subtitle"><?php echo htmlspecialchars($trip['destination']); ?></p>
        </div>
        <?php if ($canEdit): ?>
        <button id="addExpenseBtn" class="ht-btn ht-btn-primary">
            <span class="ht-btn-icon">+</span>
            Add Expense
        </button>
        <?php endif; ?>
    </div>

    <!-- Summary Card -->
    <div class="ht-expense-summary">
        <div class="ht-expense-total-card">
            <span class="ht-expense-total-label">Total Spent</span>
            <span class="ht-expense-total-value"><?php echo ht_format_currency($grandTotal, $trip['budget_currency']); ?></span>
            <span class="ht-expense-count"><?php echo count($expenses); ?> expense<?php echo count($expenses) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if ($trip['budget_comfort']): ?>
        <div class="ht-budget-progress">
            <div class="ht-budget-bar">
                <?php $percent = min(100, ($grandTotal / $trip['budget_comfort']) * 100); ?>
                <div class="ht-budget-fill <?php echo $percent > 100 ? 'over-budget' : ($percent > 80 ? 'warning' : ''); ?>" style="width: <?php echo $percent; ?>%"></div>
            </div>
            <div class="ht-budget-labels">
                <span><?php echo round($percent); ?>% of budget</span>
                <span>Budget: <?php echo ht_format_currency($trip['budget_comfort'], $trip['budget_currency']); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Category Breakdown -->
    <?php if (!empty($categoryTotals)): ?>
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">📊</span>
            By Category
        </h3>
        <div class="ht-category-grid">
            <?php foreach ($categoryTotals as $cat => $total): ?>
            <div class="ht-category-card">
                <span class="ht-category-icon"><?php echo $categories[$cat]['icon'] ?? '📦'; ?></span>
                <span class="ht-category-amount"><?php echo ht_format_currency($total, $trip['budget_currency']); ?></span>
                <span class="ht-category-label"><?php echo $categories[$cat]['label'] ?? ucfirst($cat); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Expenses List -->
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">📋</span>
            All Expenses
        </h3>

        <?php if (empty($expenses)): ?>
        <div class="ht-empty-state">
            <div class="ht-empty-icon">🧾</div>
            <h3 class="ht-empty-title">No expenses yet</h3>
            <p class="ht-empty-description">
                Track your spending to stay on budget and easily split costs later.
            </p>
            <?php if ($canEdit): ?>
            <button class="ht-btn ht-btn-primary" onclick="document.getElementById('addExpenseBtn').click()">
                Add Your First Expense
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="ht-expense-list">
            <?php
            $currentDate = '';
            foreach ($expenses as $expense):
                if ($expense['expense_date'] !== $currentDate):
                    $currentDate = $expense['expense_date'];
            ?>
            <div class="ht-expense-date-header">
                <?php echo ht_format_date($currentDate, 'l, M j'); ?>
            </div>
            <?php endif; ?>
            <div class="ht-expense-item" data-expense-id="<?php echo $expense['id']; ?>">
                <span class="ht-expense-category-icon"><?php echo $categories[$expense['category']]['icon'] ?? '📦'; ?></span>
                <div class="ht-expense-item-content">
                    <h4 class="ht-expense-description"><?php echo htmlspecialchars($expense['description']); ?></h4>
                    <span class="ht-expense-meta">
                        Paid by <?php echo htmlspecialchars($expense['paid_by_name'] ?? 'Unknown'); ?>
                        <?php if ($expense['split_with_json']): ?>
                        · Split with <?php echo count(json_decode($expense['split_with_json'], true)); ?> people
                        <?php endif; ?>
                    </span>
                </div>
                <span class="ht-expense-amount"><?php echo ht_format_currency($expense['amount'], $expense['currency']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Split Settlement -->
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">🤝</span>
            Split & Settle
        </h3>
        <p class="ht-section-description">
            Calculate who owes who based on all expenses. Uses a smart algorithm to minimize the number of payments needed.
        </p>
        <button id="calculateSplitBtn" class="ht-btn ht-btn-secondary">
            <span class="ht-btn-icon">📊</span>
            Calculate Settlement
        </button>
    </div>
</div>

<!-- Add/Edit Expense Modal -->
<div id="expenseModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content">
        <h3 class="ht-modal-title">Add Expense</h3>
        <form id="expenseForm">
            <div class="ht-form-group">
                <label class="ht-label">Category</label>
                <select name="category" class="ht-select" required>
                    <?php foreach ($categories as $cat => $info): ?>
                    <option value="<?php echo $cat; ?>"><?php echo $info['icon']; ?> <?php echo $info['label']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Description *</label>
                <input type="text" name="description" class="ht-input" placeholder="What was this for?" required maxlength="255">
            </div>
            <div class="ht-form-row">
                <div class="ht-form-group">
                    <label class="ht-label">Amount *</label>
                    <div class="ht-input-group">
                        <span class="ht-input-prefix"><?php echo $trip['budget_currency']; ?></span>
                        <input type="number" name="amount" class="ht-input" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div class="ht-form-group">
                    <label class="ht-label">Date</label>
                    <input type="date" name="expense_date" class="ht-input" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Notes (optional)</label>
                <textarea name="notes" class="ht-textarea" rows="2" placeholder="Add any additional details..." maxlength="500"></textarea>
            </div>
            <div class="ht-modal-actions">
                <button type="button" class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
                <button type="submit" class="ht-btn ht-btn-primary">Add Expense</button>
            </div>
        </form>
    </div>
</div>

<!-- Settlement Modal -->
<div id="settlementModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content ht-modal-lg">
        <div class="ht-modal-header">
            <h3 class="ht-modal-title">Settlement Summary</h3>
            <button class="ht-modal-close" data-action="close">&times;</button>
        </div>
        <div class="ht-modal-body" id="settlementContent">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const tripId = <?php echo $trip['id']; ?>;
    const currency = '<?php echo $trip['budget_currency']; ?>';

    // Initialize Expenses UI when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => HT.ExpensesUI.init(tripId, currency));
    } else {
        HT.ExpensesUI.init(tripId, currency);
    }
})();
</script>

<style>
.ht-section-description {
    font-size: 14px;
    color: var(--ht-text-secondary);
    margin: 0 0 16px 0;
}

.ht-expense-summary {
    margin-bottom: 24px;
}

.ht-expense-total-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px;
    background: var(--ht-glass-medium);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-lg);
    text-align: center;
    margin-bottom: 16px;
}

.ht-expense-total-label {
    font-size: 14px;
    color: var(--ht-text-muted);
    margin-bottom: 8px;
}

.ht-expense-total-value {
    font-size: 36px;
    font-weight: 800;
    color: var(--ht-text-primary);
}

.ht-expense-count {
    font-size: 13px;
    color: var(--ht-text-secondary);
    margin-top: 4px;
}

.ht-budget-progress {
    padding: 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
}

.ht-budget-bar {
    height: 12px;
    background: var(--ht-glass-medium);
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 8px;
}

.ht-budget-fill {
    height: 100%;
    background: linear-gradient(90deg, #11998e, #38ef7d);
    border-radius: 6px;
    transition: width 0.5s ease;
}

.ht-budget-fill.warning { background: linear-gradient(90deg, #f7971e, #ffd200); }
.ht-budget-fill.over-budget { background: linear-gradient(90deg, #ff416c, #ff4b2b); }

.ht-budget-labels {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--ht-text-muted);
}

.ht-category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 12px;
}

.ht-category-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 12px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    text-align: center;
}

.ht-category-icon { font-size: 24px; margin-bottom: 8px; }
.ht-category-amount { font-size: 16px; font-weight: 700; color: var(--ht-text-primary); }
.ht-category-label { font-size: 11px; color: var(--ht-text-muted); margin-top: 4px; }

.ht-expense-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-expense-date-header {
    font-size: 13px;
    font-weight: 600;
    color: var(--ht-text-muted);
    padding: 12px 0 4px 0;
    border-bottom: 1px solid var(--ht-glass-border);
    margin-top: 8px;
}

.ht-expense-date-header:first-child { margin-top: 0; }

.ht-expense-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    cursor: pointer;
    transition: var(--ht-transition);
}

.ht-expense-item:hover {
    background: var(--ht-glass-medium);
}

.ht-expense-category-icon { font-size: 24px; }
.ht-expense-item-content { flex: 1; min-width: 0; }
.ht-expense-description { font-size: 14px; font-weight: 600; color: var(--ht-text-primary); margin: 0 0 2px 0; }
.ht-expense-meta { font-size: 12px; color: var(--ht-text-muted); }
.ht-expense-amount { font-size: 16px; font-weight: 700; color: var(--ht-text-primary); }

/* Form enhancements */
.ht-input-group {
    display: flex;
    align-items: stretch;
}

.ht-input-prefix {
    display: flex;
    align-items: center;
    padding: 0 12px;
    background: var(--ht-glass-medium);
    border: 1px solid var(--ht-glass-border);
    border-right: none;
    border-radius: var(--ht-radius-sm) 0 0 var(--ht-radius-sm);
    font-size: 14px;
    color: var(--ht-text-secondary);
}

.ht-input-group .ht-input {
    border-radius: 0 var(--ht-radius-sm) var(--ht-radius-sm) 0;
}

.ht-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-radio-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--ht-text-secondary);
    cursor: pointer;
}

.ht-radio-label input[type="radio"] {
    width: 18px;
    height: 18px;
    accent-color: var(--ht-primary);
}

.ht-split-members {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-sm);
}

.ht-split-member {
    padding: 6px 12px;
    background: var(--ht-glass-medium);
    border-radius: var(--ht-radius-sm);
    font-size: 13px;
}

/* Action dialog */
.ht-action-dialog {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.ht-action-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}

.ht-action-content {
    position: relative;
    width: 100%;
    max-width: 400px;
    padding: 16px;
    background: var(--ht-bg-card);
    border-radius: var(--ht-radius-lg) var(--ht-radius-lg) 0 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-action-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 14px 16px;
    background: var(--ht-glass-light);
    border: none;
    border-radius: var(--ht-radius-md);
    font-size: 15px;
    color: var(--ht-text-primary);
    cursor: pointer;
    transition: var(--ht-transition);
}

.ht-action-btn:hover { background: var(--ht-glass-medium); }
.ht-action-btn.danger { color: #ff4444; }
.ht-action-btn.cancel { color: var(--ht-text-muted); margin-top: 8px; }
.ht-action-icon { font-size: 18px; }

/* Settlement styles */
.ht-settlement-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.ht-settlement-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    text-align: center;
}

.ht-settlement-stat-label {
    font-size: 12px;
    color: var(--ht-text-muted);
    margin-bottom: 4px;
}

.ht-settlement-stat-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--ht-text-primary);
}

.ht-settlement-section {
    margin-bottom: 20px;
}

.ht-settlement-section-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--ht-text-secondary);
    margin: 0 0 12px 0;
}

.ht-member-balances {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-member-balance {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    border-left: 3px solid var(--ht-glass-border);
}

.ht-member-balance.positive { border-left-color: #38ef7d; }
.ht-member-balance.negative { border-left-color: #ff4444; }

.ht-member-name {
    font-weight: 600;
    color: var(--ht-text-primary);
    min-width: 80px;
}

.ht-member-details {
    flex: 1;
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: var(--ht-text-muted);
}

.ht-member-balance-amount {
    font-weight: 700;
    font-size: 15px;
}

.ht-member-balance.positive .ht-member-balance-amount { color: #38ef7d; }
.ht-member-balance.negative .ht-member-balance-amount { color: #ff4444; }

.ht-settlements-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-settlement-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: linear-gradient(135deg, rgba(255,68,68,0.1), rgba(56,239,125,0.1));
    border-radius: var(--ht-radius-md);
}

.ht-settlement-from {
    font-weight: 600;
    color: #ff6666;
}

.ht-settlement-arrow {
    font-size: 18px;
    color: var(--ht-text-muted);
}

.ht-settlement-to {
    font-weight: 600;
    color: #38ef7d;
}

.ht-settlement-amount {
    margin-left: auto;
    font-weight: 700;
    font-size: 16px;
    color: var(--ht-text-primary);
}

.ht-settlement-balanced {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 20px;
    background: rgba(56, 239, 125, 0.1);
    border-radius: var(--ht-radius-md);
    color: #38ef7d;
    font-weight: 500;
}

.ht-balanced-icon {
    font-size: 24px;
}

@media (max-width: 480px) {
    .ht-settlement-summary {
        grid-template-columns: 1fr;
    }

    .ht-member-details {
        flex-direction: column;
        gap: 4px;
    }
}
</style>
