<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/ui.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user || !adminPlatformAccess($user)) {
    header('Location: ' . ($user ? '/home/' : '/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminCheckCsrf();
    $id = filter_var($_POST['plan_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = $_POST['action'] ?? '';
    $returnTo = '/admin/plans.php' . ($id ? '#plan' . $id : '');
    try {
        if (!$id) {
            throw new InvalidArgumentException('Choose a plan first.');
        }
        if ($action === 'update_plan') {
            $price = filter_var($_POST['price_cents'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
            if ($price === false) {
                throw new InvalidArgumentException('Enter a valid price in cents.');
            }
            $stmt = $db->prepare('UPDATE plans SET price_cents = ? WHERE id = ?');
            $stmt->execute([$price, $id]);
            adminRedirect($returnTo, 'Plan pricing saved.');
        }
        if ($action === 'toggle_plan') {
            $active = $_POST['is_active'] ?? '';
            if (!in_array($active, ['0', '1'], true)) {
                throw new InvalidArgumentException('Choose a valid plan status.');
            }
            $stmt = $db->prepare('UPDATE plans SET is_active = ? WHERE id = ?');
            $stmt->execute([(int)$active, $id]);
            adminRedirect($returnTo, $active === '1' ? 'Plan enabled.' : 'Plan disabled.');
        }
        throw new InvalidArgumentException('Unknown plan action.');
    } catch (InvalidArgumentException $e) {
        adminRedirect($returnTo, $e->getMessage(), false);
    } catch (Throwable $e) {
        error_log('Plan administration: ' . $e->getMessage());
        adminRedirect($returnTo, 'The plan could not be saved. Please try again.', false);
    }
}

$plans = $db->query("
    SELECT p.id, p.code, p.name, p.billing_period, p.price_cents, p.currency, p.max_members, p.is_active,
        (SELECT COUNT(*) FROM families WHERE current_plan_id = p.id) AS assigned_families
    FROM plans p ORDER BY FIELD(p.billing_period, 'monthly', 'yearly'), p.price_cents, p.id")->fetchAll();

$pageTitle = 'Manage plans';
require __DIR__ . '/header.php';
?>

<main class="family-main admin-main">
    <?php adminHero('Manage plans', 'Update displayed pricing and plan availability.'); ?>
    <?php adminNavigation($user, 'plans'); ?>
    <?php adminShowFlash(); ?>

    <?php foreach (['monthly' => 'Monthly plans', 'yearly' => 'Yearly plans'] as $period => $label): ?>
        <?php $periodPlans = array_filter($plans, static fn(array $plan): bool => $plan['billing_period'] === $period); ?>
        <?php if (!$periodPlans) continue; ?>
        <section class="admin-section" aria-labelledby="<?= $period ?>Title">
            <h2 class="family-section-title" id="<?= $period ?>Title"><?= $label ?></h2>
            <div class="admin-plans">
                <?php foreach ($periodPlans as $plan): ?>
                    <?php $id = (int)$plan['id']; $active = (bool)$plan['is_active']; ?>
                    <article class="admin-card admin-plan" id="plan<?= $id ?>">
                        <div class="admin-plan-heading">
                            <h3><?= adminEscape($plan['name']) ?></h3>
                            <span class="admin-chip" data-status="<?= $active ? 'active' : 'disabled' ?>"><?= $active ? 'Active' : 'Disabled' ?></span>
                        </div>
                        <code><?= adminEscape($plan['code']) ?></code>
                        <dl class="admin-plan-info">
                            <div><dt>Family size</dt><dd><?= (int)$plan['max_members'] === 0 ? 'Unlimited' : (int)$plan['max_members'] ?></dd></div>
                            <div><dt>Families assigned</dt><dd><?= number_format((int)$plan['assigned_families']) ?></dd></div>
                        </dl>
                        <form method="post" action="/admin/plans.php" class="admin-price-form">
                            <?= adminCsrfField() ?>
                            <input type="hidden" name="action" value="update_plan">
                            <input type="hidden" name="plan_id" value="<?= $id ?>">
                            <label>Price in <?= adminEscape($plan['currency']) ?> cents
                                <input type="number" name="price_cents" min="0" max="2147483647" step="1" required value="<?= (int)$plan['price_cents'] ?>">
                            </label>
                            <small>Current price <?= adminEscape(adminMoney((int)$plan['price_cents'], $plan['currency'])) ?></small>
                            <button type="submit" class="admin-button admin-primary">Save price</button>
                        </form>
                        <form method="post" action="/admin/plans.php" data-confirm="<?= $active ? 'Disable' : 'Enable' ?> <?= adminEscape($plan['name']) ?> for new subscriptions?">
                            <?= adminCsrfField() ?>
                            <input type="hidden" name="action" value="toggle_plan">
                            <input type="hidden" name="plan_id" value="<?= $id ?>">
                            <input type="hidden" name="is_active" value="<?= $active ? 0 : 1 ?>">
                            <button type="submit" class="admin-button<?= $active ? ' admin-danger' : '' ?>"><?= $active ? 'Disable plan' : 'Enable plan' ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!$plans): ?><p class="admin-empty admin-card">No subscription plans are configured.</p><?php endif; ?>
</main>

<?php require __DIR__ . '/../shared/components/footer.php'; ?>
