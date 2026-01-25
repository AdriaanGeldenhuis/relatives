<?php
/**
 * Holiday Traveling - Wallet View
 * Stub implementation - Full in Phase 6
 */
$itemTypes = [
    'ticket' => ['icon' => '🎫', 'label' => 'Ticket'],
    'booking' => ['icon' => '🏨', 'label' => 'Booking'],
    'doc' => ['icon' => '📄', 'label' => 'Document'],
    'note' => ['icon' => '📝', 'label' => 'Note'],
    'qr' => ['icon' => '📱', 'label' => 'QR Code'],
    'link' => ['icon' => '🔗', 'label' => 'Link'],
    'contact' => ['icon' => '👤', 'label' => 'Contact'],
    'insurance' => ['icon' => '🛡️', 'label' => 'Insurance'],
    'visa' => ['icon' => '🛂', 'label' => 'Visa']
];
?>

<div class="ht-wallet-page">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-back-link">← Back to Trip</a>
        <div class="ht-page-header-content">
            <h1 class="ht-page-title">
                <span class="ht-title-icon">📱</span>
                Travel Wallet
            </h1>
            <p class="ht-page-subtitle"><?php echo htmlspecialchars($trip['destination']); ?></p>
        </div>
        <?php if ($canEdit): ?>
        <button id="addWalletItemBtn" class="ht-btn ht-btn-primary">
            <span class="ht-btn-icon">+</span>
            Add Item
        </button>
        <?php endif; ?>
    </div>

    <!-- Offline Notice -->
    <div class="ht-offline-notice">
        <span class="ht-offline-icon">📶</span>
        <p>Items are cached locally for offline access. Last synced: <span id="lastSyncTime">Just now</span></p>
    </div>

    <!-- Essential Items -->
    <?php
    $essentialItems = array_filter($walletItems, fn($i) => $i['is_essential']);
    if (!empty($essentialItems)):
    ?>
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">⭐</span>
            Essential Items
        </h3>
        <div class="ht-wallet-grid">
            <?php foreach ($essentialItems as $item): ?>
            <div class="ht-wallet-card ht-wallet-essential" data-item-id="<?php echo $item['id']; ?>">
                <span class="ht-wallet-type-icon"><?php echo $itemTypes[$item['type']]['icon'] ?? '📎'; ?></span>
                <div class="ht-wallet-card-content">
                    <h4 class="ht-wallet-label"><?php echo htmlspecialchars($item['label']); ?></h4>
                    <span class="ht-wallet-type-label"><?php echo $itemTypes[$item['type']]['label'] ?? ucfirst($item['type']); ?></span>
                </div>
                <?php if ($canEdit): ?>
                <button class="ht-wallet-menu-btn" data-item-id="<?php echo $item['id']; ?>">⋮</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Items -->
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">📂</span>
            All Items (<?php echo count($walletItems); ?>)
        </h3>

        <?php if (empty($walletItems)): ?>
        <div class="ht-empty-state">
            <div class="ht-empty-icon">📱</div>
            <h3 class="ht-empty-title">No wallet items yet</h3>
            <p class="ht-empty-description">
                Store tickets, bookings, QR codes, and important documents for offline access.
            </p>
            <?php if ($canEdit): ?>
            <button class="ht-btn ht-btn-primary" onclick="document.getElementById('addWalletItemBtn').click()">
                Add Your First Item
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="ht-wallet-list">
            <?php foreach ($walletItems as $item): ?>
            <div class="ht-wallet-item" data-item-id="<?php echo $item['id']; ?>">
                <span class="ht-wallet-type-icon"><?php echo $itemTypes[$item['type']]['icon'] ?? '📎'; ?></span>
                <div class="ht-wallet-item-content">
                    <h4 class="ht-wallet-label"><?php echo htmlspecialchars($item['label']); ?></h4>
                    <span class="ht-wallet-type-label"><?php echo $itemTypes[$item['type']]['label'] ?? ucfirst($item['type']); ?></span>
                    <?php if ($item['content']): ?>
                    <p class="ht-wallet-preview"><?php echo htmlspecialchars(substr($item['content'], 0, 100)); ?><?php echo strlen($item['content']) > 100 ? '...' : ''; ?></p>
                    <?php endif; ?>
                </div>
                <div class="ht-wallet-item-actions">
                    <?php if ($item['is_essential']): ?>
                    <span class="ht-essential-badge">⭐</span>
                    <?php endif; ?>
                    <button class="ht-wallet-view-btn" data-item-id="<?php echo $item['id']; ?>">View</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Item Modal -->
<div id="walletItemModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content">
        <h3 class="ht-modal-title">Add Wallet Item</h3>
        <form id="walletItemForm">
            <div class="ht-form-group">
                <label class="ht-label">Type</label>
                <select name="type" class="ht-select" required>
                    <?php foreach ($itemTypes as $type => $info): ?>
                    <option value="<?php echo $type; ?>"><?php echo $info['icon']; ?> <?php echo $info['label']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Label *</label>
                <input type="text" name="label" class="ht-input" placeholder="e.g., Flight Confirmation" required maxlength="255">
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Content</label>
                <textarea name="content" class="ht-textarea" rows="4" placeholder="Paste text, booking details, confirmation numbers, etc."></textarea>
            </div>
            <div class="ht-form-group">
                <label class="ht-checkbox-label">
                    <input type="checkbox" name="is_essential" value="1">
                    <span>Mark as essential (show prominently)</span>
                </label>
            </div>
            <div class="ht-modal-actions">
                <button type="button" class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
                <button type="submit" class="ht-btn ht-btn-primary">Save Item</button>
            </div>
        </form>
    </div>
</div>

<!-- View Item Modal -->
<div id="walletViewModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content ht-modal-lg">
        <div class="ht-modal-header">
            <h3 class="ht-modal-title">Item Details</h3>
            <button class="ht-modal-close" data-action="close">&times;</button>
        </div>
        <div class="ht-modal-body" id="walletViewContent">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const tripId = <?php echo $trip['id']; ?>;

    // Initialize Wallet UI when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => HT.WalletUI.init(tripId));
    } else {
        HT.WalletUI.init(tripId);
    }
})();
</script>

<style>
.ht-offline-notice {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    margin-bottom: 20px;
}

.ht-offline-icon { font-size: 20px; }
.ht-offline-notice p { margin: 0; font-size: 13px; color: var(--ht-text-secondary); }

.ht-wallet-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}

.ht-wallet-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 16px;
    background: var(--ht-glass-medium);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-md);
    text-align: center;
    cursor: pointer;
    transition: var(--ht-transition);
    position: relative;
}

.ht-wallet-card:hover { transform: translateY(-2px); }
.ht-wallet-essential { border-color: rgba(247, 151, 30, 0.5); }

.ht-wallet-type-icon { font-size: 32px; margin-bottom: 8px; }
.ht-wallet-label { font-size: 14px; font-weight: 600; color: var(--ht-text-primary); margin: 0 0 4px 0; }
.ht-wallet-type-label { font-size: 11px; color: var(--ht-text-muted); }

.ht-wallet-menu-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    background: none;
    border: none;
    color: var(--ht-text-muted);
    cursor: pointer;
}

.ht-wallet-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-wallet-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--ht-glass-light);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-md);
}

.ht-wallet-item-content { flex: 1; min-width: 0; }
.ht-wallet-preview { font-size: 12px; color: var(--ht-text-muted); margin: 4px 0 0 0; overflow: hidden; text-overflow: ellipsis; }

.ht-wallet-item-actions { display: flex; align-items: center; gap: 8px; }
.ht-essential-badge { font-size: 14px; }
.ht-wallet-view-btn {
    padding: 6px 12px;
    background: var(--ht-glass-medium);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-sm);
    color: var(--ht-text-secondary);
    font-size: 12px;
    cursor: pointer;
}

.ht-checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--ht-text-secondary);
    cursor: pointer;
}
</style>
