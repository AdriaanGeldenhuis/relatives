<?php
declare(strict_types=1);

/**
 * ============================================
 * SUBSCRIPTION & BILLING PAGE v1.0
 * Manage family subscription and payments
 * ============================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }

    // Only owners and admins can access this page
    if (!in_array($user['role'] ?? '', ['owner', 'admin'])) {
        header('Location: /profile/', true, 302);
        exit;
    }
} catch (Exception $e) {
    error_log('Subscription page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get subscription status
$subscriptionManager = new SubscriptionManager($db);
$subStatus = $subscriptionManager->getFamilySubscriptionStatus($user['family_id']);

// Get family info
$family = null;
try {
    $stmt = $db->prepare("SELECT * FROM families WHERE id = ?");
    $stmt->execute([$user['family_id']]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family fetch error: ' . $e->getMessage());
}

// Get subscription details if active
$subscription = null;
try {
    $stmt = $db->prepare("
        SELECT * FROM subscriptions
        WHERE family_id = ? AND status IN ('active', 'trial')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$user['family_id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Subscription fetch error: ' . $e->getMessage());
}

// Get payment history
$paymentHistory = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM payment_history
        WHERE family_id = ?
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute([$user['family_id']]);
    $paymentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
    error_log('Payment history fetch error: ' . $e->getMessage());
}

// Plan names
$planNames = [
    'relatives_monthly' => 'Monthly Plan',
    'relatives_yearly' => 'Yearly Plan',
    'relatives.monthly' => 'Monthly Plan',
    'relatives.yearly' => 'Yearly Plan',
    'com.relatives.monthly' => 'Monthly Plan',
    'com.relatives.yearly' => 'Yearly Plan',
];

$statusLabels = [
    'active' => ['label' => 'Active', 'class' => 'status-active'],
    'trial' => ['label' => 'Trial', 'class' => 'status-trial'],
    'expired' => ['label' => 'Expired', 'class' => 'status-expired'],
    'locked' => ['label' => 'Locked', 'class' => 'status-locked'],
];

$providerNames = [
    'google_play' => 'Google Play',
    'apple_app_store' => 'Apple App Store',
    'stripe' => 'Credit Card',
    'none' => 'None',
];

$pageTitle = 'Subscription & Billing';
$pageCSS = ['/profile/css/profile.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<style>
    .subscription-card {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 20px;
    }

    .subscription-status {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
    }

    .status-active {
        background: rgba(67, 233, 123, 0.2);
        border: 1px solid rgba(67, 233, 123, 0.4);
        color: #43e97b;
    }

    .status-trial {
        background: rgba(102, 126, 234, 0.2);
        border: 1px solid rgba(102, 126, 234, 0.4);
        color: #a3b3ff;
    }

    .status-expired, .status-locked {
        background: rgba(255, 71, 87, 0.2);
        border: 1px solid rgba(255, 71, 87, 0.4);
        color: #ff4757;
    }

    .plan-name {
        font-size: 20px;
        font-weight: 700;
        color: white;
    }

    .subscription-details {
        display: grid;
        gap: 12px;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        color: rgba(255, 255, 255, 0.6);
        font-size: 13px;
    }

    .detail-value {
        color: white;
        font-weight: 600;
        font-size: 14px;
    }

    .payment-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 10px;
        margin-bottom: 10px;
    }

    .payment-item:last-child {
        margin-bottom: 0;
    }

    .payment-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .payment-date {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.6);
    }

    .payment-description {
        font-size: 14px;
        font-weight: 500;
        color: white;
    }

    .payment-amount {
        font-size: 16px;
        font-weight: 700;
        color: #43e97b;
    }

    .manage-links {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 15px;
    }

    .manage-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        color: white;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .manage-link:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateX(4px);
    }

    .manage-link-icon {
        font-size: 20px;
    }

    .empty-state {
        text-align: center;
        padding: 30px;
        color: rgba(255, 255, 255, 0.6);
    }

    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 15px;
    }

    .trial-warning {
        background: rgba(255, 193, 7, 0.15);
        border: 1px solid rgba(255, 193, 7, 0.3);
        border-radius: 12px;
        padding: 14px;
        margin-bottom: 20px;
        color: #ffc107;
        font-size: 14px;
    }
</style>

<main class="main-content">
    <div class="profile-container">

        <div class="profile-header">
            <div class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <?php echo strtoupper(substr($user['name'] ?? $user['full_name'] ?? '?', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="profile-name">Subscription & Billing</div>
        </div>

        <!-- Current Plan -->
        <div class="subscription-card">
            <div class="subscription-status">
                <span class="status-badge <?php echo $statusLabels[$subStatus['status']]['class'] ?? 'status-expired'; ?>">
                    <?php echo $statusLabels[$subStatus['status']]['label'] ?? 'Unknown'; ?>
                </span>
                <?php if ($subStatus['plan_code']): ?>
                    <span class="plan-name"><?php echo $planNames[$subStatus['plan_code']] ?? $subStatus['plan_code']; ?></span>
                <?php endif; ?>
            </div>

            <?php if ($subStatus['status'] === 'trial'): ?>
                <div class="trial-warning">
                    Your trial ends on <?php echo date('F j, Y', strtotime($subStatus['trial_ends_at'])); ?>.
                    Subscribe to continue using all features!
                </div>
            <?php endif; ?>

            <?php if ($subStatus['status'] === 'locked' || $subStatus['status'] === 'expired'): ?>
                <div class="trial-warning" style="background: rgba(255, 71, 87, 0.15); border-color: rgba(255, 71, 87, 0.3); color: #ff4757;">
                    Your subscription has expired. Please renew to access all features.
                </div>
            <?php endif; ?>

            <div class="subscription-details">
                <?php if ($subStatus['provider'] !== 'none'): ?>
                    <div class="detail-row">
                        <span class="detail-label">Payment Provider</span>
                        <span class="detail-value"><?php echo $providerNames[$subStatus['provider']] ?? $subStatus['provider']; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($subStatus['current_period_end']): ?>
                    <div class="detail-row">
                        <span class="detail-label"><?php echo $subStatus['status'] === 'active' ? 'Renews On' : 'Expires On'; ?></span>
                        <span class="detail-value"><?php echo date('F j, Y', strtotime($subStatus['current_period_end'])); ?></span>
                    </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="detail-label">Family</span>
                    <span class="detail-value"><?php echo htmlspecialchars($family['name'] ?? 'My Family'); ?></span>
                </div>
            </div>

            <!-- Manage Subscription Links -->
            <div class="manage-links">
                <?php if ($subStatus['provider'] === 'google_play'): ?>
                    <a href="https://play.google.com/store/account/subscriptions" target="_blank" class="manage-link">
                        <span class="manage-link-icon">Google Play</span>
                        <span>Manage on Google Play</span>
                    </a>
                <?php elseif ($subStatus['provider'] === 'apple_app_store'): ?>
                    <a href="https://apps.apple.com/account/subscriptions" target="_blank" class="manage-link">
                        <span class="manage-link-icon">App Store</span>
                        <span>Manage on App Store</span>
                    </a>
                <?php endif; ?>

                <?php if ($subStatus['status'] !== 'active'): ?>
                    <a href="/admin/plans-public.php" class="manage-link" style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.3), rgba(118, 75, 162, 0.3));">
                        <span class="manage-link-icon">View Plans</span>
                        <span>View Subscription Plans</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment History -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Payment History</span>
            </div>

            <?php if (empty($paymentHistory)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">-</div>
                    <p>No payment history available</p>
                </div>
            <?php else: ?>
                <?php foreach ($paymentHistory as $payment): ?>
                    <div class="payment-item">
                        <div class="payment-info">
                            <span class="payment-date"><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></span>
                            <span class="payment-description">
                                <?php echo htmlspecialchars($payment['description'] ?? $planNames[$payment['product_id'] ?? ''] ?? 'Subscription Payment'); ?>
                            </span>
                        </div>
                        <span class="payment-amount">
                            <?php
                            $amount = $payment['amount'] ?? 0;
                            $currency = $payment['currency'] ?? 'USD';
                            echo strtoupper($currency) . ' ' . number_format($amount / 100, 2);
                            ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Help Section -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Need Help?</span>
            </div>

            <div class="alert alert-info" style="margin-bottom: 0;">
                <p><strong>Subscription Questions?</strong></p>
                <ul style="margin: 10px 0 0 20px; padding: 0; font-size: 13px;">
                    <li>Subscriptions are managed through the app store where you purchased</li>
                    <li>Contact support if you have billing issues</li>
                    <li>Cancellation takes effect at the end of your billing period</li>
                </ul>
            </div>

            <a href="/help/" class="btn btn-secondary btn-block" style="margin-top: 15px;">
                Contact Support
            </a>
        </div>

        <a href="/profile/" class="btn btn-secondary btn-block" style="margin-top: 20px;">
            Back to Profile
        </a>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
