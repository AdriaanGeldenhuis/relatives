<?php
declare(strict_types=1);

session_name('RELATIVES_SESSION');
session_start();

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

$yoco = new Yoco($db);
$subscription = $yoco->getSubscription($user['family_id']);
$isPro = $yoco->isPro($user['family_id']);

// If already Pro, redirect to billing
if ($isPro) {
    header('Location: /upgrade/billing.php');
    exit;
}

// Get current usage stats
$stats = [
    'members' => $yoco->getFeatureUsage($user['family_id'], 'members'),
    'messages' => $yoco->getFeatureUsage($user['family_id'], 'messages'),
    'notes' => $yoco->getFeatureUsage($user['family_id'], 'notes'),
    'shopping_items' => $yoco->getFeatureUsage($user['family_id'], 'shopping_items')
];

$pageTitle = 'Upgrade to Pro';
$pageCSS = ['/upgrade/css/upgrade.css'];
$pageJS = ['/upgrade/js/upgrade.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <!-- Hero Section -->
        <section class="hero-section">
            <div class="hero-badge">
                <span class="badge-icon">⚡</span>
                <span>Upgrade Available</span>
            </div>
            <h1 class="hero-title">
                <span class="gradient-text">Unlock Premium Features</span>
            </h1>
            <p class="hero-subtitle">
                Get unlimited access to everything your family needs
            </p>
        </section>

        <!-- Comparison Table -->
        <section class="comparison-section">
            <div class="comparison-grid">
                
                <!-- FREE PLAN -->
                <div class="plan-card free-plan">
                    <div class="plan-header">
                        <div class="plan-icon">🆓</div>
                        <h3 class="plan-name">Free</h3>
                        <div class="plan-price">
                            <span class="price-amount">R0</span>
                            <span class="price-period">/month</span>
                        </div>
                        <p class="plan-description">Perfect for getting started</p>
                    </div>
                    
                    <div class="plan-features">
                        <div class="feature-item">
                            <span class="feature-icon">👥</span>
                            <span class="feature-text">2 family members</span>
                            <span class="feature-badge current"><?php echo $stats['members']; ?>/2</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">💬</span>
                            <span class="feature-text">Last 100 messages</span>
                            <span class="feature-badge current"><?php echo min($stats['messages'], 100); ?>/100</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">🛒</span>
                            <span class="feature-text">1 shopping list</span>
                            <span class="feature-badge">20 items max</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">📝</span>
                            <span class="feature-text">5 notes max</span>
                            <span class="feature-badge current"><?php echo min($stats['notes'], 5); ?>/5</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">📅</span>
                            <span class="feature-text">View calendar only</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">📍</span>
                            <span class="feature-text">1 hour location history</span>
                        </div>
                        <div class="feature-item disabled">
                            <span class="feature-icon">🎤</span>
                            <span class="feature-text">No voice messages</span>
                        </div>
                        <div class="feature-item disabled">
                            <span class="feature-icon">📸</span>
                            <span class="feature-text">No photo sharing</span>
                        </div>
                        <div class="feature-item disabled">
                            <span class="feature-icon">📖</span>
                            <span class="feature-text">No stories</span>
                        </div>
                    </div>
                    
                    <div class="plan-footer">
                        <div class="current-badge">
                            <span class="badge-icon">✓</span>
                            Current Plan
                        </div>
                    </div>
                </div>

                <!-- PRO PLAN -->
                <div class="plan-card pro-plan featured">
                    <div class="popular-badge">
                        <span>🔥 Most Popular</span>
                    </div>
                    
                    <div class="plan-header">
                        <div class="plan-icon">⭐</div>
                        <h3 class="plan-name">Pro</h3>
                        <div class="plan-price">
                            <span class="price-amount">R99</span>
                            <span class="price-period">/month</span>
                        </div>
                        <p class="plan-description">Everything your family needs</p>
                    </div>
                    
                    <div class="plan-features">
                        <div class="feature-item highlighted">
                            <span class="feature-icon">👥</span>
                            <span class="feature-text">Unlimited members</span>
                            <span class="feature-badge pro">∞</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">💬</span>
                            <span class="feature-text">Unlimited messages</span>
                            <span class="feature-badge pro">∞</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">🛒</span>
                            <span class="feature-text">Unlimited shopping lists</span>
                            <span class="feature-badge pro">∞</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">📝</span>
                            <span class="feature-text">Unlimited notes</span>
                            <span class="feature-badge pro">∞</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">📅</span>
                            <span class="feature-text">Full calendar access</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">📍</span>
                            <span class="feature-text">30-day location history</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">🎤</span>
                            <span class="feature-text">Voice messages</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">📸</span>
                            <span class="feature-text">Photo sharing</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">📖</span>
                            <span class="feature-text">Family stories</span>
                        </div>
                        <div class="feature-item highlighted">
                            <span class="feature-icon">⚡</span>
                            <span class="feature-text">Priority support</span>
                        </div>
                    </div>
                    
                    <div class="plan-footer">
                        <button onclick="startCheckout()" class="btn btn-pro btn-large">
                            <span class="btn-icon">⚡</span>
                            Upgrade to Pro
                            <span class="btn-arrow">→</span>
                        </button>
                        <p class="plan-note">
                            Cancel anytime • Secure payment via Yoco
                        </p>
                    </div>
                </div>

            </div>
        </section>

        <!-- Benefits Section -->
        <section class="benefits-section">
            <h2 class="section-title">Why Go Pro?</h2>
            
            <div class="benefits-grid">
                <div class="benefit-card">
                    <div class="benefit-icon">🚀</div>
                    <h3 class="benefit-title">No Limits</h3>
                    <p class="benefit-text">
                        Add unlimited family members, messages, notes, and shopping lists. 
                        Your family hub grows with you.
                    </p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">🎯</div>
                    <h3 class="benefit-title">Premium Features</h3>
                    <p class="benefit-text">
                        Voice messages, photo sharing, family stories, and full calendar control. 
                        Everything you need in one place.
                    </p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">📍</div>
                    <h3 class="benefit-title">Extended History</h3>
                    <p class="benefit-text">
                        30-day location history and unlimited message archive. 
                        Never lose important family moments.
                    </p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">⚡</div>
                    <h3 class="benefit-title">Priority Support</h3>
                    <p class="benefit-text">
                        Get help when you need it with priority email support. 
                        We're here for your family.
                    </p>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section class="faq-section">
            <h2 class="section-title">Frequently Asked Questions</h2>
            
            <div class="faq-grid">
                <div class="faq-item">
                    <div class="faq-question">
                        <span class="faq-icon">💳</span>
                        <span>How does billing work?</span>
                    </div>
                    <div class="faq-answer">
                        You'll be charged R99 per month. Cancel anytime - no long-term contracts or hidden fees.
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <span class="faq-icon">🔒</span>
                        <span>Is payment secure?</span>
                    </div>
                    <div class="faq-answer">
                        Yes! All payments are processed securely through Yoco, a PCI-DSS compliant payment provider.
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <span class="faq-icon">❌</span>
                        <span>Can I cancel anytime?</span>
                    </div>
                    <div class="faq-answer">
                        Absolutely! Cancel anytime from your account settings. Your data remains safe.
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <span class="faq-icon">💾</span>
                        <span>What happens if I cancel?</span>
                    </div>
                    <div class="faq-answer">
                        You'll revert to the free plan with existing data intact. Pro features will be disabled.
                    </div>
                </div>
            </div>
        </section>

        <!-- Trust Badges -->
        <section class="trust-section">
            <div class="trust-badges">
                <div class="trust-badge">
                    <span class="trust-icon">🔒</span>
                    <span class="trust-text">Secure Payment</span>
                </div>
                <div class="trust-badge">
                    <span class="trust-icon">🇿🇦</span>
                    <span class="trust-text">South African</span>
                </div>
                <div class="trust-badge">
                    <span class="trust-icon">⚡</span>
                    <span class="trust-text">Instant Activation</span>
                </div>
                <div class="trust-badge">
                    <span class="trust-icon">✅</span>
                    <span class="trust-text">Cancel Anytime</span>
                </div>
            </div>
        </section>

    </div>
</main>

<!-- Checkout Modal -->
<div id="checkoutModal" class="modal">
    <div class="modal-content checkout-modal">
        <button onclick="closeCheckout()" class="modal-close">&times;</button>
        <div class="modal-header">
            <div class="modal-icon">⚡</div>
            <h2>Upgrade to Pro</h2>
            <p>Complete your payment to unlock all features</p>
        </div>
        <div class="modal-body">
            <div class="checkout-summary">
                <div class="summary-row">
                    <span>Relatives Pro (Monthly)</span>
                    <span class="summary-price">R99.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total</span>
                    <span class="summary-price">R99.00</span>
                </div>
            </div>
            
            <div id="yocoCheckoutContainer" style="margin-top: 30px;">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading secure checkout...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://js.yoco.com/sdk/v1/yoco-sdk-web.js"></script>
<script>
const YOCO_PUBLIC_KEY = '<?php echo $yoco->getPublicKey(); ?>';
const FAMILY_ID = <?php echo $user['family_id']; ?>;

let yocoSDK;
let checkoutId;

async function startCheckout() {
    try {
        // Show modal
        document.getElementById('checkoutModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Initialize Yoco SDK
        if (!yocoSDK) {
            yocoSDK = new window.YocoSDK({ publicKey: YOCO_PUBLIC_KEY });
        }
        
        // Create checkout session
        const response = await fetch('/upgrade/create-checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ family_id: FAMILY_ID })
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to create checkout');
        }
        
        checkoutId = data.checkout.id;
        
        // Show Yoco inline checkout
        yocoSDK.showPopup({
            amountInCents: 9900,
            currency: 'ZAR',
            name: 'Relatives Pro',
            description: 'Monthly subscription',
            metadata: {
                family_id: FAMILY_ID.toString(),
                plan: 'pro'
            },
            callback: function(result) {
                if (result.error) {
                    Toast.error(result.error.message);
                    closeCheckout();
                } else {
                    // Payment successful
                    window.location.href = '/upgrade/success.php?checkout_id=' + result.id;
                }
            }
        });
        
    } catch (error) {
        Toast.error(error.message);
        closeCheckout();
    }
}

function closeCheckout() {
    document.getElementById('checkoutModal').classList.remove('active');
    document.body.style.overflow = '';
}

// FAQ accordion
document.querySelectorAll('.faq-item').forEach(item => {
    const question = item.querySelector('.faq-question');
    question.addEventListener('click', () => {
        item.classList.toggle('active');
    });
});
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>