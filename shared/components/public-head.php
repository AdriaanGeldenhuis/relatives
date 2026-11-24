<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f5ee">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="/shared/public/site.css?v=<?= filemtime(__DIR__ . '/../public/site.css') ?>">
    <?php if (!empty($authPage)): ?>
    <script src="/shared/public/auth.js?v=<?= filemtime(__DIR__ . '/../public/auth.js') ?>" defer></script>
    <?php endif; ?>
</head>
