/**
 * ============================================
 * RELATIVES - SHARED APP FUNCTIONS v8.2
 * Global utilities used across all pages
 * ADDED: Subscription lock detection
 * ============================================
 */

console.log('🚀 Relatives App v8.2 loading...');

// ============================================
// SUBSCRIPTION LOCK HANDLER
// ============================================
if (typeof window.SubscriptionLock === 'undefined') {
    class SubscriptionLock {
        static isLocked() {
            return window.subscriptionLocked === true;
        }
        
        static showModal() {
            // Check if modal already exists
            let modal = document.getElementById('subscriptionLockedModal');
            
            if (!modal) {
                // Create modal
                modal = document.createElement('div');
                modal.id = 'subscriptionLockedModal';
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-backdrop" style="position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(10px); z-index: 99998;"></div>
                    <div class="modal-content" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, rgba(255,107,107,0.95), rgba(238,90,111,0.95)); backdrop-filter: blur(40px); padding: 40px; border-radius: 24px; max-width: 500px; width: 90%; z-index: 99999; box-shadow: 0 20px 60px rgba(0,0,0,0.5); text-align: center;">
                        <div style="font-size: 80px; margin-bottom: 20px;">🔒</div>
                        <h2 style="color: white; font-size: 28px; font-weight: 900; margin-bottom: 15px;">Trial Ended</h2>
                        <p style="color: rgba(255,255,255,0.9); font-size: 16px; line-height: 1.6; margin-bottom: 30px;">
                            Your 3-day trial has ended. You're now in <strong>view-only mode</strong>. 
                            Subscribe to continue adding and editing content.
                        </p>
                        <a href="/admin/plans-public.php" style="display: inline-flex; align-items: center; gap: 10px; background: white; color: #ff6b6b; padding: 16px 32px; border-radius: 16px; text-decoration: none; font-weight: 900; font-size: 18px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                            <span style="font-size: 24px;">⚡</span>
                            <span>Subscribe Now</span>
                        </a>
                        <button onclick="window.SubscriptionLock.closeModal()" style="display: block; margin: 20px auto 0; background: none; border: none; color: rgba(255,255,255,0.8); cursor: pointer; font-size: 14px; text-decoration: underline;">
                            Close
                        </button>
                    </div>
                `;
                document.body.appendChild(modal);
            }
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        static closeModal() {
            const modal = document.getElementById('subscriptionLockedModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }
        
        static handleError(error, response) {
            if (error === 'subscription_locked' || 
                (response && response.error === 'subscription_locked')) {
                window.subscriptionLocked = true;
                this.showModal();
                return true;
            }
            return false;
        }
    }
    
    window.SubscriptionLock = SubscriptionLock;
    console.log('✅ SubscriptionLock class initialized');
}

// ============================================
// TOAST NOTIFICATION SYSTEM
// ============================================
if (typeof window.Toast === 'undefined') {
    class Toast {
        static show(message, type = 'info', duration = 3000) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            
            // Styling
            Object.assign(toast.style, {
                position: 'fixed',
                top: '20px',
                right: '20px',
                padding: '15px 25px',
                borderRadius: '12px',
                color: 'white',
                fontWeight: '600',
                fontSize: '14px',
                zIndex: '100000',
                boxShadow: '0 10px 30px rgba(0,0,0,0.3)',
                animation: 'slideInRight 0.3s ease',
                maxWidth: '400px',
                wordWrap: 'break-word'
            });
            
            // Colors
            const colors = {
                success: 'linear-gradient(135deg, #43e97b, #38f9d7)',
                error: 'linear-gradient(135deg, #fa709a, #fee140)',
                warning: 'linear-gradient(135deg, #f093fb, #f5576c)',
                info: 'linear-gradient(135deg, #667eea, #764ba2)'
            };
            
            toast.style.background = colors[type] || colors.info;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
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
    
    window.Toast = Toast;
    console.log('✅ Toast class initialized');
}

// Toast animations (only add once)
if (!document.getElementById('toast-styles')) {
    const style = document.createElement('style');
    style.id = 'toast-styles';
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
}

// ============================================
// API HELPER - WITH SUBSCRIPTION LOCK CHECK
// ============================================
if (typeof window.API === 'undefined') {
    class API {
        static async call(endpoint, options = {}) {
            const defaultOptions = {
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            };
            
            const config = { ...defaultOptions, ...options };
            
            try {
                const response = await fetch(endpoint, config);
                const data = await response.json();
                
                // Check for subscription lock
                if (data.error === 'subscription_locked') {
                    SubscriptionLock.handleError('subscription_locked', data);
                    throw new Error('Subscription required');
                }
                
                if (!response.ok) {
                    throw new Error(data.error || 'Request failed');
                }
                
                return data;
            } catch (error) {
                console.error('API Error:', error);
                
                // Check if it's a subscription lock error
                if (error.message !== 'Subscription required') {
                    // Regular error handling
                }
                
                throw error;
            }
        }
        
        static get(endpoint) {
            return this.call(endpoint, { method: 'GET' });
        }
        
        static post(endpoint, body) {
            return this.call(endpoint, {
                method: 'POST',
                body: JSON.stringify(body)
            });
        }
        
        static delete(endpoint) {
            return this.call(endpoint, { method: 'DELETE' });
        }
    }
    
    window.API = API;
    console.log('✅ API class initialized');
}

// ============================================
// MODAL SYSTEM
// ============================================
if (typeof window.Modal === 'undefined') {
    class Modal {
        static open(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            setTimeout(() => {
                modal.style.opacity = '1';
            }, 10);
        }
        
        static close(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            modal.style.opacity = '0';
            
            setTimeout(() => {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }, 300);
        }
    }
    
    window.Modal = Modal;
    console.log('✅ Modal class initialized');
}

// ============================================
// FORM VALIDATION
// ============================================
if (typeof window.FormValidator === 'undefined') {
    class FormValidator {
        static validate(form) {
            const fields = form.querySelectorAll('[required]');
            let isValid = true;
            
            fields.forEach(field => {
                if (!field.value.trim()) {
                    this.showError(field, 'This field is required');
                    isValid = false;
                } else {
                    this.clearError(field);
                }
                
                // Email validation
                if (field.type === 'email' && field.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(field.value)) {
                        this.showError(field, 'Please enter a valid email');
                        isValid = false;
                    }
                }
            });
            
            return isValid;
        }
        
        static showError(field, message) {
            this.clearError(field);
            
            const error = document.createElement('div');
            error.className = 'field-error';
            error.textContent = message;
            error.style.color = '#f56565';
            error.style.fontSize = '12px';
            error.style.marginTop = '4px';
            
            field.parentElement.appendChild(error);
            field.style.borderColor = '#f56565';
        }
        
        static clearError(field) {
            const error = field.parentElement.querySelector('.field-error');
            if (error) error.remove();
            field.style.borderColor = '';
        }
    }
    
    window.FormValidator = FormValidator;
    console.log('✅ FormValidator class initialized');
}

// ============================================
// LOCAL STORAGE HELPER
// ============================================
if (typeof window.Storage === 'undefined') {
    class Storage {
        static set(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
                return true;
            } catch (error) {
                console.error('Storage error:', error);
                return false;
            }
        }
        
        static get(key, defaultValue = null) {
            try {
                const item = localStorage.getItem(key);
                return item ? JSON.parse(item) : defaultValue;
            } catch (error) {
                console.error('Storage error:', error);
                return defaultValue;
            }
        }
        
        static remove(key) {
            localStorage.removeItem(key);
        }
        
        static clear() {
            localStorage.clear();
        }
    }
    
    window.Storage = Storage;
    console.log('✅ Storage class initialized');
}

// ============================================
// DATE/TIME UTILITIES
// ============================================
if (typeof window.DateTime === 'undefined') {
    class DateTime {
        static formatDate(date) {
            return new Date(date).toLocaleDateString('en-ZA', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
        
        static formatTime(date) {
            return new Date(date).toLocaleTimeString('en-ZA', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        static formatRelative(date) {
            const now = new Date();
            const then = new Date(date);
            const diff = now - then;
            
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);
            
            if (days > 7) {
                return this.formatDate(date);
            } else if (days > 0) {
                return `${days} day${days > 1 ? 's' : ''} ago`;
            } else if (hours > 0) {
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else if (minutes > 0) {
                return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
            } else {
                return 'Just now';
            }
        }
    }
    
    window.DateTime = DateTime;
    console.log('✅ DateTime class initialized');
}

// ============================================
// DEBOUNCE FUNCTION
// ============================================
if (typeof window.debounce === 'undefined') {
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
    
    window.debounce = debounce;
    console.log('✅ Debounce function initialized');
}

// ============================================
// MARK PAGE AS LOADED
// ============================================
window.addEventListener('load', () => {
    document.body.classList.add('loaded');
    console.log('✅ Page fully loaded');
});

console.log('✅ Shared app utilities loaded');