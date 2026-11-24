<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/ui.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user || !adminPlatformAccess($user)) {
    header('Location: ' . ($user ? '/home/' : '/login.php'));
    exit;
}

$subscriptions = $db->query("
    SELECT s.id, s.provider, s.store_product_id, s.store_order_id, s.status, s.current_period_start,
        s.expires_at, s.cancelled_at, s.last_verified_at, f.name AS family_name, u.email AS owner_email
    FROM subscriptions s
    JOIN families f ON f.id = s.family_id
    LEFT JOIN users u ON u.id = f.owner_user_id
    WHERE s.provider IN ('google_play', 'apple_app_store')
    ORDER BY s.created_at DESC, s.id DESC")->fetchAll();

$payments = $db->query("
    SELECT ph.provider, ph.external_id, ph.amount_cents, ph.currency, ph.status, ph.created_at, f.name AS family_name
    FROM payment_history ph JOIN families f ON f.id = ph.family_id
    WHERE ph.provider IN ('google_play', 'apple_app_store')
    ORDER BY ph.created_at DESC, ph.id DESC LIMIT 50")->fetchAll();

$stores = ['google_play' => 'Google Play', 'apple_app_store' => 'App Store'];
$pageTitle = 'Store subscriptions';
require __DIR__ . '/header.php';
?>

<main class="family-main admin-main">
    <?php adminHero('Store subscriptions', 'Review subscriptions and recent payments from Google Play and the App Store.'); ?>
    <?php adminNavigation($user, 'store'); ?>

    <section class="admin-section" aria-labelledby="subscriptionsTitle">
        <div class="admin-section-head">
            <h2 class="family-section-title" id="subscriptionsTitle">Subscriptions</h2>
            <?php if ($subscriptions): ?>
                <label class="admin-filter" hidden>Search subscriptions <input type="search" placeholder="Family, store or status" data-filter="subscriptionRows"></label>
            <?php endif; ?>
        </div>
        <?php if (!$subscriptions): ?>
            <p class="admin-card admin-empty">No store subscriptions yet.</p>
        <?php else: ?>
            <div class="admin-table-wrap admin-card" tabindex="0" role="region" aria-labelledby="subscriptionsTitle">
                <table class="admin-table">
                    <thead><tr><th scope="col">Family</th><th scope="col">Store</th><th scope="col">Status</th><th scope="col">Expires</th><th scope="col">Details</th></tr></thead>
                    <tbody id="subscriptionRows">
                        <?php foreach ($subscriptions as $subscription): ?>
                            <tr data-search-row>
                                <th scope="row"><?= adminEscape($subscription['family_name']) ?></th>
                                <td><?= $stores[$subscription['provider']] ?></td>
                                <td><span class="admin-chip" data-status="<?= adminEscape($subscription['status']) ?>"><?= adminEscape(ucfirst($subscription['status'])) ?></span></td>
                                <td><?= adminEscape(adminDate($subscription['expires_at'])) ?></td>
                                <td>
                                    <button type="button" class="admin-button" data-open-dialog="subscriptionModal"
                                            data-details="subscription<?= (int)$subscription['id'] ?>"
                                            aria-label="View subscription for <?= adminEscape($subscription['family_name']) ?>">View</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="admin-card admin-empty" data-filter-empty="subscriptionRows" hidden>No subscriptions match your search.</p>
        <?php endif; ?>
    </section>

    <section class="admin-section" aria-labelledby="paymentsTitle">
        <h2 class="family-section-title" id="paymentsTitle">Recent store payments</h2>
        <?php if (!$payments): ?>
            <p class="admin-card admin-empty">No store payments yet.</p>
        <?php else: ?>
            <div class="admin-table-wrap admin-card" tabindex="0" role="region" aria-labelledby="paymentsTitle">
                <table class="admin-table">
                    <thead><tr><th scope="col">Family</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Order reference</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <th scope="row"><?= adminEscape($payment['family_name']) ?><small><?= $stores[$payment['provider']] ?></small></th>
                                <td><?= adminEscape(adminMoney((int)$payment['amount_cents'], $payment['currency'])) ?></td>
                                <td><span class="admin-chip" data-status="<?= adminEscape($payment['status']) ?>"><?= adminEscape(ucfirst($payment['status'])) ?></span></td>
                                <td><?= adminEscape(adminDate($payment['created_at'])) ?></td>
                                <td><code><?= adminEscape($payment['external_id'] ?: '—') ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php if ($subscriptions): ?>
    <dialog id="subscriptionModal" class="admin-dialog" aria-labelledby="subscriptionTitle">
        <header><h2 id="subscriptionTitle">Subscription details</h2><button class="admin-button" type="button" data-close-dialog>Close</button></header>
        <div class="admin-dialog-body" data-dialog-content></div>
    </dialog>
    <?php foreach ($subscriptions as $subscription): ?>
        <template id="subscription<?= (int)$subscription['id'] ?>">
            <h3><?= adminEscape($subscription['family_name']) ?></h3>
            <dl class="admin-details">
                <?php foreach ([
                    'Owner email' => $subscription['owner_email'] ?: '—',
                    'Store' => $stores[$subscription['provider']],
                    'Product' => $subscription['store_product_id'] ?: '—',
                    'Status' => ucfirst($subscription['status']),
                    'Current period started' => adminDate($subscription['current_period_start']),
                    'Expires' => adminDate($subscription['expires_at']),
                    'Cancelled' => adminDate($subscription['cancelled_at']),
                    'Last verified' => adminDate($subscription['last_verified_at']),
                    'Order reference' => $subscription['store_order_id'] ?: '—'
                ] as $label => $value): ?>
                    <div><dt><?= $label ?></dt><dd><?= adminEscape($value) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </template>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../shared/components/footer.php'; ?>
