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

// ====================================
// SUPER ADMIN ONLY - NOBODY ELSE
// ====================================
if (!$user || $user['role'] !== 'admin' || (int) $user['family_id'] !== 1) {
    header('Location: /home/index.php');
    exit;
}

// Get all subscriptions from stores
$stmt = $db->query("
    SELECT 
        s.*,
        f.name as family_name,
        u.full_name as owner_name,
        u.email as owner_email
    FROM subscriptions s
    JOIN families f ON s.family_id = f.id
    LEFT JOIN users u ON f.owner_user_id = u.id
    WHERE s.provider IN ('google_play', 'apple_app_store')
    ORDER BY s.created_at DESC
");
$subscriptions = $stmt->fetchAll();

// Get payment history
$stmt = $db->query("
    SELECT 
        ph.*,
        f.name as family_name
    FROM payment_history ph
    JOIN families f ON ph.family_id = f.id
    WHERE ph.provider IN ('google_play', 'apple_app_store')
    ORDER BY ph.created_at DESC
    LIMIT 50
");
$payments = $stmt->fetchAll();

$pageTitle = 'Store Subscriptions';
$pageCSS = ['/admin/css/admin.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <section class="page-header">
            <h1 class="page-title">
                <span class="gradient-text">Store Subscriptions</span>
            </h1>
            <p class="page-subtitle">View all subscriptions from Google Play & App Store</p>
        </section>

        <!-- Subscriptions Table -->
        <section class="admin-section">
            <h2 class="section-title">
                <span class="title-icon">📱</span>
                Active Subscriptions
            </h2>
            
            <div class="subscriptions-table glass-card" style="padding: 30px; overflow-x: auto;">
                
                <?php if (empty($subscriptions)): ?>
                    <div class="empty-state" style="text-align: center; padding: 60px 20px;">
                        <div style="font-size: 64px; margin-bottom: 20px;">📱</div>
                        <h3 style="color: white; font-size: 24px; margin-bottom: 10px;">No Subscriptions Yet</h3>
                        <p style="color: rgba(255, 255, 255, 0.7);">
                            Subscriptions will appear here when families subscribe via the mobile app
                        </p>
                    </div>
                <?php else: ?>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid rgba(255, 255, 255, 0.2);">
                            <th style="padding: 15px; text-align: left; color: white; font-weight: 800;">Family</th>
                            <th style="padding: 15px; text-align: left; color: white; font-weight: 800;">Product</th>
                            <th style="padding: 15px; text-align: left; color: white; font-weight: 800;">Provider</th>
                            <th style="padding: 15px; text-align: left; color: white; font-weight: 800;">Status</th>
                            <th style="padding: 15px; text-align: left; color: white; font-weight: 800;">Started</th>
                            <th style="padding: 15px; text-align: left; color: white; font-weight: 800;">Expires</th>
                            <th style="padding: 15px; text-align: right; color: white; font-weight: 800;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $sub): ?>
                            <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                <td style="padding: 15px; color: white;">
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($sub['family_name']); ?></div>
                                    <div style="font-size: 12px; color: rgba(255, 255, 255, 0.6);">
                                        <?php echo htmlspecialchars($sub['owner_email'] ?? ''); ?>
                                    </div>
                                </td>
                                <td style="padding: 15px;">
                                    <code style="font-size: 11px; background: rgba(0, 0, 0, 0.3); padding: 4px 8px; border-radius: 4px; color: white;">
                                        <?php echo htmlspecialchars($sub['store_product_id']); ?>
                                    </code>
                                </td>
                                <td style="padding: 15px;">
                                    <span style="padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: rgba(255, 255, 255, 0.15); color: white;">
                                        <?php echo $sub['provider'] === 'google_play' ? '🤖 Google' : '🍎 Apple'; ?>
                                    </span>
                                </td>
                                <td style="padding: 15px;">
                                    <span class="status-badge status-<?php echo $sub['status']; ?>" style="padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                                        <?php 
                                        $icons = [
                                            'active' => '✓',
                                            'cancelled' => '⏸',
                                            'expired' => '✗',
                                            'trial' => '🎁'
                                        ];
                                        echo ($icons[$sub['status']] ?? '•') . ' ' . ucfirst($sub['status']);
                                        ?>
                                    </span>
                                </td>
                                <td style="padding: 15px; color: rgba(255, 255, 255, 0.8); font-size: 13px;">
                                    <?php echo date('M j, Y', strtotime($sub['current_period_start'])); ?>
                                </td>
                                <td style="padding: 15px; color: rgba(255, 255, 255, 0.8); font-size: 13px;">
                                    <?php 
                                    if ($sub['current_period_end']) {
                                        $expiry = new DateTime($sub['current_period_end']);
                                        $now = new DateTime();
                                        $isExpired = $expiry < $now;
                                        ?>
                                        <span style="<?php echo $isExpired ? 'color: #ff6b6b;' : ''; ?>">
                                            <?php echo $expiry->format('M j, Y'); ?>
                                        </span>
                                    <?php } else { echo '-'; } ?>
                                </td>
                                <td style="padding: 15px; text-align: right;">
                                    <button onclick="viewSubscriptionDetails(<?php echo $sub['id']; ?>)" class="btn-icon-action" title="View Details">
                                        👁️
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php endif; ?>
                
            </div>
        </section>

        <!-- Payment History -->
        <section class="admin-section">
            <h2 class="section-title">
                <span class="title-icon">💳</span>
                Recent Payments
            </h2>
            
            <div class="payments-table glass-card" style="padding: 30px; overflow-x: auto;">
                
                <?php if (empty($payments)): ?>
                    <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                        <p style="color: rgba(255, 255, 255, 0.7);">No payment history yet</p>
                    </div>
                <?php else: ?>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid rgba(255, 255, 255, 0.2);">
                            <th style="padding: 12px; text-align: left; color: white; font-weight: 800;">Family</th>
                            <th style="padding: 12px; text-align: left; color: white; font-weight: 800;">Provider</th>
                            <th style="padding: 12px; text-align: left; color: white; font-weight: 800;">External ID</th>
                            <th style="padding: 12px; text-align: left; color: white; font-weight: 800;">Amount</th>
                            <th style="padding: 12px; text-align: left; color: white; font-weight: 800;">Status</th>
                            <th style="padding: 12px; text-align: left; color: white; font-weight: 800;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                <td style="padding: 12px; color: white; font-size: 13px;">
                                    <?php echo htmlspecialchars($payment['family_name']); ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: 700; background: rgba(255, 255, 255, 0.15); color: white;">
                                        <?php echo $payment['provider'] === 'google_play' ? '🤖' : '🍎'; ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <code style="font-size: 10px; color: rgba(255, 255, 255, 0.7);">
                                        <?php echo htmlspecialchars(substr($payment['external_id'] ?? 'N/A', 0, 20)) . '...'; ?>
                                    </code>
                                </td>
                                <td style="padding: 12px; color: white; font-weight: 700; font-size: 13px;">
                                    <?php 
                                    if ($payment['amount_cents'] > 0) {
                                        echo $payment['currency'] . ' ' . number_format($payment['amount_cents'] / 100, 2);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="color: #51cf66; font-weight: 700; font-size: 12px;">
                                        <?php echo $payment['status'] === 'successful' ? '✓' : '•'; ?>
                                        <?php echo ucfirst($payment['status']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; color: rgba(255, 255, 255, 0.7); font-size: 12px;">
                                    <?php echo date('M j, Y g:i A', strtotime($payment['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php endif; ?>
                
            </div>
        </section>

        <!-- Info Box -->
        <section class="admin-section">
            <div class="info-box glass-card" style="display: flex; gap: 20px; padding: 30px; align-items: flex-start;">
                <div style="font-size: 48px; flex-shrink: 0;">ℹ️</div>
                <div>
                    <h3 style="color: white; font-size: 18px; font-weight: 800; margin-bottom: 10px;">
                        How Store Subscriptions Work
                    </h3>
                    <ul style="color: rgba(255, 255, 255, 0.8); line-height: 1.8; margin: 0; padding-left: 20px;">
                        <li>Users subscribe via the mobile app (Android or iOS)</li>
                        <li>Payment is handled by Google Play or App Store</li>
                        <li>The app sends purchase tokens to /api/subscription/start-from-native.php</li>
                        <li>Backend verifies with store APIs and activates the subscription</li>
                        <li>Renewals and cancellations are handled by the stores</li>
                        <li>This page shows subscriptions managed entirely by stores (read-only)</li>
                    </ul>
                </div>
            </div>
        </section>

    </div>
</main>

<!-- Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <button onclick="closeModal('detailsModal')" class="modal-close">&times;</button>
        <div class="modal-header">
            <div class="modal-icon">📋</div>
            <h2>Subscription Details</h2>
        </div>
        <div class="modal-body" id="detailsContent">
            Loading...
        </div>
    </div>
</div>

<style>
.btn-icon-action {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.1);
    color: white;
    font-size: 18px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-icon-action:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.1);
}

.status-badge.status-active {
    background: linear-gradient(135deg, #51cf66, #37b24d);
    color: white;
}

.status-badge.status-cancelled {
    background: linear-gradient(135deg, #ff6b6b, #ee5a6f);
    color: white;
}

.status-badge.status-expired {
    background: rgba(255, 255, 255, 0.15);
    color: rgba(255, 255, 255, 0.6);
}

.status-badge.status-trial {
    background: linear-gradient(135deg, #43e97b, #38f9d7);
    color: #000;
}
</style>

<script>
async function viewSubscriptionDetails(subscriptionId) {
    showModal('detailsModal');
    
    try {
        const response = await fetch(`/api/subscription/details.php?id=${subscriptionId}`);
        const data = await response.json();
        
        if (data.ok && data.subscription) {
            const sub = data.subscription;
            document.getElementById('detailsContent').innerHTML = `
                <div style="display: grid; gap: 15px;">
                    <div>
                        <strong style="color: rgba(255, 255, 255, 0.7);">Store Purchase Token:</strong><br>
                        <code style="background: rgba(0, 0, 0, 0.3); padding: 8px; border-radius: 6px; display: block; margin-top: 5px; color: white; font-size: 11px; word-break: break-all;">
                            ${sub.store_purchase_token || 'N/A'}
                        </code>
                    </div>
                    <div>
                        <strong style="color: rgba(255, 255, 255, 0.7);">Store Order ID:</strong><br>
                        <code style="background: rgba(0, 0, 0, 0.3); padding: 8px; border-radius: 6px; display: block; margin-top: 5px; color: white; font-size: 11px;">
                            ${sub.store_order_id || 'N/A'}
                        </code>
                    </div>
                    <div>
                        <strong style="color: rgba(255, 255, 255, 0.7);">Last Verified:</strong><br>
                        <span style="color: white;">${sub.updated_at || 'Never'}</span>
                    </div>
                </div>
            `;
        } else {
            throw new Error(data.error || 'Failed to load details');
        }
    } catch (error) {
        document.getElementById('detailsContent').innerHTML = `
            <div style="color: #ff6b6b;">Error: ${error.message}</div>
        `;
    }
}

function showModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = '';
}
</script>

<script src="/home/js/home.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>