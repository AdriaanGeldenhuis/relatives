'use strict';

ShoppingApp.bulkMode = false;
ShoppingApp.bulkBusy = false;
ShoppingApp.selectedItems = new Set();

function updateBulkControls() {
    const count = ShoppingApp.selectedItems.size;
    const visible = ShoppingApp.bulkMode;
    shop('bulkActionsBar').hidden = !visible;
    shop('bulkModeBtn').setAttribute('aria-pressed', String(visible));
    shop('bulkModeBtn').textContent = visible ? 'Done selecting' : 'Select';
    setText(shop('bulkSelectedCount'), `${count} selected`);
    for (const id of ['bulkBought', 'bulkAssign', 'bulkCategory']) {
        shop(id).disabled = !count || ShoppingApp.bulkBusy;
    }
    shop('bulkSelectVisible').disabled = ShoppingApp.bulkBusy;
    shop('bulkCancel').disabled = ShoppingApp.bulkBusy;
}

function toggleBulkMode() {
    if (ShoppingApp.bulkBusy) return;
    ShoppingApp.bulkMode = !ShoppingApp.bulkMode;
    ShoppingApp.selectedItems.clear();
    renderBoard();
}

async function applyBulkAction(action, fields = {}, dialog = null) {
    if (ShoppingApp.bulkBusy || !ShoppingApp.selectedItems.size) return;
    const listId = ShoppingApp.currentListId;
    const itemIds = [...ShoppingApp.selectedItems];
    ShoppingApp.bulkBusy = true;
    if (dialog) setSaving(dialog, true);
    renderBoard();
    let result;
    const saved = await writeShopping('/shopping/api/bulk.php', {
        action, item_ids: JSON.stringify(itemIds), ...fields
    }, data => { result = data; });
    ShoppingApp.bulkBusy = false;
    if (dialog) setSaving(dialog, false);
    if (saved) {
        if (dialog) closeModal(dialog.id);
        if (ShoppingApp.currentListId === listId) {
            ShoppingApp.bulkMode = false;
            ShoppingApp.selectedItems.clear();
        }
        showToast(result.message);
    }
    renderBoard();
}

shop('bulkModeBtn').addEventListener('click', toggleBulkMode);
shop('bulkCancel').addEventListener('click', toggleBulkMode);
shop('bulkSelectVisible').addEventListener('click', () => {
    shop('shopBoard').querySelectorAll('.item-card').forEach(row => {
        if (row.getClientRects().length && row.querySelector('input').getClientRects().length) {
            ShoppingApp.selectedItems.add(Number(row.dataset.itemId));
        }
    });
    renderBoard();
});
shop('bulkBought').addEventListener('click', () => applyBulkAction('mark_bought'));
shop('bulkCategory').addEventListener('click', () => showModal('bulkCategoryModal'));
shop('bulkAssign').addEventListener('click', () => showModal('bulkAssignModal'));
shop('bulkCategoryModal').addEventListener('click', event => {
    const button = event.target.closest('[data-category-choice]');
    if (button) void applyBulkAction('move_category', { category: button.dataset.categoryChoice }, shop('bulkCategoryModal'));
});
shop('bulkAssignModal').addEventListener('click', event => {
    const button = event.target.closest('[data-assign]');
    if (button) void applyBulkAction('assign', { assign_to: button.dataset.assign }, shop('bulkAssignModal'));
});
