'use strict';

const shop = id => document.getElementById(id);
const initialShopping = JSON.parse(shop('shopData').textContent);
const money = new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatPrice = price => 'R' + money.format(price);
const ShoppingApp = {
    currentListId: Number(initialShopping.listId),
    lists: initialShopping.lists,
    allItems: [],
    categories: initialShopping.categories,
    filter: 'all',
    rows: new Map(),
    toggles: new Map(),
    writing: 0,
    writeVersion: 0,
    switching: false,
    listRequest: 0
};

async function apiCall(endpoint, data, method = 'POST') {
    const fields = new URLSearchParams(data);
    const response = await fetch(method === 'GET' ? endpoint + '?' + fields : endpoint, {
        method,
        body: method === 'POST' ? fields : null,
        cache: 'no-store',
        signal: AbortSignal.timeout(15000)
    });
    if (response.status === 401) throw new Error('Sign in again to use Shopping.');
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.success) {
        throw new Error(result?.message || result?.error || 'Shopping is temporarily unavailable. Try again.');
    }
    return result;
}

const listAPI = '/shopping/api/lists.php';
const itemAPI = '/shopping/api/items.php';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
}

function categoryFor(item) {
    return Object.hasOwn(ShoppingApp.categories, item.category) ? item.category : 'other';
}

function setItems(items) {
    ShoppingApp.allItems = items.filter(item => item.status === 'pending' || item.status === 'bought');
}

function activeList() {
    return ShoppingApp.lists.find(list => Number(list.id) === ShoppingApp.currentListId);
}

function placeElement(parent, element, position) {
    const current = parent.children[position];
    if (current !== element) parent.insertBefore(element, current || null);
}

function setText(element, value) {
    if (element.textContent !== String(value)) element.textContent = value;
}

function listURL(id) {
    return '/shopping/?list=' + id;
}

function renderLists() {
    const nav = shop('shopLists');
    const tabs = new Map(Array.from(nav.children, tab => [Number(tab.dataset.listId), tab]));
    ShoppingApp.lists.forEach((list, index) => {
        const id = Number(list.id);
        const active = id === ShoppingApp.currentListId;
        let tab = tabs.get(id);
        if (!tab) {
            tab = document.createElement('a');
            tab.className = 'list-tab';
            tab.dataset.listId = id;
            tab.href = listURL(id);
            tab.innerHTML = '<span class="list-icon" aria-hidden="true"></span><span class="list-name"></span><span class="list-count"></span>';
        }
        if (active) tab.setAttribute('aria-current', 'page');
        else tab.removeAttribute('aria-current');
        setText(tab.children[0], list.icon || '🛒');
        setText(tab.children[1], list.name);
        setText(tab.children[2], active ? ShoppingApp.allItems.filter(item => item.status === 'pending').length : list.pending_count);
        tab.children[2].setAttribute('aria-label', tab.children[2].textContent + ' to buy');
        placeElement(nav, tab, index);
        tabs.delete(id);
    });
    tabs.forEach(tab => tab.remove());
}

function shoppingRow(item) {
    const id = Number(item.id);
    let row = ShoppingApp.rows.get(id);
    if (!row) {
        row = document.createElement('div');
        row.className = 'item-card';
        row.dataset.itemId = id;
        row.innerHTML = `
            <label class="shopping-check"><input type="checkbox" data-shop-check></label>
            <div class="item-content">
                <button type="button" class="item-name" data-shop-action="edit-item"></button>
                <div class="item-details"></div>
            </div>
            <details class="shopping-options" name="shopping-options">
                <summary class="shopping-more"></summary>
                <div class="shopping-menu">
                    <button type="button" data-shop-action="edit-item">Edit item</button>
                    <button type="button" data-shop-action="delete-item" class="shopping-danger">Delete item</button>
                </div>
            </details>`;
        row.querySelector('summary').textContent = '⋯';
        ShoppingApp.rows.set(id, row);
    }
    const bought = item.status === 'bought';
    const checkbox = row.querySelector('input');
    checkbox.checked = ShoppingApp.bulkMode ? ShoppingApp.selectedItems.has(id) : ShoppingApp.toggles.get(id) ?? bought;
    checkbox.disabled = ShoppingApp.toggles.has(id) || ShoppingApp.bulkBusy;
    const checkLabel = ShoppingApp.bulkMode ? 'Select ' : bought ? 'Move to To buy: ' : 'Mark bought: ';
    checkbox.setAttribute('aria-label', checkLabel + item.name);
    row.classList.toggle('bought', bought);
    row.querySelector('summary').setAttribute('aria-label', 'Options for ' + item.name);
    setText(row.querySelector('.item-name'), item.name);

    const details = row.querySelector('.item-details');
    const markup = [
        item.qty ? `<span class="item-qty">${escapeHtml(item.qty)}</span>` : '',
        Number(item.price) > 0 ? `<button type="button" class="item-price" data-shop-action="price-history">${formatPrice(item.price)}</button>` : '',
        item.store ? `<span>${escapeHtml(item.store)}</span>` : '',
        item.assigned_to_name ? `<span class="item-assigned">For ${escapeHtml(item.assigned_to_name)}</span>` : '',
        item.added_by_name ? `<span class="item-author">Added by ${escapeHtml(item.added_by_name)}</span>` : ''
    ].filter(Boolean).join('');
    if (details.dataset.markup !== markup) {
        details.innerHTML = markup;
        details.dataset.markup = markup;
    }
    return row;
}

function renderGroups(container, items) {
    const groups = new Map(Array.from(container.children, group => [group.dataset.category, group]));
    let position = 0;
    for (const [category, info] of Object.entries(ShoppingApp.categories)) {
        const matches = items.filter(item => categoryFor(item) === category);
        const existing = groups.get(category);
        if (!matches.length) continue;
        const group = existing || document.createElement('section');
        if (!existing) {
            group.dataset.category = category;
            group.className = 'category-section';
            group.innerHTML = '<h3 class="category-heading"><span aria-hidden="true"></span> <span></span><span class="category-count"></span></h3><div class="items-list"></div>';
            setText(group.children[0].children[0], info.icon);
            setText(group.children[0].children[1], info.name);
        }
        setText(group.querySelector('.category-count'), matches.length);
        placeElement(container, group, position++);
        const itemList = group.lastElementChild;
        const visibleIds = new Set(matches.map(item => Number(item.id)));
        for (const child of Array.from(itemList.children)) {
            if (!visibleIds.has(Number(child.dataset.itemId))) child.remove();
        }
        matches.forEach((item, index) => placeElement(itemList, shoppingRow(item), index));
        groups.delete(category);
    }
    groups.forEach(group => group.remove());
}

function searchKey(value) {
    return String(value ?? '').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
}

function renderBoard() {
    const list = activeList();
    const items = ShoppingApp.allItems;
    const boughtCount = items.filter(item => item.status === 'bought').length;
    const pendingCount = items.length - boughtCount;
    const estimate = items
        .filter(item => item.status === 'pending')
        .reduce((sum, item) => sum + (Number(item.price) || 0), 0);

    renderLists();
    if (!list) return;
    setText(shop('shopListTitle'), list.name);
    setText(shop('shopProgress'), `${boughtCount} of ${items.length} bought`);
    shop('shopProgressBar').max = items.length || 1;
    shop('shopProgressBar').value = boughtCount;
    shop('shopEstimate').hidden = estimate <= 0;
    setText(shop('shopEstimate'), `Est. ${formatPrice(estimate)} to buy`);
    document.querySelector('[data-shop-action="delete-list"]').hidden = ShoppingApp.lists.length <= 1;
    shop('bulkModeBtn').disabled = !items.length;

    const assignedToMe = item => Number(item.assigned_to) === initialShopping.userId;
    const counts = { all: items.length, pending: pendingCount, bought: boughtCount, mine: items.filter(assignedToMe).length };
    const filter = ShoppingApp.filter;
    document.querySelectorAll('.shopping-filters button').forEach(button => {
        button.setAttribute('aria-pressed', String(filter === button.dataset.filter));
        setText(button.lastElementChild, counts[button.dataset.filter]);
    });

    const terms = searchKey(shop('shopSearch').value.trim()).split(/\s+/).filter(Boolean);
    const matches = items.filter(item => {
        if (filter === 'mine' ? !assignedToMe(item) : filter !== 'all' && item.status !== filter) return false;
        if (!terms.length) return true;
        const category = ShoppingApp.categories[categoryFor(item)];
        const text = searchKey([item.name, item.qty, item.store, item.assigned_to_name, category.name].filter(Boolean).join(' '));
        return terms.every(term => text.includes(term));
    });
    const pending = matches.filter(item => item.status === 'pending');
    const bought = matches.filter(item => item.status === 'bought');

    const ids = new Set(items.map(item => Number(item.id)));
    for (const id of ShoppingApp.rows.keys()) {
        if (!ids.has(id)) ShoppingApp.rows.delete(id);
    }
    for (const id of ShoppingApp.selectedItems) {
        if (!ids.has(id)) ShoppingApp.selectedItems.delete(id);
    }

    renderGroups(shop('shopPending'), pending);
    renderGroups(shop('shopCompleted'), bought);
    shop('shopBought').hidden = !bought.length;
    setText(shop('shopBoughtLabel'), `Bought (${bought.length})`);
    let emptyMessage = '';
    if (!matches.length) {
        if (!items.length) emptyMessage = 'This list is empty. Add something to get started.';
        else if (terms.length) emptyMessage = 'No items match your search.';
        else if (filter === 'mine') emptyMessage = 'No items are assigned to you on this list.';
        else if (filter === 'bought') emptyMessage = 'Nothing bought yet.';
        else emptyMessage = 'Everything on this list has been bought.';
    }
    shop('shopEmpty').hidden = !emptyMessage;
    setText(shop('shopEmpty'), emptyMessage);
    setText(shop('shopResults'), `${matches.length} ${matches.length === 1 ? 'item' : 'items'} shown`);
    updateBulkControls();
}

function setFilter(filter) {
    ShoppingApp.filter = filter;
    shop('shopBought').open = filter === 'bought' || Boolean(shop('shopSearch').value.trim());
    renderBoard();
}

function syncNotice(message = '') {
    shop('shopSync').hidden = !message;
    setText(shop('shopSync'), message);
}

async function fetchBoard(id) {
    return apiCall(listAPI, { action: 'board', list_id: id }, 'GET');
}

let refreshing = false;
let refreshQueued = false;
async function refreshBoard() {
    if (ShoppingApp.writing || ShoppingApp.switching) return;
    if (refreshing) {
        refreshQueued = true;
        return;
    }
    refreshing = true;
    const id = ShoppingApp.currentListId;
    const version = ShoppingApp.writeVersion;
    const request = ShoppingApp.listRequest;
    const isCurrent = () => version === ShoppingApp.writeVersion && request === ShoppingApp.listRequest;
    try {
        const data = await fetchBoard(id);
        if (!isCurrent()) return;
        if (data.items === null || !data.lists.some(list => Number(list.id) === id)) {
            if (data.lists.length) {
                const next = Number(data.lists[0].id);
                await switchList(next, 'replace');
                showToast('This list was removed. Opened another family list.');
            } else {
                location.replace('/shopping/');
            }
            return;
        }
        ShoppingApp.lists = data.lists;
        setItems(data.items);
        renderBoard();
        syncNotice();
    } catch (error) {
        if (isCurrent()) syncNotice(error.message);
    } finally {
        refreshing = false;
        if (refreshQueued) {
            refreshQueued = false;
            void refreshBoard();
        }
    }
}

async function switchList(id, historyMode = 'push') {
    const request = ++ShoppingApp.listRequest;
    const version = ShoppingApp.writeVersion;
    ShoppingApp.switching = true;
    shop('shopBoard').setAttribute('aria-busy', 'true');
    shop('shopBoard').inert = true;
    shop('shopAdd').disabled = true;
    shop('shopPaste').disabled = true;
    try {
        const data = await fetchBoard(id);
        if (request !== ShoppingApp.listRequest) return;
        if (data.items === null || !data.lists.some(list => Number(list.id) === id)) {
            const fallback = data.lists[0];
            if (fallback && Number(fallback.id) !== id) {
                return await switchList(Number(fallback.id), 'replace');
            }
            location.replace('/shopping/');
            return;
        }
        const changed = ShoppingApp.currentListId !== id;
        ShoppingApp.currentListId = id;
        ShoppingApp.lists = data.lists;
        setItems(data.items);
        if (changed) {
            ShoppingApp.bulkMode = false;
            ShoppingApp.selectedItems.clear();
            shop('shopSearch').value = '';
            ShoppingApp.filter = 'all';
            shop('shopBought').open = false;
        }
        if (historyMode === 'replace') history.replaceState(null, '', listURL(id));
        else if (historyMode === 'push' && changed) history.pushState(null, '', listURL(id));
        renderBoard();
        syncNotice();
    } catch (error) {
        if (request === ShoppingApp.listRequest) syncNotice(error.message);
    } finally {
        if (request === ShoppingApp.listRequest) {
            ShoppingApp.switching = false;
            shop('shopBoard').removeAttribute('aria-busy');
            shop('shopBoard').inert = false;
            shop('shopAdd').disabled = false;
            shop('shopPaste').disabled = false;
            if (version !== ShoppingApp.writeVersion) void refreshBoard();
        }
    }
}

async function writeShopping(endpoint, fields, apply) {
    ShoppingApp.writing++;
    ShoppingApp.writeVersion++;
    try {
        const result = await apiCall(endpoint, fields);
        if (apply) apply(result);
        return true;
    } catch (error) {
        showToast(error.message, 'error');
        return false;
    } finally {
        ShoppingApp.writing--;
        ShoppingApp.writeVersion++;
        void refreshBoard();
    }
}

function showModal(id) {
    const dialog = shop(id);
    if (dialog.open) return;
    dialog.querySelector('.shopping-dialog-notice')?.remove();
    dialog.showModal();
    dialog.querySelector('input:not([type="hidden"]):not([type="radio"]), textarea')?.focus();
}

function closeModal(id) {
    const dialog = shop(id);
    if (!dialog.dataset.saving) dialog.close();
}

let toastTimer;
function showToast(message, type = 'info') {
    const dialog = document.querySelector('.shopping-dialog[open]');
    if (dialog && type === 'error') {
        let notice = dialog.querySelector('.shopping-dialog-notice');
        if (!notice) {
            notice = document.createElement('p');
            notice.className = 'shopping-notice shopping-dialog-notice';
            notice.setAttribute('role', 'alert');
            dialog.querySelector('.modal-body').prepend(notice);
        }
        setText(notice, message);
        return;
    }
    const toast = shop('shopToast');
    toast.hidden = false;
    toast.classList.toggle('toast-error', type === 'error');
    setText(toast, message);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.hidden = true; }, 3600);
}

function setSaving(container, saving) {
    if (container.matches('dialog')) {
        if (saving) container.dataset.saving = 'true';
        else delete container.dataset.saving;
        container.querySelectorAll('button, input, select, textarea').forEach(control => {
            if (saving && !control.disabled) {
                control.dataset.saveDisabled = 'true';
                control.disabled = true;
            } else if (!saving && control.dataset.saveDisabled) {
                delete control.dataset.saveDisabled;
                control.disabled = false;
            }
        });
    }
    container.setAttribute('aria-busy', String(saving));
}

const commonItems = {
    dairy: 'Milk|Melk|Cheese|Kaas|Yogurt|Yoghurt|Butter|Botter|Cream|Eggs|Eiers',
    meat: 'Chicken|Hoender|Beef|Pork|Fish|Vis|Sausage|Wors|Bacon|Spek|Mince|Maalvleis',
    produce: 'Apples|Appels|Bananas|Piesangs|Tomatoes|Tamaties|Lettuce|Blaarslaai|Onions|Uie|Potatoes|Aartappels|Carrots|Wortels',
    bakery: 'Bread|Brood|Rolls|Croissants|Bagels|Muffins',
    pantry: 'Rice|Rys|Pasta|Flour|Meel|Sugar|Suiker|Salt|Sout|Oil|Olie',
    frozen: 'Ice Cream|Roomys|Frozen Vegetables|Chicken Nuggets',
    snacks: 'Chips|Cookies|Chocolate|Popcorn|Nuts|Koekies',
    beverages: 'Coffee|Koffie|Tea|Tee|Juice|Sap|Soda|Water',
    household: 'Toilet Paper|Toiletpapier|Paper Towels|Dish Soap|Detergent'
};
const itemCategories = new Map(Object.entries(commonItems).flatMap(([category, names]) =>
    names.split('|').map(name => [searchKey(name), category])
));

function parseItem(line) {
    const value = line.trim();
    const amount = '(?:\\d+(?:[.,]\\d+)?(?:\\s?(?:kg|g|mg|l|ml|packs?|x))?|half\\s+dozen|dozen)';
    const leading = value.match(new RegExp(`^(${amount})\\s+(.+)$`, 'i'));
    const multiplied = value.match(/^(\d+)\s*x\s*(\D.+)$/i);
    const trailing = value.match(new RegExp(`^(.+?)\\s+(\\d+(?:[.,]\\d+)?\\s?(?:kg|g|mg|l|ml|packs?))$`, 'i'));
    const name = leading?.[2] ?? multiplied?.[2] ?? trailing?.[1] ?? value;
    const qty = leading?.[1] ?? multiplied?.[1] ?? trailing?.[2] ?? '';
    const category = itemCategories.get(searchKey(name)) || 'other';
    return { name, qty, category };
}

async function addShoppingItems(items, dialog = null) {
    const listId = ShoppingApp.currentListId;
    const isBatch = items.length > 1;
    if (dialog?.dataset.saving) return false;
    if (dialog) setSaving(dialog, true);
    const saved = await writeShopping(itemAPI, isBatch
        ? { action: 'add_many', list_id: listId, items: JSON.stringify(items) }
        : { action: 'add', list_id: listId, ...items[0] });
    if (dialog) setSaving(dialog, false);
    if (!saved) return false;
    if (dialog) closeModal(dialog.id);
    showToast(isBatch ? `Added ${items.length} items` : `Added “${items[0].name}”`);
    if (ShoppingApp.currentListId === listId && ['bought', 'mine'].includes(ShoppingApp.filter)) setFilter('pending');
    return true;
}

function formSubmit(id, handler) {
    shop(id).addEventListener('submit', event => {
        event.preventDefault();
        void handler(event.currentTarget);
    });
}

formSubmit('quickAddForm', async form => {
    const input = shop('quickItemName');
    const text = input.value.trim();
    const button = form.querySelector('button');
    if (!text || button.disabled) return;
    button.disabled = true;
    if (await addShoppingItems([parseItem(text)]) && input.value.trim() === text) input.value = '';
    button.disabled = false;
});

formSubmit('addItemForm', async () => {
    const text = shop('itemName').value.trim();
    if (!text) return;
    const item = parseItem(text);
    item.qty = shop('itemQty').value.trim() || item.qty;
    item.price = shop('itemPrice').value;
    item.category = shop('itemCategory').value || item.category;
    await addShoppingItems([item], shop('addItemModal'));
});

formSubmit('pasteForm', async form => {
    const lines = shop('pasteItems').value.split(/\r?\n/)
        .map(line => line.trim().replace(/^(?:[-*•]\s+(?:\[[ xX]\]\s+)?|\d+[.)]\s+|\[[ xX]\]\s+)/, '').trim())
        .filter(Boolean);
    if (!lines.length || lines.length > 50) {
        showToast('Enter between 1 and 50 items, one per line.', 'error');
        return;
    }
    if (await addShoppingItems(lines.map(parseItem), form.closest('dialog'))) form.reset();
});

const editFields = {
    name: 'editItemName',
    qty: 'editItemQty',
    price: 'editItemPrice',
    store: 'editItemStore',
    category: 'editItemCategory',
    assigned_to: 'editItemAssign'
};

let editOriginal;
function editItem(item) {
    shop('editItemId').value = item.id;
    for (const [field, id] of Object.entries(editFields)) {
        shop(id).value = field === 'category' ? categoryFor(item) : item[field] ?? '';
    }
    editOriginal = Object.fromEntries(Object.entries(editFields).map(([field, id]) => [field, shop(id).value.trim()]));
    showModal('editItemModal');
}

formSubmit('editItemForm', async form => {
    const dialog = form.closest('dialog');
    if (dialog.dataset.saving) return;
    const changes = {};
    for (const [field, id] of Object.entries(editFields)) {
        const value = shop(id).value.trim();
        if (value !== editOriginal[field]) changes[field] = value;
    }
    if (!Object.keys(changes).length) {
        closeModal(dialog.id);
        return;
    }
    setSaving(dialog, true);
    const saved = await writeShopping(itemAPI, { action: 'update', item_id: shop('editItemId').value, ...changes });
    setSaving(dialog, false);
    if (saved) {
        closeModal(dialog.id);
        showToast('Item updated');
    }
});

function openListEditor(list = null) {
    shop('listModalTitle').textContent = list ? 'Edit list' : 'New shopping list';
    shop('listId').value = list?.id ?? '';
    shop('listName').value = list?.name ?? '';
    const icons = Array.from(shop('listForm').querySelectorAll('[name=listIcon]'));
    const radio = icons.find(icon => icon.value === list?.icon) || icons[0];
    radio.checked = true;
    showModal('listModal');
}

formSubmit('listForm', async form => {
    const dialog = form.closest('dialog');
    if (dialog.dataset.saving) return;
    const id = shop('listId').value;
    const name = shop('listName').value.trim();
    if (!name) return;
    const icon = form.querySelector('[name=listIcon]:checked').value;
    setSaving(dialog, true);
    let createdId;
    const saved = await writeShopping(listAPI, id
        ? { action: 'update', list_id: id, name, icon }
        : { action: 'create', name, icon }, result => { createdId = Number(result.list_id); });
    setSaving(dialog, false);
    if (saved) {
        closeModal(dialog.id);
        if (createdId) await switchList(createdId);
        showToast(id ? 'List updated' : `Created “${name}”`);
    }
});

async function setBought(item, checkbox) {
    const id = Number(item.id);
    const checked = checkbox.checked;
    if (ShoppingApp.toggles.has(id)) return;
    ShoppingApp.toggles.set(id, checked);
    const hadFocus = document.activeElement === checkbox;
    checkbox.disabled = true;
    const saved = await writeShopping(itemAPI, {
        action: 'toggle', item_id: id, status: checked ? 'bought' : 'pending'
    }, result => {
        const current = ShoppingApp.allItems.find(row => Number(row.id) === id);
        if (current) current.status = result.status;
    });
    ShoppingApp.toggles.delete(id);
    renderBoard();
    if (!saved) checkbox.checked = !checked;
    if (hadFocus && document.activeElement === document.body) {
        if (checkbox.getClientRects().length) checkbox.focus({ preventScroll: true });
        else {
            const next = shop('shopPending').querySelector('input:not(:disabled)')
                || document.querySelector('.shopping-filters [aria-pressed="true"]');
            next.focus({ preventScroll: true });
        }
    }
}

shop('shopAdd').addEventListener('click', () => {
    shop('addItemForm').reset();
    shop('itemName').value = '';
    showModal('addItemModal');
});
shop('shopPaste').addEventListener('click', () => showModal('pasteModal'));
shop('shopNewList').addEventListener('click', () => openListEditor());
shop('shopShare').addEventListener('click', shareList);
shop('clearBoughtBtn').addEventListener('click', async event => {
    const button = event.currentTarget;
    const listId = ShoppingApp.currentListId;
    if (!confirm(`Clear all bought items from “${activeList().name}”?`)) return;
    button.disabled = true;
    await writeShopping(itemAPI, { action: 'clear_bought', list_id: listId }, result => {
        if (ShoppingApp.currentListId === listId) {
            setItems(ShoppingApp.allItems.filter(item => item.status !== 'bought'));
            renderBoard();
        }
        showToast(`Cleared ${result.count} bought ${result.count === 1 ? 'item' : 'items'}`);
    });
    button.disabled = false;
});
shop('shopSearch').addEventListener('input', () => setFilter(ShoppingApp.filter));
shop('shopLists').addEventListener('click', event => {
    const tab = event.target.closest('.list-tab');
    if (!tab || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return;
    event.preventDefault();
    const id = Number(tab.dataset.listId);
    if (id !== ShoppingApp.currentListId || ShoppingApp.switching) void switchList(id);
});
shop('shopBoard').addEventListener('change', event => {
    const checkbox = event.target;
    if (!checkbox.matches('[data-shop-check]')) return;
    const id = Number(checkbox.closest('.item-card').dataset.itemId);
    if (ShoppingApp.bulkMode) {
        if (checkbox.checked) ShoppingApp.selectedItems.add(id);
        else ShoppingApp.selectedItems.delete(id);
        updateBulkControls();
    } else {
        const item = ShoppingApp.allItems.find(row => Number(row.id) === id);
        if (item) void setBought(item, checkbox);
    }
});

document.addEventListener('click', async event => {
    const control = event.target.closest('button');
    const dialog = event.target.closest('.shopping-dialog');
    if (dialog && (event.target === dialog || control?.matches('[data-close]'))) closeModal(dialog.id);
    if (control?.dataset.frequentName) {
        await addShoppingItems([{
            name: control.dataset.frequentName, category: control.dataset.frequentCategory
        }], shop('addItemModal'));
    }
    if (!event.target.closest('.shopping-options')) {
        document.querySelector('.shopping-options[open]')?.removeAttribute('open');
    }
    if (!control || !shop('shopBoard').contains(control)) return;
    if (control.dataset.filter) setFilter(control.dataset.filter);

    const action = control.dataset.shopAction;
    if (!action) return;
    const options = control.closest('.shopping-options');
    if (options) {
        options.open = false;
        options.querySelector('summary').focus({ preventScroll: true });
    }
    const item = ShoppingApp.allItems.find(row => Number(row.id) === Number(control.closest('.item-card')?.dataset.itemId));
    const list = activeList();
    switch (action) {
        case 'edit-item':
            if (item) editItem(item);
            break;
        case 'delete-item':
            if (item && confirm(`Delete “${item.name}”?`)) {
                await writeShopping(itemAPI, { action: 'delete', item_id: item.id }, () => showToast('Item deleted'));
            }
            break;
        case 'price-history':
            if (item) void showPriceHistory(item.name);
            break;
        case 'edit-list':
            openListEditor(list);
            break;
        case 'delete-list':
            if (list && confirm(`Delete “${list.name}” and its items?`)) {
                await writeShopping(listAPI, { action: 'delete', list_id: list.id }, () => showToast('List deleted'));
            }
            break;
    }
});

document.addEventListener('toggle', event => {
    if (event.target.matches('.shopping-options[open]')) {
        event.target.querySelector('.shopping-menu').scrollIntoView({ block: 'nearest' });
    }
}, true);
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const options = document.querySelector('.shopping-options[open]');
    if (options) {
        options.open = false;
        options.querySelector('summary').focus({ preventScroll: true });
    }
});
document.querySelectorAll('.shopping-dialog').forEach(dialog => {
    dialog.addEventListener('cancel', event => {
        if (dialog.dataset.saving) event.preventDefault();
    });
});
window.addEventListener('popstate', () => {
    const id = Number(new URLSearchParams(location.search).get('list')) || Number(ShoppingApp.lists[0]?.id);
    if (id) void switchList(id, 'none');
});
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) void refreshBoard();
});
window.addEventListener('online', refreshBoard);
window.loadItems = refreshBoard;
setInterval(() => {
    if (!document.hidden) void refreshBoard();
}, 8000);

document.addEventListener('DOMContentLoaded', () => {
    setItems(initialShopping.items);
    renderBoard();
    const url = new URL(location.href);
    if (url.searchParams.get('new') === '1' && shop('itemName').value.trim()) {
        showModal('addItemModal');
        shop('itemName').select();
    }
    url.searchParams.set('list', ShoppingApp.currentListId);
    url.searchParams.delete('new');
    url.searchParams.delete('content');
    history.replaceState(null, '', url);
});

async function showAnalytics() {
    showModal('analyticsModal');

    const content = document.getElementById('analyticsContent');
    content.innerHTML = '<div class="loading">Loading analytics...</div>';

    try {
        const result = await apiCall('/shopping/api/analytics.php', { action: 'get' }, 'GET');

        const analytics = result.analytics;

        let html = '<div class="analytics-dashboard">';

        html += '<div class="analytics-section">';
        html += '<h3>📊 Last 30 Days</h3>';
        html += '<div class="analytics-stats">';
        html += `<div class="stat-box">
            <div class="stat-label">Total Items</div>
            <div class="stat-value">${analytics.totals.total_items || 0}</div>
        </div>`;
        html += `<div class="stat-box">
            <div class="stat-label">Total Spent</div>
            <div class="stat-value">R${parseFloat(analytics.totals.total_spent || 0).toFixed(2)}</div>
        </div>`;
        html += `<div class="stat-box">
            <div class="stat-label">Avg Price</div>
            <div class="stat-value">R${parseFloat(analytics.totals.avg_price || 0).toFixed(2)}</div>
        </div>`;
        html += '</div>';
        html += '</div>';

        if (analytics.top_categories && analytics.top_categories.length > 0) {
            html += '<div class="analytics-section">';
            html += '<h3>🏆 Top Categories</h3>';
            html += '<div class="category-list">';

            analytics.top_categories.forEach(cat => {
                const catInfo = ShoppingApp.categories[cat.category] || { icon: '📦', name: cat.category };
                html += `<div class="category-item">
                    <span class="cat-icon">${catInfo.icon}</span>
                    <span class="cat-name">${catInfo.name}</span>
                    <span class="cat-count">${cat.count} items</span>
                    <span class="cat-total">R${parseFloat(cat.total || 0).toFixed(2)}</span>
                </div>`;
            });

            html += '</div>';
            html += '</div>';
        }

        if (analytics.frequent_items && analytics.frequent_items.length > 0) {
            html += '<div class="analytics-section">';
            html += '<h3>⭐ Most Bought Items</h3>';
            html += '<div class="frequent-list">';

            analytics.frequent_items.forEach(item => {
                html += `<div class="frequent-item">
                    <span class="item-name">${escapeHtml(item.item_name)}</span>
                    <span class="item-freq">×${item.frequency}</span>
                    <span class="item-price">~R${parseFloat(item.avg_price || 0).toFixed(2)}</span>
                </div>`;
            });

            html += '</div>';
            html += '</div>';
        }

        html += '</div>';

        content.innerHTML = html;

    } catch (error) {
        content.innerHTML = `<div class="error-message">Failed to load analytics: ${escapeHtml(error.message)}</div>`;
    }
}

async function showPriceHistory(itemName) {
    showModal('priceHistoryModal');

    const content = document.getElementById('priceHistoryContent');
    content.innerHTML = '<div class="loading">Loading price history...</div>';

    try {
        const result = await apiCall(itemAPI, {
            action: 'price_history',
            item_name: itemName
        }, 'GET');

        const history = result.history;

        if (!history || history.length === 0) {
            content.innerHTML = '<p>No price history available for this item.</p>';
            return;
        }

        let html = `<h3>${escapeHtml(itemName)}</h3>`;
        html += '<div class="price-history-list">';

        history.forEach(record => {
            const date = new Date(record.recorded_at);
            html += `<div class="price-record">
                <span class="price-date">${date.toLocaleDateString()}</span>
                <span class="price-value">R${parseFloat(record.price).toFixed(2)}</span>
                ${record.store ? `<span class="price-store">${escapeHtml(record.store)}</span>` : ''}
            </div>`;
        });

        html += '</div>';

        content.innerHTML = html;

    } catch (error) {
        content.innerHTML = `<div class="error-message">${escapeHtml(error.message)}</div>`;
    }
}

function shareList() {
    showModal('shareModal');

    const shareLink = window.location.origin + listURL(ShoppingApp.currentListId);
    const shareLinkInput = document.getElementById('shareLink');
    shop('shopShareStatus').textContent = '';
    if (shareLinkInput) {
        shareLinkInput.value = shareLink;
    }
}

async function copyShareLink() {
    const input = shop('shareLink');
    try {
        await navigator.clipboard.writeText(input.value);
    } catch {
        input.select();
        if (!document.execCommand('copy')) {
            showToast('Could not copy the link. Select it to copy manually.', 'error');
            return;
        }
    }
    shop('shopShareStatus').textContent = 'Link copied';
}

async function shareViaWhatsApp() {
    try {
        const result = await apiCall('/shopping/api/share.php', {
            action: 'whatsapp',
            list_id: ShoppingApp.currentListId
        });

        if (result.whatsapp_url) {
            window.open(result.whatsapp_url, '_blank');
        }
    } catch (error) {
        const shareLink = document.getElementById('shareLink')?.value || window.location.href;
        const text = encodeURIComponent(`Check out our shopping list: ${shareLink}`);
        window.open(`https://wa.me/?text=${text}`, '_blank');
    }
}


function exportListAs(format) {
    closeModal('shareModal');

    const items = ShoppingApp.allItems || [];
    if (items.length === 0) {
        showToast('No items to export', 'error');
        return;
    }

    // Get list name from active tab
    const listName = activeList()?.name || 'Shopping List';

    if (format === 'pdf') {
        exportShoppingPDF(items, listName);
    } else if (format === 'csv') {
        exportShoppingCSV(items, listName);
    } else if (format === 'text') {
        exportShoppingText(items, listName);
    }
}

function exportShoppingPDF(items, listName) {
    const pending = items.filter(i => i.status === 'pending');
    const bought = items.filter(i => i.status === 'bought');
    const totalPrice = pending.reduce((sum, i) => sum + (parseFloat(i.price) || 0), 0);

    const printContent = `<!DOCTYPE html>
<html>
<head>
    <title>${escapeHtml(listName)}</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; color: #333; }
        h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .summary { background: #f5f5f5; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .summary span { margin-right: 20px; }
        h2 { color: #667eea; margin-top: 24px; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #667eea; color: white; text-align: left; padding: 8px 12px; }
        td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        tr:nth-child(even) { background: #f9f9f9; }
        .bought { text-decoration: line-through; color: #999; }
        .footer { margin-top: 30px; font-size: 12px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>${escapeHtml(listName)}</h1>
    <div class="summary">
        <span><strong>${pending.length}</strong> pending</span>
        <span><strong>${bought.length}</strong> bought</span>
        ${totalPrice > 0 ? '<span>Est. total: <strong>R' + totalPrice.toFixed(2) + '</strong></span>' : ''}
    </div>
    ${pending.length > 0 ? '<h2>Still needed</h2>' : ''}
    ${pending.length > 0 ? buildShoppingTable(pending) : ''}
    ${bought.length > 0 ? '<h2>Already bought</h2>' : ''}
    ${bought.length > 0 ? buildShoppingTable(bought, true) : ''}
    <div class="footer">Exported from Relatives App on ${new Date().toLocaleDateString('en-ZA')}</div>
</body>
</html>`;

    const printWindow = window.open('', '_blank');
    if (!printWindow) {
        showToast('Allow pop-ups to open the print preview.', 'error');
        return;
    }
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.print();
    };
    showToast('PDF ready to print!', 'success');
}

function buildShoppingTable(items, isBought) {
    let html = '<table><tr><th>Item</th><th>Qty</th><th>Category</th><th>Price</th><th>Store</th></tr>';
    items.forEach(item => {
        const cls = isBought ? ' class="bought"' : '';
        const qty = (item.qty && item.qty !== 'null' && String(item.qty).trim()) ? escapeHtml(String(item.qty)) : '-';
        const cat = item.category || 'other';
        const price = (item.price && parseFloat(item.price) > 0) ? 'R' + parseFloat(item.price).toFixed(2) : '-';
        const store = (item.store && item.store !== 'null' && String(item.store).trim()) ? escapeHtml(String(item.store)) : '-';
        html += '<tr' + cls + '><td>' + escapeHtml(item.name) + '</td><td>' + qty + '</td><td>' + escapeHtml(cat) + '</td><td>' + price + '</td><td>' + store + '</td></tr>';
    });
    html += '</table>';
    return html;
}

function exportShoppingCSV(items, listName) {
    let csv = 'Name,Quantity,Category,Price,Store,Status\n';
    items.forEach(item => {
        const name = '"' + (item.name || '').replace(/"/g, '""') + '"';
        const qty = '"' + ((item.qty && item.qty !== 'null') ? String(item.qty).replace(/"/g, '""') : '') + '"';
        const cat = '"' + categoryFor(item) + '"';
        const price = (item.price && parseFloat(item.price) > 0) ? parseFloat(item.price).toFixed(2) : '';
        const store = '"' + ((item.store && item.store !== 'null') ? String(item.store).replace(/"/g, '""') : '') + '"';
        const status = item.status || 'pending';
        csv += name + ',' + qty + ',' + cat + ',' + price + ',' + store + ',' + status + '\n';
    });

    downloadFile(csv, listName.replace(/[^a-zA-Z0-9]/g, '-') + '.csv', 'text/csv');
    showToast('CSV downloaded!', 'success');
}

function exportShoppingText(items, listName) {
    const pending = items.filter(i => i.status === 'pending');
    const bought = items.filter(i => i.status === 'bought');

    let text = listName + '\n' + '='.repeat(listName.length) + '\n\n';

    if (pending.length > 0) {
        text += 'STILL NEEDED:\n';
        // Group by category
        const grouped = {};
        pending.forEach(item => {
            const cat = (item.category || 'other').toUpperCase();
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(item);
        });
        Object.keys(grouped).sort().forEach(cat => {
            text += '\n  ' + cat + '\n';
            grouped[cat].forEach(item => {
                let line = '  - ' + item.name;
                if (item.qty && item.qty !== 'null' && String(item.qty).trim()) line += ' (' + item.qty + ')';
                if (item.price && parseFloat(item.price) > 0) line += ' - R' + parseFloat(item.price).toFixed(2);
                if (item.store && item.store !== 'null' && String(item.store).trim()) line += ' @ ' + item.store;
                text += line + '\n';
            });
        });
    }

    if (bought.length > 0) {
        text += '\nALREADY BOUGHT:\n';
        bought.forEach(item => {
            text += '  [x] ' + item.name + '\n';
        });
    }

    const totalPrice = pending.reduce((sum, i) => sum + (parseFloat(i.price) || 0), 0);
    if (totalPrice > 0) {
        text += '\nEstimated total: R' + totalPrice.toFixed(2) + '\n';
    }

    text += '\nExported from Relatives App on ' + new Date().toLocaleDateString('en-ZA') + '\n';

    downloadFile(text, listName.replace(/[^a-zA-Z0-9]/g, '-') + '.txt', 'text/plain');
    showToast('Text file downloaded!', 'success');
}

function downloadFile(content, filename, mimeType) {
    const blob = new Blob([content], { type: mimeType + ';charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
