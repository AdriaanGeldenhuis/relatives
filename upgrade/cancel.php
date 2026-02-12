<?php
declare(strict_types=1);

session_name('RELATIVES_SESSION');
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$pageTitle = 'Payment Cancelled';
$pageCSS = ['/upgrade/css/upgrade.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <div class="result-card cancel-card">
            <div class="result-icon cancel-icon">⏸️</div>
            
            <h1 class="result-title">Payment Cancelled</h1>
            
            <p class="result-text">
                No worries! Your payment was cancelled and you were not charged.
            </p>
            
            <div class="result-info">
                <p>
                    You can still enjoy the free plan with basic features. 
                    Upgrade anytime when you're ready!
                </p>
            </div>
            
            <div class="result-actions">
                <a href="/upgrade/" class="btn btn-primary">
                    ← Try Again
                </a>
                <a href="/home/" class="btn btn-secondary">
                    🏠 Go Home
                </a>
            </div>
        </div>
        
    </div>
</main>

<script src="/home/js/home.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>