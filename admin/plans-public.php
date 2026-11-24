<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/ui.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user || $user['role'] !== 'owner') {
    header('Location: ' . ($user ? '/home/' : '/login.php'));
    exit;
}

$status = null;
try {
    $status = (new SubscriptionManager($db))->getFamilySubscriptionStatus((int)$user['family_id']);
} catch (Throwable $e) {
    error_log('Family subscription screen: ' . $e->getMessage());
}
$plans = $db->query("
    SELECT name, code, billing_period, price_cents, currency, max_members
    FROM plans WHERE is_active = 1 ORDER BY FIELD(billing_period, 'monthly', 'yearly'), price_cents, id")->fetchAll();

$isAndroidApp = str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'RelativesAndroid/');
$subscribeLink = $isAndroidApp ? '/upgrade/' : 'relatives://subscription';
$manageLink = match ($status['provider'] ?? '') {
    'google_play' => 'https://play.google.com/store/account/subscriptions',
    'apple_app_store' => 'https://apps.apple.com/account/subscriptions',
    default => null
};
$pageTitle = 'Your subscription';
require __DIR__ . '/header.php';
?>

<main class="family-main admin-main">
    <?php adminHero('Your subscription', 'Check your family’s subscription and compare the available plans.'); ?>
    <?php adminNavigation($user, 'subscription'); ?>

    <?php
    [$statusTitle, $statusDetail] = match ($status['status'] ?? '') {
        'active' => ['Subscription active', 'Current period ends ' . adminDate($status['current_period_end'])],
        'trial' => ['Free trial', 'Trial ends ' . adminDate($status['trial_ends_at'])],
        'locked', 'expired' => ['Trial ended', 'Subscribe in the Relatives app to restore editing.'],
        default => ['Subscription status unavailable', 'Refresh this page to try again.']
    };
    ?>
    <section class="admin-banner" aria-labelledby="subscriptionStatus">
        <div>
            <h2 id="subscriptionStatus"><?= $statusTitle ?></h2>
            <p><?= adminEscape($statusDetail) ?></p>
            <?php if ($status['plan_code'] ?? null): ?><code><?= adminEscape($status['plan_code']) ?></code><?php endif; ?>
        </div>
        <?php if ($manageLink): ?>
            <a class="admin-button admin-primary" href="<?= $manageLink ?>">Manage in store</a>
        <?php elseif ($plans && ($status['status'] ?? '') !== 'active'): ?>
            <a class="admin-button admin-primary" href="<?= $subscribeLink ?>">Subscribe in the app</a>
        <?php endif; ?>
    </section>

    <?php if ($plans): ?>
        <section class="admin-section" aria-labelledby="availableTitle">
            <h2 class="family-section-title" id="availableTitle">Available plans</h2>
            <p class="admin-section-note">All plans include shopping lists, notes, messaging and location sharing. Purchases are handled in the Relatives mobile app.</p>
            <?php foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly'] as $period => $label): ?>
                <?php $periodPlans = array_filter($plans, static fn(array $plan): bool => $plan['billing_period'] === $period); ?>
                <?php if (!$periodPlans) continue; ?>
                <h3 class="admin-subheading"><?= $label ?></h3>
                <div class="admin-plans">
                    <?php foreach ($periodPlans as $plan): ?>
                        <article class="admin-card admin-plan admin-public-plan">
                            <h4><?= adminEscape($plan['name']) ?></h4>
                            <p class="admin-public-price"><?= adminEscape(adminMoney((int)$plan['price_cents'], $plan['currency'])) ?>
                                <small>/ <?= $period === 'monthly' ? 'month' : 'year' ?></small></p>
                            <p><?= (int)$plan['max_members'] === 0 ? 'Unlimited family members' : 'Up to ' . (int)$plan['max_members'] . ' family members' ?></p>
                            <details><summary>Product ID</summary><code><?= adminEscape($plan['code']) ?></code></details>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <p class="admin-empty admin-card">There are no plans available right now.</p>
    <?php endif; ?>

    <section class="admin-section" aria-labelledby="faqTitle">
        <h2 class="family-section-title" id="faqTitle">Subscription questions</h2>
        <div class="admin-faq admin-card">
            <details><summary>Where do I subscribe?</summary><p>Open Relatives on Android or iOS and choose a plan in the app. Google Play or the App Store handles your payment.</p></details>
            <details><summary>How do I cancel?</summary><p>Manage or cancel your subscription in the account settings of the store where you subscribed.</p></details>
            <details><summary>What happens when my trial ends?</summary><p>You can still view your family data. Subscribe to add or edit it again.</p></details>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../shared/components/footer.php'; ?>
