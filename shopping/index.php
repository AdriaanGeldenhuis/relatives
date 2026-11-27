<?php
/**
 * RELATIVES - SHOPPING LIST v4
 * Aurora command-center style - All logic in API
 */

require_once __DIR__ . '/../core/session_boot.php';

$requestedList = filter_input(INPUT_GET, 'list', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$returnTo = is_int($requestedList) ? '/shopping/?list=' . $requestedList : '/shopping/';
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . rawurlencode($returnTo));
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php?redirect=' . rawurlencode($returnTo));
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
$currentListId = $requestedList ?: (int) ($lists[0]['id'] ?? 0);

if (!$currentListId) {
    // Create default list
    $currentListId = (int)$listManager->getDefaultList()['id'];
    header('Location: /shopping/?list=' . $currentListId);
    exit;
}

// Get list with items
$currentList = $listManager->getList($currentListId);

if (!$currentList) {
    header('Location: /shopping/');
    exit;
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
    SELECT id, full_name
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
if (isset($_GET['new']) && $_GET['new'] == '1' && is_string($_GET['content'] ?? null)) {
    $voicePrefillContent = trim($_GET['content']);
}

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

<main class="family-main shopping-main" id="shopping">
    <header class="family-hero app-hero shopping-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Family shopping</h1>
            <p class="family-hero-summary">Plan together and tick things off as you shop.</p>
            <div class="family-actions">
                <button type="button" class="family-button family-button-primary" id="shopAdd">＋ Add item</button>
                <button type="button" class="family-button" id="shopPaste">Paste a list</button>
            </div>
        </div>
        <?php renderFamilyAppArt('shopping', true); ?>
    </header>

    <section aria-labelledby="shopping-lists-heading">
        <div class="shopping-heading">
            <h2 class="family-section-title" id="shopping-lists-heading">Shopping lists</h2>
            <button type="button" class="family-button" id="shopNewList">＋ New list</button>
        </div>
        <nav class="lists-tabs" id="shopLists" aria-label="Shopping lists"></nav>
    </section>

    <section class="shopping-board" id="shopBoard" aria-labelledby="shopListTitle">
        <div class="shopping-heading shopping-list-heading">
            <div>
                <h2 class="family-section-title" id="shopListTitle"></h2>
                <p class="shopping-progress"><span id="shopProgress"></span><span id="shopEstimate" hidden></span></p>
            </div>
            <div class="shopping-list-actions">
                <button type="button" class="family-button" id="bulkModeBtn" aria-pressed="false">Select</button>
                <button type="button" class="family-button" id="shopShare">Share</button>
                <details class="shopping-options" name="shopping-options">
                    <summary class="shopping-more" aria-label="List options">⋯</summary>
                    <div class="shopping-menu">
                        <button type="button" data-shop-action="edit-list">Edit list</button>
                        <button type="button" data-shop-action="delete-list" class="shopping-danger">Delete list</button>
                    </div>
                </details>
            </div>
        </div>
        <progress class="shopping-progress-bar" id="shopProgressBar" value="0" max="1" aria-label="Items bought"></progress>

        <form id="quickAddForm" class="shopping-quick">
            <label class="shopping-sr-only" for="quickItemName">Quick add item</label>
            <input class="form-control" id="quickItemName" placeholder="Quick add, e.g. 2L milk" maxlength="160" required autocomplete="off" enterkeyhint="done">
            <button type="submit" class="family-button family-button-primary">＋ Add</button>
        </form>

        <div class="shopping-toolbar">
            <div class="shopping-filters" role="group" aria-label="Filter items">
                <button type="button" data-filter="all" aria-pressed="true">All <span></span></button>
                <button type="button" data-filter="pending" aria-pressed="false">To buy <span></span></button>
                <button type="button" data-filter="bought" aria-pressed="false">Bought <span></span></button>
                <button type="button" data-filter="mine" aria-pressed="false">For me <span></span></button>
            </div>
            <label class="shopping-search"><span class="shopping-sr-only">Search items</span>
                <input type="search" id="shopSearch" placeholder="Search items…" autocomplete="off">
            </label>
        </div>

        <div id="bulkActionsBar" class="bulk-actions-bar" hidden>
            <span id="bulkSelectedCount" role="status">0 selected</span>
            <div class="bulk-buttons">
                <button type="button" class="family-button" id="bulkSelectVisible">Select visible</button>
                <button type="button" class="family-button" id="bulkBought">Mark bought</button>
                <button type="button" class="family-button" id="bulkCategory">Category</button>
                <button type="button" class="family-button" id="bulkAssign">Assign</button>
                <button type="button" class="family-button" id="bulkCancel">Cancel</button>
            </div>
        </div>

        <p class="shopping-notice" id="shopSync" role="status" hidden></p>
        <p class="shopping-empty" id="shopEmpty" hidden></p>
        <div class="categories-grid" id="shopPending"></div>
        <details id="shopBought" class="shopping-bought" hidden>
            <summary id="shopBoughtLabel">Bought</summary>
            <div class="shopping-bought-actions">
                <button type="button" class="family-button" id="clearBoughtBtn">Clear bought</button>
            </div>
            <div class="categories-grid" id="shopCompleted"></div>
        </details>
        <p id="shopResults" class="shopping-sr-only" role="status"></p>
    </section>
    <noscript><p class="shopping-empty">Enable JavaScript to use the shopping checklist.</p></noscript>
</main>
<div id="shopToast" class="shopping-toast" role="status" hidden></div>

<dialog class="modal shopping-dialog" id="pasteModal" aria-labelledby="pasteHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="pasteHeading">Paste a shopping list</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="pasteForm">
                <div class="form-group">
                    <label for="pasteItems">One item per line (up to 50)</label>
                    <textarea class="form-control" id="pasteItems" rows="7" placeholder="2L milk&#10;Bread&#10;1kg potatoes" required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Add items</button>
                    <button type="button" class="btn btn-secondary" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</dialog>

<!-- Add Item Modal -->
<dialog id="addItemModal" class="modal shopping-dialog" aria-labelledby="addItemHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="addItemHeading">➕ Add Item</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addItemForm" >
                <div class="form-group">
                    <label for="itemName">Item Name</label>
                    <input type="text" id="itemName" class="form-control" maxlength="160"
                           placeholder="e.g., 2L Milk, 1kg Potatoes"
                           autocomplete="off"
                           <?php if ($voicePrefillContent): ?>value="<?php echo htmlspecialchars($voicePrefillContent); ?>"<?php endif; ?>
                           required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="itemQty">Quantity</label>
                        <input type="text" id="itemQty" class="form-control" maxlength="40" placeholder="e.g., 2L, 500g">
                    </div>
                    <div class="form-group">
                        <label for="itemPrice">Price (R)</label>
                        <input type="number" id="itemPrice" class="form-control" step="0.01" min="0" max="999999.99" inputmode="decimal">
                    </div>
                </div>

                <div class="form-group">
                    <label for="itemCategory">Category</label>
                    <select id="itemCategory" class="form-control">
                        <option value="">Automatic</option>

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
                                data-frequent-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                data-frequent-category="<?= htmlspecialchars($item['category'] ?? '', ENT_QUOTES) ?>"
                                class="frequent-chip">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Add Item</button>
                    <button type="button" data-close class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</dialog>

<!-- Create/Edit List Modal -->
<dialog id="listModal" class="modal shopping-dialog" aria-labelledby="listModalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="listModalTitle">📝 Create Shopping List</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="listForm">
                <input type="hidden" id="listId" value="">

                <div class="form-group">
                    <label for="listName">List Name</label>
                    <input type="text" id="listName" class="form-control" maxlength="120"
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
                    <button type="button" data-close class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</dialog>

<!-- Edit Item Modal -->
<dialog id="editItemModal" class="modal shopping-dialog" aria-labelledby="editItemHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="editItemHeading">✏️ Edit Item</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editItemForm">
                <input type="hidden" id="editItemId" value="">

                <div class="form-group">
                    <label for="editItemName">Item Name</label>
                    <input type="text" id="editItemName" class="form-control" maxlength="160" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="editItemQty">Quantity</label>
                        <input type="text" id="editItemQty" class="form-control" maxlength="40" placeholder="e.g., 2L, 500g">
                    </div>

                    <div class="form-group">
                        <label for="editItemPrice">Price (R)</label>
                        <input type="number" id="editItemPrice" class="form-control" step="0.01" min="0" max="999999.99" inputmode="decimal">
                    </div>
                </div>

                <div class="form-group">
                    <label for="editItemStore">Store</label>
                    <input type="text" id="editItemStore" class="form-control" maxlength="100" placeholder="e.g., Woolworths">
                </div>

                <div class="form-group">
                    <label for="editItemCategory">Category</label>
                    <select id="editItemCategory" class="form-control">
                        <?php foreach ($categories as $key => $cat): ?>
                            <option value="<?php echo $key; ?>">
                                <?php echo $cat['icon'] . ' ' . $cat['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="editItemAssign">Assign To</label>
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
                    <button type="button" data-close class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</dialog>

<!-- Bulk Category Modal -->
<dialog id="bulkCategoryModal" class="modal shopping-dialog" aria-labelledby="bulkCategoryHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="bulkCategoryHeading">📁 Move to Category</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="category-grid">
                <?php foreach ($categories as $key => $cat): ?>
                    <button type="button" data-category-choice="<?= $key ?>" class="category-option">
                        <span class="cat-icon"><?php echo $cat['icon']; ?></span>
                        <span class="cat-name"><?php echo $cat['name']; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</dialog>

<!-- Bulk Assign Modal -->
<dialog id="bulkAssignModal" class="modal shopping-dialog" aria-labelledby="bulkAssignHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="bulkAssignHeading">👤 Assign Items</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="assign-grid">
                <button type="button" data-assign="" class="assign-option">No one</button>
                <?php foreach ($familyMembers as $member): ?>
                    <button type="button" data-assign="<?= (int)$member['id'] ?>" class="assign-option">
                        <?= htmlspecialchars($member['full_name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</dialog>

<!-- Analytics Modal -->
<dialog id="analyticsModal" class="modal shopping-dialog" aria-labelledby="analyticsHeading">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="analyticsHeading">📊 Shopping Analytics</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="analyticsContent">
                <div class="loading">Loading analytics...</div>
            </div>
        </div>
    </div>
</dialog>

<!-- Price History Modal -->
<dialog id="priceHistoryModal" class="modal shopping-dialog" aria-labelledby="priceHistoryHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="priceHistoryHeading">💰 Price History</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="priceHistoryContent">
                <div class="loading">Loading price history...</div>
            </div>
        </div>
    </div>
</dialog>

<!-- Share Modal -->
<dialog id="shareModal" class="modal shopping-dialog" aria-labelledby="shareHeading">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="shareHeading">📤 Share Shopping List</h2>
            <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="modal-description">
                Family members can open this list link.
            </p>

            <div class="share-link-display">
                <input type="text" id="shareLink" class="form-control" readonly aria-label="Family shopping list link">
                <button onclick="copyShareLink()" class="btn btn-primary">
                    <span class="btn-icon">📋</span>
                    <span>Copy</span>
                </button>
            </div>

            <p id="shopShareStatus" role="status"></p>
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
</dialog>

<script type="application/json" id="shopData"><?= json_encode([
    'listId' => $currentListId,
    'userId' => (int)$user['id'],
    'lists' => $lists,
    'items' => $currentList['items'],
    'categories' => $categories,
], JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
