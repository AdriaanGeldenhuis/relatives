<?php
/**
 * RELATIVES - SHOPPING LIST v4
 * Aurora command-center style - All logic in API
 */

require_once __DIR__ . '/../core/session_boot.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

// Load shopping classes
header('Cache-Control: private, no-store');

require_once __DIR__ . '/classes/ShoppingList.php';
require_once __DIR__ . '/classes/ShoppingItem.php';

$listManager = new ShoppingList($db, $user['family_id'], $user['id']);
$itemManager = new ShoppingItem($db, $user['family_id'], $user['id']);

// Get all lists
$lists = $listManager->getLists();

// Get current list
$currentListId = $_GET['list'] ?? ($lists[0]['id'] ?? null);

if (!$currentListId) {
    // Create default list
    $currentListId = $listManager->createList('Main List', '🛒');
    header('Location: /shopping/?list=' . $currentListId);
    exit;
}

// Get list with items
$currentList = $listManager->getList($currentListId);

if (!$currentList) {
    header('Location: /shopping/');
    exit;
}

// Group items by category
$itemsByCategory = [];
foreach ($currentList['items'] as $item) {
    $category = $item['category'] ?: 'other';
    if (!isset($itemsByCategory[$category])) {
        $itemsByCategory[$category] = [];
    }
    $itemsByCategory[$category][] = $item;
}

// Category metadata
$categories = [
    'dairy' => ['icon' => '🥛', 'name' => 'Dairy'],
    'meat' => ['icon' => '🥩', 'name' => 'Meat & Poultry'],
    'produce' => ['icon' => '🥬', 'name' => 'Fruits & Vegetables'],
    'bakery' => ['icon' => '🍞', 'name' => 'Bakery'],
    'pantry' => ['icon' => '🥫', 'name' => 'Pantry'],
    'frozen' => ['icon' => '🧊', 'name' => 'Frozen'],
    'snacks' => ['icon' => '🍿', 'name' => 'Snacks'],
    'beverages' => ['icon' => '🥤', 'name' => 'Beverages'],
    'household' => ['icon' => '🧹', 'name' => 'Household'],
    'other' => ['icon' => '📦', 'name' => 'Other']
];

// Get family members for assignment
$stmt = $db->prepare("
    SELECT id, full_name, avatar_color
    FROM users
    WHERE family_id = ? AND status = 'active'
    ORDER BY full_name
");
$stmt->execute([$user['family_id']]);
$familyMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get frequent items for quick add
$frequentItems = $itemManager->getFrequentItems(5);

// Voice prefill
$voicePrefillContent = '';
if (isset($_GET['new']) && $_GET['new'] == '1' && isset($_GET['content'])) {
    $voicePrefillContent = trim($_GET['content']);
}

// Calculate totals
$totalItems = count($currentList['items']);
$pendingItems = count(array_filter($currentList['items'], fn($i) => $i['status'] === 'pending'));
$boughtItems = count(array_filter($currentList['items'], fn($i) => $i['status'] === 'bought'));
$totalPrice = array_sum(array_column(array_filter($currentList['items'], fn($i) => $i['status'] === 'pending'), 'price'));
$percentage = $totalItems > 0 ? round(($boughtItems / $totalItems) * 100) : 0;

// Set page metadata
$pageTitle = 'Shopping';
$pageInlineStylesheets = [
    __DIR__ . '/../shared/css/aurora.css',
    __DIR__ . '/css/shopping.css'
];
$pageJS = [
    '/shopping/js/shopping.js',
    '/shopping/js/bulk.js'
];
$pageJSVersion = max(filemtime(__DIR__ . '/js/shopping.js'), filemtime(__DIR__ . '/js/bulk.js'));

// Include header
require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content family-main">
    <div class="hub">
        <header class="shopping-hero family-hero app-hero">
            <div class="family-hero-content">
                <h1 class="family-hero-title">Family shopping</h1>
                <p class="family-hero-summary">Shared shopping lists and what’s still to buy.</p>
                <div class="family-actions">
                    <button type="button" onclick="showAddItemModal()" class="family-button family-button-primary">
                        <span aria-hidden="true">＋</span> Add item
                    </button>
                    <button type="button" onclick="toggleBulkMode()" id="bulkModeBtn" class="family-button" title="Select multiple items">
                        <span aria-hidden="true">☑️</span> Select
                    </button>
                    <button type="button" onclick="exportList()" class="family-button" title="Share or export this list">
                        <span aria-hidden="true">📤</span> Share
                    </button>
                </div>
            </div>
            <?php renderFamilyAppArt('shopping', true); ?>
        </header>

        <!-- List tabs -->
        <section class="lists-section" aria-labelledby="shopping-lists-heading">
            <h2 class="family-section-title" id="shopping-lists-heading">Shopping lists</h2>
            <div class="lists-tabs">
                <?php foreach ($lists as $list): ?>
                    <div class="list-tab-wrapper <?php echo $list['id'] == $currentListId ? 'active' : ''; ?>" data-list-id="<?php echo $list['id']; ?>">
                        <button onclick="switchList(<?php echo $list['id']; ?>)"
                           class="list-tab <?php echo $list['id'] == $currentListId ? 'active' : ''; ?>"
                           data-list-id="<?php echo $list['id']; ?>"
                           data-list-name="<?php echo htmlspecialchars($list['name']); ?>"
                           data-list-icon="<?php echo htmlspecialchars($list['icon']); ?>">
                            <span class="list-icon"><?php echo htmlspecialchars($list['icon']); ?></span>
                            <span class="list-name"><?php echo htmlspecialchars($list['name']); ?></span>
                            <span class="list-count"><?php echo $list['pending_count']; ?></span>
                            <?php if ($list['total_price'] > 0): ?>
                                <span class="list-price">R<?php echo number_format($list['total_price'], 2); ?></span>
                            <?php endif; ?>
                        </button>
                        <details class="list-gear-menu shopping-options" name="shopping-options">
                            <summary class="list-action-btn gear-btn" title="List options" aria-label="List options">⚙️</summary>
                            <div class="list-gear-dropdown">
                                <button type="button" class="gear-option" data-shopping-action="edit-list">Edit</button>
                                <?php if (count($lists) > 1): ?>
                                    <button type="button" class="gear-option gear-delete" data-shopping-action="delete-list">Delete</button>
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>
                <?php endforeach; ?>
                <button onclick="showCreateListModal()" class="list-tab-add">
                    <span class="list-icon">＋</span>
                    <span class="list-name">New list</span>
                </button>
            </div>
        </section>

        <!-- Bulk Actions Bar (Hidden by default) -->
        <div id="bulkActionsBar" class="bulk-actions-bar" style="display: none;">
            <div class="bulk-info">
                <span id="bulkSelectedCount">0</span> selected
            </div>
            <div class="bulk-buttons">
                <button onclick="bulkMarkBought()" class="btn btn-success btn-sm">
                    <span class="btn-icon">✓</span>
                    <span>Bought</span>
                </button>
                <button onclick="showBulkCategoryModal()" class="btn btn-secondary btn-sm">
                    <span class="btn-icon">📁</span>
                    <span>Category</span>
                </button>
                <button onclick="showBulkAssignModal()" class="btn btn-secondary btn-sm">
                    <span class="btn-icon">👤</span>
                    <span>Assign</span>
                </button>
                <button onclick="toggleBulkMode()" class="btn btn-secondary btn-sm">
                    <span class="btn-icon">✕</span>
                    <span>Cancel</span>
                </button>
            </div>
        </div>

        <!-- Shopping Items -->
        <div class="shopping-content">

                <!-- Empty State -->
                <div class="empty-state panel" id="shoppingEmpty"<?= $totalItems ? ' hidden' : '' ?>>
                    <div class="empty-icon">🛒</div>
                    <h2>Your list is empty</h2>
                    <p>Add your first item and the family sees it instantly</p>
                    <button onclick="showAddItemModal()" class="btn btn-primary">＋ Add first item</button>
                </div>

                <!-- Progress + stats strip -->
                <div class="list-overview panel"<?= $totalItems ? '' : ' hidden' ?>>
                    <div class="list-actions" style="display: flex;">
                        <div class="list-progress">
                            <div class="progress-text">
                                <span class="progress-icon">✓</span>
                                <?php echo $boughtItems; ?> of <?php echo $totalItems; ?> items bought (<?php echo $percentage; ?>%)
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button onclick="clearBought()" class="btn btn-secondary btn-sm" id="clearBoughtBtn" <?php echo $boughtItems === 0 ? 'disabled' : ''; ?>>
                                <span class="btn-icon">🗑️</span>
                                <span>Clear bought</span>
                                <?php if ($boughtItems > 0): ?>
                                    <span class="badge"><?php echo $boughtItems; ?></span>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>

                    <div class="list-stats" style="display: grid;">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $totalItems; ?></div>
                            <div class="stat-label">Items</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value stat-pending"><?php echo $pendingItems; ?></div>
                            <div class="stat-label">To buy</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value stat-bought"><?php echo $boughtItems; ?></div>
                            <div class="stat-label">Bought</div>
                        </div>
                        <div class="stat-item" <?php echo $totalPrice > 0 ? '' : 'style="display: none;"'; ?>>
                            <div class="stat-value stat-price">R<?php echo number_format($totalPrice, 2); ?></div>
                            <div class="stat-label">Estimated</div>
                        </div>
                    </div>
                </div>

                <!-- Items by Category -->
                <div class="categories-grid">
                    <?php foreach ($itemsByCategory as $categoryKey => $categoryItems): ?>
                        <div class="category-section" data-category="<?php echo $categoryKey; ?>">
                            <div class="category-header">
                                <span class="category-icon"><?php echo $categories[$categoryKey]['icon']; ?></span>
                                <span class="category-name"><?php echo $categories[$categoryKey]['name']; ?></span>
                                <span class="category-count"><?php echo count($categoryItems); ?></span>
                            </div>

                            <div class="items-list">
                                <?php foreach ($categoryItems as $item): ?>
                                    <div class="item-card <?php echo $item['status']; ?>"
                                         data-item-id="<?php echo $item['id']; ?>"
                                         data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                                         data-assigned-to="<?= (int)($item['assigned_to'] ?? 0) ?>">

                                        <!-- Bulk Select Checkbox (Hidden by default) -->
                                        <div class="item-bulk-select" style="display: none;">
                                            <input
                                                type="checkbox"
                                                class="bulk-checkbox"
                                                data-item-id="<?php echo $item['id']; ?>">
                                        </div>

                                        <div class="item-checkbox">
                                            <input
                                                type="checkbox"
                                                id="item_<?php echo $item['id']; ?>"
                                                <?php echo $item['status'] === 'bought' ? 'checked' : ''; ?>
                                                onchange="toggleItem(<?php echo $item['id']; ?>)">
                                            <label for="item_<?php echo $item['id']; ?>" class="checkbox-label"></label>
                                        </div>

                                        <div class="item-content">
                                            <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>

                                            <div class="item-details">
                                                <?php if ($item['qty']): ?>
                                                    <span class="item-qty"><?php echo htmlspecialchars($item['qty']); ?></span>
                                                <?php endif; ?>

                                                <?php if ($item['price']): ?>
                                                    <span class="item-price"
                                                          onclick="showPriceHistory('<?php echo htmlspecialchars($item['name']); ?>')"
                                                          title="Click to see price history">
                                                        R<?php echo number_format($item['price'], 2); ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($item['store']): ?>
                                                    <span class="item-store"><?php echo htmlspecialchars($item['store']); ?></span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="item-meta">
                                                <span class="item-added-by">
                                                    <?php echo htmlspecialchars($item['added_by_name'] ?? 'Unknown'); ?>
                                                </span>

                                                <?php if ($item['assigned_to']): ?>
                                                    <span class="item-assigned">
                                                        → <?php echo htmlspecialchars($item['assigned_to_name']); ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($item['bought_at']): ?>
                                                    <span class="item-bought-at">
                                                        ✓ <?php echo date('H:i', strtotime($item['bought_at'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="item-actions">
                                            <details class="item-gear-menu shopping-options" name="shopping-options">
                                                <summary class="item-action-btn gear-btn" title="Item options" aria-label="Item options">⋯</summary>
                                                <div class="gear-dropdown">
                                                    <button type="button" class="gear-option" data-shopping-action="edit-item">Edit</button>
                                                    <button type="button" class="gear-option gear-delete" data-shopping-action="delete-item">Delete</button>
                                                </div>
                                            </details>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
        </div>
    </div>
</main>

<!-- MODALS START -->

<!-- Add Item Modal -->
<div id="addItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>➕ Add Item</h2>
            <button onclick="closeModal('addItemModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addItemForm" onsubmit="submitAddItem(event)">
                <div class="form-group suggestions-anchor">
                    <label>Item Name</label>
                    <input type="text" id="itemName" class="form-control"
                           placeholder="e.g., 2L Milk, 1kg Potatoes"
                           autocomplete="off"
                           <?php if ($voicePrefillContent): ?>value="<?php echo htmlspecialchars($voicePrefillContent); ?>"<?php endif; ?>
                           required>
                    <div id="suggestions" class="suggestions-dropdown" style="display: none;">
                        <div id="suggestionsList"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="text" id="itemQty" class="form-control" placeholder="e.g., 2L, 500g">
                    </div>
                    <div class="form-group">
                        <label>Price (R)</label>
                        <input type="number" id="itemPrice" class="form-control" step="0.01" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select id="itemCategory" class="form-control">
                        <option value="other">Other</option>
                        <?php foreach ($categories as $key => $cat): ?>
                            <option value="<?php echo $key; ?>">
                                <?php echo $cat['icon'] . ' ' . $cat['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Frequent Items Quick Add -->
                <?php if (!empty($frequentItems)): ?>
                <div class="frequent-items-modal">
                    <div class="frequent-title">Quick add</div>
                    <div class="frequent-chips">
                        <?php foreach (array_slice($frequentItems, 0, 6) as $item): ?>
                            <button type="button"
                                onclick="quickAddFromModal('<?php echo htmlspecialchars($item['item_name']); ?>', '<?php echo $item['category']; ?>')"
                                class="frequent-chip">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Add Item</button>
                    <button type="button" onclick="closeModal('addItemModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create/Edit List Modal -->
<div id="listModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="listModalTitle">📝 Create Shopping List</h2>
            <button onclick="closeModal('listModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="listForm">
                <input type="hidden" id="listId" value="">

                <div class="form-group">
                    <label>List Name</label>
                    <input type="text" id="listName" class="form-control"
                           placeholder="e.g., Woolworths, Weekly Groceries" required>
                </div>

                <div class="form-group">
                    <label>Icon</label>
                    <div class="icon-picker">
                        <input type="radio" name="listIcon" value="🛒" id="icon1" checked>
                        <label for="icon1" class="icon-option">🛒</label>

                        <input type="radio" name="listIcon" value="🏪" id="icon2">
                        <label for="icon2" class="icon-option">🏪</label>

                        <input type="radio" name="listIcon" value="🏬" id="icon3">
                        <label for="icon3" class="icon-option">🏬</label>

                        <input type="radio" name="listIcon" value="🍎" id="icon4">
                        <label for="icon4" class="icon-option">🍎</label>

                        <input type="radio" name="listIcon" value="🥗" id="icon5">
                        <label for="icon5" class="icon-option">🥗</label>

                        <input type="radio" name="listIcon" value="🍕" id="icon6">
                        <label for="icon6" class="icon-option">🍕</label>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save List</button>
                    <button type="button" onclick="closeModal('listModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>✏️ Edit Item</h2>
            <button onclick="closeModal('editItemModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editItemForm">
                <input type="hidden" id="editItemId" value="">

                <div class="form-group">
                    <label>Item Name</label>
                    <input type="text" id="editItemName" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="text" id="editItemQty" class="form-control" placeholder="e.g., 2L, 500g">
                    </div>

                    <div class="form-group">
                        <label>Price (R)</label>
                        <input type="number" id="editItemPrice" class="form-control" step="0.01" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label>Store</label>
                    <input type="text" id="editItemStore" class="form-control" placeholder="e.g., Woolworths">
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select id="editItemCategory" class="form-control">
                        <?php foreach ($categories as $key => $cat): ?>
                            <option value="<?php echo $key; ?>">
                                <?php echo $cat['icon'] . ' ' . $cat['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assign To</label>
                    <select id="editItemAssign" class="form-control">
                        <option value="">No one</option>
                        <?php foreach ($familyMembers as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="closeModal('editItemModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Category Modal -->
<div id="bulkCategoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📁 Move to Category</h2>
            <button onclick="closeModal('bulkCategoryModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="category-grid">
                <?php foreach ($categories as $key => $cat): ?>
                    <button onclick="applyBulkCategory('<?php echo $key; ?>')" class="category-option">
                        <span class="cat-icon"><?php echo $cat['icon']; ?></span>
                        <span class="cat-name"><?php echo $cat['name']; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div id="bulkAssignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>👤 Assign Items</h2>
            <button onclick="closeModal('bulkAssignModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="assign-grid">
                <?php foreach ($familyMembers as $member): ?>
                    <button onclick="applyBulkAssign(<?php echo $member['id']; ?>)" class="assign-option">
                        <div class="assign-avatar" style="background: <?php echo $member['avatar_color']; ?>">
                            <img src="/saves/<?php echo (int)$member['id']; ?>/avatar/avatar.webp"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                 style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                            <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                        </div>
                        <span class="assign-name"><?php echo htmlspecialchars($member['full_name']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Analytics Modal -->
<div id="analyticsModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2>📊 Shopping Analytics</h2>
            <button onclick="closeModal('analyticsModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="analyticsContent">
                <div class="loading">Loading analytics...</div>
            </div>
        </div>
    </div>
</div>

<!-- Price History Modal -->
<div id="priceHistoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>💰 Price History</h2>
            <button onclick="closeModal('priceHistoryModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="priceHistoryContent">
                <div class="loading">Loading price history...</div>
            </div>
        </div>
    </div>
</div>

<!-- Share Modal -->
<div id="shareModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📤 Share Shopping List</h2>
            <button onclick="closeModal('shareModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="modal-description">
                Share this list with anyone:
            </p>

            <div class="share-link-display">
                <input type="text" id="shareLink" class="form-control" readonly>
                <button onclick="copyShareLink()" class="btn btn-primary">
                    <span class="btn-icon">📋</span>
                    <span>Copy</span>
                </button>
            </div>

            <div class="share-methods">
                <p class="share-methods-title">Share via</p>
                <div class="share-buttons">
                    <button onclick="shareViaWhatsApp()" class="btn btn-success btn-sm">
                        WhatsApp
                    </button>
                </div>
            </div>

            <div class="export-options">
                <p class="share-methods-title">Export as</p>
                <div class="share-buttons">
                    <button onclick="exportListAs('pdf')" class="btn btn-secondary btn-sm">
                        📄 PDF
                    </button>
                    <button onclick="exportListAs('csv')" class="btn btn-secondary btn-sm">
                        📊 CSV
                    </button>
                    <button onclick="exportListAs('text')" class="btn btn-secondary btn-sm">
                        📝 Text
                    </button>
                </div>
            </div>

            <div class="analytics-link">
                <p class="share-methods-title">Insights</p>
                <div class="share-buttons">
                    <button onclick="closeModal('shareModal'); showAnalytics();" class="btn btn-primary btn-sm">
                        📊 View Analytics
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODALS END -->

<?php if ($voicePrefillContent): ?>
<script>
// Auto-focus on prefilled input
document.addEventListener('DOMContentLoaded', function() {
    const itemNameInput = document.getElementById('itemName');
    if (itemNameInput && itemNameInput.value) {
        if (typeof showModal === 'function') showModal('addItemModal');
        itemNameInput.focus();
        itemNameInput.select();
        if (typeof showToast === 'function') {
            showToast('🎤 Voice command prefilled!', 'success');
        }
    }
});
</script>
<?php endif; ?>

<script>
// Pass PHP data to JavaScript
window.currentListId = <?php echo $currentListId; ?>;
window.currentUser = <?php echo json_encode([
    'id' => $user['id'],
    'name' => $user['name']
]); ?>;
window.allItems = <?php echo json_encode($currentList['items']); ?>;
window.categories = <?php echo json_encode($categories); ?>;
window.familyMembers = <?php echo json_encode($familyMembers); ?>;
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
