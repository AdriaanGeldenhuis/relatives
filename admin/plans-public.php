<?php
declare(strict_types=1);

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

// Only family owners can access
if ($user['role'] !== 'owner') {
    header('Location: /home/index.php');
    exit;
}

// Get subscription state
$subscriptionManager = new SubscriptionManager($db);

try {
    $status = $subscriptionManager->getFamilySubscriptionStatus($user['family_id']);
    $trialInfo = $subscriptionManager->getTrialInfo($user['family_id']);
} catch (Exception $e) {
    error_log('plans-public.php error: ' . $e->getMessage());
    // Fallback to safe defaults
    $status = [
        'status' => 'trial',
        'trial_ends_at' => null,
        'current_period_end' => null,
        'provider' => 'none',
        'plan_code' => null,
        'family_id' => $user['family_id']
    ];
    $trialInfo = [
        'is_trial' => true,
        'ends_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
        'expired' => false
    ];
}

// Get all active plans
$stmt = $db->query("
    SELECT * FROM plans 
    WHERE is_active = 1 
    ORDER BY 
        FIELD(billing_period, 'monthly', 'yearly'),
        price_cents ASC
");
$plans = $stmt->fetchAll();

// Group by billing period
$monthlyPlans = array_filter($plans, fn($p) => $p['billing_period'] === 'monthly');
$yearlyPlans = array_filter($plans, fn($p) => $p['billing_period'] === 'yearly');

$pageTitle = 'Subscription Plans';
$pageCSS = ['/admin/css/admin.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <!-- Current Status Section -->
        <?php if ($status['status'] === 'active'): ?>
            <!-- ACTIVE SUBSCRIPTION -->
            <section class="hero-section">
                <div class="greeting-card glass-card" style="background: linear-gradient(135deg, rgba(81, 207, 102, 0.2), rgba(55, 178, 77, 0.2)); border: 2px solid rgba(81, 207, 102, 0.5);">
                    <div style="font-size: 64px; margin-bottom: 20px;">✓</div>
                    <h1 class="page-title">
                        <span class="gradient-text">Active Subscription</span>
                    </h1>
                    <p class="page-subtitle">
                        You're subscribed to <?php echo htmlspecialchars($status['plan_code'] ?? 'Premium'); ?>
                    </p>
                    
                    <div style="margin-top: 30px; padding: 25px; background: rgba(255, 255, 255, 0.1); border-radius: 16px;">
                        <div style="display: grid; gap: 15px; text-align: left; max-width: 500px; margin: 0 auto;">
                            <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                                <span style="color: rgba(255, 255, 255, 0.8);">Product:</span>
                                <span style="color: white; font-weight: 800; font-family: monospace; font-size: 12px;">
                                    <?php echo htmlspecialchars($status['plan_code']); ?>
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                                <span style="color: rgba(255, 255, 255, 0.8);">Platform:</span>
                                <span style="color: white; font-weight: 800;">
                                    <?php 
                                    if ($status['provider'] === 'google_play') {
                                        echo '🤖 Google Play';
                                    } elseif ($status['provider'] === 'apple_app_store') {
                                        echo '🍎 App Store';
                                    } else {
                                        echo '📱 App Store';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 12px 0;">
                                <span style="color: rgba(255, 255, 255, 0.8);">Renews:</span>
                                <span style="color: white; font-weight: 800;">
                                    <?php echo $status['current_period_end'] ? date('M j, Y', strtotime($status['current_period_end'])) : 'N/A'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 25px; padding: 20px; background: rgba(255, 255, 255, 0.1); border-radius: 12px;">
                        <p style="color: rgba(255, 255, 255, 0.9); font-size: 14px; line-height: 1.6;">
                            <strong>💡 To manage or cancel your subscription:</strong><br>
                            <?php if ($status['provider'] === 'google_play'): ?>
                                Open Google Play Store → Menu → Subscriptions → Relatives
                            <?php elseif ($status['provider'] === 'apple_app_store'): ?>
                                Open Settings → Apple ID → Subscriptions → Relatives
                            <?php else: ?>
                                Manage via your app store subscription settings
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </section>
        
        <?php elseif ($status['status'] === 'trial'): ?>
            <!-- TRIAL STATUS -->
            <section class="hero-section">
                <div class="greeting-card glass-card" style="background: linear-gradient(135deg, rgba(67, 233, 123, 0.2), rgba(56, 249, 215, 0.2)); border: 2px solid rgba(67, 233, 123, 0.5);">
                    <div style="font-size: 64px; margin-bottom: 20px;">🎁</div>
                    <h1 class="page-title">
                        <span class="gradient-text">3-Day Free Trial</span>
                    </h1>
                    <?php
                    $trialEnd = new DateTime($trialInfo['ends_at']);
                    $now = new DateTime();
                    $diff = $now->diff($trialEnd);
                    $daysLeft = max(0, $diff->days);
                    ?>
                    <p class="page-subtitle">
                        <?php echo $daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> remaining
                    </p>
                    <p style="color: rgba(255, 255, 255, 0.9); margin-top: 10px;">
                        Trial ends: <strong><?php echo $trialEnd->format('F j, Y \a\t g:i A'); ?></strong>
                    </p>
                </div>
            </section>
        
        <?php else: ?>
            <!-- LOCKED/EXPIRED -->
            <section class="hero-section">
                <div class="greeting-card glass-card" style="background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(238, 90, 111, 0.2)); border: 2px solid rgba(255, 107, 107, 0.5);">
                    <div style="font-size: 64px; margin-bottom: 20px;">🔒</div>
                    <h1 class="page-title">
                        <span class="gradient-text">Subscription Required</span>
                    </h1>
                    <p class="page-subtitle">
                        Your trial has ended. Subscribe to continue using Relatives.
                    </p>
                </div>
            </section>
        <?php endif; ?>

        <!-- Monthly Plans -->
        <section class="plans-section" style="margin-bottom: 60px;">
            <h2 class="section-title" style="text-align: center; margin-bottom: 40px;">
                <span class="title-icon">📅</span>
                Available Plans
            </h2>
            
            <div class="plans-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 30px;">
                
                <?php foreach ($plans as $plan): ?>
                    <div class="plan-card glass-card" style="padding: 40px; text-align: center; transition: all 0.3s ease;">
                        <div class="plan-icon" style="font-size: 64px; margin-bottom: 20px;">
                            <?php 
                            if (stripos($plan['code'], 'small') !== false) {
                                echo '🏃';
                            } elseif (stripos($plan['code'], 'big') !== false) {
                                echo '🏠';
                            } else {
                                echo '👨‍👩‍👧‍👦';
                            }
                            ?>
                        </div>
                        
                        <h3 class="plan-name" style="font-size: 28px; font-weight: 900; color: white; margin-bottom: 10px;">
                            <?php echo htmlspecialchars($plan['name']); ?>
                        </h3>
                        
                        <div class="plan-price" style="margin-bottom: 20px;">
                            <span style="font-size: 48px; font-weight: 900; color: white;">
                                R<?php echo number_format($plan['price_cents'] / 100, 0); ?>
                            </span>
                            <span style="font-size: 18px; color: rgba(255, 255, 255, 0.7);">/<?php echo $plan['billing_period']; ?></span>
                        </div>
                        
                        <div class="plan-features" style="text-align: left; margin-bottom: 30px;">
                            <div class="feature-item" style="padding: 12px 0; color: white; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">👥</span>
                                <span>
                                    <?php 
                                    echo $plan['max_members'] === 0 ? 'Unlimited members' : 
                                         "Up to {$plan['max_members']} members";
                                    ?>
                                </span>
                            </div>
                            <div class="feature-item" style="padding: 12px 0; color: white; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">💬</span>
                                <span>Unlimited messages</span>
                            </div>
                            <div class="feature-item" style="padding: 12px 0; color: white; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">🛒</span>
                                <span>Unlimited shopping lists</span>
                            </div>
                            <div class="feature-item" style="padding: 12px 0; color: white; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">📝</span>
                                <span>Unlimited notes</span>
                            </div>
                            <div class="feature-item" style="padding: 12px 0; color: white; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">📍</span>
                                <span>Location tracking</span>
                            </div>
                            <div class="feature-item" style="padding: 12px 0; color: white; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">🎤</span>
                                <span>Voice messages</span>
                            </div>
                        </div>
                        
                        <div class="plan-cta">
                            <!-- Deep link button - only works in native app -->
                            <a href="relatives://subscription" 
                               class="btn btn-primary btn-large" 
                               style="display: inline-flex; align-items: center; gap: 10px; text-decoration: none; margin-bottom: 15px;">
                                <span style="font-size: 24px;">⚡</span>
                                <span>Subscribe in App</span>
                            </a>
                            
                            <!-- Web browser fallback message -->
                            <div class="info-box" style="background: rgba(255, 255, 255, 0.1); padding: 15px; border-radius: 12px; margin-top: 15px;">
                                <p style="color: rgba(255, 255, 255, 0.9); font-size: 13px; line-height: 1.6;">
                                    📱 If nothing happens when you tap "Subscribe in App", you're viewing this in a normal browser. 
                                    Please download the <strong>Relatives app</strong> on Android or iOS and manage your subscription there.
                                </p>
                            </div>
                            
                            <div style="padding: 15px; background: rgba(255, 255, 255, 0.05); border-radius: 10px; margin-top: 15px;">
                                <p style="color: rgba(255, 255, 255, 0.7); font-size: 12px; margin-bottom: 8px;">
                                    Product ID:
                                </p>
                                <code style="display: block; background: rgba(0, 0, 0, 0.3); padding: 10px; border-radius: 6px; color: white; font-size: 11px; word-break: break-all;">
                                    <?php echo htmlspecialchars($plan['code']); ?>
                                </code>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            </div>
        </section>

        <!-- How to Subscribe -->
        <section class="help-section" style="margin-top: 60px;">
            <h2 class="section-title" style="text-align: center; margin-bottom: 40px;">
                <span class="title-icon">❓</span>
                How to Subscribe
            </h2>
            
            <div class="help-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;">
                
                <div class="help-card glass-card" style="padding: 30px; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 20px;">📱</div>
                    <h3 style="color: white; font-size: 20px; font-weight: 800; margin-bottom: 15px;">
                        1. Download the App
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        Get the Relatives app from Google Play Store (Android) or App Store (iOS)
                    </p>
                </div>
                
                <div class="help-card glass-card" style="padding: 30px; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 20px;">⚙️</div>
                    <h3 style="color: white; font-size: 20px; font-weight: 800; margin-bottom: 15px;">
                        2. Open Settings
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        In the app, tap the menu and go to Settings → Subscription
                    </p>
                </div>
                
                <div class="help-card glass-card" style="padding: 30px; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 20px;">✨</div>
                    <h3 style="color: white; font-size: 20px; font-weight: 800; margin-bottom: 15px;">
                        3. Choose Your Plan
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        Select a plan and complete payment through Google Play or App Store
                    </p>
                </div>
                
            </div>
        </section>

        <!-- FAQ -->
        <section class="faq-section" style="margin-top: 60px; margin-bottom: 60px;">
            <h2 class="section-title" style="text-align: center; margin-bottom: 40px;">
                <span class="title-icon">💬</span>
                Frequently Asked Questions
            </h2>
            
            <div class="faq-list" style="max-width: 800px; margin: 0 auto;">
                
                <div class="faq-item glass-card" style="padding: 25px; margin-bottom: 20px;">
                    <h3 style="color: white; font-size: 18px; font-weight: 800; margin-bottom: 10px;">
                        ❓ Can I subscribe on the website?
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        No. Subscriptions are only available through the mobile app via Google Play and App Store. This is for your security and convenience.
                    </p>
                </div>
                
                <div class="faq-item glass-card" style="padding: 25px; margin-bottom: 20px;">
                    <h3 style="color: white; font-size: 18px; font-weight: 800; margin-bottom: 10px;">
                        ❓ Can I cancel anytime?
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        Yes! Cancel anytime through your Google Play or App Store account settings. Your subscription remains active until the end of the billing period.
                    </p>
                </div>
                
                <div class="faq-item glass-card" style="padding: 25px; margin-bottom: 20px;">
                    <h3 style="color: white; font-size: 18px; font-weight: 800; margin-bottom: 10px;">
                        ❓ What happens after my trial ends?
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        Your account becomes view-only. You can still see all your data, but you won't be able to add or edit anything until you subscribe.
                    </p>
                </div>
                
                <div class="faq-item glass-card" style="padding: 25px;">
                    <h3 style="color: white; font-size: 18px; font-weight: 800; margin-bottom: 10px;">
                        ❓ Is my payment secure?
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        Absolutely! All payments are processed securely through Google Play or Apple App Store. We never see or store your payment information.
                    </p>
                </div>
                
            </div>
        </section>

    </div>
</main>

<style>
.plan-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
}

.help-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
}
</style>

<script src="/home/js/home.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>