<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$pageTitle = 'Payment Failed';
$pageCSS = ['/upgrade/css/upgrade.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <div class="result-card error-card">
            <div class="result-icon error-icon">⚠️</div>
            
            <h1 class="result-title">Payment Failed</h1>
            
            <p class="result-text">
                Unfortunately, your payment could not be processed.
            </p>
            
            <div class="result-info">
                <h3>Common reasons:</h3>
                <ul>
                    <li>Insufficient funds</li>
                    <li>Card declined by bank</li>
                    <li>Incorrect card details</li>
                    <li>Security check failed</li>
                </ul>
                
                <p style="margin-top: 20px;">
                    Please check your payment details and try again, 
                    or contact your bank for more information.
                </p>
            </div>
            
            <div class="result-actions">
                <a href="/upgrade/" class="btn btn-primary">
                    🔄 Try Again
                </a>
                <a href="/home/" class="btn btn-secondary">
                    Go Home
                </a>
            </div>
            
            <div class="result-footer">
                <p>
                    Need help? Email us at 
                    <a href="mailto:support@relatives.co.za">support@relatives.co.za</a>
                </p>
            </div>
        </div>
        
    </div>
</main>

<script src="/home/js/home.js"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>