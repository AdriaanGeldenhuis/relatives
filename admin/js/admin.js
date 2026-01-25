/**
 * ============================================
 * RELATIVES v3.0 - ADMIN PANEL
 * Ultimate Admin Experience
 * ============================================
 */

console.log('%c⚙️ Admin Panel v3.0 Loading...', 'font-size: 16px; font-weight: bold; color: #667eea;');

// ============================================
// PARTICLE SYSTEM - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS, class is a no-op stub
// ============================================
class ParticleSystem {
    constructor(canvasId) {
        // Disabled - canvas hidden via CSS for performance
    }
}

// Initialize (no-op)
const particleSystem = new ParticleSystem('particles');

// ============================================
// TOAST NOTIFICATION SYSTEM
// ============================================
class Toast {
    static show(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: '✓',
            error: '✗',
            warning: '⚠',
            info: 'ℹ'
        };
        
        toast.innerHTML = `
            <div class="toast-icon">${icons[type] || icons.info}</div>
            <div class="toast-message">${message}</div>
        `;
        
        document.body.appendChild(toast);
        
        // Trigger animation
        setTimeout(() => toast.classList.add('show'), 10);
        
        // Remove after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
    
    static success(message, duration) {
        this.show(message, 'success', duration);
    }
    
    static error(message, duration) {
        this.show(message, 'error', duration);
    }
    
    static warning(message, duration) {
        this.show(message, 'warning', duration);
    }
    
    static info(message, duration) {
        this.show(message, 'info', duration);
    }
}

// Add toast styles
const toastStyles = document.createElement('style');
toastStyles.textContent = `
    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        padding: 16px 24px;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 10000;
        transform: translateX(400px);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }
    
    .toast.show {
        transform: translateX(0);
        opacity: 1;
    }
    
    .toast-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: bold;
        flex-shrink: 0;
    }
    
    .toast-message {
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .toast-success .toast-icon {
        background: linear-gradient(135deg, #51cf66, #37b24d);
        color: white;
    }
    
    .toast-error .toast-icon {
        background: linear-gradient(135deg, #ff6b6b, #ee5a6f);
        color: white;
    }
    
    .toast-warning .toast-icon {
        background: linear-gradient(135deg, #ffd93d, #ffa94d);
        color: white;
    }
    
    .toast-info .toast-icon {
        background: linear-gradient(135deg, #4facfe, #00f2fe);
        color: white;
    }
    
    @media (max-width: 768px) {
        .toast {
            left: 20px;
            right: 20px;
            transform: translateY(-100px);
        }
        
        .toast.show {
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(toastStyles);

// ============================================
// MODAL MANAGEMENT
// ============================================
class ModalManager {
    static open(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Focus trap
        const focusableElements = modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        
        if (firstElement) {
            setTimeout(() => firstElement.focus(), 100);
        }
        
        // Trap focus
        modal.addEventListener('keydown', function trapFocus(e) {
            if (e.key === 'Tab') {
                if (e.shiftKey) {
                    if (document.activeElement === firstElement) {
                        lastElement.focus();
                        e.preventDefault();
                    }
                } else {
                    if (document.activeElement === lastElement) {
                        firstElement.focus();
                        e.preventDefault();
                    }
                }
            }
        });
    }
    
    static close(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    static closeAll() {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
}

// Global functions for onclick handlers
function showModal(modalId) {
    ModalManager.open(modalId);
}

function closeModal(modalId) {
    ModalManager.close(modalId);
}

// Close on backdrop click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        ModalManager.close(e.target.id);
    }
});

// Close on ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        ModalManager.closeAll();
    }
});

// ============================================
// INVITE CODE MANAGEMENT
// ============================================
function showInviteModal() {
    ModalManager.open('inviteModal');
}

async function copyInviteCode() {
    const codeElement = document.getElementById('inviteCodeDisplay') || 
                        document.getElementById('modalInviteCode');
    
    if (!codeElement) {
        Toast.error('Invite code not found');
        return;
    }
    
    const code = codeElement.textContent.trim();
    
    try {
        await navigator.clipboard.writeText(code);
        Toast.success('📋 Invite code copied to clipboard!');
        
        // Visual feedback
        codeElement.style.transform = 'scale(1.1)';
        setTimeout(() => {
            codeElement.style.transform = 'scale(1)';
        }, 200);
        
    } catch (error) {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = code;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            document.execCommand('copy');
            Toast.success('📋 Invite code copied!');
        } catch (err) {
            Toast.error('Failed to copy. Please copy manually.');
        }
        
        document.body.removeChild(textarea);
    }
}

async function regenerateInviteCode() {
    const confirmed = await showConfirmDialog(
        'Regenerate Invite Code?',
        'The old code will stop working. All family members with the old code will not be able to join.',
        'warning'
    );
    
    if (!confirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'regenerate_invite');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update displays
            const displays = [
                document.getElementById('inviteCodeDisplay'),
                document.getElementById('modalInviteCode')
            ];
            
            displays.forEach(display => {
                if (display) {
                    display.textContent = data.code;
                    display.style.animation = 'none';
                    setTimeout(() => {
                        display.style.animation = 'codeReveal 0.5s ease';
                    }, 10);
                }
            });
            
            Toast.success('🔄 New invite code generated!');
        } else {
            throw new Error(data.error || 'Failed to regenerate code');
        }
        
    } catch (error) {
        Toast.error('❌ ' + error.message);
    }
}

// Add animation for code reveal
const codeAnimStyle = document.createElement('style');
codeAnimStyle.textContent = `
    @keyframes codeReveal {
        0% {
            opacity: 0;
            transform: scale(0.8) rotateY(90deg);
        }
        100% {
            opacity: 1;
            transform: scale(1) rotateY(0deg);
        }
    }
`;
document.head.appendChild(codeAnimStyle);

// ============================================
// INVITE LINK SHARING
// ============================================
function getInviteLink() {
    try {
        const el = document.getElementById('inviteLinkDisplay');
        return el ? el.textContent.trim() : '';
    } catch (e) {
        return '';
    }
}

// Helper to copy text
function copyTextToClipboard(text, successMsg) {
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        ta.setAttribute('readonly', '');
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, 99999);

        let success = false;
        try {
            success = document.execCommand('copy');
        } catch (e) {
            success = false;
        }
        document.body.removeChild(ta);

        if (success) {
            Toast.success(successMsg || 'Copied!');
            return true;
        }
    } catch (e) {}
    return false;
}

function copyInviteLink() {
    try {
        const link = getInviteLink();
        if (!link) {
            Toast.error('Invite link not found');
            return;
        }

        if (copyTextToClipboard(link, '🔗 Invite link copied!')) {
            return;
        }

        // Try clipboard API as backup
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function() {
                Toast.success('🔗 Invite link copied!');
            }).catch(function() {
                Toast.info('Long-press the link to copy');
            });
        } else {
            Toast.info('Long-press the link to copy');
        }
    } catch (err) {
        console.log('copyInviteLink error:', err);
        Toast.info('Long-press the link to copy');
    }
}


// ============================================
// FAMILY SETTINGS MANAGEMENT
// ============================================
function editFamilyName() {
    ModalManager.open('editFamilyNameModal');
    const input = document.getElementById('newFamilyName');
    if (input) {
        setTimeout(() => {
            input.focus();
            input.select();
        }, 100);
    }
}

async function saveFamilyName(event) {
    event.preventDefault();
    
    const newName = document.getElementById('newFamilyName').value.trim();
    
    if (!newName) {
        Toast.error('Family name cannot be empty');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_family_name');
        formData.append('name', newName);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('familyNameDisplay').textContent = newName;
            ModalManager.close('editFamilyNameModal');
            Toast.success('✓ Family name updated!');
        } else {
            throw new Error(data.error || 'Failed to update');
        }
        
    } catch (error) {
        Toast.error('❌ ' + error.message);
    }
}

function editTimezone() {
    ModalManager.open('editTimezoneModal');
}

async function saveTimezone(event) {
    event.preventDefault();
    
    const timezone = document.getElementById('newTimezone').value;
    const displayText = document.querySelector('#newTimezone option:checked').textContent;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_timezone');
        formData.append('timezone', timezone);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('timezoneDisplay').textContent = displayText;
            ModalManager.close('editTimezoneModal');
            Toast.success('🌍 Timezone updated!');
        } else {
            throw new Error(data.error || 'Failed to update');
        }
        
    } catch (error) {
        Toast.error('❌ ' + error.message);
    }
}

// ============================================
// USER MANAGEMENT
// ============================================
async function changeUserRole(userId, newRole) {
    if (newRole === '') {
        return; // "Change Role..." option selected
    }
    
    const roleNames = {
        'member': 'Member',
        'admin': 'Admin'
    };
    
    const confirmed = await showConfirmDialog(
        'Change User Role?',
        `Change this user's role to ${roleNames[newRole]}?`,
        'info'
    );
    
    if (!confirmed) {
        location.reload();
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_role');
        formData.append('user_id', userId);
        formData.append('role', newRole);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            Toast.success(`✓ Role changed to ${roleNames[newRole]}`);
            
            // Animate the card
            const card = document.querySelector(`[data-user-id="${userId}"]`);
            if (card) {
                card.style.animation = 'cardUpdate 0.5s ease';
            }
            
            setTimeout(() => location.reload(), 1000);
        } else {
            throw new Error(data.error || 'Failed to update role');
        }
        
    } catch (error) {
        Toast.error('❌ ' + error.message);
        location.reload();
    }
}

async function toggleUserStatus(userId, newStatus, userName) {
    const isActivating = newStatus === 'active';
    
    const confirmed = await showConfirmDialog(
        isActivating ? 'Activate User?' : 'Deactivate User?',
        isActivating 
            ? `Activate ${userName}? They will regain access to the family hub.`
            : `Deactivate ${userName}? They will lose access to the family hub.`,
        isActivating ? 'success' : 'warning'
    );
    
    if (!confirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_user_status');
        formData.append('user_id', userId);
        formData.append('status', newStatus);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const message = isActivating 
                ? `✓ ${userName} activated!` 
                : `${userName} deactivated`;
            
            Toast.success(message);
            
            // Visual feedback
            const card = document.querySelector(`[data-user-id="${userId}"]`);
            if (card) {
                card.style.transition = 'all 0.3s ease';
                card.style.opacity = '0.5';
                card.style.transform = 'scale(0.95)';
            }
            
            setTimeout(() => location.reload(), 800);
        } else {
            throw new Error(data.error || 'Failed to update status');
        }
        
    } catch (error) {
        Toast.error('❌ ' + error.message);
    }
}

// ============================================
// CUSTOM CONFIRM DIALOG
// ============================================
function showConfirmDialog(title, message, type = 'info') {
    return new Promise((resolve) => {
        const dialog = document.createElement('div');
        dialog.className = 'confirm-dialog';
        
        const icons = {
            success: '✓',
            error: '✗',
            warning: '⚠',
            info: 'ℹ'
        };
        
        dialog.innerHTML = `
            <div class="confirm-backdrop"></div>
            <div class="confirm-content confirm-${type}">
                <div class="confirm-icon">${icons[type]}</div>
                <h3 class="confirm-title">${title}</h3>
                <p class="confirm-message">${message}</p>
                <div class="confirm-actions">
                    <button class="confirm-btn confirm-btn-cancel">Cancel</button>
                    <button class="confirm-btn confirm-btn-confirm">Confirm</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(dialog);
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => dialog.classList.add('active'), 10);
        
        const remove = (result) => {
            dialog.classList.remove('active');
            setTimeout(() => {
                dialog.remove();
                document.body.style.overflow = '';
                resolve(result);
            }, 300);
        };
        
        dialog.querySelector('.confirm-btn-cancel').onclick = () => remove(false);
        dialog.querySelector('.confirm-btn-confirm').onclick = () => remove(true);
        dialog.querySelector('.confirm-backdrop').onclick = () => remove(false);
        
        // ESC to cancel
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                remove(false);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    });
}

// Add confirm dialog styles
const confirmStyles = document.createElement('style');
confirmStyles.textContent = `
    .confirm-dialog {
        position: fixed;
        inset: 0;
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .confirm-dialog.active {
        opacity: 1;
    }
    
    .confirm-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
    }
    
    .confirm-content {
        position: relative;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(40px);
        border-radius: 24px;
        padding: 40px;
        max-width: 400px;
        width: 100%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        transform: scale(0.9) translateY(20px);
        transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }
    
    .confirm-dialog.active .confirm-content {
        transform: scale(1) translateY(0);
    }
    
    .confirm-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        margin: 0 auto 24px;
        font-weight: bold;
    }
    
    .confirm-success .confirm-icon {
        background: linear-gradient(135deg, #51cf66, #37b24d);
        color: white;
    }
    
    .confirm-error .confirm-icon {
        background: linear-gradient(135deg, #ff6b6b, #ee5a6f);
        color: white;
    }
    
    .confirm-warning .confirm-icon {
        background: linear-gradient(135deg, #ffd93d, #ffa94d);
        color: white;
    }
    
    .confirm-info .confirm-icon {
        background: linear-gradient(135deg, #4facfe, #00f2fe);
        color: white;
    }
    
    .confirm-title {
        font-size: 24px;
        font-weight: 900;
        color: #333;
        margin-bottom: 12px;
    }
    
    .confirm-message {
        font-size: 15px;
        color: #666;
        line-height: 1.6;
        margin-bottom: 32px;
    }
    
    .confirm-actions {
        display: flex;
        gap: 12px;
    }
    
    .confirm-btn {
        flex: 1;
        padding: 14px 24px;
        border: none;
        border-radius: 12px;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }
    
    .confirm-btn-cancel {
        background: #e0e0e0;
        color: #666;
    }
    
    .confirm-btn-cancel:hover {
        background: #d0d0d0;
        transform: translateY(-2px);
    }
    
    .confirm-btn-confirm {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }
    
    .confirm-btn-confirm:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
    }
    
    @media (max-width: 480px) {
        .confirm-content {
            padding: 32px 24px;
        }
        
        .confirm-actions {
            flex-direction: column-reverse;
        }
    }
`;
document.head.appendChild(confirmStyles);

// ============================================
// ANIMATIONS & INTERACTIONS
// ============================================

// Number animation
function animateNumber(element, start, end, duration) {
    const startTime = performance.now();
    const range = end - start;
    
    function updateNumber(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        const easeOutElastic = (t) => {
            const c4 = (2 * Math.PI) / 3;
            return t === 0 ? 0 : t === 1 ? 1 :
                Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * c4) + 1;
        };
        
        const current = Math.floor(start + range * easeOutElastic(progress));
        element.textContent = current;
        
        if (progress < 1) {
            requestAnimationFrame(updateNumber);
        } else {
            element.textContent = end;
        }
    }
    
    requestAnimationFrame(updateNumber);
}

// 3D Tilt Effect
class TiltEffect {
    constructor(element) {
        this.element = element;
        this.width = element.offsetWidth;
        this.height = element.offsetHeight;
        this.settings = {
            max: 10,
            perspective: 1200,
            scale: 1.03,
            speed: 400,
            easing: 'cubic-bezier(0.03, 0.98, 0.52, 0.99)'
        };
        
        this.init();
    }
    
    init() {
        this.element.style.transform = 'perspective(1200px) rotateX(0deg) rotateY(0deg)';
        this.element.style.transition = `transform ${this.settings.speed}ms ${this.settings.easing}`;
        
        this.element.addEventListener('mouseenter', () => this.onMouseEnter());
        this.element.addEventListener('mousemove', (e) => this.onMouseMove(e));
        this.element.addEventListener('mouseleave', () => this.onMouseLeave());
    }
    
    onMouseEnter() {
        this.width = this.element.offsetWidth;
        this.height = this.element.offsetHeight;
    }
    
    onMouseMove(e) {
        const rect = this.element.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        const percentX = (x / this.width) - 0.5;
        const percentY = (y / this.height) - 0.5;
        
        const tiltX = percentY * this.settings.max;
        const tiltY = -percentX * this.settings.max;
        
        this.element.style.transform = `
            perspective(${this.settings.perspective}px) 
            rotateX(${tiltX}deg) 
            rotateY(${tiltY}deg) 
            scale3d(${this.settings.scale}, ${this.settings.scale}, ${this.settings.scale})
        `;
    }
    
    onMouseLeave() {
        this.element.style.transform = `
            perspective(${this.settings.perspective}px) 
            rotateX(0deg) 
            rotateY(0deg) 
            scale3d(1, 1, 1)
        `;
    }
}

// Add card update animation
const cardUpdateStyle = document.createElement('style');
cardUpdateStyle.textContent = `
    @keyframes cardUpdate {
        0%, 100% {
            transform: scale(1);
        }
        25% {
            transform: scale(0.95) rotate(-2deg);
        }
        75% {
            transform: scale(1.05) rotate(2deg);
        }
    }
`;
document.head.appendChild(cardUpdateStyle);

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    console.log('%c✅ Admin Panel v3.0 Initialized', 'font-size: 16px; font-weight: bold; color: #51cf66;');
    
    // Apply tilt to stat cards
    document.querySelectorAll('[data-tilt]').forEach(card => {
        new TiltEffect(card);
    });
    
    // Animate stat numbers on scroll
    const statNumbers = document.querySelectorAll('.stat-number[data-count]');
    const miniStatValues = document.querySelectorAll('.mini-stat-value');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('animated')) {
                const text = entry.target.textContent;
                const num = parseInt(entry.target.dataset.count || text);
                
                if (!isNaN(num)) {
                    animateNumber(entry.target, 0, num, 2000);
                    entry.target.classList.add('animated');
                }
            }
        });
    }, { threshold: 0.3 });
    
    statNumbers.forEach(num => observer.observe(num));
    miniStatValues.forEach(val => observer.observe(val));
    
    // Animate progress bars
    const progressBars = document.querySelectorAll('.progress-fill');
    const progressObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('animated')) {
                const targetWidth = entry.target.style.width;
                entry.target.style.width = '0%';
                
                setTimeout(() => {
                    entry.target.style.width = targetWidth;
                    entry.target.classList.add('animated');
                }, 100);
            }
        });
    }, { threshold: 0.5 });
    
    progressBars.forEach(bar => progressObserver.observe(bar));
    
    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + I = Invite
        if ((e.ctrlKey || e.metaKey) && e.key === 'i') {
            e.preventDefault();
            showInviteModal();
        }
        
        // Ctrl/Cmd + E = Edit family name (owner only)
        if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
            const editBtn = document.querySelector('[onclick="editFamilyName()"]');
            if (editBtn) {
                e.preventDefault();
                editFamilyName();
            }
        }
        
        // Ctrl/Cmd + K = Copy invite code
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            copyInviteCode();
        }
    });
    
    // Auto-refresh activity every 60 seconds
    setInterval(() => {
        console.log('🔄 Checking for updates...');
        // Could add AJAX refresh here
    }, 60000);
    
    // Add hover effects to setting cards
    document.querySelectorAll('.setting-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.querySelector('.setting-icon').style.transform = 'scale(1.1) rotate(5deg)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.querySelector('.setting-icon').style.transform = 'scale(1) rotate(0deg)';
        });
    });
    
    // Smooth scroll to sections
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Log helpful tips
    console.log('%c💡 Keyboard Shortcuts:', 'font-size: 14px; font-weight: bold; color: #4facfe;');
    console.log('  Ctrl/Cmd + I → Open Invite Modal');
    console.log('  Ctrl/Cmd + E → Edit Family Name');
    console.log('  Ctrl/Cmd + K → Copy Invite Code');
    console.log('  ESC → Close Modals');
});

// ============================================
// PAGE TRANSITIONS
// ============================================
document.querySelectorAll('a[href^="/"]').forEach(link => {
    if (link.getAttribute('href') && !link.getAttribute('target')) {
        link.addEventListener('click', (e) => {
            if (!e.ctrlKey && !e.metaKey && !e.shiftKey) {
                e.preventDefault();
                const href = link.getAttribute('href');
                
                document.body.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                document.body.style.opacity = '0';
                document.body.style.transform = 'scale(0.98)';
                
                setTimeout(() => {
                    window.location.href = href;
                }, 300);
            }
        });
    }
});

// ============================================
// PERFORMANCE MONITORING
// ============================================
if ('performance' in window) {
    window.addEventListener('load', () => {
        const perfData = performance.getEntriesByType('navigation')[0];
        console.log(`⚡ Page loaded in ${Math.round(perfData.loadEventEnd - perfData.fetchStart)}ms`);
    });
}

// ============================================
// ERROR HANDLING
// ============================================
window.addEventListener('error', (e) => {
    console.error('❌ Error:', e.message);
    // Don't show toast for share-related errors or user cancellations
    if (e.message && (
        e.message.includes('share') ||
        e.message.includes('Share') ||
        e.message.includes('clipboard') ||
        e.message.includes('AbortError') ||
        e.message.includes('cancelled') ||
        e.message.includes('canceled')
    )) {
        return; // Silent fail for share operations
    }
    Toast.error('An error occurred. Please refresh the page.');
});

window.addEventListener('unhandledrejection', (e) => {
    console.error('❌ Unhandled Promise Rejection:', e.reason);
    // Don't show toast for share-related rejections
    const reason = String(e.reason || '');
    if (reason.includes('share') || reason.includes('Share') ||
        reason.includes('AbortError') || reason.includes('cancel')) {
        e.preventDefault(); // Prevent default handling
        return;
    }
});

// ============================================
// INIT COMPLETE
// ============================================
console.log('%c🎉 All systems operational!', 'font-size: 14px; font-weight: bold; color: #51cf66;');
console.log('%cBuilt with ❤️ for families', 'font-size: 12px; color: #667eea;');