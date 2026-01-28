<?php
/**
 * RELATIVES - ADMIN PANEL v3
 * Family management only - NO subscription management
 */

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

// Check if user is admin or owner
if (!in_array($user['role'], ['owner', 'admin'])) {
    header('Location: /home/index.php');
    exit;
}

// Get family info
$stmt = $db->prepare("SELECT * FROM families WHERE id = ?");
$stmt->execute([$user['family_id']]);
$family = $stmt->fetch();

// Get all family members with detailed stats
$stmt = $db->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM shopping_items WHERE added_by = u.id) as items_added,
           (SELECT COUNT(*) FROM notes WHERE user_id = u.id) as notes_created,
           (SELECT COUNT(*) FROM events WHERE user_id = u.id) as events_created,
           (SELECT MAX(created_at) FROM audit_log WHERE user_id = u.id) as last_activity
    FROM users u
    WHERE u.family_id = ?
    ORDER BY 
        CASE u.role
            WHEN 'owner' THEN 1
            WHEN 'admin' THEN 2
            WHEN 'member' THEN 3
        END,
        u.full_name ASC
");
$stmt->execute([$user['family_id']]);
$members = $stmt->fetchAll();

// Get comprehensive stats
$stats = [
    'total_members' => count($members),
    'active_members' => count(array_filter($members, fn($m) => $m['status'] === 'active')),
    'total_shopping_items' => 0,
    'pending_shopping_items' => 0,
    'total_notes' => 0,
    'total_events' => 0,
    'upcoming_events' => 0,
    'shopping_lists' => 0
];

// Shopping items
$stmt = $db->prepare("
    SELECT COUNT(*) as total, 
           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM shopping_items si
    JOIN shopping_lists sl ON si.list_id = sl.id
    WHERE sl.family_id = ?
");
$stmt->execute([$user['family_id']]);
$shopping = $stmt->fetch();
$stats['total_shopping_items'] = (int)$shopping['total'];
$stats['pending_shopping_items'] = (int)$shopping['pending'];

// Notes
$stmt = $db->prepare("SELECT COUNT(*) FROM notes WHERE family_id = ?");
$stmt->execute([$user['family_id']]);
$stats['total_notes'] = (int)$stmt->fetchColumn();

// Events
$stmt = $db->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN starts_at >= NOW() AND starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) AND status = 'pending' THEN 1 ELSE 0 END) as upcoming
    FROM events 
    WHERE family_id = ?
");
$stmt->execute([$user['family_id']]);
$events = $stmt->fetch();
$stats['total_events'] = (int)$events['total'];
$stats['upcoming_events'] = (int)$events['upcoming'];

// Shopping lists
$stmt = $db->prepare("SELECT COUNT(*) FROM shopping_lists WHERE family_id = ?");
$stmt->execute([$user['family_id']]);
$stats['shopping_lists'] = (int)$stmt->fetchColumn();

// Recent activity (last 10)
$stmt = $db->prepare("
    SELECT al.action, al.entity_type, al.created_at, al.user_id, u.full_name, u.avatar_color
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.family_id = ?
    ORDER BY al.created_at DESC
    LIMIT 10
");
$stmt->execute([$user['family_id']]);
$recentActivity = $stmt->fetchAll();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'update_role':
                if ($user['role'] !== 'owner') {
                    throw new Exception('Only owner can change roles');
                }
                
                $targetUserId = (int)$_POST['user_id'];
                $newRole = $_POST['role'];
                
                if (!in_array($newRole, ['admin', 'member'])) {
                    throw new Exception('Invalid role');
                }
                
                if ($targetUserId === $user['id']) {
                    throw new Exception('Cannot change your own role');
                }
                
                $stmt = $db->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ? AND family_id = ?");
                $stmt->execute([$newRole, $targetUserId, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'toggle_user_status':
                if (!in_array($user['role'], ['owner', 'admin'])) {
                    throw new Exception('Unauthorized');
                }
                
                $targetUserId = (int)$_POST['user_id'];
                $newStatus = $_POST['status'];
                
                if (!in_array($newStatus, ['active', 'disabled'])) {
                    throw new Exception('Invalid status');
                }
                
                if ($targetUserId === $user['id']) {
                    throw new Exception('Cannot change your own status');
                }
                
                $stmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? AND family_id = ?");
                $stmt->execute([$newStatus, $targetUserId, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'regenerate_invite':
                if ($user['role'] !== 'owner') {
                    throw new Exception('Only owner can regenerate invite code');
                }
                
                do {
                    $newCode = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8));
                    $stmt = $db->prepare("SELECT id FROM families WHERE invite_code = ?");
                    $stmt->execute([$newCode]);
                } while ($stmt->fetchColumn());
                
                $stmt = $db->prepare("UPDATE families SET invite_code = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newCode, $user['family_id']]);
                
                echo json_encode(['success' => true, 'code' => $newCode]);
                exit;
                
            case 'update_family_name':
                if ($user['role'] !== 'owner') {
                    throw new Exception('Only owner can change family name');
                }
                
                $newName = trim($_POST['name'] ?? '');
                
                if (empty($newName)) {
                    throw new Exception('Family name cannot be empty');
                }
                
                $stmt = $db->prepare("UPDATE families SET name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newName, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'update_timezone':
                if ($user['role'] !== 'owner') {
                    throw new Exception('Only owner can change timezone');
                }
                
                $timezone = trim($_POST['timezone'] ?? '');
                
                if (!in_array($timezone, timezone_identifiers_list())) {
                    throw new Exception('Invalid timezone');
                }
                
                $stmt = $db->prepare("UPDATE families SET timezone = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$timezone, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Set page metadata
$pageTitle = 'Admin Panel';
$cacheVersion = '3.1.0';
$pageCSS = ['/admin/css/admin.css?v=' . $cacheVersion];
$pageJS = ['/admin/js/admin.js?v=' . $cacheVersion];

// Include header
require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<!-- Main Content -->
<main class="main-content">
    <div class="container">
        
        <!-- Hero Header -->
        <section class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo ucfirst($user['role']); ?> Panel</div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">⚙️</span>
                    <span class="greeting-name"><?php echo htmlspecialchars($family['name']); ?></span>
                </h1>
                <p class="greeting-subtitle">Complete control over your family hub</p>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <button onclick="showInviteModal()" class="quick-action-btn">
                        <span class="qa-icon">📨</span>
                        <span>Invite Member</span>
                    </button>
                    <button onclick="copyInviteCode()" class="quick-action-btn">
                        <span class="qa-icon">📋</span>
                        <span>Copy Code</span>
                    </button>
                    <a href="/home/" class="quick-action-btn">
                        <span class="qa-icon">🏠</span>
                        <span>Home</span>
                    </a>
                </div>
            </div>
        </section>
   
        <!-- Subscription Status Banner (OWNERS ONLY) -->
        <?php if ($user['role'] === 'owner'): ?>
        <?php
        // Get subscription state
        $subscriptionManager = new SubscriptionManager($db);
        $status = $subscriptionManager->getFamilySubscriptionStatus($user['family_id']);
        $trialInfo = $subscriptionManager->getTrialInfo($user['family_id']);
        ?>
        
        <?php if ($status['status'] === 'trial'): ?>
            <!-- TRIAL BANNER -->
            <section class="subscription-banner">
                <div class="banner-card glass-card" style="background: linear-gradient(135deg, rgba(67, 233, 123, 0.2), rgba(56, 249, 215, 0.2)); border: 2px solid rgba(67, 233, 123, 0.5); padding: 30px; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">🎁</div>
                    <h2 style="color: white; font-size: 24px; font-weight: 900; margin-bottom: 10px;">
                        You're on a 3-day free trial
                    </h2>
                    <?php
                    $trialEnd = new DateTime($trialInfo['ends_at']);
                    $now = new DateTime();
                    $diff = $now->diff($trialEnd);
                    $daysLeft = max(0, $diff->days);
                    ?>
                    <p style="color: rgba(255, 255, 255, 0.9); font-size: 16px; margin-bottom: 20px;">
                        Trial ends: <strong><?php echo $trialEnd->format('F j, Y \a\t g:i A'); ?></strong>
                        <?php if ($daysLeft <= 1): ?>
                            <br><span style="color: #ff6b6b; font-weight: 800;">⚠️ Less than <?php echo max(1, $daysLeft); ?> day left!</span>
                        <?php endif; ?>
                    </p>
                    <a href="/admin/plans-public.php" class="btn btn-primary btn-large" style="display: inline-flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">⚡</span>
                        <span>View Subscription Plans</span>
                    </a>
                </div>
            </section>
        
        <?php elseif ($status['status'] === 'locked' || $status['status'] === 'expired'): ?>
            <!-- LOCKED/EXPIRED BANNER -->
            <section class="subscription-banner">
                <div class="banner-card glass-card" style="background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(238, 90, 111, 0.2)); border: 2px solid rgba(255, 107, 107, 0.5); padding: 30px; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">🔒</div>
                    <h2 style="color: white; font-size: 24px; font-weight: 900; margin-bottom: 10px;">
                        Your trial has ended
                    </h2>
                    <p style="color: rgba(255, 255, 255, 0.9); font-size: 16px; margin-bottom: 20px;">
                        Subscribe now to continue using all Relatives features
                    </p>
                    <a href="/admin/plans-public.php" class="btn btn-primary btn-large" style="display: inline-flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">⚡</span>
                        <span>Subscribe Now</span>
                    </a>
                </div>
            </section>
        
        <?php elseif ($status['status'] === 'active'): ?>
            <!-- ACTIVE SUBSCRIPTION BANNER -->
            <section class="subscription-banner">
                <div class="banner-card glass-card" style="background: linear-gradient(135deg, rgba(81, 207, 102, 0.2), rgba(55, 178, 77, 0.2)); border: 2px solid rgba(81, 207, 102, 0.5); padding: 20px; display: flex; align-items: center; justify-content: space-between; gap: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="font-size: 32px;">✓</div>
                        <div>
                            <div style="color: white; font-size: 18px; font-weight: 800;">
                                Active Subscription: <?php echo htmlspecialchars($status['plan_code'] ?? 'Premium'); ?>
                            </div>
                            <div style="color: rgba(255, 255, 255, 0.8); font-size: 14px;">
                                Renews: <?php echo date('F j, Y', strtotime($status['current_period_end'])); ?>
                            </div>
                        </div>
                    </div>
                    <a href="/admin/plans-public.php" class="btn btn-secondary" style="flex-shrink: 0;">
                        View Details
                    </a>
                </div>
            </section>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Quick Stats Grid -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-icon">👥</span>
                    <div class="stat-number"><?php echo $stats['active_members']; ?></div>
                    <div class="stat-label">Active Members</div>
                </div>

                <div class="stat-card">
                    <span class="stat-icon">🛒</span>
                    <div class="stat-number"><?php echo $stats['pending_shopping_items']; ?></div>
                    <div class="stat-label">Pending Items</div>
                </div>

                <div class="stat-card">
                    <span class="stat-icon">📝</span>
                    <div class="stat-number"><?php echo $stats['total_notes']; ?></div>
                    <div class="stat-label">Family Notes</div>
                </div>

                <div class="stat-card">
                    <span class="stat-icon">📅</span>
                    <div class="stat-number"><?php echo $stats['upcoming_events']; ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
            </div>
        </section>

        <!-- Family Settings -->
        <section class="admin-section">
            <h2 class="section-title">
                <span class="title-icon">🏠</span>
                Family Settings
            </h2>
            
            <div class="settings-grid">
                <!-- Family Name -->
                <div class="setting-card glass-card">
                    <div class="setting-icon">📝</div>
                    <div class="setting-content">
                        <div class="setting-label">Family Name</div>
                        <div class="setting-value" id="familyNameDisplay">
                            <?php echo htmlspecialchars($family['name']); ?>
                        </div>
                    </div>
                    <?php if ($user['role'] === 'owner'): ?>
                    <button onclick="editFamilyName()" class="btn-icon-action" title="Edit">
                        ✏️
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Invite Code -->
                <div class="setting-card glass-card">
                    <div class="setting-icon">🎫</div>
                    <div class="setting-content">
                        <div class="setting-label">Invite Code</div>
                        <div class="setting-value">
                            <code class="invite-code-display" id="inviteCodeDisplay">
                                <?php echo htmlspecialchars($family['invite_code']); ?>
                            </code>
                        </div>
                    </div>
                    <div class="setting-actions">
                        <button onclick="copyInviteCode()" class="btn-icon-action" title="Copy">
                            📋
                        </button>
                        <?php if ($user['role'] === 'owner'): ?>
                        <button onclick="regenerateInviteCode()" class="btn-icon-action" title="Regenerate">
                            🔄
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Timezone -->
                <div class="setting-card glass-card">
                    <div class="setting-icon">🌍</div>
                    <div class="setting-content">
                        <div class="setting-label">Timezone</div>
                        <div class="setting-value" id="timezoneDisplay">
                            <?php echo htmlspecialchars($family['timezone'] ?? 'Africa/Johannesburg'); ?>
                        </div>
                    </div>
                    <?php if ($user['role'] === 'owner'): ?>
                    <button onclick="editTimezone()" class="btn-icon-action" title="Edit">
                        ✏️
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Created Date -->
                <div class="setting-card glass-card">
                    <div class="setting-icon">📆</div>
                    <div class="setting-content">
                        <div class="setting-label">Created</div>
                        <div class="setting-value">
                            <?php echo date('F j, Y', strtotime($family['created_at'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Family Members -->
        <section class="admin-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="title-icon">👥</span>
                    Family Members
                    <span class="member-count"><?php echo count($members); ?></span>
                </h2>
                <button onclick="showInviteModal()" class="btn btn-primary">
                    + Invite Member
                </button>
            </div>

            <div class="members-grid">
                <?php foreach ($members as $member): ?>
                    <div class="member-card glass-card <?php echo $member['status']; ?>" data-user-id="<?php echo $member['id']; ?>">
                        <div class="member-header">
                            <?php
                            $avatarPath = '/saves/' . $member['id'] . '/avatar/avatar.webp';
                            $avatarExists = file_exists(__DIR__ . '/..' . $avatarPath);
                            if ($avatarExists): ?>
                                <div class="member-avatar" style="background-image: url('<?php echo htmlspecialchars($avatarPath); ?>'); background-size: cover; background-position: center;"></div>
                            <?php else: ?>
                                <div class="member-avatar" style="background: <?php echo htmlspecialchars($member['avatar_color']); ?>">
                                    <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="member-info">
                                <div class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                <div class="member-email"><?php echo htmlspecialchars($member['email']); ?></div>
                                <div class="member-badges">
                                    <span class="badge badge-role badge-<?php echo $member['role']; ?>">
                                        <?php echo ucfirst($member['role']); ?>
                                    </span>
                                    <span class="badge badge-status badge-<?php echo $member['status']; ?>">
                                        <?php echo $member['status'] === 'active' ? '✓ Active' : '✗ Disabled'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="member-stats-grid">
                            <div class="mini-stat">
                                <div class="mini-stat-icon">🛒</div>
                                <div class="mini-stat-value"><?php echo $member['items_added']; ?></div>
                                <div class="mini-stat-label">Items</div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-icon">📝</div>
                                <div class="mini-stat-value"><?php echo $member['notes_created']; ?></div>
                                <div class="mini-stat-label">Notes</div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-icon">📅</div>
                                <div class="mini-stat-value"><?php echo $member['events_created']; ?></div>
                                <div class="mini-stat-label">Events</div>
                            </div>
                        </div>

                        <?php if ($member['id'] !== $user['id']): ?>
                        <div class="member-actions">
                            <?php if ($user['role'] === 'owner' && $member['role'] !== 'owner'): ?>
                                <select onchange="changeUserRole(<?php echo $member['id']; ?>, this.value)" class="role-select">
                                    <option value="">Change Role...</option>
                                    <option value="member" <?php echo $member['role'] === 'member' ? 'selected' : ''; ?>>Member</option>
                                    <option value="admin" <?php echo $member['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            <?php endif; ?>

                            <?php if ($member['status'] === 'active'): ?>
                                <button onclick="toggleUserStatus(<?php echo $member['id']; ?>, 'disabled', '<?php echo htmlspecialchars($member['full_name']); ?>')" 
                                        class="btn btn-sm btn-danger">
                                    Deactivate
                                </button>
                            <?php else: ?>
                                <button onclick="toggleUserStatus(<?php echo $member['id']; ?>, 'active', '<?php echo htmlspecialchars($member['full_name']); ?>')" 
                                        class="btn btn-sm btn-success">
                                    Activate
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="member-actions">
                            <span class="text-muted">This is you</span>
                        </div>
                        <?php endif; ?>

                        <div class="member-footer">
                            <small>Joined <?php echo date('M j, Y', strtotime($member['created_at'])); ?></small>
                            <?php if ($member['last_activity']): ?>
                                <small class="last-activity">
                                    Last active: <?php echo date('M j, g:i A', strtotime($member['last_activity'])); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Recent Activity -->
        <section class="admin-section">
            <h2 class="section-title">
                <span class="title-icon">📊</span>
                Recent Activity
            </h2>

            <div class="activity-timeline glass-card">
                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <p>No recent activity</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="activity-item">
                            <?php
                            $activityAvatarPath = '/saves/' . $activity['user_id'] . '/avatar/avatar.webp';
                            $activityAvatarExists = $activity['user_id'] && file_exists(__DIR__ . '/..' . $activityAvatarPath);
                            if ($activityAvatarExists): ?>
                                <div class="activity-avatar" style="background-image: url('<?php echo htmlspecialchars($activityAvatarPath); ?>'); background-size: cover; background-position: center;"></div>
                            <?php else: ?>
                                <div class="activity-avatar" style="background: <?php echo htmlspecialchars($activity['avatar_color'] ?? '#667eea'); ?>">
                                    <?php echo strtoupper(substr($activity['full_name'] ?? '?', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <strong><?php echo htmlspecialchars($activity['full_name'] ?? 'Someone'); ?></strong>
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $activity['action'])); ?>
                                    <?php if ($activity['entity_type']): ?>
                                        <span class="activity-entity">(<?php echo htmlspecialchars($activity['entity_type']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-time">
                                    <?php
                                    $time = new DateTime($activity['created_at']);
                                    $now = new DateTime();
                                    $diff = $now->diff($time);
                                    
                                    if ($diff->d > 0) {
                                        echo $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
                                    } elseif ($diff->h > 0) {
                                        echo $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                                    } elseif ($diff->i > 0) {
                                        echo $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                                    } else {
                                        echo 'Just now';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    </div>
</main>

<!-- MODALS -->

<!-- Invite Modal -->
<div id="inviteModal" class="modal">
    <div class="modal-content">
        <button onclick="closeModal('inviteModal')" class="modal-close">&times;</button>
        <div class="modal-header">
            <div class="modal-icon">📨</div>
            <h2>Invite Family Member</h2>
        </div>
        <div class="modal-body">
            <?php
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
            $inviteLink = $baseUrl . '/register-join.php?code=' . urlencode($family['invite_code']);
            ?>

            <p class="modal-description">
                Share this link with family members to let them join:
            </p>

            <div class="invite-code-box">
                <code id="inviteLinkDisplay" style="font-size: 0.85rem; word-break: break-all;"><?php echo htmlspecialchars($inviteLink); ?></code>
            </div>

            <button onclick="copyInviteLink()" class="btn btn-primary btn-block" style="margin-top: 15px;">
                🔗 Copy Invite Link
            </button>

            <div class="invite-instructions" style="margin-top: 20px;">
                <h3>How to invite:</h3>
                <ol>
                    <li>Copy the link above</li>
                    <li>Send it via WhatsApp, SMS, or email</li>
                    <li>They click the link and register</li>
                    <li>Done! They're part of your family</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Edit Family Name Modal -->
<div id="editFamilyNameModal" class="modal">
    <div class="modal-content">
        <button onclick="closeModal('editFamilyNameModal')" class="modal-close">&times;</button>
        <div class="modal-header">
            <div class="modal-icon">✏️</div>
            <h2>Edit Family Name</h2>
        </div>
        <div class="modal-body">
            <form id="editFamilyNameForm" onsubmit="saveFamilyName(event)">
                <div class="form-group">
                    <label>Family Name</label>
                    <input type="text" id="newFamilyName" class="form-input" 
                           value="<?php echo htmlspecialchars($family['name']); ?>" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="closeModal('editFamilyNameModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Timezone Modal -->
<div id="editTimezoneModal" class="modal">
    <div class="modal-content">
        <button onclick="closeModal('editTimezoneModal')" class="modal-close">&times;</button>
        <div class="modal-header">
            <div class="modal-icon">🌍</div>
            <h2>Edit Timezone</h2>
        </div>
        <div class="modal-body">
            <form id="editTimezoneForm" onsubmit="saveTimezone(event)">
                <div class="form-group">
                    <label>Timezone</label>
                    <select id="newTimezone" class="form-input" required>
                        <?php
                        $timezones = [
                            'Africa/Johannesburg' => 'South Africa (SAST)',
                            'Europe/London' => 'London (GMT/BST)',
                            'Europe/Paris' => 'Paris (CET/CEST)',
                            'America/New_York' => 'New York (EST/EDT)',
                            'America/Los_Angeles' => 'Los Angeles (PST/PDT)',
                            'Asia/Dubai' => 'Dubai (GST)',
                            'Asia/Tokyo' => 'Tokyo (JST)',
                            'Australia/Sydney' => 'Sydney (AEST/AEDT)'
                        ];
                        
                        $currentTZ = $family['timezone'] ?? 'Africa/Johannesburg';
                        
                        foreach ($timezones as $tz => $label) {
                            $selected = $tz === $currentTZ ? 'selected' : '';
                            echo "<option value=\"$tz\" $selected>$label</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="closeModal('editTimezoneModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>