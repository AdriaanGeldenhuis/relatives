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

$yoco = new Yoco($db);
$subscription = $yoco->getSubscription($user['family_id']);
$isPro = $yoco->isPro($user['family_id']);
$paymentHistory = $yoco->getPaymentHistory($user['family_id'], 20);

// Handle cancel request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'cancel_subscription') {
            if ($user['role'] !== 'owner') {
                throw new Exception('Only owner can cancel subscription');
            }
            
            if (!$yoco->cancelSubscription($user['family_id'])) {
                throw new Exception('Failed to cancel subscription');
            }
            
            // Log audit
            $stmt = $db->prepare("
                INSERT INTO audit_log (family_id, user_id, action, entity_type, created_at)
                VALUES (?, ?, 'cancel_subscription', 'subscription', NOW())
            ");
            $stmt->execute([$user['family_id'], $user['id']]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Billing & Subscription';
$pageCSS = ['/upgrade/css/upgrade.css'];
$pageJS = ['/upgrade/js/billing.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <!-- Header -->
        <section class="page-header">
            <h1 class="page-title">
                <span class="gradient-text">Billing & Subscription</span>
            </h1>
            <p class="page-subtitle">Manage your family's subscription</p>
        </section>

        <!-- Current Plan -->
        <section class="billing-section">
            <h2 class="section-title">Current Plan</h2>
            
            <div class="plan-status-card glass-card">
                <div class="plan-status-header">
                    <div class="plan-badge <?php echo $isPro ? 'pro' : 'free'; ?>">
                        <span class="badge-icon"><?php echo $isPro ? '⭐' : '🆓'; ?></span>
                        <span class="badge-text"><?php echo $isPro ? 'Pro Plan' : 'Free Plan'; ?></span>
                    </div>
                    
                    <?php if ($subscription && $subscription['status']): ?>
                        <div class="status-badge status-<?php echo $subscription['status']; ?>">
                            <?php
                            $statusIcons = [
                                'active' => '✓',
                                'cancelled' => '⏸',
                                'expired' => '✗',
                                'trialing' => '🎁'
                            ];
                            echo $statusIcons[$subscription['status']] ?? '•';
                            echo ' ' . ucfirst($subscription['status']);
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="plan-status-body">
                    <?php if ($isPro && $subscription): ?>
                        <div class="billing-info">
                            <div class="info-row">
                                <span class="info-label">Amount</span>
                                <span class="info-value">R<?php echo number_format($subscription['amount_cents'] / 100, 2); ?></span>
                            </div>
                            
                            <div class="info-row">
                                <span class="info-label">Billing Cycle</span>
                                <span class="info-value"><?php echo ucfirst($subscription['billing_interval'] ?? 'monthly'); ?></span>
                            </div>
                            
                            <?php if ($subscription['current_period_end']): ?>
                                <div class="info-row">
                                    <span class="info-label">Next Billing Date</span>
                                    <span class="info-value">
                                        <?php echo date('F j, Y', strtotime($subscription['current_period_end'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($subscription['status'] === 'cancelled' && $subscription['cancelled_at']): ?>
                                <div class="info-row warning">
                                    <span class="info-label">Cancelled On</span>
                                    <span class="info-value">
                                        <?php echo date('F j, Y', strtotime($subscription['cancelled_at'])); ?>
                                    </span>
                                </div>
                                <p class="cancellation-note">
                                    Your Pro features will remain active until 
                                    <?php echo date('F j, Y', strtotime($subscription['current_period_end'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($user['role'] === 'owner' && $subscription['status'] === 'active'): ?>
                            <div class="plan-actions">
                                <button onclick="confirmCancel()" class="btn btn-danger">
                                    Cancel Subscription
                                </button>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <!-- FREE PLAN -->
                        <div class="free-plan-info">
                            <p>You're currently on the free plan with basic features.</p>
                            <a href="/upgrade/" class="btn btn-primary btn-large">
                                ⚡ Upgrade to Pro
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Payment History -->
        <?php if (!empty($paymentHistory)): ?>
            <section class="billing-section">
                <h2 class="section-title">Payment History</h2>
                
                <div class="payment-history-table glass-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentHistory as $payment): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['description'] ?? 'Relatives Pro - Monthly'); ?>
                                    </td>
                                    <td>R<?php echo number_format($payment['amount_cents'] / 100, 2); ?></td>
                                    <td>
                                        <span class="payment-status status-<?php echo $payment['status']; ?>">
                                            <?php
                                            $icons = [
                                                'successful' => '✓',
                                                'pending' => '⏳',
                                                'failed' => '✗',
                                                'refunded' => '↩'
                                            ];
                                            echo $icons[$payment['status']] ?? '•';
                                            echo ' ' . ucfirst($payment['status']);
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Help Section -->
        <section class="billing-section">
            <div class="help-card glass-card">
                <div class="help-icon">💡</div>
                <h3>Need Help?</h3>
                <p>
                    If you have questions about billing or need to update your payment method, 
                    please contact us at <a href="mailto:support@relatives.co.za">support@relatives.co.za</a>
                </p>
            </div>
        </section>

    </div>
</main>

<!-- Cancel Confirmation Modal -->
<div id="cancelModal" class="modal">
    <div class="modal-content">
        <button onclick="closeCancelModal()" class="modal-close">&times;</button>
        <div class="modal-header">
            <div class="modal-icon warning">⚠️</div>
            <h2>Cancel Subscription?</h2>
        </div>
        <div class="modal-body">
            <p class="warning-text">
                Are you sure you want to cancel your Pro subscription?
            </p>
            
            <div class="cancellation-details">
                <h4>What happens when you cancel:</h4>
                <ul>
                    <li>✓ Access continues until <?php echo $subscription ? date('F j, Y', strtotime($subscription['current_period_end'])) : 'end of period'; ?></li>
                    <li>✗ Pro features will be disabled after that</li>
                    <li>✓ Your data remains safe</li>
                    <li>✓ You can resubscribe anytime</li>
                </ul>
            </div>
            
            <div class="modal-actions">
                <button onclick="cancelSubscription()" class="btn btn-danger">
                    Yes, Cancel Subscription
                </button>
                <button onclick="closeCancelModal()" class="btn btn-secondary">
                    Keep Subscription
                </button>
            </div>
        </div>
    </div>
</div>

<script src="/home/js/home.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>