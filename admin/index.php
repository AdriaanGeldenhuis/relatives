<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/ui.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user || !in_array($user['role'], ['owner', 'admin'], true)) {
    header('Location: ' . ($user ? '/home/' : '/login.php'));
    exit;
}

$userId = (int)$user['id'];
$familyId = (int)$user['family_id'];
$isOwner = $user['role'] === 'owner';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminCheckCsrf();
    $action = $_POST['action'] ?? '';
    $returnTo = in_array($action, ['update_role', 'toggle_user_status'], true) ? '/admin/#members' : '/admin/#settings';
    try {
        switch ($action) {
            case 'update_role':
            case 'toggle_user_status':
                $changingRole = $action === 'update_role';
                $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $value = $_POST[$changingRole ? 'role' : 'status'] ?? '';
                $choices = $changingRole ? ['admin', 'member'] : ['active', 'disabled'];
                if (!$targetId || $targetId === $userId || !in_array($value, $choices, true) || ($changingRole && !$isOwner)) {
                    throw new InvalidArgumentException('That member change is unavailable.');
                }
                $field = $changingRole ? 'role' : 'status';
                $stmt = $db->prepare("UPDATE users SET $field = ?, updated_at = NOW() WHERE id = ? AND family_id = ? AND role <> 'owner'");
                $stmt->execute([$value, $targetId, $familyId]);
                if (!$stmt->rowCount()) {
                    throw new InvalidArgumentException('No change was made to that member.');
                }
                adminRedirect($returnTo, $changingRole ? 'Member role updated.' : 'Member access updated.');
            case 'update_family_name':
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                if (!$isOwner || $name === '' || mb_strlen($name) > 120) {
                    throw new InvalidArgumentException('Enter a family name of 1 to 120 characters.');
                }
                $stmt = $db->prepare('UPDATE families SET name = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$name, $familyId]);
                adminRedirect($returnTo, 'Family name updated.');
            case 'update_timezone':
                $timezone = $_POST['timezone'] ?? '';
                if (!$isOwner || !in_array($timezone, timezone_identifiers_list(), true)) {
                    throw new InvalidArgumentException('Choose a valid timezone.');
                }
                $stmt = $db->prepare('UPDATE families SET timezone = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$timezone, $familyId]);
                adminRedirect($returnTo, 'Family timezone updated.');
            case 'regenerate_invite':
                if (!$isOwner) {
                    throw new InvalidArgumentException('Only the family owner can replace the invite code.');
                }
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $stmt = $db->prepare('UPDATE families SET invite_code = ?, updated_at = NOW() WHERE id = ? AND invite_code <> ?');
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $code = '';
                    for ($character = 0; $character < 8; $character++) {
                        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                    }
                    try {
                        $stmt->execute([$code, $familyId, $code]);
                        if ($stmt->rowCount()) {
                            adminRedirect($returnTo, 'New invite code ready to share.');
                        }
                    } catch (PDOException $e) {
                        if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                            throw $e;
                        }
                    }
                }
                throw new RuntimeException('Could not allocate a new invite code.');
            default:
                throw new InvalidArgumentException('Unknown administration action.');
        }
    } catch (InvalidArgumentException $e) {
        adminRedirect($returnTo, $e->getMessage(), false);
    } catch (Throwable $e) {
        error_log('Family administration: ' . $e->getMessage());
        adminRedirect($returnTo, 'The change could not be saved. Please try again.', false);
    }
}

$stmt = $db->prepare('SELECT name, invite_code, timezone, created_at FROM families WHERE id = ?');
$stmt->execute([$familyId]);
$family = $stmt->fetch();
if (!$family) {
    http_response_code(404);
    exit('Family not found.');
}

$stmt = $db->prepare("
    SELECT u.id, u.full_name, u.email, u.role, u.status, u.created_at,
        (SELECT COUNT(*) FROM shopping_items WHERE added_by = u.id) AS items_added,
        (SELECT COUNT(*) FROM notes WHERE user_id = u.id) AS notes_created,
        (SELECT COUNT(*) FROM events WHERE user_id = u.id) AS events_created,
        (SELECT MAX(created_at) FROM audit_log WHERE user_id = u.id AND family_id = u.family_id) AS last_activity
    FROM users u WHERE u.family_id = ?
    ORDER BY FIELD(u.role, 'owner', 'admin', 'member'), u.full_name, u.id");
$stmt->execute([$familyId]);
$members = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT
        (SELECT COUNT(*) FROM shopping_items si JOIN shopping_lists sl ON sl.id = si.list_id WHERE sl.family_id = ? AND si.status = 'pending') AS shopping,
        (SELECT COUNT(*) FROM notes WHERE family_id = ?) AS notes,
        (SELECT COUNT(*) FROM events WHERE family_id = ? AND starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status = 'pending') AS events");
$stmt->execute([$familyId, $familyId, $familyId]);
$activityCounts = $stmt->fetch();
$activeMembers = count(array_filter($members, static fn(array $member): bool => $member['status'] === 'active'));

$stmt = $db->prepare("
    SELECT al.action, al.entity_type, al.created_at, u.full_name
    FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
    WHERE al.family_id = ? ORDER BY al.created_at DESC LIMIT 10");
$stmt->execute([$familyId]);
$activity = $stmt->fetchAll();

$subscription = null;
if ($isOwner) {
    try {
        $subscription = (new SubscriptionManager($db))->getFamilySubscriptionStatus($familyId);
    } catch (Throwable $e) {
        error_log('Family administration subscription: ' . $e->getMessage());
    }
}
$inviteLink = rtrim($_ENV['APP_URL'], '/') . '/register-join.php?code=' . rawurlencode($family['invite_code']);
$timezone = $family['timezone'] ?: 'Africa/Johannesburg';
$timezoneOptions = [
    'Africa/Johannesburg' => 'South Africa (SAST)', 'Europe/London' => 'London',
    'Europe/Paris' => 'Paris', 'America/New_York' => 'New York',
    'America/Los_Angeles' => 'Los Angeles', 'Asia/Dubai' => 'Dubai',
    'Asia/Tokyo' => 'Tokyo', 'Australia/Sydney' => 'Sydney'
];
if (!isset($timezoneOptions[$timezone])) {
    $timezoneOptions[$timezone] = str_replace('_', ' ', $timezone);
}
$pageTitle = 'Family administration';
require __DIR__ . '/header.php';
?>

<main class="family-main admin-main">
    <header class="family-hero admin-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title"><?= adminEscape($family['name']) ?></h1>
            <p class="family-hero-summary">Manage your family, invite members and review activity.</p>
            <div class="family-actions">
                <button class="family-button family-button-primary" type="button" data-open-dialog="inviteModal">Invite member</button>
                <a href="#members" class="family-button">Family members</a>
            </div>
        </div>
        <?php adminHeroArt(); ?>
    </header>
    <?php adminNavigation($user, 'family'); ?>

    <?php if ($subscription): ?>
        <?php
        [$subscriptionLabel, $subscriptionDetail] = match ($subscription['status']) {
            'active' => ['Subscription active', 'Renews ' . adminDate($subscription['current_period_end'])],
            'trial' => ['Free trial', 'Ends ' . adminDate($subscription['trial_ends_at'])],
            default => ['Trial ended', 'View plans to continue using paid features.']
        };
        ?>
        <aside class="admin-banner">
            <div><strong><?= $subscriptionLabel ?></strong><p><?= adminEscape($subscriptionDetail) ?></p></div>
            <a class="admin-button" href="/admin/plans-public.php">View subscription</a>
        </aside>
    <?php endif; ?>

    <dl class="admin-stats" aria-label="Family at a glance">
        <?php foreach (['Active members' => $activeMembers, 'To buy' => $activityCounts['shopping'], 'Notes' => $activityCounts['notes'], 'Events next 7 days' => $activityCounts['events']] as $label => $count): ?>
            <div><dt><?= $label ?></dt><dd><?= number_format((int)$count) ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <?php adminShowFlash(); ?>
    <p class="admin-feedback" id="adminCopyFeedback" role="status" hidden></p>

    <section class="admin-section" id="settings" aria-labelledby="settingsTitle">
        <h2 class="family-section-title" id="settingsTitle">Family settings</h2>
        <dl class="admin-settings">
            <div class="admin-setting admin-card">
                <div><dt>Family name</dt><dd><?= adminEscape($family['name']) ?></dd></div>
                <?php if ($isOwner): ?><button class="admin-button" type="button" data-open-dialog="nameModal" aria-label="Edit family name">Edit</button><?php endif; ?>
            </div>
            <div class="admin-setting admin-card">
                <div><dt>Invite code</dt><dd><code id="familyInviteCode"><?= adminEscape($family['invite_code']) ?></code></dd></div>
                <button class="admin-button" type="button" data-copy="familyInviteCode">Copy code</button>
            </div>
            <div class="admin-setting admin-card">
                <div><dt>Timezone</dt><dd><?= adminEscape($timezoneOptions[$timezone]) ?></dd></div>
                <?php if ($isOwner): ?><button class="admin-button" type="button" data-open-dialog="timezoneModal" aria-label="Edit family timezone">Edit</button><?php endif; ?>
            </div>
            <div class="admin-setting admin-card">
                <div><dt>Family created</dt><dd><?= adminEscape(date('j M Y', strtotime($family['created_at']))) ?></dd></div>
            </div>
        </dl>
    </section>

    <section class="admin-section" id="members" aria-labelledby="membersTitle">
        <div class="admin-section-head">
            <h2 class="family-section-title" id="membersTitle">Family members</h2>
            <?php if (count($members) > 1): ?>
                <label class="admin-filter" hidden>Search members <input type="search" placeholder="Name or email" data-filter="membersGrid"></label>
            <?php endif; ?>
        </div>
        <div class="admin-members" id="membersGrid">
            <?php foreach ($members as $member): ?>
                <?php
                $memberId = (int)$member['id'];
                $name = adminEscape($member['full_name']);
                $initial = adminEscape(mb_strtoupper(mb_substr($member['full_name'], 0, 1)));
                $avatar = avatarUrl($memberId);
                $canManage = $memberId !== $userId && $member['role'] !== 'owner';
                ?>
                <article class="admin-member admin-card" data-search-row data-search-text="<?= adminEscape($member['full_name'] . ' ' . $member['email']) ?>">
                    <div class="admin-member-head">
                        <span class="admin-avatar" aria-hidden="true">
                            <?php if (!str_ends_with($avatar, 'v=0')): ?><img alt="" src="<?= $avatar ?>"><?php else: ?><?= $initial ?><?php endif; ?>
                        </span>
                        <div>
                            <h3><?= $name ?><?= $memberId === $userId ? ' <small>(you)</small>' : '' ?></h3>
                            <p><?= adminEscape($member['email']) ?></p>
                            <span class="admin-chip"><?= adminEscape(ucfirst($member['role'])) ?></span>
                            <span class="admin-chip" data-status="<?= adminEscape($member['status']) ?>"><?= adminEscape(ucfirst($member['status'])) ?></span>
                        </div>
                    </div>
                    <dl class="admin-member-stats">
                        <?php foreach (['Items' => $member['items_added'], 'Notes' => $member['notes_created'], 'Events' => $member['events_created']] as $label => $count): ?>
                            <div><dt><?= $label ?></dt><dd><?= number_format((int)$count) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <?php if ($canManage): ?>
                        <div class="admin-member-actions">
                            <?php if ($isOwner): ?>
                                <form method="post" action="/admin/" data-confirm="Save this role change for <?= $name ?>?">
                                    <?= adminCsrfField() ?>
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?= $memberId ?>">
                                    <label class="admin-sr-only" for="role<?= $memberId ?>">Role for <?= $name ?></label>
                                    <select id="role<?= $memberId ?>" name="role">
                                        <option value="member"<?= $member['role'] === 'member' ? ' selected' : '' ?>>Member</option>
                                        <option value="admin"<?= $member['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                                    </select>
                                    <button class="admin-button" type="submit">Save role</button>
                                </form>
                            <?php endif; ?>
                            <?php $deactivate = $member['status'] === 'active'; ?>
                            <form method="post" action="/admin/" data-confirm="<?= $deactivate ? 'Deactivate' : 'Activate' ?> <?= $name ?>?">
                                <?= adminCsrfField() ?>
                                <input type="hidden" name="action" value="toggle_user_status">
                                <input type="hidden" name="user_id" value="<?= $memberId ?>">
                                <input type="hidden" name="status" value="<?= $deactivate ? 'disabled' : 'active' ?>">
                                <button class="admin-button<?= $deactivate ? ' admin-danger' : '' ?>" type="submit"><?= $deactivate ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <footer>Joined <?= adminEscape(date('j M Y', strtotime($member['created_at']))) ?><?php if ($member['last_activity']): ?> · Last activity <?= adminEscape(adminDate($member['last_activity'])) ?><?php endif; ?></footer>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="admin-empty admin-card" data-filter-empty="membersGrid" hidden>No family members match your search.</p>
    </section>

    <section class="admin-section" aria-labelledby="activityTitle">
        <h2 class="family-section-title" id="activityTitle">Recent activity</h2>
        <?php if ($activity): ?>
            <ul class="admin-activity admin-card">
                <?php foreach ($activity as $entry): ?>
                    <li>
                        <span><strong><?= adminEscape($entry['full_name'] ?? 'Family member') ?></strong>
                            <?= adminEscape(str_replace('_', ' ', $entry['action'])) ?> <?= adminEscape($entry['entity_type']) ?></span>
                        <time datetime="<?= adminEscape(date(DATE_ATOM, strtotime($entry['created_at']))) ?>"><?= adminEscape(adminDate($entry['created_at'])) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="admin-empty admin-card">No recent activity.</p>
        <?php endif; ?>
    </section>
</main>

<dialog id="inviteModal" class="admin-dialog" aria-labelledby="inviteTitle">
    <header><h2 id="inviteTitle">Invite a family member</h2><button class="admin-button" type="button" data-close-dialog>Close</button></header>
    <div class="admin-dialog-body">
        <p>Share this link with the person you’d like to join your family.</p>
        <label>Invite link <input id="familyInviteLink" value="<?= adminEscape($inviteLink) ?>" readonly></label>
        <div class="admin-dialog-actions">
            <button class="admin-button admin-primary" type="button" data-copy="familyInviteLink">Copy invite link</button>
            <?php if ($isOwner): ?>
                <form method="post" action="/admin/" data-confirm="Replace the invite code? Previously shared links will stop working.">
                    <?= adminCsrfField() ?>
                    <input type="hidden" name="action" value="regenerate_invite">
                    <button class="admin-button" type="submit">Replace invite code</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="admin-feedback" role="status" hidden></p>
    </div>
</dialog>

<?php if ($isOwner): ?>
    <dialog id="nameModal" class="admin-dialog" aria-labelledby="nameTitle">
        <header><h2 id="nameTitle">Edit family name</h2><button class="admin-button" type="button" data-close-dialog>Close</button></header>
        <form class="admin-dialog-body" method="post" action="/admin/">
            <?= adminCsrfField() ?>
            <input type="hidden" name="action" value="update_family_name">
            <label>Family name <input name="name" value="<?= adminEscape($family['name']) ?>" maxlength="120" required autofocus></label>
            <button class="admin-button admin-primary" type="submit">Save name</button>
        </form>
    </dialog>

    <dialog id="timezoneModal" class="admin-dialog" aria-labelledby="timezoneTitle">
        <header><h2 id="timezoneTitle">Edit timezone</h2><button class="admin-button" type="button" data-close-dialog>Close</button></header>
        <form class="admin-dialog-body" method="post" action="/admin/">
            <?= adminCsrfField() ?>
            <input type="hidden" name="action" value="update_timezone">
            <label>Timezone
                <select name="timezone" autofocus required>
                    <?php foreach ($timezoneOptions as $zone => $label): ?>
                        <option value="<?= adminEscape($zone) ?>"<?= $zone === $timezone ? ' selected' : '' ?>><?= adminEscape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="admin-button admin-primary" type="submit">Save timezone</button>
        </form>
    </dialog>
<?php endif; ?>

<?php require __DIR__ . '/../shared/components/footer.php'; ?>
