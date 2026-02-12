/**
 * ============================================
 * RELATIVES SHOPPING LIST - REAL-TIME VERSION 2.0
 * Enhanced with Better Error Handling & Performance
 * ============================================
 */

console.log('%c🛒 Shopping List Loading v2.0...', 'font-size: 16px; font-weight: bold; color: #667eea;');

// ============================================
// GLOBAL STATE
// ============================================
const ShoppingApp = {
    currentListId: window.currentListId || null,
    currentUser: window.currentUser || {},
    allItems: window.allItems || [],
    categories: window.categories || {},
    familyMembers: window.familyMembers || [],
    bulkMode: false,
    selectedItems: new Set(),
    
    API: {
        items: '/shopping/api/items.php',
        lists: '/shopping/api/lists.php',
        bulk: '/shopping/api/bulk.php',
        analytics: '/shopping/api/analytics.php',
        share: '/shopping/api/share.php'
    },
    
    // Performance optimizations
    updateDebounceTimer: null,
    pendingUpdates: new Set()
};

// ============================================
// API HELPER - ENHANCED ERROR HANDLING
// ============================================
async function apiCall(endpoint, data = {}, method = 'POST') {
    const maxRetries = 2;
    let lastError = null;
    
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
        try {
            const options = { 
                method: method,
                credentials: 'same-origin' // Include cookies for session
            };
            
            if (method === 'POST') {
                const formData = new FormData();
                for (const key in data) {
                    if (data[key] !== null && data[key] !== undefined) {
                        formData.append(key, data[key]);
                    }
                }
                options.body = formData;
            }
            
            const url = method === 'GET' && Object.keys(data).length > 0
                ? endpoint + '?' + new URLSearchParams(data)
                : endpoint;
            
            const response = await fetch(url, options);
            
            // Handle HTTP errors
            if (!response.ok) {
                if (response.status === 401) {
                    // Session expired
                    showToast('Session expired. Please login again.', 'error');
                    setTimeout(() => {
                        window.location.href = '/login.php';
                    }, 2000);
                    throw new Error('Session expired');
                }
                
                if (response.status === 403) {
                    throw new Error('Access denied');
                }
                
                if (response.status === 404) {
                    throw new Error('Resource not found');
                }
                
                if (response.status >= 500) {
                    throw new Error('Server error. Please try again.');
                }
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Request failed');
            }
            
            return result;
            
        } catch (error) {
            lastError = error;
            
            // Don't retry on auth errors
            if (error.message.includes('Session expired') || error.message.includes('Access denied')) {
                throw error;
            }
            
            // Retry on network errors
            if (attempt < maxRetries) {
                console.warn(`API call failed, retrying (${attempt + 1}/${maxRetries})...`);
                await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));
                continue;
            }
            
            throw error;
        }
    }
    
    throw lastError;
}

// ============================================
// ADD ITEM - ENHANCED VALIDATION
// ============================================
async function addItem(event) {
    if (event) event.preventDefault();
    
    const nameInput = document.getElementById('itemName');
    const qtyInput = document.getElementById('itemQty');
    const priceInput = document.getElementById('itemPrice');
    const categorySelect = document.getElementById('itemCategory');
    
    const name = nameInput.value.trim();
    
    if (!name) {
        showToast('Please enter item name', 'error');
        nameInput.focus();
        return;
    }
    
    if (name.length > 160) {
        showToast('Item name too long (max 160 characters)', 'error');
        nameInput.focus();
        return;
    }
    
    // Show loading state
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="btn-icon">⏳</span><span class="btn-text">Adding...</span>';
    
    try {
        // Parse smart input
        const parsed = parseSmartInput(name);
        
        // Build data object
        const data = {
            action: 'add',
            list_id: ShoppingApp.currentListId,
            name: parsed.name
        };
        
        // Add optional fields
        const qty = qtyInput.value.trim() || parsed.qty;
        if (qty) {
            data.qty = qty;
        }
        
        const price = priceInput.value.trim();
        if (price && parseFloat(price) > 0) {
            data.price = price;
        }
        
        const category = categorySelect.value;
        if (category && category !== 'other') {
            data.category = category;
        }
        
        // Call API
        const result = await apiCall(ShoppingApp.API.items, data);
        
        showToast(`✓ Added "${parsed.name}"!`, 'success');
        
        // Clear form
        nameInput.value = '';
        qtyInput.value = '';
        priceInput.value = '';
        categorySelect.value = 'other';
        nameInput.focus();
        
        const suggestionsEl = document.getElementById('suggestions');
        if (suggestionsEl) suggestionsEl.style.display = 'none';
        
        // Add to DOM instantly
        addItemToDOM({
            id: result.item_id,
            name: parsed.name,
            qty: qty || null,
            price: price || null,
            category: category || 'other',
            status: 'pending',
            added_by_name: ShoppingApp.currentUser.name,
            avatar_color: '#667eea'
        });
        
        updateProgressBar();
        
    } catch (error) {
        showToast(error.message || 'Failed to add item', 'error');
        console.error('Add item error:', error);
    } finally {
        // Restore button
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
}

// ============================================
// ADD ITEM TO DOM - ENHANCED NULL HANDLING
// ============================================
function addItemToDOM(item) {
    const category = item.category || 'other';
    let categorySection = document.querySelector(`[data-category="${category}"]`);
    
    // Create category if doesn't exist
    if (!categorySection) {
        let categoriesGrid = document.querySelector('.categories-grid');
        
        if (!categoriesGrid) {
            const shoppingContent = document.querySelector('.shopping-content');
            if (!shoppingContent) {
                console.error('Shopping content container not found');
                return;
            }
            
            const emptyState = shoppingContent.querySelector('.empty-state');
            if (emptyState) emptyState.remove();
            
            categoriesGrid = document.createElement('div');
            categoriesGrid.className = 'categories-grid';
            shoppingContent.appendChild(categoriesGrid);
        }
        
        const catInfo = ShoppingApp.categories[category] || { icon: '📦', name: category };
        
        categorySection = document.createElement('div');
        categorySection.className = 'category-section glass-card';
        categorySection.setAttribute('data-category', category);
        categorySection.innerHTML = `
            <div class="category-header">
                <span class="category-icon">${catInfo.icon}</span>
                <span class="category-name">${catInfo.name}</span>
                <span class="category-count">0</span>
            </div>
            <div class="items-list"></div>
        `;
        
        categoriesGrid.appendChild(categorySection);
    }
    
    const itemsList = categorySection.querySelector('.items-list');
    
    if (!itemsList) {
        console.error('Items list not found in category section');
        return;
    }
    
    // Build details HTML - only show non-null/non-empty values
    let detailsHTML = '';
    
    if (item.qty && item.qty !== 'null' && item.qty !== null && String(item.qty).trim() !== '') {
        detailsHTML += `<span class="item-qty">${escapeHtml(String(item.qty))}</span>`;
    }
    
    if (item.price && item.price !== 'null' && item.price !== null && parseFloat(item.price) > 0) {
        detailsHTML += `<span class="item-price" onclick="showPriceHistory('${escapeHtml(item.name).replace(/'/g, "\\'")}')">R${parseFloat(item.price).toFixed(2)}</span>`;
    }
    
    if (item.store && item.store !== 'null' && item.store !== null && String(item.store).trim() !== '') {
        detailsHTML += `<span class="item-store">${escapeHtml(String(item.store))}</span>`;
    }
    
    // Build meta HTML
    let metaHTML = `<span class="item-added-by">Added by ${escapeHtml(item.added_by_name || 'You')}</span>`;
    
    if (item.assigned_to_name && item.assigned_to_name !== 'null' && item.assigned_to_name !== null) {
        metaHTML += `<span class="item-assigned"> • Assigned to ${escapeHtml(String(item.assigned_to_name))}</span>`;
    }
    
    // Create item card
    const itemCard = document.createElement('div');
    itemCard.className = `item-card ${item.status || 'pending'}`;
    itemCard.setAttribute('data-item-id', item.id);
    itemCard.setAttribute('data-item-name', escapeHtml(item.name));
    
    itemCard.innerHTML = `
        <div class="item-bulk-select" style="display: none;">
            <input type="checkbox" class="bulk-checkbox" data-item-id="${item.id}">
        </div>
        <div class="item-checkbox">
            <input type="checkbox" id="item_${item.id}" ${item.status === 'bought' ? 'checked' : ''} onchange="toggleItem(${item.id})">
            <label for="item_${item.id}" class="checkbox-label"></label>
        </div>
        <div class="item-content">
            <div class="item-name">${escapeHtml(item.name)}</div>
            ${detailsHTML ? `<div class="item-details">${detailsHTML}</div>` : ''}
            <div class="item-meta">${metaHTML}</div>
        </div>
        <div class="item-actions">
            <div class="item-gear-menu">
                <button class="item-action-btn gear-btn" onclick="toggleGearMenu(event, ${item.id})" title="Options">⚙️</button>
                <div class="gear-dropdown" id="gearMenu_${item.id}">
                    <button onclick="editItem(${item.id}); closeAllGearMenus();" class="gear-option">
                        <span class="gear-icon">✏️</span>
                        <span>Edit</span>
                    </button>
                    <button onclick="deleteItem(${item.id}); closeAllGearMenus();" class="gear-option gear-delete">
                        <span class="gear-icon">🗑️</span>
                        <span>Delete</span>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Add with animation
    itemCard.style.opacity = '0';
    itemCard.style.transform = 'translateY(-20px)';
    itemsList.insertBefore(itemCard, itemsList.firstChild);
    
    requestAnimationFrame(() => {
        itemCard.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        itemCard.style.opacity = '1';
        itemCard.style.transform = 'translateY(0)';
    });
    
    // Update category count
    updateCategoryCount(category);
    
    // Remove empty state if exists
    const emptyState = document.querySelector('.empty-state');
    if (emptyState) {
        emptyState.style.opacity = '0';
        setTimeout(() => emptyState.remove(), 200);
    }
    
    // Show list actions and stats if hidden
    const listActions = document.querySelector('.list-actions');
    if (listActions && listActions.style.display === 'none') {
        listActions.style.display = 'flex';
    }
    
    const listStats = document.querySelector('.list-stats');
    if (listStats && listStats.style.display === 'none') {
        listStats.style.display = 'grid';
    }
}

// ============================================
// TOGGLE ITEM - INSTANT UPDATE
// ============================================
async function toggleItem(itemId) {
    const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
    if (!itemCard) {
        console.warn('Item card not found:', itemId);
        return;
    }
    
    const checkbox = itemCard.querySelector('.item-checkbox input[type="checkbox"]');
    if (!checkbox) {
        console.warn('Checkbox not found for item:', itemId);
        return;
    }
    
    const wasChecked = checkbox.checked;
    
    // Optimistic UI update
    if (wasChecked) {
        itemCard.classList.add('bought');
    } else {
        itemCard.classList.remove('bought');
    }
    
    try {
        const result = await apiCall(ShoppingApp.API.items, {
            action: 'toggle',
            item_id: itemId
        });
        
        // Verify status matches
        if (result.status === 'bought') {
            itemCard.classList.add('bought');
            checkbox.checked = true;
        } else {
            itemCard.classList.remove('bought');
            checkbox.checked = false;
        }

        updateProgressBar();
        updateClearBoughtButton();
        
    } catch (error) {
        // Revert on error
        checkbox.checked = !wasChecked;
        if (wasChecked) {
            itemCard.classList.remove('bought');
        } else {
            itemCard.classList.add('bought');
        }
        
        showToast(error.message || 'Failed to update item', 'error');
        console.error('Toggle error:', error);
    }
}

// ============================================
// DELETE ITEM - INSTANT REMOVAL
// ============================================
async function deleteItem(itemId) {
    const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
    if (!itemCard) {
        console.warn('Item card not found:', itemId);
        return;
    }
    
    const itemName = itemCard.getAttribute('data-item-name') || 'this item';
    
    if (!confirm(`Delete "${itemName}"?`)) {
        return;
    }
    
    const category = itemCard.closest('.category-section')?.getAttribute('data-category');
    
    // Optimistic UI update
    itemCard.style.opacity = '0';
    itemCard.style.transform = 'translateX(-100px)';
    
    try {
        await apiCall(ShoppingApp.API.items, {
            action: 'delete',
            item_id: itemId
        });
        
        showToast(`Deleted "${itemName}"`, 'success');
        
        // Remove from DOM after animation
        setTimeout(() => {
            itemCard.remove();

            if (category) {
                updateCategoryCount(category);
            }

            updateProgressBar();
            updateClearBoughtButton();

            // Check if we need to show empty state
            const remainingItems = document.querySelectorAll('.item-card');
            if (remainingItems.length === 0) {
                showEmptyState();
            }
        }, 300);
        
    } catch (error) {
        // Revert on error
        itemCard.style.opacity = '1';
        itemCard.style.transform = 'translateX(0)';
        
        showToast(error.message || 'Failed to delete item', 'error');
        console.error('Delete error:', error);
    }
}

// ============================================
// EDIT ITEM - ENHANCED
// ============================================
function editItem(itemId) {
    const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
    if (!itemCard) {
        showToast('Item not found', 'error');
        return;
    }
    
    // Get item data from DOM
    const name = itemCard.querySelector('.item-name')?.textContent || '';
    const qtyEl = itemCard.querySelector('.item-qty');
    const priceEl = itemCard.querySelector('.item-price');
    const storeEl = itemCard.querySelector('.item-store');
    const category = itemCard.closest('.category-section')?.getAttribute('data-category') || 'other';
    
    // Get assigned info from ShoppingApp.allItems if available
    const itemData = ShoppingApp.allItems.find(i => i.id == itemId);
    
    // Populate form
    document.getElementById('editItemId').value = itemId;
    document.getElementById('editItemName').value = name;
    document.getElementById('editItemQty').value = qtyEl ? qtyEl.textContent : '';
    document.getElementById('editItemPrice').value = priceEl ? priceEl.textContent.replace('R', '').trim() : '';
    document.getElementById('editItemStore').value = storeEl ? storeEl.textContent : '';
    document.getElementById('editItemCategory').value = category;
    document.getElementById('editItemAssign').value = itemData?.assigned_to || '';
    
    showModal('editItemModal');
}

async function saveEditedItem(event) {
    if (event) event.preventDefault();
    
    const itemId = document.getElementById('editItemId').value;
    const name = document.getElementById('editItemName').value.trim();
    const qty = document.getElementById('editItemQty').value.trim();
    const price = document.getElementById('editItemPrice').value.trim();
    const store = document.getElementById('editItemStore').value.trim();
    const category = document.getElementById('editItemCategory').value;
    const assignedTo = document.getElementById('editItemAssign').value;
    
    if (!name) {
        showToast('Item name cannot be empty', 'error');
        return;
    }
    
    // Show loading
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';
    
    try {
        await apiCall(ShoppingApp.API.items, {
            action: 'update',
            item_id: itemId,
            name: name,
            qty: qty || null,
            price: price || null,
            store: store || null,
            category: category,
            assigned_to: assignedTo || null
        });
        
        showToast('Item updated!', 'success');
        closeModal('editItemModal');
        
        // Update DOM
        updateItemInDOM(itemId, { 
            name, 
            qty: qty || null, 
            price: price || null, 
            store: store || null, 
            category, 
            assigned_to: assignedTo || null 
        });
        
    } catch (error) {
        showToast(error.message || 'Failed to update item', 'error');
        console.error('Update error:', error);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

// ============================================
// UPDATE ITEM IN DOM - ENHANCED
// ============================================
function updateItemInDOM(itemId, data) {
    const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
    if (!itemCard) {
        console.warn('Item card not found for update:', itemId);
        return;
    }
    
    const oldCategory = itemCard.closest('.category-section')?.getAttribute('data-category');
    const newCategory = data.category;
    
    // Update item name
    const nameEl = itemCard.querySelector('.item-name');
    if (nameEl && data.name) {
        nameEl.textContent = data.name;
        itemCard.setAttribute('data-item-name', data.name);
    }
    
    // Update details
    let detailsEl = itemCard.querySelector('.item-details');
    
    if (!detailsEl && (data.qty || data.price || data.store)) {
        detailsEl = document.createElement('div');
        detailsEl.className = 'item-details';
        const contentEl = itemCard.querySelector('.item-content');
        const nameEl = contentEl.querySelector('.item-name');
        nameEl.after(detailsEl);
    }
    
    if (detailsEl) {
        let detailsHTML = '';
        
        if (data.qty && String(data.qty).trim() !== '') {
            detailsHTML += `<span class="item-qty">${escapeHtml(String(data.qty))}</span>`;
        }
        
        if (data.price && parseFloat(data.price) > 0) {
            detailsHTML += `<span class="item-price" onclick="showPriceHistory('${escapeHtml(data.name).replace(/'/g, "\\'")}')">R${parseFloat(data.price).toFixed(2)}</span>`;
        }
        
        if (data.store && String(data.store).trim() !== '') {
            detailsHTML += `<span class="item-store">${escapeHtml(String(data.store))}</span>`;
        }
        
        detailsEl.innerHTML = detailsHTML;
        
        if (!detailsHTML) {
            detailsEl.remove();
        }
    }
    
    // Update assignment
    if (data.assigned_to !== undefined) {
        const metaEl = itemCard.querySelector('.item-meta');
        if (metaEl) {
            let assignedSpan = metaEl.querySelector('.item-assigned');
            
            if (data.assigned_to) {
                const member = ShoppingApp.familyMembers.find(m => m.id == data.assigned_to);
                const memberName = member ? member.full_name : 'Unknown';
                
                if (!assignedSpan) {
                    assignedSpan = document.createElement('span');
                    assignedSpan.className = 'item-assigned';
                    metaEl.appendChild(assignedSpan);
                }
                assignedSpan.textContent = ` • Assigned to ${memberName}`;
            } else if (assignedSpan) {
                assignedSpan.remove();
            }
        }
    }
    
    // Move to new category if changed
    if (oldCategory && newCategory && oldCategory !== newCategory) {
        const oldCategorySection = itemCard.closest('.category-section');
        let newCategorySection = document.querySelector(`[data-category="${newCategory}"]`);
        
        if (!newCategorySection) {
            // Create new category
            const categoriesGrid = document.querySelector('.categories-grid');
            const catInfo = ShoppingApp.categories[newCategory] || { icon: '📦', name: newCategory };
            
            newCategorySection = document.createElement('div');
            newCategorySection.className = 'category-section glass-card';
            newCategorySection.setAttribute('data-category', newCategory);
            newCategorySection.innerHTML = `
                <div class="category-header">
                    <span class="category-icon">${catInfo.icon}</span>
                    <span class="category-name">${catInfo.name}</span>
                    <span class="category-count">0</span>
                </div>
                <div class="items-list"></div>
            `;
            categoriesGrid.appendChild(newCategorySection);
        }
        
        const newItemsList = newCategorySection.querySelector('.items-list');
        newItemsList.appendChild(itemCard);
        
        updateCategoryCount(oldCategory);
        updateCategoryCount(newCategory);
        
        // Remove old category if empty
        if (oldCategorySection && oldCategorySection.querySelectorAll('.item-card').length === 0) {
            oldCategorySection.style.opacity = '0';
            setTimeout(() => oldCategorySection.remove(), 300);
        }
    }
}

// ============================================
// CLEAR BOUGHT - ENHANCED
// ============================================
async function clearBought() {
    const boughtCards = document.querySelectorAll('.item-card.bought');
    const boughtCount = boughtCards.length;

    if (boughtCount === 0) {
        showToast('No bought items to clear', 'info');
        return;
    }

    if (!confirm(`Clear ${boughtCount} bought item${boughtCount > 1 ? 's' : ''}? Items will be archived to history.`)) {
        return;
    }

    try {
        await apiCall(ShoppingApp.API.items, {
            action: 'clear_bought',
            list_id: ShoppingApp.currentListId
        });

        showToast(`🧹 Cleared ${boughtCount} item${boughtCount > 1 ? 's' : ''}!`, 'success');

        // Remove from DOM with staggered animation
        boughtCards.forEach((card, index) => {
            setTimeout(() => {
                card.style.opacity = '0';
                card.style.transform = 'scale(0.8) translateX(-20px)';
            }, index * 50);
        });

        setTimeout(() => {
            boughtCards.forEach(card => card.remove());

            updateProgressBar();
            updateClearBoughtButton();

            // Remove empty categories
            document.querySelectorAll('.category-section').forEach(cat => {
                const items = cat.querySelectorAll('.item-card');
                if (items.length === 0) {
                    updateCategoryCount(cat.getAttribute('data-category'));
                }
            });

            // Show empty state if no items left
            const remainingItems = document.querySelectorAll('.item-card');
            if (remainingItems.length === 0) {
                showEmptyState();
            }
        }, boughtCards.length * 50 + 400);

    } catch (error) {
        showToast(error.message || 'Failed to clear items', 'error');
        console.error('Clear bought error:', error);
    }
}

/**
 * Clear bought from hero button
 */
async function clearBoughtHero() {
    await clearBought();
}

/**
 * Update the Clear Bought button state in hero
 */
function updateClearBoughtButton() {
    const clearBoughtBtn = document.getElementById('clearBoughtBtn');
    if (!clearBoughtBtn) return;

    const boughtCount = document.querySelectorAll('.item-card.bought').length;

    // Update badge (matches .badge class used in server-rendered HTML)
    let badge = clearBoughtBtn.querySelector('.badge');

    if (boughtCount > 0) {
        clearBoughtBtn.disabled = false;

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge';
            clearBoughtBtn.appendChild(badge);
        }
        badge.textContent = boughtCount;
    } else {
        clearBoughtBtn.disabled = true;

        if (badge) {
            badge.remove();
        }
    }
}

// ============================================
// HELPER FUNCTIONS - ENHANCED
// ============================================

/**
 * Parse smart input - Enhanced with more patterns
 */
function parseSmartInput(input) {
    const patterns = [
        // "2L Milk", "500g Butter", "2kg Potatoes"
        { regex: /^(\d+(?:\.\d+)?(?:kg|g|L|ml|l|pack|packs|x)?)\s+(.+)$/i, qtyFirst: true },
        // "Milk 2L", "Butter 500g"
        { regex: /^(.+?)\s+(\d+(?:\.\d+)?(?:kg|g|L|ml|l|pack|packs)?)$/i, qtyFirst: false },
        // "2x Bread", "3 x Eggs"
        { regex: /^(\d+)\s*x\s*(.+)$/i, qtyFirst: true },
        // "Half dozen eggs", "Dozen eggs"
        { regex: /^(half\s+dozen|dozen)\s+(.+)$/i, qtyFirst: true }
    ];
    
    for (const pattern of patterns) {
        const match = input.match(pattern.regex);
        if (match) {
            if (pattern.qtyFirst) {
                return { qty: match[1].trim(), name: match[2].trim() };
            } else {
                return { name: match[1].trim(), qty: match[2].trim() };
            }
        }
    }
    
    return { qty: '', name: input.trim() };
}

/**
 * Update progress bar - Enhanced with debouncing
 */
function updateProgressBar() {
    clearTimeout(ShoppingApp.updateDebounceTimer);
    
    ShoppingApp.updateDebounceTimer = setTimeout(() => {
        const progressFill = document.querySelector('.progress-fill');
        const progressText = document.querySelector('.progress-text');
        
        if (!progressFill || !progressText) return;
        
        const items = document.querySelectorAll('.item-card');
        const boughtItems = document.querySelectorAll('.item-card.bought');
        
        const total = items.length;
        const bought = boughtItems.length;
        const pending = total - bought;
        const percentage = total > 0 ? Math.round((bought / total) * 100) : 0;
        
        progressFill.style.width = percentage + '%';
        progressText.innerHTML = `<span class="progress-icon">✓</span>${bought} of ${total} items bought (${percentage}%)`;
        
        // Update stats
        const statTotalEl = document.querySelector('.stat-value');
        const statPendingEl = document.querySelector('.stat-pending');
        const statBoughtEl = document.querySelector('.stat-bought');
        
        if (statTotalEl) statTotalEl.textContent = total;
        if (statPendingEl) statPendingEl.textContent = pending;
        if (statBoughtEl) statBoughtEl.textContent = bought;
        
        // Calculate total price from pending items
        const priceEls = document.querySelectorAll('.item-card.pending .item-price');
        let totalPrice = 0;
        
        priceEls.forEach(el => {
            const priceText = el.textContent.replace('R', '').replace(',', '').trim();
            const price = parseFloat(priceText);
            if (!isNaN(price) && price > 0) {
                totalPrice += price;
            }
        });
        
        const statPriceEl = document.querySelector('.stat-price');
        if (statPriceEl) {
            if (totalPrice > 0) {
                statPriceEl.textContent = 'R' + totalPrice.toFixed(2);
                const statItem = statPriceEl.closest('.stat-item');
                if (statItem) statItem.style.display = 'block';
            } else {
                const statItem = statPriceEl.closest('.stat-item');
                if (statItem) statItem.style.display = 'none';
            }
        }
    }, 100);
}

/**
 * Update category count - Enhanced with safety checks
 */
function updateCategoryCount(category) {
    if (!category) {
        console.warn('updateCategoryCount called with no category');
        return;
    }
    
    const categorySection = document.querySelector(`[data-category="${category}"]`);
    if (!categorySection) {
        console.warn(`Category section not found: ${category}`);
        return;
    }
    
    const items = categorySection.querySelectorAll('.item-card');
    const count = items.length;
    const countEl = categorySection.querySelector('.category-count');
    
    if (countEl) {
        countEl.textContent = count;
    }
    
    // Remove category if empty
    if (count === 0) {
        categorySection.style.opacity = '0';
        categorySection.style.transform = 'scale(0.95)';
        
        setTimeout(() => {
            categorySection.remove();
            
            // Check if all categories are gone
            const remainingCategories = document.querySelectorAll('.category-section');
            if (remainingCategories.length === 0) {
                showEmptyState();
            }
        }, 300);
    }
}

/**
 * Show empty state - Enhanced to preserve other elements
 */
function showEmptyState() {
    const shoppingContent = document.querySelector('.shopping-content');
    if (!shoppingContent) {
        console.warn('Shopping content container not found');
        return;
    }
    
    // Remove only categories grid and existing empty state
    const categoriesGrid = shoppingContent.querySelector('.categories-grid');
    if (categoriesGrid) {
        categoriesGrid.style.opacity = '0';
        setTimeout(() => categoriesGrid.remove(), 200);
    }
    
    const oldEmptyState = shoppingContent.querySelector('.empty-state');
    if (oldEmptyState) oldEmptyState.remove();
    
    // Hide stats and actions
    const listStats = document.querySelector('.list-stats');
    if (listStats) {
        listStats.style.display = 'none';
    }
    
    const listActions = document.querySelector('.list-actions');
    if (listActions) {
        listActions.style.display = 'none';
    }
    
    // Create and add empty state
    const emptyState = document.createElement('div');
    emptyState.className = 'empty-state glass-card';
    emptyState.innerHTML = `
        <div class="empty-icon">🛒</div>
        <h2>Your list is empty</h2>
        <p>Add items using voice, the form above, or quick-add from frequently bought</p>
    `;
    
    emptyState.style.opacity = '0';
    shoppingContent.appendChild(emptyState);
    
    requestAnimationFrame(() => {
        emptyState.style.transition = 'opacity 0.3s ease';
        emptyState.style.opacity = '1';
    });
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

/**
 * Show modal
 */
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Focus first input
        const firstInput = modal.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), textarea, select');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }
}

/**
 * Close modal
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

/**
 * Show toast notification - Enhanced
 */
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.shopping-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `shopping-toast toast-${type}`;
    
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    toast.innerHTML = `
        <span class="toast-icon">${icons[type] || icons.info}</span>
        <span class="toast-message">${escapeHtml(message)}</span>
    `;
    
    document.body.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ============================================
// SMART SUGGESTIONS
// ============================================
const commonItems = {
    'dairy': ['Milk', 'Cheese', 'Yogurt', 'Butter', 'Cream', 'Eggs'],
    'meat': ['Chicken', 'Beef', 'Pork', 'Fish', 'Sausage', 'Bacon'],
    'produce': ['Apples', 'Bananas', 'Tomatoes', 'Lettuce', 'Onions', 'Potatoes'],
    'bakery': ['Bread', 'Rolls', 'Croissants', 'Bagels', 'Muffins'],
    'pantry': ['Rice', 'Pasta', 'Flour', 'Sugar', 'Salt', 'Oil'],
    'frozen': ['Ice Cream', 'Pizza', 'Vegetables', 'Chicken Nuggets'],
    'snacks': ['Chips', 'Cookies', 'Chocolate', 'Popcorn', 'Nuts'],
    'beverages': ['Coffee', 'Tea', 'Juice', 'Soda', 'Water'],
    'household': ['Toilet Paper', 'Paper Towels', 'Dish Soap', 'Detergent']
};

function initSmartSuggestions() {
    const itemNameInput = document.getElementById('itemName');
    const suggestionsContainer = document.getElementById('suggestions');
    const suggestionsList = document.getElementById('suggestionsList');
    
    if (!itemNameInput || !suggestionsContainer || !suggestionsList) return;
    
    const showSuggestions = debounce((query) => {
        const parsed = parseSmartInput(query);
        const searchQuery = parsed.name.toLowerCase();
        
        if (searchQuery.length < 2) {
            suggestionsContainer.style.display = 'none';
            return;
        }
        
        const suggestions = [];
        const seen = new Set();
        
        for (const items of Object.values(commonItems)) {
            for (const item of items) {
                const itemLower = item.toLowerCase();
                if (itemLower.includes(searchQuery) && !seen.has(itemLower)) {
                    suggestions.push(item);
                    seen.add(itemLower);
                }
            }
        }
        
        if (suggestions.length === 0) {
            suggestionsContainer.style.display = 'none';
            return;
        }
        
        suggestionsList.innerHTML = suggestions.slice(0, 5).map(item => 
            `<div class="suggestion-item" onclick="applySuggestion('${escapeHtml(item).replace(/'/g, "\\'")}')">${escapeHtml(item)}</div>`
        ).join('');
        
        suggestionsContainer.style.display = 'block';
    }, 300);
    
    itemNameInput.addEventListener('input', (e) => {
        showSuggestions(e.target.value.trim());
    });
}

function applySuggestion(itemName) {
    const itemNameInput = document.getElementById('itemName');
    if (itemNameInput) {
        itemNameInput.value = itemName;
        itemNameInput.focus();
    }
    
    const suggestionsContainer = document.getElementById('suggestions');
    if (suggestionsContainer) {
        suggestionsContainer.style.display = 'none';
    }
    
    const qtyInput = document.getElementById('itemQty');
    if (qtyInput) {
        qtyInput.focus();
    }
}

// ============================================
// FREQUENT ITEMS QUICK ADD
// ============================================
async function quickAddFrequent(event, itemName, category) {
    // Get the button that was clicked
    const button = event?.target?.closest('.frequent-chip');
    
    // Show loading state
    if (button) {
        button.disabled = true;
        button.style.opacity = '0.6';
        button.style.cursor = 'wait';
    }
    
    try {
        const result = await apiCall(ShoppingApp.API.items, {
            action: 'add',
            list_id: ShoppingApp.currentListId,
            name: itemName,
            category: category
        });
        
        showToast(`✓ Added "${itemName}"!`, 'success');
        
        addItemToDOM({
            id: result.item_id,
            name: itemName,
            category: category,
            status: 'pending',
            added_by_name: ShoppingApp.currentUser.name
        });
        
        updateProgressBar();
        
    } catch (error) {
        showToast(error.message || 'Failed to add item', 'error');
        console.error('Quick add error:', error);
    } finally {
        // Restore button state
        if (button) {
            button.disabled = false;
            button.style.opacity = '1';
            button.style.cursor = 'pointer';
        }
    }
}

// ============================================
// LIST MANAGEMENT
// ============================================

function showCreateListModal() {
    document.getElementById('listModalTitle').textContent = '📝 Create Shopping List';
    document.getElementById('listId').value = '';
    document.getElementById('listName').value = '';
    
    document.querySelectorAll('input[name="listIcon"]').forEach(input => {
        input.checked = false;
    });
    document.getElementById('icon1').checked = true;
    
    showModal('listModal');
}

async function saveList(event) {
    if (event) event.preventDefault();
    
    const listId = document.getElementById('listId').value;
    const name = document.getElementById('listName').value.trim();
    const iconInput = document.querySelector('input[name="listIcon"]:checked');
    const icon = iconInput ? iconInput.value : '🛒';
    
    if (!name) {
        showToast('Please enter a list name', 'error');
        return;
    }
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';
    
    try {
        if (listId) {
            await apiCall('/shopping/api/lists.php', {
                action: 'update',
                list_id: listId,
                name: name,
                icon: icon
            });
            
            showToast('List updated!', 'success');
            closeModal('listModal');
            
            setTimeout(() => location.reload(), 500);
            
        } else {
            const result = await apiCall('/shopping/api/lists.php', {
                action: 'create',
                name: name,
                icon: icon
            });
            
            showToast(`Created list "${name}"!`, 'success');
            closeModal('listModal');
            
            setTimeout(() => {
                window.location.href = '?list=' + result.list_id;
            }, 1000);
        }
        
    } catch (error) {
        showToast(error.message || 'Failed to save list', 'error');
        console.error('Save list error:', error);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

async function deleteList(listId) {
    if (!confirm('Delete this list? All items will be removed.')) {
        return;
    }
    
    try {
        await apiCall('/shopping/api/lists.php', {
            action: 'delete',
            list_id: listId
        });
        
        showToast('List deleted!', 'success');
        
        setTimeout(() => {
            window.location.href = '/shopping/';
        }, 1000);
        
    } catch (error) {
        showToast(error.message || 'Failed to delete list', 'error');
    }
}

// ============================================
// ANALYTICS & SHARING
// ============================================

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
        const result = await apiCall(ShoppingApp.API.items, { 
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

async function shareList() {
    showModal('shareModal');
    
    const shareLink = window.location.origin + '/shopping/?list=' + ShoppingApp.currentListId + '&share=1';
    const shareLinkInput = document.getElementById('shareLink');
    if (shareLinkInput) {
        shareLinkInput.value = shareLink;
    }
}

function copyShareLink() {
    const input = document.getElementById('shareLink');
    if (!input) return;
    
    input.select();
    input.setSelectionRange(0, 99999);
    
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(() => {
                showToast('Link copied!', 'success');
            }).catch(() => {
                document.execCommand('copy');
                showToast('Link copied!', 'success');
            });
        } else {
            document.execCommand('copy');
            showToast('Link copied!', 'success');
        }
    } catch (err) {
        showToast('Failed to copy link', 'error');
        console.error('Copy error:', err);
    }
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


function exportList() {
    showModal('shareModal');
}

function exportListAs(format) {
    closeModal('shareModal');

    const items = ShoppingApp.allItems || [];
    if (items.length === 0) {
        showToast('No items to export', 'error');
        return;
    }

    // Get list name from active tab
    const activeTab = document.querySelector('.list-tab.active');
    const listName = activeTab ? activeTab.textContent.trim() : 'Shopping List';

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
        const cat = '"' + (item.category || 'other') + '"';
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

// ============================================
// ADD ITEM MODAL FUNCTIONS
// ============================================

/**
 * Show the Add Item modal
 */
function showAddItemModal() {
    // Reset form
    const form = document.getElementById('addItemForm');
    if (form) form.reset();

    showModal('addItemModal');
}

/**
 * Submit Add Item form
 */
async function submitAddItem(event) {
    if (event) event.preventDefault();

    const nameInput = document.getElementById('itemName');
    const qtyInput = document.getElementById('itemQty');
    const priceInput = document.getElementById('itemPrice');
    const categorySelect = document.getElementById('itemCategory');

    const name = nameInput?.value?.trim();

    if (!name) {
        showToast('Please enter item name', 'error');
        if (nameInput) nameInput.focus();
        return;
    }

    // Get submit button
    const submitBtn = event?.target?.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn?.innerHTML || 'Add Item';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Adding...';
    }

    try {
        // Parse smart input
        const parsed = parseSmartInput(name);

        // Build data object
        const data = {
            action: 'add',
            list_id: ShoppingApp.currentListId,
            name: parsed.name
        };

        // Add optional fields
        const qty = qtyInput?.value?.trim() || parsed.qty;
        if (qty) data.qty = qty;

        const price = priceInput?.value?.trim();
        if (price && parseFloat(price) > 0) data.price = price;

        const category = categorySelect?.value;
        if (category && category !== 'other') data.category = category;

        // Call API
        const result = await apiCall(ShoppingApp.API.items, data);

        showToast(`Added "${parsed.name}"!`, 'success');

        // Close modal and clear form
        closeModal('addItemModal');
        if (nameInput) nameInput.value = '';
        if (qtyInput) qtyInput.value = '';
        if (priceInput) priceInput.value = '';
        if (categorySelect) categorySelect.value = 'other';

        // Add to DOM instantly
        addItemToDOM({
            id: result.item_id,
            name: parsed.name,
            qty: qty || null,
            price: price || null,
            category: category || 'other',
            status: 'pending',
            added_by_name: ShoppingApp.currentUser.name,
            avatar_color: '#667eea'
        });

        updateProgressBar();
        updateClearBoughtButton();

    } catch (error) {
        showToast(error.message || 'Failed to add item', 'error');
        console.error('Add item error:', error);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    }
}

/**
 * Quick add from modal frequent items
 */
async function quickAddFromModal(itemName, category) {
    try {
        const result = await apiCall(ShoppingApp.API.items, {
            action: 'add',
            list_id: ShoppingApp.currentListId,
            name: itemName,
            category: category || 'other'
        });

        showToast(`Added "${itemName}"!`, 'success');
        closeModal('addItemModal');

        addItemToDOM({
            id: result.item_id,
            name: itemName,
            category: category || 'other',
            status: 'pending',
            added_by_name: ShoppingApp.currentUser.name
        });

        updateProgressBar();
        updateClearBoughtButton();

    } catch (error) {
        showToast(error.message || 'Failed to add item', 'error');
        console.error('Quick add error:', error);
    }
}

// ============================================
// LIST EDIT FUNCTION
// ============================================

/**
 * Edit existing list (show modal with current values)
 */
function editList(listId, listName, listIcon) {
    document.getElementById('listModalTitle').textContent = '✏️ Edit Shopping List';
    document.getElementById('listId').value = listId;
    document.getElementById('listName').value = listName;

    // Set the correct icon
    document.querySelectorAll('input[name="listIcon"]').forEach(input => {
        input.checked = (input.value === listIcon);
    });

    // If no icon matched, default to first
    if (!document.querySelector('input[name="listIcon"]:checked')) {
        document.getElementById('icon1').checked = true;
    }

    showModal('listModal');
}

// ============================================
// GEAR MENU FUNCTIONS
// ============================================

/**
 * Toggle gear dropdown menu (for items)
 */
function toggleGearMenu(event, itemId) {
    event.stopPropagation();

    const menu = document.getElementById(`gearMenu_${itemId}`);
    if (!menu) return;

    const wasActive = menu.classList.contains('active');

    // Close all other menus first
    closeAllGearMenus();
    closeAllListGearMenus();

    // If it was already open, just close (done above)
    if (wasActive) return;

    // Position using fixed coordinates
    const btn = event.currentTarget;
    const rect = btn.getBoundingClientRect();

    let top = rect.bottom + 4;
    let left = rect.right - 130;

    // Keep within viewport bounds
    if (left < 8) left = 8;
    if (left + 130 > window.innerWidth) left = window.innerWidth - 138;

    // If it would go below viewport, show above the button
    if (top + 90 > window.innerHeight) {
        top = rect.top - 90;
    }

    menu.style.top = top + 'px';
    menu.style.left = left + 'px';
    menu.style.right = 'auto';
    menu.classList.add('active');
}

/**
 * Close all gear dropdown menus (items)
 */
function closeAllGearMenus() {
    document.querySelectorAll('.gear-dropdown.active').forEach(menu => {
        menu.classList.remove('active');
    });
}

/**
 * Toggle list gear dropdown menu
 */
function toggleListGearMenu(event, listId) {
    event.stopPropagation();

    const menu = document.getElementById(`listGearMenu_${listId}`);
    const btn = event.currentTarget;
    if (!menu || !btn) return;

    // Close all other menus first
    closeAllGearMenus();
    closeAllListGearMenus();

    // Position the dropdown using fixed positioning
    const rect = btn.getBoundingClientRect();
    menu.style.top = (rect.bottom + 4) + 'px';
    menu.style.left = Math.max(10, rect.right - 110) + 'px';

    // Toggle this menu
    menu.classList.toggle('active');
}

/**
 * Close all list gear dropdown menus
 */
function closeAllListGearMenus() {
    document.querySelectorAll('.list-gear-dropdown.active').forEach(menu => {
        menu.classList.remove('active');
    });
}

// Close all gear menus when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.item-gear-menu')) {
        closeAllGearMenus();
    }
    if (!e.target.closest('.list-gear-menu')) {
        closeAllListGearMenus();
    }
});

// Close fixed dropdowns on scroll so they don't float away from their item
window.addEventListener('scroll', () => {
    closeAllGearMenus();
    closeAllListGearMenus();
}, { passive: true });

// ============================================
// AJAX LIST SWITCHING
// ============================================

/**
 * Switch to a different list without page refresh
 */
async function switchList(listId) {
    if (listId === ShoppingApp.currentListId) return;

    // Update UI immediately for responsiveness
    document.querySelectorAll('.list-tab-wrapper').forEach(wrapper => {
        wrapper.classList.remove('active');
        wrapper.querySelector('.list-tab').classList.remove('active');
    });

    const newWrapper = document.querySelector(`.list-tab-wrapper[data-list-id="${listId}"]`);
    if (newWrapper) {
        newWrapper.classList.add('active');
        newWrapper.querySelector('.list-tab').classList.add('active');
    }

    // Show loading state
    const categoriesGrid = document.querySelector('.categories-grid');
    if (categoriesGrid) {
        categoriesGrid.style.opacity = '0.5';
    }

    try {
        // Fetch list data via AJAX
        const result = await apiCall(ShoppingApp.API.lists, {
            action: 'get_items',
            list_id: listId
        }, 'GET');

        if (result.success) {
            // Update current list ID
            ShoppingApp.currentListId = listId;

            // Update URL without refresh
            const newUrl = `${window.location.pathname}?list=${listId}`;
            window.history.pushState({ listId: listId }, '', newUrl);

            // Render new items
            renderListItems(result.items, result.categories);

            // Update stats
            updateListStats(result.stats);

            // Update progress bar
            updateProgressBar();

            // Reset real-time updates timestamp
            RealTimeUpdates.lastUpdate = Date.now();
        }
    } catch (error) {
        console.error('Error switching list:', error);
        showToast('Failed to load list', 'error');
        // Fallback to page refresh
        window.location.href = `?list=${listId}`;
    }

    if (categoriesGrid) {
        categoriesGrid.style.opacity = '1';
    }
}

/**
 * Render list items in the DOM
 */
function renderListItems(items, categories) {
    const categoriesGrid = document.querySelector('.categories-grid');
    if (!categoriesGrid) return;

    // Group items by category
    const itemsByCategory = {};
    const categoryInfo = {
        'dairy': { icon: '🥛', name: 'Dairy' },
        'meat': { icon: '🥩', name: 'Meat & Seafood' },
        'produce': { icon: '🥬', name: 'Produce' },
        'bakery': { icon: '🍞', name: 'Bakery' },
        'pantry': { icon: '🥫', name: 'Pantry' },
        'frozen': { icon: '🧊', name: 'Frozen' },
        'snacks': { icon: '🍿', name: 'Snacks' },
        'beverages': { icon: '🥤', name: 'Beverages' },
        'household': { icon: '🧹', name: 'Household' },
        'other': { icon: '📦', name: 'Other' }
    };

    items.forEach(item => {
        const cat = item.category || 'other';
        if (!itemsByCategory[cat]) {
            itemsByCategory[cat] = [];
        }
        itemsByCategory[cat].push(item);
    });

    // Check if empty
    if (items.length === 0) {
        categoriesGrid.innerHTML = `
            <div class="glass-card empty-state">
                <div class="empty-icon">🛒</div>
                <h2>List is empty</h2>
                <p>Add your first item above!</p>
            </div>
        `;
        return;
    }

    // Build HTML
    let html = '';
    for (const [category, catItems] of Object.entries(itemsByCategory)) {
        const info = categoryInfo[category] || categoryInfo['other'];
        html += `
            <div class="category-section glass-card" data-category="${category}">
                <div class="category-header">
                    <span class="category-icon">${info.icon}</span>
                    <span class="category-name">${info.name}</span>
                    <span class="category-count">${catItems.length}</span>
                </div>
                <div class="items-list">
        `;

        catItems.forEach(item => {
            const isBought = item.status === 'bought';
            html += buildItemCardHTML(item, isBought);
        });

        html += `
                </div>
            </div>
        `;
    }

    categoriesGrid.innerHTML = html;
}

/**
 * Build item card HTML
 */
function buildItemCardHTML(item, isBought) {
    let detailsHTML = '';
    if (item.qty) detailsHTML += `<span class="item-qty">${escapeHtml(item.qty)}</span>`;
    if (item.price) detailsHTML += `<span class="item-price" onclick="showPriceHistory('${escapeHtml(item.name)}')">R${parseFloat(item.price).toFixed(2)}</span>`;
    if (item.store) detailsHTML += `<span class="item-store">${escapeHtml(item.store)}</span>`;

    let metaHTML = '';
    if (item.added_by_name) metaHTML += `<span class="item-added-by">Added by ${escapeHtml(item.added_by_name)}</span>`;
    if (item.assigned_to_name) metaHTML += `<span class="item-assigned">• For ${escapeHtml(item.assigned_to_name)}</span>`;

    return `
        <div class="item-card ${isBought ? 'bought' : ''}" data-item-id="${item.id}">
            <div class="item-bulk-select">
                <input type="checkbox" class="bulk-checkbox" data-item-id="${item.id}">
            </div>
            <div class="item-checkbox">
                <input type="checkbox" id="item_${item.id}" ${isBought ? 'checked' : ''} onchange="toggleItem(${item.id})">
                <label for="item_${item.id}" class="checkbox-label"></label>
            </div>
            <div class="item-content">
                <div class="item-name">${escapeHtml(item.name)}</div>
                ${detailsHTML ? `<div class="item-details">${detailsHTML}</div>` : ''}
                <div class="item-meta">${metaHTML}</div>
            </div>
            <div class="item-actions">
                <div class="item-gear-menu">
                    <button class="item-action-btn gear-btn" onclick="toggleGearMenu(event, ${item.id})" title="Options">⚙️</button>
                    <div class="gear-dropdown" id="gearMenu_${item.id}">
                        <button onclick="editItem(${item.id}); closeAllGearMenus();" class="gear-option">
                            <span class="gear-icon">✏️</span>
                            <span>Edit</span>
                        </button>
                        <button onclick="deleteItem(${item.id}); closeAllGearMenus();" class="gear-option gear-delete">
                            <span class="gear-icon">🗑️</span>
                            <span>Delete</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Update list stats in the DOM
 */
function updateListStats(stats) {
    if (!stats) return;

    const statItems = document.querySelectorAll('.stat-item');
    if (statItems.length >= 3) {
        const totalEl = statItems[0].querySelector('.stat-value');
        const pendingEl = statItems[1].querySelector('.stat-value');
        const boughtEl = statItems[2].querySelector('.stat-value');

        if (totalEl) totalEl.textContent = stats.total || 0;
        if (pendingEl) pendingEl.textContent = stats.pending || 0;
        if (boughtEl) boughtEl.textContent = stats.bought || 0;
    }

    updateClearBoughtButton();
}

// Handle browser back/forward
window.addEventListener('popstate', (e) => {
    if (e.state && e.state.listId) {
        switchList(e.state.listId);
    }
});

// ============================================
// REAL-TIME UPDATES SYSTEM
// ============================================

const RealTimeUpdates = {
    pollInterval: 10000, // Poll every 10 seconds
    lastUpdate: Date.now(),
    isPolling: false,
    pollTimer: null,

    /**
     * Start real-time polling
     */
    start() {
        if (this.isPolling) return;
        this.isPolling = true;
        this.poll();
        console.log('%c🔄 Real-time updates started', 'color: #43e97b;');
    },

    /**
     * Stop polling
     */
    stop() {
        this.isPolling = false;
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
            this.pollTimer = null;
        }
    },

    /**
     * Poll for updates
     */
    async poll() {
        if (!this.isPolling) return;

        try {
            const result = await apiCall(ShoppingApp.API.items, {
                action: 'poll',
                list_id: ShoppingApp.currentListId,
                since: this.lastUpdate
            }, 'GET');

            if (result.success && result.updates) {
                this.processUpdates(result.updates);
                this.lastUpdate = result.timestamp || Date.now();
            }
        } catch (error) {
            // Silently fail on poll errors
            console.warn('Poll error:', error.message);
        }

        // Schedule next poll
        this.pollTimer = setTimeout(() => this.poll(), this.pollInterval);
    },

    /**
     * Process updates from server
     */
    processUpdates(updates) {
        if (!updates || updates.length === 0) return;

        updates.forEach(update => {
            switch (update.type) {
                case 'item_added':
                    if (!document.querySelector(`[data-item-id="${update.item.id}"]`)) {
                        addItemToDOM(update.item);
                        showToast(`${update.user} added "${update.item.name}"`, 'info');
                    }
                    break;

                case 'item_updated':
                    updateItemInDOM(update.item.id, update.item);
                    break;

                case 'item_toggled':
                    this.handleItemToggled(update.item);
                    break;

                case 'item_deleted':
                    this.handleItemDeleted(update.item_id);
                    break;

                case 'items_cleared':
                    this.handleItemsCleared();
                    break;

                case 'list_updated':
                    // Refresh the page for list-level changes
                    location.reload();
                    break;
            }
        });

        // Update UI
        updateProgressBar();
        updateClearBoughtButton();
    },

    /**
     * Handle item toggled
     */
    handleItemToggled(item) {
        const itemCard = document.querySelector(`[data-item-id="${item.id}"]`);
        if (!itemCard) return;

        const checkbox = itemCard.querySelector('.item-checkbox input[type="checkbox"]');

        if (item.status === 'bought') {
            itemCard.classList.add('bought');
            if (checkbox) checkbox.checked = true;
        } else {
            itemCard.classList.remove('bought');
            if (checkbox) checkbox.checked = false;
        }
    },

    /**
     * Handle item deleted
     */
    handleItemDeleted(itemId) {
        const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
        if (!itemCard) return;

        const category = itemCard.closest('.category-section')?.getAttribute('data-category');

        itemCard.style.opacity = '0';
        itemCard.style.transform = 'scale(0.8)';

        setTimeout(() => {
            itemCard.remove();
            if (category) updateCategoryCount(category);

            // Check for empty state
            if (document.querySelectorAll('.item-card').length === 0) {
                showEmptyState();
            }
        }, 300);
    },

    /**
     * Handle items cleared
     */
    handleItemsCleared() {
        document.querySelectorAll('.item-card.bought').forEach(card => {
            const category = card.closest('.category-section')?.getAttribute('data-category');
            card.remove();
            if (category) updateCategoryCount(category);
        });

        if (document.querySelectorAll('.item-card').length === 0) {
            showEmptyState();
        }
    }
};

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
function initKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + N - Focus on add item input
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            const itemNameInput = document.getElementById('itemName');
            if (itemNameInput) itemNameInput.focus();
        }
        
        // Escape - Close modals and exit bulk mode
        if (e.key === 'Escape') {
            const activeModals = document.querySelectorAll('.modal.active');
            if (activeModals.length > 0) {
                activeModals.forEach(modal => closeModal(modal.id));
            } else if (ShoppingApp.bulkMode) {
                toggleBulkMode();
            }
        }
        
        // Ctrl/Cmd + S - Save (prevent browser save dialog)
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const activeModal = document.querySelector('.modal.active');
            if (activeModal) {
                const submitBtn = activeModal.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.click();
            }
        }
    });
}

// ============================================
// INITIALIZE APP
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    console.log('%c✨ Shopping List Ready v2.0!', 'font-size: 14px; font-weight: bold; color: #43e97b;');

    // Initialize features
    initSmartSuggestions();
    initKeyboardShortcuts();
    updateProgressBar();
    updateClearBoughtButton();
    
    // Close modals on backdrop click
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) {
            closeModal(e.target.id);
        }
        
        // Hide suggestions when clicking outside
        const suggestions = document.getElementById('suggestions');
        const itemName = document.getElementById('itemName');
        if (suggestions && itemName && 
            !suggestions.contains(e.target) && 
            e.target !== itemName) {
            suggestions.style.display = 'none';
        }
    });
    
    // Form event listeners
    const quickAddForm = document.getElementById('quickAddForm');
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', addItem);
    }
    
    const listForm = document.getElementById('listForm');
    if (listForm) {
        listForm.addEventListener('submit', saveList);
    }
    
    const editItemForm = document.getElementById('editItemForm');
    if (editItemForm) {
        editItemForm.addEventListener('submit', saveEditedItem);
    }
    
    // Start real-time updates
    RealTimeUpdates.start();

    // Stop polling when page is hidden
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            RealTimeUpdates.stop();
        } else {
            RealTimeUpdates.start();
        }
    });

    console.log('%c💡 Tips:', 'font-size: 12px; color: #43e97b;');
    console.log('  • Ctrl+N to add new item');
    console.log('  • Ctrl+B to toggle bulk mode');
    console.log('  • ESC to close modals');
    console.log('  • Type "2L Milk" for smart qty parsing');
    console.log('  • Real-time sync enabled');
});

// ============================================
// EXPORT FUNCTIONS TO GLOBAL SCOPE
// ============================================
window.ShoppingApp = ShoppingApp;
window.addItem = addItem;
window.toggleItem = toggleItem;
window.deleteItem = deleteItem;
window.editItem = editItem;
window.saveEditedItem = saveEditedItem;
window.clearBought = clearBought;
window.clearBoughtHero = clearBoughtHero;
window.updateClearBoughtButton = updateClearBoughtButton;
window.quickAddFrequent = quickAddFrequent;
window.applySuggestion = applySuggestion;
window.showModal = showModal;
window.closeModal = closeModal;
window.showCreateListModal = showCreateListModal;
window.saveList = saveList;
window.deleteList = deleteList;
window.editList = editList;
window.showAddItemModal = showAddItemModal;
window.submitAddItem = submitAddItem;
window.quickAddFromModal = quickAddFromModal;
window.showAnalytics = showAnalytics;
window.showPriceHistory = showPriceHistory;
window.shareList = shareList;
window.copyShareLink = copyShareLink;
window.shareViaWhatsApp = shareViaWhatsApp;
window.exportList = exportList;
window.exportListAs = exportListAs;
window.toggleGearMenu = toggleGearMenu;
window.closeAllGearMenus = closeAllGearMenus;
window.toggleListGearMenu = toggleListGearMenu;
window.closeAllListGearMenus = closeAllListGearMenus;
window.switchList = switchList;
window.renderListItems = renderListItems;
window.buildItemCardHTML = buildItemCardHTML;
window.updateListStats = updateListStats;
window.RealTimeUpdates = RealTimeUpdates;