<?php
declare(strict_types=1);

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

    // Get user's location update interval (default 60 seconds for battery efficiency)
    $pollingInterval = (int)($user['location_update_interval'] ?? 60);
    // Ensure minimum 10 seconds, maximum 300 seconds
    $pollingInterval = max(10, min(300, $pollingInterval));

} catch (Exception $e) {
    error_log('Tracking page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get family members
$familyMembers = [];
try {
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.full_name,
            u.avatar_color,
            u.has_avatar,
            u.location_sharing,
            ts.is_tracking_enabled,
            ts.update_interval_seconds
        FROM users u
        LEFT JOIN tracking_settings ts ON u.id = ts.user_id
        WHERE u.family_id = ? 
          AND u.status = 'active'
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$user['family_id']]);
    $familyMembers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Members fetch error: ' . $e->getMessage());
}

// Get zones
$zones = [];
try {
    $stmt = $db->prepare("
        SELECT 
            id, name, type, center_lat, center_lng, 
            radius_m, polygon_json, color, icon, is_active
        FROM tracking_zones
        WHERE family_id = ? AND is_active = 1
        ORDER BY name ASC
    ");
    $stmt->execute([$user['family_id']]);
    $zones = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Zones fetch error: ' . $e->getMessage());
}

$pageTitle = 'Family Tracking';
$activePage = 'tracking';
$pageCSS = ['/tracking/css/tracking.css'];
$pageJS = ['/tracking/js/tracking.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="tracking-container">
    <!-- Map -->
    <div id="trackingMap" class="tracking-map"></div>
    
    <!-- Enhanced Toolbar -->
    <div class="tracking-toolbar">
        <button class="toolbar-btn" id="myLocationBtn" title="Center on Me" data-tooltip="Center on Me">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
            </svg>
        </button>
        
        <button class="toolbar-btn" id="familyViewBtn" title="View All" data-tooltip="View All Family">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
        </button>
        
        <button class="toolbar-btn" id="zonesToggleBtn" title="Toggle Zones" data-tooltip="Toggle Zones">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
                <line x1="15" y1="3" x2="15" y2="21"></line>
            </svg>
        </button>
        
        <button class="toolbar-btn" id="historyBtn" title="History" data-tooltip="View History">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
        </button>
        
        <div class="toolbar-divider"></div>
        
        <button class="toolbar-btn" id="mapStyleBtn" title="Map Style" data-tooltip="Toggle Map Style">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
        </button>
        
        <button class="toolbar-btn" id="settingsBtn" title="Settings" data-tooltip="Settings">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
        </button>
    </div>
    
    <!-- Status Bar -->
    <div class="tracking-status-bar" id="statusBar">
        <div class="status-indicator">
            <div class="status-pulse"></div>
            <span class="status-text">Tracking Active</span>
        </div>
        <div class="status-info">
            <span id="memberCount"><?= count($familyMembers) ?> members</span>
            <span class="status-divider">•</span>
            <span id="lastUpdate">Just now</span>
        </div>
    </div>
    
    <!-- Enhanced Sidebar -->
    <div class="tracking-sidebar" id="trackingSidebar">
        <div class="sidebar-header">
            <div class="sidebar-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <h2>Family Members</h2>
            </div>
            <button class="sidebar-close" id="sidebarClose">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        
        <div class="sidebar-search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
            </svg>
            <input type="text" id="memberSearch" placeholder="Search members...">
        </div>
        
        <div class="sidebar-content">
            <div class="member-list" id="memberList">
                <?php if (empty($familyMembers)): ?>
                    <div class="empty-state">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <p>No family members found</p>
                        <span class="empty-hint">Invite members to start tracking</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($familyMembers as $member): ?>
                        <div class="member-card" data-user-id="<?= $member['id'] ?>" data-name="<?= htmlspecialchars(strtolower($member['full_name'])) ?>">
                            <div class="member-avatar" style="background: <?= htmlspecialchars($member['avatar_color']) ?>">
                                <?php 
                                $avatarPath = __DIR__ . "/../saves/{$member['id']}/avatar/avatar.webp";
                                if (file_exists($avatarPath)): 
                                ?>
                                    <img src="/saves/<?= $member['id'] ?>/avatar/avatar.webp?<?= time() ?>" 
                                         alt="<?= htmlspecialchars($member['full_name']) ?>" 
                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <?= strtoupper(substr($member['full_name'], 0, 1)) ?>
                                <?php endif; ?>
                                <div class="member-status-dot"></div>
                            </div>
                            
                            <div class="member-info">
                                <div class="member-header">
                                    <span class="member-name"><?= htmlspecialchars($member['full_name']) ?></span>
                                    <?php if ($member['id'] == $user['id']): ?>
                                        <span class="member-badge">You</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="member-status">
                                    <?php if ($member['location_sharing'] && $member['is_tracking_enabled']): ?>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="12" cy="12" r="10"></circle>
                                        </svg>
                                        <span>Tracking</span>
                                    <?php else: ?>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="12" cy="12" r="10"></circle>
                                        </svg>
                                        <span>Offline</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="member-details">
                                    <div class="detail-row">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                        <span class="last-seen-time">--</span>
                                    </div>
                                    <div class="detail-row">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                                            <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                                            <line x1="6" y1="1" x2="6" y2="4"></line>
                                            <line x1="10" y1="1" x2="10" y2="4"></line>
                                            <line x1="14" y1="1" x2="14" y2="4"></line>
                                        </svg>
                                        <span class="member-speed">-- km/h</span>
                                    </div>
                                    <div class="detail-row">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="1" y="6" width="18" height="12" rx="2" ry="2"></rect>
                                            <line x1="23" y1="13" x2="23" y2="11"></line>
                                        </svg>
                                        <span class="member-battery">--%</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="member-actions">
                                <button class="action-btn" onclick="window.TrackingMap.centerOnMember(<?= $member['id'] ?>); event.stopPropagation();" title="Center">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="10" r="3"></circle>
                                        <path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"></path>
                                    </svg>
                                </button>
                                <button class="action-btn" onclick="window.TrackingMap.showMemberHistory(<?= $member['id'] ?>); event.stopPropagation();" title="History">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="1 4 1 10 7 10"></polyline>
                                        <polyline points="23 20 23 14 17 14"></polyline>
                                        <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="zones-section">
                <div class="section-header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                    <h3>Places & Zones</h3>
                </div>
                
                <div class="zones-list" id="zonesList">
                    <?php if (empty($zones)): ?>
                        <div class="empty-state-small">
                            <p>No zones defined</p>
                            <button class="btn-primary-small" onclick="window.TrackingMap.openZoneCreator()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                                Add Zone
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($zones as $zone): ?>
                            <div class="zone-card" data-zone-id="<?= $zone['id'] ?>">
                                <div class="zone-header">
                                    <span class="zone-icon"><?= htmlspecialchars($zone['icon']) ?></span>
                                    <span class="zone-name"><?= htmlspecialchars($zone['name']) ?></span>
                                    <div class="zone-color" style="background: <?= htmlspecialchars($zone['color']) ?>"></div>
                                </div>
                                <button class="zone-toggle" onclick="window.TrackingMap.toggleZone(<?= $zone['id'] ?>)">
                                    <svg class="zone-visible" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    <svg class="zone-hidden" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                        <line x1="1" y1="1" x2="23" y2="23"></line>
                                    </svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                        <button class="btn-secondary-block" onclick="window.TrackingMap.openZoneCreator()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Add New Zone
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Mobile Toggle -->
    <button class="sidebar-toggle-mobile" id="sidebarToggleMobile">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
        </svg>
        <span class="toggle-badge"><?= count($familyMembers) ?></span>
    </button>
</div>

<!-- History Modal -->
<div class="modal" id="historyModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-large">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <h2>Location History</h2>
            </div>
            <button class="modal-close" id="closeHistoryModal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="history-controls">
                <select id="historyMemberSelect" class="form-select">
                    <option value="">Select member...</option>
                    <?php foreach ($familyMembers as $member): ?>
                        <option value="<?= $member['id'] ?>">
                            <?= htmlspecialchars($member['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="historyDateSelect" class="form-input" value="<?= date('Y-m-d') ?>">
                <button class="btn-primary" onclick="window.TrackingMap.loadHistory()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                    </svg>
                    Load
                </button>
            </div>
            <div id="historyResults" class="history-results">
                <div class="empty-state">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <p>Select a member and date to view history</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal with Avatar Upload -->
<div class="modal" id="settingsModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M12 1v6m0 6v6"></path>
                    <path d="M17 8.517a9 9 0 1 0-10 0"></path>
                    <path d="M19.07 4.93A10 10 0 0 0 4.93 19.07M4.93 4.93A10 10 0 0 1 19.07 19.07"></path>
                </svg>
                <h2>Tracking Settings</h2>
            </div>
            <button class="modal-close" id="closeSettingsModal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="settingsForm">
                <!-- Avatar Upload Section -->
                <div class="settings-section">
                    <h3 class="section-title">Profile Avatar</h3>
                    
                    <div class="avatar-upload-container">
                        <div class="avatar-preview" id="avatarPreview" style="
                            width: 120px;
                            height: 120px;
                            border-radius: 50%;
                            background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-size: 48px;
                            font-weight: 900;
                            color: white;
                            margin: 0 auto 20px;
                            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
                            position: relative;
                            overflow: hidden;
                            cursor: pointer;
                        " onclick="document.getElementById('avatarInput').click()">
                            <?php 
                            $avatarPath = __DIR__ . "/../saves/{$user['id']}/avatar/avatar.webp";
                            if (file_exists($avatarPath)): 
                            ?>
                                <img src="/saves/<?php echo $user['id']; ?>/avatar/avatar.webp?<?php echo time(); ?>" 
                                     alt="Avatar" 
                                     style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="avatar-upload-controls">
                            <input type="file" 
                                   id="avatarInput" 
                                   name="avatar" 
                                   accept="image/jpeg,image/png,image/gif,image/webp"
                                   style="display: none;"
                                   onchange="handleAvatarUpload(this)">
                            
                            <button type="button" 
                                    class="btn-primary-block"
                                    onclick="document.getElementById('avatarInput').click()"
                                    style="margin-bottom: 10px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                Upload New Avatar
                            </button>
                            
                            <p class="form-hint" style="text-align: center;">
                                JPG, PNG, GIF or WebP (max 5MB)
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Location Tracking Section -->
                <div class="settings-section">
                    <h3 class="section-title">Location Tracking</h3>
                    
                    <div class="form-group">
                        <label class="form-label">Update Interval</label>
                        <select name="update_interval_seconds" class="form-select">
                            <option value="10">Every 10 seconds (High accuracy)</option>
                            <option value="30">Every 30 seconds (Balanced)</option>
                            <option value="60" selected>Every 60 seconds (Recommended)</option>
                            <option value="120">Every 2 minutes (Battery saver)</option>
                            <option value="300">Every 5 minutes (Low frequency)</option>
                        </select>
                        <span class="form-hint">60 seconds recommended for battery efficiency</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="is_tracking_enabled" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-text">Enable tracking for this device</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="high_accuracy_mode" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-text">High accuracy mode (GPS)</span>
                        </label>
                        <span class="form-hint">Uses GPS for better accuracy (higher battery usage)</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="background_tracking" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-text">Continue tracking in background</span>
                        </label>
                    </div>
                </div>
                
                <!-- Display Options Section -->
                <div class="settings-section">
                    <h3 class="section-title">Display Options</h3>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="show_speed" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-text">Show speed on map</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="show_battery" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-text">Show battery level</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="show_accuracy" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-text">Show accuracy circle</span>
                        </label>
                    </div>
                </div>
                
                <!-- Data Management Section -->
                <div class="settings-section">
                    <h3 class="section-title">Data Management</h3>
                    
                    <div class="form-group">
                        <label class="form-label">History Retention</label>
                        <div class="input-group">
                            <input type="number" 
                                   name="history_retention_days" 
                                   class="form-input" 
                                   value="30" 
                                   min="1" 
                                   max="365"
                                   style="flex: 1;">
                            <span class="input-suffix">days</span>
                        </div>
                        <span class="form-hint">Location history older than this will be deleted</span>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary-block">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Save Settings
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay" style="display: none;">
    <div class="loading-spinner"></div>
    <p class="loading-text">Loading...</p>
</div>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Config -->
<script>
window.TrackingConfig = {
    familyId: <?= $user['family_id'] ?>,
    userId: <?= $user['id'] ?>,
    members: <?= json_encode($familyMembers) ?>,
    zones: <?= json_encode($zones) ?>,
    defaultCenter: [-26.2041, 28.0473],
    defaultZoom: 12,

    // Polling intervals (seconds)
    pollingInterval: <?= $pollingInterval ?>,           // User's location update interval
    pollingIntervalDefault: <?= max(10, $pollingInterval) ?>, // Normal polling (default: user setting or 30s)
    pollingIntervalViewing: 10,                         // Fast polling when viewing someone (10s)
    pollingIntervalMin: 5,                              // Minimum allowed (5s)

    // Optional Mapbox token (set in .env as MAPBOX_TOKEN)
    mapboxToken: <?= json_encode($_ENV['MAPBOX_TOKEN'] ?? null) ?>
};

/**
 * Enhanced Avatar Upload Handler
 * Supports both browser file input AND native app base64
 */
async function handleAvatarUpload(input) {
    const file = input.files[0];
    if (!file) return;
    
    if (file.size > 5 * 1024 * 1024) {
        window.TrackingMap.showToast('File too large (max 5MB)', 'error');
        return;
    }
    
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        window.TrackingMap.showToast('Invalid file type. Only images allowed.', 'error');
        return;
    }
    
    // Show preview
    const reader = new FileReader();
    reader.onload = (e) => {
        const preview = document.getElementById('avatarPreview');
        if (preview) {
            preview.innerHTML = `
                <img src="${e.target.result}" 
                     alt="Avatar Preview" 
                     style="width: 100%; height: 100%; object-fit: cover;">
            `;
        }
    };
    reader.readAsDataURL(file);
    
    // Upload
    window.TrackingMap.showLoading(true);
    
    try {
        const formData = new FormData();
        formData.append('avatar', file);
        
        const response = await fetch('/tracking/api/upload_avatar.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Upload response:', text);
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Upload failed');
        }
        
        window.TrackingMap.showToast('Avatar uploaded successfully! 🎉', 'success');
        
        // Refresh page to show new avatar everywhere
        setTimeout(() => {
            location.reload();
        }, 1500);
        
    } catch (error) {
        console.error('Avatar upload failed:', error);
        window.TrackingMap.showToast('Failed to upload avatar: ' + error.message, 'error');
    } finally {
        window.TrackingMap.showLoading(false);
    }
}

/**
 * Native App Bridge: Upload avatar from base64
 * Called by Android/iOS app
 */
window.uploadAvatarFromBase64 = async function(base64Data) {
    console.log('uploadAvatarFromBase64 called');
    
    if (!base64Data || base64Data.length === 0) {
        window.TrackingMap.showToast('No image data provided', 'error');
        return { success: false, error: 'No data' };
    }
    
    window.TrackingMap.showLoading(true);
    
    try {
        const response = await fetch('/tracking/api/upload_avatar.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                avatar_base64: base64Data
            })
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Upload response:', text);
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Upload failed');
        }
        
        // Update preview
        const preview = document.getElementById('avatarPreview');
        if (preview) {
            preview.innerHTML = `
                <img src="${data.avatar_url}" 
                     alt="Avatar" 
                     style="width: 100%; height: 100%; object-fit: cover;">
            `;
        }
        
        window.TrackingMap.showToast('Avatar uploaded successfully! 🎉', 'success');
        
        setTimeout(() => {
            location.reload();
        }, 1500);
        
        return { success: true, avatar_url: data.avatar_url };
        
    } catch (error) {
        console.error('Avatar upload failed:', error);
        window.TrackingMap.showToast('Failed to upload avatar: ' + error.message, 'error');
        return { success: false, error: error.message };
    } finally {
        window.TrackingMap.showLoading(false);
    }
};

// Modal close handlers
document.getElementById('closeHistoryModal')?.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    window.TrackingMap.closeHistoryModal();
});

document.getElementById('closeSettingsModal')?.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    window.TrackingMap.closeSettingsModal();
});
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>