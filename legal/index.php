<?php
/**
 * RELATIVES - LEGAL DOCUMENTS HUB
 * Modular tab system with separate content files
 */

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

// Get active tab from URL
$activeTab = $_GET['doc'] ?? 'terms';
$validTabs = ['terms', 'privacy', 'location', 'dpa', 'cookies', 'eula'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'terms';
}

// Check if tab file exists
$tabFile = __DIR__ . '/tabs/' . $activeTab . '.php';
if (!file_exists($tabFile)) {
    $activeTab = 'terms';
    $tabFile = __DIR__ . '/tabs/terms.php';
}

$pageTitle = 'Legal Documents';
$pageCSS = ['/legal/css/legal.css'];
$pageJS = ['/legal/js/legal.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <section class="legal-hero">
            <div class="legal-hero-icon">⚖️</div>
            <h1 class="legal-hero-title">Legal Documents</h1>
            <p class="legal-hero-subtitle">Your rights, our responsibilities — clear and transparent</p>
        </section>

        <section class="legal-tabs-container">
            <div class="legal-tabs-wrapper">
                <div class="legal-tabs" role="tablist">
                    <a href="?doc=terms" class="legal-tab <?php echo $activeTab === 'terms' ? 'active' : ''; ?>">
                        <span class="tab-icon">📄</span>
                        <span class="tab-text">Terms & Conditions</span>
                    </a>
                    
                    <a href="?doc=privacy" class="legal-tab <?php echo $activeTab === 'privacy' ? 'active' : ''; ?>">
                        <span class="tab-icon">🔒</span>
                        <span class="tab-text">Privacy Policy</span>
                    </a>
                    
                    <a href="?doc=location" class="legal-tab <?php echo $activeTab === 'location' ? 'active' : ''; ?>">
                        <span class="tab-icon">📍</span>
                        <span class="tab-text">Location Policy</span>
                    </a>
                    
                    <a href="?doc=dpa" class="legal-tab <?php echo $activeTab === 'dpa' ? 'active' : ''; ?>">
                        <span class="tab-icon">🤝</span>
                        <span class="tab-text">Data Agreement</span>
                    </a>
                    
                    <a href="?doc=cookies" class="legal-tab <?php echo $activeTab === 'cookies' ? 'active' : ''; ?>">
                        <span class="tab-icon">🍪</span>
                        <span class="tab-text">Cookie Policy</span>
                    </a>
                    
                    <a href="?doc=eula" class="legal-tab <?php echo $activeTab === 'eula' ? 'active' : ''; ?>">
                        <span class="tab-icon">📱</span>
                        <span class="tab-text">App License</span>
                    </a>
                </div>
            </div>
        </section>

        <section class="legal-content-area">
            <article class="legal-document active">
                <?php include $tabFile; ?>
            </article>
        </section>

        <section class="legal-footer">
            <div class="legal-footer-card">
                <div class="footer-icon">📧</div>
                <div class="footer-content">
                    <h3>Need Help?</h3>
                    <p>If you have questions about any of these policies, please contact us:</p>
                    <div class="footer-links">
                        <a href="mailto:support@relatives.co.za">support@relatives.co.za</a>
                        <a href="mailto:legal@relatives.co.za">legal@relatives.co.za</a>
                        <a href="https://www.relatives.co.za" target="_blank">www.relatives.co.za</a>
                    </div>
                </div>
            </div>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>