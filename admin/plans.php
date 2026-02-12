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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'update_plan':
                $planId = (int)$_POST['plan_id'];
                $priceCents = (int)$_POST['price_cents'];
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt = $db->prepare("
                    UPDATE plans 
                    SET price_cents = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$priceCents, $isActive, $planId]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'toggle_plan':
                $planId = (int)$_POST['plan_id'];
                
                $stmt = $db->prepare("
                    UPDATE plans 
                    SET is_active = NOT is_active, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$planId]);
                
                echo json_encode(['success' => true]);
                exit;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get all plans
$stmt = $db->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM families WHERE current_plan_id = p.id) as active_families
    FROM plans p
    ORDER BY 
        FIELD(p.billing_period, 'monthly', 'yearly'),
        p.price_cents ASC
");
$plans = $stmt->fetchAll();

$pageTitle = 'Manage Plans';
$pageCSS = ['/admin/css/admin.css'];
$pageJS = [];

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
                <span class="gradient-text">Subscription Plans</span>
            </h1>
            <p class="page-subtitle">Manage pricing and availability</p>
        </section>

        <section class="admin-section">
            <div class="plans-grid">
                
                <?php foreach ($plans as $plan): ?>
                    <div class="plan-admin-card glass-card <?php echo $plan['is_active'] ? '' : 'inactive'; ?>">
                        <div class="plan-admin-header">
                            <div class="plan-badge">
                                <span class="badge-icon"><?php echo $plan['billing_period'] === 'monthly' ? '📅' : '🗓️'; ?></span>
                                <span class="badge-text"><?php echo ucfirst($plan['billing_period']); ?></span>
                            </div>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_plan">
                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                <button type="submit" class="toggle-btn <?php echo $plan['is_active'] ? 'active' : ''; ?>">
                                    <?php echo $plan['is_active'] ? '✓ Active' : '✗ Disabled'; ?>
                                </button>
                            </form>
                        </div>
                        
                        <div class="plan-admin-body">
                            <h3 class="plan-title"><?php echo htmlspecialchars($plan['name']); ?></h3>
                            <p class="plan-code"><?php echo htmlspecialchars($plan['code']); ?></p>
                            
                            <form method="POST" class="plan-edit-form" onsubmit="return updatePlan(event, <?php echo $plan['id']; ?>)">
                                <input type="hidden" name="action" value="update_plan">
                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                
                                <div class="form-row">
                                    <label>Price (cents)</label>
                                    <input 
                                        type="number" 
                                        name="price_cents" 
                                        value="<?php echo $plan['price_cents']; ?>"
                                        class="form-input"
                                        min="0"
                                        step="100"
                                    >
                                    <small class="form-hint">
                                        Display: <?php echo $plan['currency']; ?> <?php echo number_format($plan['price_cents'] / 100, 2); ?>
                                    </small>
                                </div>
                                
                                <div class="form-row">
                                    <label>Max Members</label>
                                    <input 
                                        type="text" 
                                        value="<?php echo $plan['max_members'] === 0 ? 'Unlimited' : $plan['max_members']; ?>"
                                        class="form-input"
                                        readonly
                                    >
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-block">
                                    Save Changes
                                </button>
                            </form>
                        </div>
                        
                        <div class="plan-admin-footer">
                            <div class="stat">
                                <span class="stat-label">Active Families</span>
                                <span class="stat-value"><?php echo $plan['active_families']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            </div>
        </section>

    </div>
</main>

<style>
.plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 30px;
}

.plan-admin-card {
    padding: 30px;
    transition: all 0.3s ease;
}

.plan-admin-card.inactive {
    opacity: 0.6;
    filter: grayscale(50%);
}

.plan-admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.plan-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 20px;
    font-weight: 700;
}

.toggle-btn {
    padding: 8px 16px;
    border-radius: 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    background: transparent;
    color: white;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
}

.toggle-btn.active {
    background: linear-gradient(135deg, #51cf66, #37b24d);
    border-color: #51cf66;
}

.plan-title {
    font-size: 24px;
    font-weight: 900;
    color: white;
    margin-bottom: 5px;
}

.plan-code {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.6);
    font-family: monospace;
    margin-bottom: 20px;
}

.plan-edit-form {
    margin: 20px 0;
}

.form-row {
    margin-bottom: 15px;
}

.form-row label {
    display: block;
    color: white;
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 14px;
}

.form-input {
    width: 100%;
    padding: 12px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    color: white;
    font-size: 16px;
}

.form-hint {
    display: block;
    margin-top: 5px;
    color: rgba(255, 255, 255, 0.6);
    font-size: 12px;
}

.plan-admin-footer {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stat-label {
    color: rgba(255, 255, 255, 0.7);
    font-size: 14px;
}

.stat-value {
    color: white;
    font-weight: 900;
    font-size: 18px;
}
</style>

<script>
async function updatePlan(event, planId) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Plan updated successfully', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            throw new Error(data.error || 'Update failed');
        }
        
    } catch (error) {
        showToast(error.message, 'error');
    }
    
    return false;
}

function showToast(message, type) {
    // Use existing Toast class from admin.js
    if (typeof Toast !== 'undefined') {
        Toast[type](message);
    } else {
        alert(message);
    }
}
</script>

<script src="/home/js/home.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>