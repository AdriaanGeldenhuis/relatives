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

$checkoutId = $_GET['checkout_id'] ?? '';
$error = null;
$success = false;

if ($checkoutId) {
    try {
        $yoco = new Yoco($db);
        $result = $yoco->processPaymentSuccess($checkoutId);
        
        if ($result['success']) {
            $success = true;
            
            // Log audit
            $stmt = $db->prepare("
                INSERT INTO audit_log (family_id, user_id, action, entity_type, created_at)
                VALUES (?, ?, 'upgrade_to_pro', 'subscription', NOW())
            ");
            $stmt->execute([$user['family_id'], $user['id']]);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} else {
    $error = 'Missing checkout ID';
}

$pageTitle = 'Payment Successful';
$pageCSS = ['/upgrade/css/upgrade.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <?php if ($success): ?>
            <!-- SUCCESS -->
            <div class="result-card success-card">
                <div class="result-icon success-icon">
                    <div class="checkmark-circle">
                        <div class="checkmark"></div>
                    </div>
                </div>
                
                <h1 class="result-title">Welcome to Pro! 🎉</h1>
                
                <p class="result-text">
                    Your payment was successful. All Pro features are now unlocked for your family.
                </p>
                
                <div class="features-unlocked">
                    <h3>✨ You Now Have Access To:</h3>
                    <div class="feature-list">
                        <div class="feature-item">✅ Unlimited family members</div>
                        <div class="feature-item">✅ Unlimited messages & history</div>
                        <div class="feature-item">✅ Unlimited shopping lists</div>
                        <div class="feature-item">✅ Unlimited notes</div>
                        <div class="feature-item">✅ Voice messages</div>
                        <div class="feature-item">✅ Photo sharing</div>
                        <div class="feature-item">✅ Family stories</div>
                        <div class="feature-item">✅ Full calendar access</div>
                        <div class="feature-item">✅ 30-day location history</div>
                        <div class="feature-item">✅ Priority support</div>
                    </div>
                </div>
                
                <div class="result-actions">
                    <a href="/home/" class="btn btn-primary btn-large">
                        🏠 Go to Home
                    </a>
                    <a href="/upgrade/billing.php" class="btn btn-secondary">
                        📄 View Billing
                    </a>
                </div>
                
                <div class="result-footer">
                    <p>
                        You'll be charged <strong>R99/month</strong>. 
                        <a href="/upgrade/billing.php">Manage subscription</a>
                    </p>
                </div>
            </div>
            
        <?php else: ?>
            <!-- ERROR -->
            <div class="result-card error-card">
                <div class="result-icon error-icon">❌</div>
                
                <h1 class="result-title">Payment Issue</h1>
                
                <p class="result-text">
                    <?php echo htmlspecialchars($error ?? 'Unknown error occurred'); ?>
                </p>
                
                <div class="result-actions">
                    <a href="/upgrade/" class="btn btn-primary">
                        ← Try Again
                    </a>
                    <a href="/home/" class="btn btn-secondary">
                        Go Home
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</main>

<script src="/home/js/home.js"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>