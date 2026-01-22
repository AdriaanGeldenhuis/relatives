<?php
/**
 * Holiday Traveling - Packing List View
 * Full implementation - Phase 8
 */
$categories = [
    'essentials' => ['icon' => '🎒', 'label' => 'Essentials'],
    'clothing' => ['icon' => '👕', 'label' => 'Clothing'],
    'toiletries' => ['icon' => '🧴', 'label' => 'Toiletries'],
    'electronics' => ['icon' => '📱', 'label' => 'Electronics'],
    'documents' => ['icon' => '📄', 'label' => 'Documents'],
    'medicine' => ['icon' => '💊', 'label' => 'Medicine'],
    'entertainment' => ['icon' => '🎮', 'label' => 'Entertainment'],
    'kids' => ['icon' => '👶', 'label' => 'Kids'],
    'weather' => ['icon' => '🌦️', 'label' => 'Weather Gear'],
    'other' => ['icon' => '📦', 'label' => 'Other']
];
?>

<div class="ht-packing-page">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-back-link">← Back to Trip</a>
        <div class="ht-page-header-content">
            <h1 class="ht-page-title">
                <span class="ht-title-icon">🎒</span>
                Packing List
            </h1>
            <p class="ht-page-subtitle"><?php echo htmlspecialchars($trip['destination']); ?></p>
        </div>
        <button id="addItemBtn" class="ht-btn ht-btn-primary">
            <span class="ht-btn-icon">+</span>
            Add Item
        </button>
    </div>

    <!-- Progress Stats -->
    <div id="packingStats" class="ht-packing-stats">
        <div class="ht-packing-progress">
            <div class="ht-progress-bar">
                <div class="ht-progress-fill" style="width: 0%"></div>
            </div>
            <div class="ht-progress-text">
                <span class="ht-packed-count">0</span> of
                <span class="ht-total-count">0</span> items packed
                <span class="ht-percent">(0%)</span>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="ht-packing-actions">
        <button id="generateListBtn" class="ht-btn ht-btn-secondary">
            <span class="ht-btn-icon">✨</span>
            Generate Suggestions
        </button>
    </div>

    <!-- Late Mode Banner -->
    <?php if ($isLateMode): ?>
    <div class="ht-late-mode-banner">
        <span class="ht-late-icon">⏰</span>
        <div class="ht-late-content">
            <strong>Trip starts soon!</strong>
            <p>Make sure you've packed everything on your list.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Packing Items Container -->
    <div id="packingContainer" class="ht-packing-container">
        <div class="ht-loading-state">
            <div class="ht-spinner"></div>
            <p>Loading packing list...</p>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div id="packingModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content">
        <h3 class="ht-modal-title">Add Item</h3>
        <form id="packingForm">
            <div class="ht-form-group">
                <label class="ht-label">Category</label>
                <select name="category" class="ht-select" required>
                    <?php foreach ($categories as $cat => $info): ?>
                    <option value="<?php echo $cat; ?>"><?php echo $info['icon']; ?> <?php echo $info['label']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Item Name *</label>
                <input type="text" name="item_name" class="ht-input" placeholder="e.g., Passport" required maxlength="255">
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Quantity</label>
                <input type="number" name="quantity" class="ht-input" value="1" min="1" max="99">
            </div>
            <div class="ht-modal-actions">
                <button type="button" class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
                <button type="submit" class="ht-btn ht-btn-primary">Add Item</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    'use strict';

    const tripId = <?php echo $trip['id']; ?>;

    // Initialize Packing UI when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => HT.PackingUI.init(tripId));
    } else {
        HT.PackingUI.init(tripId);
    }
})();
</script>

<style>
.ht-packing-stats {
    margin-bottom: 20px;
}

.ht-packing-progress {
    padding: 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
}

.ht-progress-bar {
    height: 12px;
    background: var(--ht-glass-medium);
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 8px;
}

.ht-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #11998e, #38ef7d);
    border-radius: 6px;
    transition: width 0.5s ease;
}

.ht-progress-text {
    font-size: 14px;
    color: var(--ht-text-secondary);
    text-align: center;
}

.ht-packed-count { font-weight: 700; color: #38ef7d; }
.ht-total-count { font-weight: 600; }
.ht-percent { color: var(--ht-text-muted); }

.ht-packing-actions {
    margin-bottom: 20px;
}

.ht-late-mode-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: linear-gradient(135deg, rgba(255, 153, 0, 0.2), rgba(255, 68, 68, 0.2));
    border: 1px solid rgba(255, 153, 0, 0.3);
    border-radius: var(--ht-radius-md);
    margin-bottom: 20px;
}

.ht-late-icon { font-size: 28px; }
.ht-late-content strong { color: #ff9900; display: block; margin-bottom: 4px; }
.ht-late-content p { margin: 0; font-size: 13px; color: var(--ht-text-secondary); }

.ht-packing-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.ht-packing-category {
    background: var(--ht-glass-medium);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-lg);
    overflow: hidden;
}

.ht-category-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--ht-glass-light);
    border-bottom: 1px solid var(--ht-glass-border);
}

.ht-category-icon { font-size: 20px; }
.ht-category-name { font-weight: 600; color: var(--ht-text-primary); flex: 1; }
.ht-category-count { font-size: 13px; color: var(--ht-text-muted); }

.ht-packing-items {
    padding: 8px;
}

.ht-packing-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: var(--ht-radius-sm);
    transition: var(--ht-transition);
}

.ht-packing-item:hover {
    background: var(--ht-glass-light);
}

.ht-packing-item.packed {
    opacity: 0.6;
}

.ht-packing-item.packed .ht-item-name {
    text-decoration: line-through;
    color: var(--ht-text-muted);
}

.ht-pack-checkbox-label {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    cursor: pointer;
}

.ht-pack-checkbox {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.ht-pack-checkmark {
    width: 22px;
    height: 22px;
    border: 2px solid var(--ht-glass-border);
    border-radius: 6px;
    transition: var(--ht-transition);
}

.ht-pack-checkbox:checked ~ .ht-pack-checkmark {
    background: #38ef7d;
    border-color: #38ef7d;
}

.ht-pack-checkbox:checked ~ .ht-pack-checkmark::after {
    content: '✓';
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: #000;
    font-size: 14px;
    font-weight: 700;
}

.ht-item-name {
    flex: 1;
    font-size: 14px;
    color: var(--ht-text-primary);
}

.ht-item-qty {
    font-size: 12px;
    color: var(--ht-text-muted);
    padding: 2px 8px;
    background: var(--ht-glass-light);
    border-radius: 10px;
}

.ht-delete-item-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    background: none;
    color: var(--ht-text-muted);
    font-size: 18px;
    cursor: pointer;
    opacity: 0;
    transition: var(--ht-transition);
}

.ht-packing-item:hover .ht-delete-item-btn {
    opacity: 1;
}

.ht-delete-item-btn:hover {
    color: #ff4444;
}

/* Loading state */
.ht-loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 40px;
    color: var(--ht-text-muted);
}

.ht-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--ht-glass-border);
    border-top-color: var(--ht-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 12px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 480px) {
    .ht-delete-item-btn {
        opacity: 1;
    }
}
</style>
