<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f5ee">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style><?php readfile(__DIR__ . '/../public/site.css'); ?></style>
    <?php if (!empty($authPage)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        <?php readfile(__DIR__ . '/../public/auth.js'); ?>
    });
    </script>
    <?php endif; ?>
</head>
