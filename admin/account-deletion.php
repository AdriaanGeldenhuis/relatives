<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/ui.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user || !adminPlatformAccess($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$requests = $db->query(
    'SELECT r.user_id, r.requested_at, u.email, u.family_id
     FROM account_deletion_requests r
     JOIN users u ON u.id = r.user_id
     ORDER BY r.requested_at ASC, r.user_id'
)->fetchAll();
$pageTitle = 'Account deletion requests';
require __DIR__ . '/header.php';
?>

<main class="family-main admin-main">
    <?php adminHero('Account deletion requests', 'Review pending requests with the privacy team.'); ?>
    <?php adminNavigation($user, 'deletion'); ?>

    <section class="admin-section" aria-labelledby="deletionTitle">
        <div class="admin-section-head">
            <h2 class="family-section-title" id="deletionTitle">Pending requests</h2>
            <?php if ($requests): ?>
                <label class="admin-filter" hidden>Search requests <input type="search" placeholder="Email or user ID" data-filter="requestRows"></label>
            <?php endif; ?>
        </div>
        <p class="admin-section-note">Follow the retention policy when removing accounts and their associated data. A pending request clears when its user record is deleted.</p>
        <?php if (!$requests): ?>
            <p class="admin-card admin-empty">No pending requests.</p>
        <?php else: ?>
            <div class="admin-table-wrap admin-card" tabindex="0" role="region" aria-labelledby="deletionTitle">
                <table class="admin-table">
                    <thead><tr><th scope="col">Email</th><th scope="col">User ID</th><th scope="col">Family ID</th><th scope="col">Received</th></tr></thead>
                    <tbody id="requestRows">
                        <?php foreach ($requests as $request): ?>
                            <tr data-search-row>
                                <th scope="row"><?= adminEscape($request['email']) ?></th>
                                <td><?= (int)$request['user_id'] ?></td>
                                <td><?= (int)$request['family_id'] ?></td>
                                <td><?= adminEscape(adminDate($request['requested_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="admin-card admin-empty" data-filter-empty="requestRows" hidden>No requests match your search.</p>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/../shared/components/footer.php'; ?>
