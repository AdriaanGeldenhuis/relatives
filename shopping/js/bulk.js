/**
 * ============================================
 * RELATIVES SHOPPING - BULK OPERATIONS 2.0
 * Enhanced with Better State Management
 * ============================================
 */

console.log('%c☑️ Bulk Operations Loading v2.0...', 'font-size: 14px; font-weight: bold; color: #f093fb;');

// ============================================
// BULK MODE TOGGLE - ENHANCED
// ============================================

/**
 * Toggle bulk selection mode
 */
function toggleBulkMode() {
    ShoppingApp.bulkMode = !ShoppingApp.bulkMode;
    
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const bulkModeBtn = document.getElementById('bulkModeBtn');
    const bulkSelects = document.querySelectorAll('.item-bulk-select');
    const regularCheckboxes = document.querySelectorAll('.item-checkbox');
    
    if (ShoppingApp.bulkMode) {
        // ENTERING BULK MODE
        if (bulkActionsBar) bulkActionsBar.style.display = 'flex';
        if (bulkModeBtn) bulkModeBtn.classList.add('active');
        
        // Show bulk checkboxes, hide and disable regular
        bulkSelects.forEach(el => {
            el.style.display = 'flex';
        });
        
        regularCheckboxes.forEach(el => {
            el.style.display = 'none';
            const checkbox = el.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.disabled = true;
            }
        });
        
        // Clear selections
        ShoppingApp.selectedItems.clear();
        updateBulkCount();
        
        showToast('✅ Bulk mode enabled - Select items', 'info');
        
    } else {
        // EXITING BULK MODE
        if (bulkActionsBar) bulkActionsBar.style.display = 'none';
        if (bulkModeBtn) bulkModeBtn.classList.remove('active');
        
        // Hide bulk checkboxes, show and enable regular
        bulkSelects.forEach(el => {
            el.style.display = 'none';
        });
        
        regularCheckboxes.forEach(el => {
            el.style.display = 'flex';
            const checkbox = el.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.disabled = false;
            }
        });
        
        // Clear selections
        ShoppingApp.selectedItems.clear();
        document.querySelectorAll('.bulk-checkbox').forEach(cb => {
            cb.checked = false;
        });
        
        showToast('Bulk mode disabled', 'info');
    }
}

/**
 * Update bulk selected count and button states
 */
function updateBulkCount() {
    const countEl = document.getElementById('bulkSelectedCount');
    if (countEl) {
        countEl.textContent = ShoppingApp.selectedItems.size;
    }
    
    // Enable/disable action buttons based on selection
    const bulkButtons = document.querySelectorAll('.bulk-buttons .btn:not(:last-child)');
    const hasSelection = ShoppingApp.selectedItems.size > 0;
    
    bulkButtons.forEach(btn => {
        btn.disabled = !hasSelection;
        btn.style.opacity = hasSelection ? '1' : '0.5';
        btn.style.cursor = hasSelection ? 'pointer' : 'not-allowed';
        btn.style.pointerEvents = hasSelection ? 'auto' : 'none';
    });
}

/**
 * Handle bulk checkbox change
 */
document.addEventListener('change', (e) => {
    if (e.target.classList.contains('bulk-checkbox')) {
        const itemId = parseInt(e.target.dataset.itemId);
        
        if (e.target.checked) {
            ShoppingApp.selectedItems.add(itemId);
        } else {
            ShoppingApp.selectedItems.delete(itemId);
        }
        
        updateBulkCount();
    }
});

/**
 * Select all items in view
 */
function bulkSelectAll() {
    document.querySelectorAll('.bulk-checkbox').forEach(cb => {
        cb.checked = true;
        ShoppingApp.selectedItems.add(parseInt(cb.dataset.itemId));
    });
    
    updateBulkCount();
    showToast(`Selected ${ShoppingApp.selectedItems.size} items`, 'success');
}

/**
 * Deselect all items
 */
function bulkDeselectAll() {
    document.querySelectorAll('.bulk-checkbox').forEach(cb => {
        cb.checked = false;
    });
    
    ShoppingApp.selectedItems.clear();
    updateBulkCount();
    showToast('All items deselected', 'info');
}

// ============================================
// BULK DELETE - ENHANCED
// ============================================

/**
 * Bulk delete items with confirmation
 */
async function bulkDelete() {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        return;
    }
    
    const count = ShoppingApp.selectedItems.size;
    const itemIds = Array.from(ShoppingApp.selectedItems);
    
    if (!confirm(`Delete ${count} selected item${count > 1 ? 's' : ''}? This cannot be undone.`)) {
        return;
    }
    
    // Show loading state
    const deleteBtn = document.querySelector('.bulk-buttons .btn-danger');
    if (deleteBtn) {
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<span class="btn-icon">⏳</span><span>Deleting...</span>';
    }
    
    try {
        const result = await apiCall(ShoppingApp.API.bulk, {
            action: 'delete',
            item_ids: JSON.stringify(itemIds)
        });
        
        showToast(`✓ Deleted ${result.count || count} items!`, 'success');
        
        // Remove from DOM with staggered animation
        itemIds.forEach((itemId, index) => {
            setTimeout(() => {
                const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
                if (itemCard) {
                    const category = itemCard.closest('.category-section')?.getAttribute('data-category');
                    
                    itemCard.style.opacity = '0';
                    itemCard.style.transform = 'translateX(-100px)';
                    
                    setTimeout(() => {
                        itemCard.remove();
                        
                        if (category) {
                            updateCategoryCount(category);
                        }
                    }, 300);
                }
            }, index * 50); // Stagger by 50ms
        });
        
        // Exit bulk mode after animations
        setTimeout(() => {
            toggleBulkMode();
            updateProgressBar();
            
            // Show empty state if no items left
            const remainingItems = document.querySelectorAll('.item-card');
            if (remainingItems.length === 0) {
                setTimeout(() => showEmptyState(), 300);
            }
        }, itemIds.length * 50 + 400);
        
    } catch (error) {
        showToast(error.message || 'Failed to delete items', 'error');
        console.error('Bulk delete error:', error);
    } finally {
        // Restore button
        if (deleteBtn) {
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = '<span class="btn-icon">🗑️</span><span>Delete</span>';
        }
    }
}

// ============================================
// BULK MARK BOUGHT - ENHANCED
// ============================================

/**
 * Bulk mark as bought
 */
async function bulkMarkBought() {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        return;
    }
    
    const count = ShoppingApp.selectedItems.size;
    const itemIds = Array.from(ShoppingApp.selectedItems);
    
    // Show loading state
    const boughtBtn = document.querySelector('.bulk-buttons .btn-success');
    if (boughtBtn) {
        boughtBtn.disabled = true;
        boughtBtn.innerHTML = '<span class="btn-icon">⏳</span><span>Updating...</span>';
    }
    
    try {
        const result = await apiCall(ShoppingApp.API.bulk, {
            action: 'mark_bought',
            item_ids: JSON.stringify(itemIds)
        });
        
        showToast(`✓ Marked ${result.count || count} items as bought!`, 'success');
        
        // Update DOM with staggered animation
        itemIds.forEach((itemId, index) => {
            setTimeout(() => {
                const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
                if (itemCard) {
                    itemCard.classList.add('bought');
                    
                    // Check the regular checkbox too
                    const checkbox = itemCard.querySelector('.item-checkbox input[type="checkbox"]');
                    if (checkbox) checkbox.checked = true;
                    
                    // Add bounce animation
                    itemCard.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        itemCard.style.transform = 'scale(1)';
                    }, 200);
                }
            }, index * 50);
        });
        
        // Exit bulk mode after animations
        setTimeout(() => {
            toggleBulkMode();
            updateProgressBar();
            if (typeof updateClearBoughtButton === 'function') {
                updateClearBoughtButton();
            }
        }, itemIds.length * 50 + 300);
        
    } catch (error) {
        showToast(error.message || 'Failed to mark items', 'error');
        console.error('Bulk mark bought error:', error);
    } finally {
        // Restore button
        if (boughtBtn) {
            boughtBtn.disabled = false;
            boughtBtn.innerHTML = '<span class="btn-icon">✓</span><span>Mark Bought</span>';
        }
    }
}

// ============================================
// BULK MOVE CATEGORY - ENHANCED
// ============================================

/**
 * Show bulk category modal
 */
function showBulkCategoryModal() {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        return;
    }
    
    showModal('bulkCategoryModal');
}

/**
 * Apply bulk category change
 */
async function applyBulkCategory(category) {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        closeModal('bulkCategoryModal');
        return;
    }
    
    const count = ShoppingApp.selectedItems.size;
    const itemIds = Array.from(ShoppingApp.selectedItems);
    
    try {
        const result = await apiCall(ShoppingApp.API.bulk, {
            action: 'move_category',
            item_ids: JSON.stringify(itemIds),
            category: category
        });
        
        const catInfo = ShoppingApp.categories[category] || { name: category };
        showToast(`✓ Moved ${result.count || count} items to ${catInfo.name}!`, 'success');
        
        closeModal('bulkCategoryModal');
        
        // Get or create new category section
        let newCategorySection = document.querySelector(`[data-category="${category}"]`);
        
        if (!newCategorySection) {
            const categoriesGrid = document.querySelector('.categories-grid');
            if (!categoriesGrid) {
                console.error('Categories grid not found');
                return;
            }
            
            const categoryInfo = ShoppingApp.categories[category] || { icon: '📦', name: category };
            
            newCategorySection = document.createElement('div');
            newCategorySection.className = 'category-section glass-card';
            newCategorySection.setAttribute('data-category', category);
            newCategorySection.innerHTML = `
                <div class="category-header">
                    <span class="category-icon">${categoryInfo.icon}</span>
                    <span class="category-name">${categoryInfo.name}</span>
                    <span class="category-count">0</span>
                </div>
                <div class="items-list"></div>
            `;
            
            categoriesGrid.appendChild(newCategorySection);
        }
        
        const newItemsList = newCategorySection.querySelector('.items-list');
        
        // Move items with animation
        itemIds.forEach((itemId, index) => {
            setTimeout(() => {
                const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
                if (itemCard) {
                    const oldCategory = itemCard.closest('.category-section')?.getAttribute('data-category');
                    const oldCategorySection = itemCard.closest('.category-section');
                    
                    // Fade out
                    itemCard.style.opacity = '0';
                    itemCard.style.transform = 'translateY(-20px)';
                    
                    setTimeout(() => {
                        // Move to new category
                        newItemsList.appendChild(itemCard);
                        
                        // Fade in
                        itemCard.style.opacity = '1';
                        itemCard.style.transform = 'translateY(0)';
                        
                        // Update old category count
                        if (oldCategory) {
                            updateCategoryCount(oldCategory);
                        }
                    }, 300);
                }
            }, index * 80);
        });
        
        // Update new category count after all moves
        setTimeout(() => {
            updateCategoryCount(category);
            
            // Exit bulk mode
            toggleBulkMode();
        }, itemIds.length * 80 + 400);
        
    } catch (error) {
        showToast(error.message || 'Failed to move items', 'error');
        console.error('Bulk move category error:', error);
        closeModal('bulkCategoryModal');
    }
}

// ============================================
// BULK ASSIGN - ENHANCED
// ============================================

/**
 * Show bulk assign modal
 */
function showBulkAssignModal() {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        return;
    }
    
    showModal('bulkAssignModal');
}

/**
 * Apply bulk assignment
 */
async function applyBulkAssign(userId) {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        closeModal('bulkAssignModal');
        return;
    }
    
    const count = ShoppingApp.selectedItems.size;
    const itemIds = Array.from(ShoppingApp.selectedItems);
    
    try {
        const result = await apiCall(ShoppingApp.API.bulk, {
            action: 'assign',
            item_ids: JSON.stringify(itemIds),
            assign_to: userId
        });
        
        const member = ShoppingApp.familyMembers.find(m => m.id == userId);
        const memberName = member ? member.full_name : 'member';
        
        showToast(`✓ Assigned ${result.count || count} items to ${memberName}!`, 'success');
        
        closeModal('bulkAssignModal');
        
        // Update DOM
        itemIds.forEach((itemId, index) => {
            setTimeout(() => {
                const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
                if (itemCard) {
                    const metaEl = itemCard.querySelector('.item-meta');
                    if (metaEl) {
                        let assignedSpan = metaEl.querySelector('.item-assigned');
                        
                        if (!assignedSpan) {
                            assignedSpan = document.createElement('span');
                            assignedSpan.className = 'item-assigned';
                            metaEl.appendChild(assignedSpan);
                        }
                        
                        assignedSpan.textContent = ` • Assigned to ${memberName}`;
                        
                        // Highlight animation
                        assignedSpan.style.backgroundColor = '#43e97b22';
                        setTimeout(() => {
                            assignedSpan.style.backgroundColor = 'transparent';
                        }, 500);
                    }
                }
            }, index * 50);
        });
        
        // Exit bulk mode
        setTimeout(() => {
            toggleBulkMode();
        }, itemIds.length * 50 + 300);
        
    } catch (error) {
        showToast(error.message || 'Failed to assign items', 'error');
        console.error('Bulk assign error:', error);
        closeModal('bulkAssignModal');
    }
}

// ============================================
// BULK UPDATE PRICES - ENHANCED
// ============================================

/**
 * Bulk update prices with better UX
 */
async function bulkUpdatePrices() {
    if (ShoppingApp.selectedItems.size === 0) {
        showToast('No items selected', 'error');
        return;
    }
    
    const prices = {};
    const itemIds = Array.from(ShoppingApp.selectedItems);
    let cancelled = false;
    
    showToast('Enter prices for selected items...', 'info');
    
    for (const itemId of itemIds) {
        const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
        if (!itemCard) continue;
        
        const itemName = itemCard.getAttribute('data-item-name') || 'Item';
        const currentPriceEl = itemCard.querySelector('.item-price');
        const currentPrice = currentPriceEl ? 
            currentPriceEl.textContent.replace('R', '').trim() : '';
        
        const promptMessage = `Price for "${itemName}"${currentPrice ? ` (current: R${currentPrice})` : ''}:`;
        const newPrice = prompt(promptMessage, currentPrice);
        
        if (newPrice === null) {
            cancelled = true;
            break;
        }
        
        if (newPrice && !isNaN(parseFloat(newPrice))) {
            const priceValue = parseFloat(newPrice);
            if (priceValue >= 0) {
                prices[itemId] = priceValue;
            }
        }
    }
    
    if (cancelled || Object.keys(prices).length === 0) {
        showToast('Price update cancelled', 'info');
        return;
    }
    
    try {
        await apiCall(ShoppingApp.API.bulk, {
            action: 'update_prices',
            prices: JSON.stringify(prices)
        });
        
        showToast(`✓ Updated prices for ${Object.keys(prices).length} items!`, 'success');
        
        // Update DOM
        for (const [itemId, price] of Object.entries(prices)) {
            const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
            if (itemCard) {
                let detailsEl = itemCard.querySelector('.item-details');
                
                if (!detailsEl) {
                    detailsEl = document.createElement('div');
                    detailsEl.className = 'item-details';
                    const contentEl = itemCard.querySelector('.item-content');
                    const nameEl = contentEl.querySelector('.item-name');
                    nameEl.after(detailsEl);
                }
                
                let priceEl = detailsEl.querySelector('.item-price');
                
                if (!priceEl) {
                    priceEl = document.createElement('span');
                    priceEl.className = 'item-price';
                    priceEl.onclick = function() { 
                        showPriceHistory(itemCard.getAttribute('data-item-name')); 
                    };
                    detailsEl.appendChild(priceEl);
                }
                
                priceEl.textContent = `R${parseFloat(price).toFixed(2)}`;
                
                // Highlight animation
                priceEl.style.backgroundColor = '#43e97b33';
                setTimeout(() => {
                    priceEl.style.backgroundColor = 'transparent';
                }, 500);
            }
        }
        
        // Exit bulk mode
        setTimeout(() => {
            toggleBulkMode();
            updateProgressBar();
        }, 500);
        
    } catch (error) {
        showToast(error.message || 'Failed to update prices', 'error');
        console.error('Bulk update prices error:', error);
    }
}

// ============================================
// BULK SELECT BY CATEGORY
// ============================================

/**
 * Select all items in a category
 */
function bulkSelectCategory(category) {
    if (!ShoppingApp.bulkMode) {
        toggleBulkMode();
    }
    
    const categorySection = document.querySelector(`[data-category="${category}"]`);
    if (!categorySection) return;
    
    const checkboxes = categorySection.querySelectorAll('.bulk-checkbox');
    let count = 0;
    
    checkboxes.forEach(cb => {
        if (!cb.checked) {
            cb.checked = true;
            ShoppingApp.selectedItems.add(parseInt(cb.dataset.itemId));
            count++;
        }
    });
    
    updateBulkCount();
    
    if (count > 0) {
        const catInfo = ShoppingApp.categories[category] || { name: category };
        showToast(`Selected ${count} items from ${catInfo.name}`, 'success');
    }
}

// ============================================
// BULK SELECT BY STATUS
// ============================================

/**
 * Select all pending/bought items
 */
function bulkSelectByStatus(status) {
    if (!ShoppingApp.bulkMode) {
        toggleBulkMode();
    }
    
    const items = document.querySelectorAll(`.item-card.${status}`);
    let count = 0;
    
    items.forEach(item => {
        const checkbox = item.querySelector('.bulk-checkbox');
        if (checkbox && !checkbox.checked) {
            checkbox.checked = true;
            ShoppingApp.selectedItems.add(parseInt(checkbox.dataset.itemId));
            count++;
        }
    });
    
    updateBulkCount();
    
    if (count > 0) {
        showToast(`Selected ${count} ${status} items`, 'success');
    }
}

// ============================================
// KEYBOARD SHORTCUTS FOR BULK
// ============================================

document.addEventListener('keydown', (e) => {
    // Ctrl/Cmd + B - Toggle bulk mode
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        toggleBulkMode();
    }
    
    // Ctrl/Cmd + A - Select all (in bulk mode)
    if (ShoppingApp.bulkMode && (e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        bulkSelectAll();
    }
    
    // Ctrl/Cmd + D - Deselect all (in bulk mode)
    if (ShoppingApp.bulkMode && (e.ctrlKey || e.metaKey) && e.key === 'd') {
        e.preventDefault();
        bulkDeselectAll();
    }
    
    // Delete key - Delete selected (in bulk mode)
    if (ShoppingApp.bulkMode && e.key === 'Delete' && ShoppingApp.selectedItems.size > 0) {
        e.preventDefault();
        bulkDelete();
    }
    
    // Ctrl/Cmd + Shift + M - Mark selected as bought (in bulk mode)
    if (ShoppingApp.bulkMode && (e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'M') {
        e.preventDefault();
        if (ShoppingApp.selectedItems.size > 0) {
            bulkMarkBought();
        }
    }
});

// ============================================
// EXPORT FUNCTIONS
// ============================================

window.toggleBulkMode = toggleBulkMode;
window.updateBulkCount = updateBulkCount;
window.bulkSelectAll = bulkSelectAll;
window.bulkDeselectAll = bulkDeselectAll;
window.bulkDelete = bulkDelete;
window.bulkMarkBought = bulkMarkBought;
window.showBulkCategoryModal = showBulkCategoryModal;
window.applyBulkCategory = applyBulkCategory;
window.showBulkAssignModal = showBulkAssignModal;
window.applyBulkAssign = applyBulkAssign;
window.bulkUpdatePrices = bulkUpdatePrices;
window.bulkSelectCategory = bulkSelectCategory;
window.bulkSelectByStatus = bulkSelectByStatus;

console.log('%c✅ Bulk Operations Ready v2.0!', 'font-size: 12px; color: #f093fb;');
console.log('%c💡 Bulk Shortcuts:', 'font-size: 12px; color: #f093fb;');
console.log('  • Ctrl+B - Toggle Bulk Mode');
console.log('  • Ctrl+A - Select All (in bulk mode)');
console.log('  • Ctrl+D - Deselect All (in bulk mode)');
console.log('  • Delete - Delete Selected (in bulk mode)');
console.log('  • Ctrl+Shift+M - Mark Bought (in bulk mode)');