<?php
/**
 * ============================================
 * PERMISSION ENGINE - MULTI-USER ACCESS CONTROL
 * ============================================
 * Handles:
 * - View/Edit/Delete permissions
 * - Shared calendars
 * - Role-based access
 * - Approval workflows
 * ============================================
 */

class PermissionEngine {
    private $db;
    private $userId;
    private $familyId;
    private static $instance = null;

    // Permission levels
    const PERM_NONE = 0;
    const PERM_VIEW = 1;
    const PERM_EDIT = 2;
    const PERM_MANAGE = 3;
    const PERM_ADMIN = 4;

    // Permission names
    const PERM_NAMES = [
        0 => 'none',
        1 => 'view',
        2 => 'edit',
        3 => 'manage',
        4 => 'admin'
    ];

    // Actions
    const ACTION_VIEW = 'view';
    const ACTION_CREATE = 'create';
    const ACTION_EDIT = 'edit';
    const ACTION_DELETE = 'delete';
    const ACTION_ASSIGN = 'assign';
    const ACTION_APPROVE = 'approve';

    private function __construct($db, $userId = null, $familyId = null) {
        $this->db = $db;
        $this->userId = $userId;
        $this->familyId = $familyId;
    }

    public static function getInstance($db, $userId = null, $familyId = null) {
        if (self::$instance === null) {
            self::$instance = new self($db, $userId, $familyId);
        }
        return self::$instance;
    }

    public function setUser($userId, $familyId) {
        $this->userId = $userId;
        $this->familyId = $familyId;
        return $this;
    }

    // ============================================
    // CHECK PERMISSIONS
    // ============================================

    /**
     * Check if user can perform action on event
     */
    public function canPerformAction(int $eventId, string $action): bool {
        $event = $this->getEventBasic($eventId);
        if (!$event) return false;

        // Check family membership
        if ($event['family_id'] != $this->familyId) {
            return false;
        }

        $permission = $this->getEffectivePermission($eventId);

        switch ($action) {
            case self::ACTION_VIEW:
                return $permission >= self::PERM_VIEW;

            case self::ACTION_CREATE:
                // Anyone in family can create
                return true;

            case self::ACTION_EDIT:
                // Owner, assigned user, or editors
                if ($event['user_id'] == $this->userId ||
                    $event['created_by'] == $this->userId ||
                    $event['assigned_to'] == $this->userId) {
                    return true;
                }
                return $permission >= self::PERM_EDIT;

            case self::ACTION_DELETE:
                // Only owner or managers
                if ($event['user_id'] == $this->userId ||
                    $event['created_by'] == $this->userId) {
                    return true;
                }
                return $permission >= self::PERM_MANAGE;

            case self::ACTION_ASSIGN:
                // Owner or managers
                if ($event['user_id'] == $this->userId ||
                    $event['created_by'] == $this->userId) {
                    return true;
                }
                return $permission >= self::PERM_MANAGE;

            case self::ACTION_APPROVE:
                // Only admins
                return $permission >= self::PERM_ADMIN;

            default:
                return false;
        }
    }

    /**
     * Get effective permission level for event
     */
    public function getEffectivePermission(int $eventId): int {
        // Check if owner
        $event = $this->getEventBasic($eventId);
        if ($event && ($event['user_id'] == $this->userId || $event['created_by'] == $this->userId)) {
            return self::PERM_ADMIN;
        }

        // Check if assigned
        if ($event && $event['assigned_to'] == $this->userId) {
            return self::PERM_EDIT;
        }

        // Check user's role in family
        $familyRole = $this->getFamilyRole();
        if ($familyRole === 'admin' || $familyRole === 'parent') {
            return self::PERM_MANAGE;
        }

        // Check explicit calendar permissions
        $calendarPerm = $this->getCalendarPermission($event['user_id'] ?? 0);
        if ($calendarPerm > 0) {
            return $calendarPerm;
        }

        // Default: family members can view
        return self::PERM_VIEW;
    }

    /**
     * Get user's role in family
     */
    private function getFamilyRole(): ?string {
        $stmt = $this->db->prepare("
            SELECT role FROM users WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$this->userId, $this->familyId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['role'] ?? null;
    }

    /**
     * Get calendar-specific permission
     */
    private function getCalendarPermission(int $calendarOwnerId): int {
        $stmt = $this->db->prepare("
            SELECT permission_level
            FROM calendar_permissions
            WHERE calendar_owner_id = ?
            AND granted_to_user_id = ?
        ");
        $stmt->execute([$calendarOwnerId, $this->userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['permission_level'] : 0;
    }

    private function getEventBasic(int $eventId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, family_id, user_id, created_by, assigned_to
            FROM events WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ============================================
    // MANAGE CALENDAR SHARING
    // ============================================

    /**
     * Share calendar with another user
     */
    public function shareCalendar(int $targetUserId, int $permissionLevel): bool {
        // Can only share own calendar
        $stmt = $this->db->prepare("
            INSERT INTO calendar_permissions
            (calendar_owner_id, granted_to_user_id, permission_level, granted_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            permission_level = VALUES(permission_level),
            updated_at = NOW()
        ");

        return $stmt->execute([
            $this->userId,
            $targetUserId,
            $permissionLevel,
            $this->userId
        ]);
    }

    /**
     * Revoke calendar access
     */
    public function revokeCalendarAccess(int $targetUserId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM calendar_permissions
            WHERE calendar_owner_id = ?
            AND granted_to_user_id = ?
        ");
        return $stmt->execute([$this->userId, $targetUserId]);
    }

    /**
     * Get users who have access to my calendar
     */
    public function getCalendarShares(): array {
        $stmt = $this->db->prepare("
            SELECT cp.*, u.full_name, u.avatar_color
            FROM calendar_permissions cp
            JOIN users u ON cp.granted_to_user_id = u.id
            WHERE cp.calendar_owner_id = ?
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get calendars shared with me
     */
    public function getSharedWithMe(): array {
        $stmt = $this->db->prepare("
            SELECT cp.*, u.full_name, u.avatar_color
            FROM calendar_permissions cp
            JOIN users u ON cp.calendar_owner_id = u.id
            WHERE cp.granted_to_user_id = ?
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // FAMILY MEMBER ACCESS
    // ============================================

    /**
     * Get family members with their permission levels
     */
    public function getFamilyMembers(): array {
        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.full_name,
                u.avatar_color,
                u.role,
                CASE
                    WHEN u.role IN ('admin', 'parent') THEN 'manage'
                    WHEN u.role = 'child' THEN 'view'
                    ELSE 'view'
                END as default_permission
            FROM users u
            WHERE u.family_id = ?
            AND u.status = 'active'
            ORDER BY u.role, u.full_name
        ");
        $stmt->execute([$this->familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get users who can be assigned events
     */
    public function getAssignableUsers(): array {
        // All active family members can be assigned
        return $this->getFamilyMembers();
    }

    // ============================================
    // APPROVAL WORKFLOW
    // ============================================

    /**
     * Submit event for approval
     */
    public function submitForApproval(int $eventId): bool {
        $stmt = $this->db->prepare("
            INSERT INTO event_approvals
            (event_id, submitted_by, status, submitted_at)
            VALUES (?, ?, 'pending', NOW())
        ");
        return $stmt->execute([$eventId, $this->userId]);
    }

    /**
     * Approve event
     */
    public function approveEvent(int $eventId): bool {
        if (!$this->canPerformAction($eventId, self::ACTION_APPROVE)) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE event_approvals
            SET status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE event_id = ?
            AND status = 'pending'
        ");
        $stmt->execute([$this->userId, $eventId]);

        // Update event status
        $stmt = $this->db->prepare("
            UPDATE events SET status = 'pending' WHERE id = ?
        ");
        return $stmt->execute([$eventId]);
    }

    /**
     * Reject event
     */
    public function rejectEvent(int $eventId, string $reason = null): bool {
        if (!$this->canPerformAction($eventId, self::ACTION_APPROVE)) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE event_approvals
            SET status = 'rejected',
                reviewed_by = ?,
                reviewed_at = NOW(),
                rejection_reason = ?
            WHERE event_id = ?
            AND status = 'pending'
        ");
        $stmt->execute([$this->userId, $reason, $eventId]);

        // Update event status
        $stmt = $this->db->prepare("
            UPDATE events SET status = 'rejected' WHERE id = ?
        ");
        return $stmt->execute([$eventId]);
    }

    /**
     * Get pending approvals
     */
    public function getPendingApprovals(): array {
        $role = $this->getFamilyRole();
        if (!in_array($role, ['admin', 'parent'])) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT ea.*, e.title, e.starts_at, e.kind,
                   u.full_name as submitted_by_name
            FROM event_approvals ea
            JOIN events e ON ea.event_id = e.id
            JOIN users u ON ea.submitted_by = u.id
            WHERE e.family_id = ?
            AND ea.status = 'pending'
            ORDER BY ea.submitted_at ASC
        ");
        $stmt->execute([$this->familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // NOTIFICATIONS FOR SHARED EVENTS
    // ============================================

    /**
     * Notify relevant users of event change
     */
    public function notifyEventChange(int $eventId, string $action): void {
        $event = $this->getEventBasic($eventId);
        if (!$event) return;

        $usersToNotify = [];

        // Event owner
        if ($event['user_id'] != $this->userId) {
            $usersToNotify[] = $event['user_id'];
        }

        // Assigned user
        if ($event['assigned_to'] && $event['assigned_to'] != $this->userId) {
            $usersToNotify[] = $event['assigned_to'];
        }

        // Users with calendar access
        $stmt = $this->db->prepare("
            SELECT granted_to_user_id FROM calendar_permissions
            WHERE calendar_owner_id = ?
            AND permission_level >= ?
        ");
        $stmt->execute([$event['user_id'], self::PERM_VIEW]);
        $sharedWith = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $usersToNotify = array_unique(array_merge($usersToNotify, $sharedWith));

        // Create notifications
        if (class_exists('NotificationManager') && !empty($usersToNotify)) {
            $notifManager = NotificationManager::getInstance($this->db);

            $stmt = $this->db->prepare("
                SELECT title FROM events WHERE id = ?
            ");
            $stmt->execute([$eventId]);
            $eventData = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("
                SELECT full_name FROM users WHERE id = ?
            ");
            $stmt->execute([$this->userId]);
            $actor = $stmt->fetch(PDO::FETCH_ASSOC);

            $messages = [
                'create' => 'created a new event',
                'update' => 'updated an event',
                'delete' => 'deleted an event',
                'complete' => 'completed an event'
            ];

            foreach ($usersToNotify as $userId) {
                $notifManager->create([
                    'user_id' => $userId,
                    'from_user_id' => $this->userId,
                    'type' => 'calendar',
                    'title' => 'Calendar Update',
                    'message' => ($actor['full_name'] ?? 'Someone') . ' ' .
                                 ($messages[$action] ?? 'modified an event') . ': ' .
                                 ($eventData['title'] ?? 'Untitled'),
                    'action_url' => '/calendar/',
                    'priority' => 'low',
                    'data' => ['event_id' => $eventId, 'action' => $action]
                ]);
            }
        }
    }

    // ============================================
    // FILTER EVENTS BY PERMISSION
    // ============================================

    /**
     * Filter events array to only those user can view
     */
    public function filterViewable(array $events): array {
        return array_filter($events, function($event) {
            $eventId = is_array($event) ? $event['id'] : $event;
            return $this->canPerformAction($eventId, self::ACTION_VIEW);
        });
    }

    /**
     * Add permission info to events
     */
    public function enrichWithPermissions(array $events): array {
        return array_map(function($event) {
            $permission = $this->getEffectivePermission($event['id']);
            $event['_permission'] = [
                'level' => $permission,
                'level_name' => self::PERM_NAMES[$permission] ?? 'unknown',
                'can_edit' => $permission >= self::PERM_EDIT,
                'can_delete' => $permission >= self::PERM_MANAGE,
                'can_assign' => $permission >= self::PERM_MANAGE,
                'is_owner' => $event['user_id'] == $this->userId ||
                             $event['created_by'] == $this->userId
            ];
            return $event;
        }, $events);
    }
}
