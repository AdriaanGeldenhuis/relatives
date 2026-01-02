/**
 * BILLING PAGE - JavaScript
 */

function confirmCancel() {
    showModal('cancelModal');
}

function closeCancelModal() {
    closeModal('cancelModal');
}

async function cancelSubscription() {
    if (!confirm('Are you absolutely sure? This cannot be undone immediately.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'cancel_subscription');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            Toast.success('Subscription cancelled successfully');
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(data.error || 'Failed to cancel');
        }
        
    } catch (error) {
        Toast.error(error.message);
    }
}

function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal on backdrop click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        closeModal(e.target.id);
    }
});

// ESC to close
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            closeModal(modal.id);
        });
    }
});

console.log('✅ Billing page loaded');