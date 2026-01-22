<?php
/**
 * Holiday Traveling - Expenses View
 * Stub implementation - Full in Phase 7
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
            Calculate who owes who based on expenses.
        </p>
        <button id="calculateSplitBtn" class="ht-btn ht-btn-secondary">
            Calculate Settlement
        </button>
    </div>
</div>

<!-- Add Expense Modal (stub) -->
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
                <input type="text" name="description" class="ht-input" placeholder="What was this for?" required>
            </div>
            <div class="ht-form-row">
                <div class="ht-form-group">
                    <label class="ht-label">Amount *</label>
                    <input type="number" name="amount" class="ht-input" step="0.01" min="0" required>
                </div>
                <div class="ht-form-group">
                    <label class="ht-label">Date</label>
                    <input type="date" name="expense_date" class="ht-input" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="ht-modal-actions">
                <button type="button" class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
                <button type="submit" class="ht-btn ht-btn-primary">Add Expense</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    'use strict';

    const tripId = <?php echo $trip['id']; ?>;
    const modal = document.getElementById('expenseModal');

    // Open add modal
    document.getElementById('addExpenseBtn')?.addEventListener('click', () => {
        modal.style.display = 'flex';
    });

    // Close modal
    modal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
        modal.style.display = 'none';
    });
    modal?.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
        modal.style.display = 'none';
    });

    // Form submission (stub)
    document.getElementById('expenseForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        HT.Toast.info('Expense tracking will be fully implemented in Phase 7');
        modal.style.display = 'none';
    });

    // Calculate split (stub)
    document.getElementById('calculateSplitBtn')?.addEventListener('click', () => {
        HT.Toast.info('Split settlement will be implemented in Phase 7');
    });
})();
</script>

<style>
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
}

.ht-expense-category-icon { font-size: 24px; }
.ht-expense-item-content { flex: 1; min-width: 0; }
.ht-expense-description { font-size: 14px; font-weight: 600; color: var(--ht-text-primary); margin: 0 0 2px 0; }
.ht-expense-meta { font-size: 12px; color: var(--ht-text-muted); }
.ht-expense-amount { font-size: 16px; font-weight: 700; color: var(--ht-text-primary); }
</style>
