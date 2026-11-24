<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/CSRF.php';

function adminEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminDate(?string $value): string
{
    return $value && ($timestamp = strtotime($value)) !== false ? date('j M Y, H:i', $timestamp) : '—';
}

function adminMoney(int $cents, string $currency): string
{
    return ($currency === 'ZAR' ? 'R' : $currency . ' ') . number_format($cents / 100, 2, '.', ' ');
}

function adminPlatformAccess(array $user): bool
{
    return $user['role'] === 'admin' && (int)$user['family_id'] === 1;
}

function adminCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . adminEscape(CSRF::generate()) . '">';
}

function adminCheckCsrf(): void
{
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !CSRF::validate($token)) {
        http_response_code(403);
        exit('This form has expired. Reload the page and try again.');
    }
}

function adminRedirect(string $location, string $message, bool $success = true): never
{
    $_SESSION['admin_flash'] = ['message' => $message, 'success' => $success];
    header('Location: ' . $location, true, 303);
    exit;
}

function adminHero(string $title, string $summary): void
{
    ?>
    <header class="family-hero admin-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title"><?= adminEscape($title) ?></h1>
            <p class="family-hero-summary"><?= adminEscape($summary) ?></p>
        </div>
        <?php adminHeroArt(); ?>
    </header>
    <?php
}

function adminHeroArt(): void
{
    ?>
    <div class="family-hero-art" aria-hidden="true">
        <span class="admin-art"><i></i><i></i><i></i></span>
    </div>
    <?php
}

function adminNavigation(array $user, string $active): void
{
    $links = ['family' => ['/admin/', 'Family']];
    if ($user['role'] === 'owner') {
        $links['subscription'] = ['/admin/plans-public.php', 'Your subscription'];
    }
    if (adminPlatformAccess($user)) {
        $links['plans'] = ['/admin/plans.php', 'Manage plans'];
        $links['store'] = ['/admin/subscription-manager.php', 'Store subscriptions'];
        $links['deletion'] = ['/admin/account-deletion.php', 'Deletion requests'];
    }
    if (count($links) === 1) {
        return;
    }
    ?>
    <nav class="admin-nav" aria-label="Administration">
        <?php foreach ($links as $key => [$url, $label]): ?>
            <a href="<?= $url ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function adminShowFlash(): void
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    if ($flash) {
        ?>
        <p data-admin-flash class="admin-feedback<?= $flash['success'] ? '' : ' is-error' ?>" role="<?= $flash['success'] ? 'status' : 'alert' ?>">
            <?= adminEscape($flash['message']) ?>
        </p>
        <?php
    }
}
