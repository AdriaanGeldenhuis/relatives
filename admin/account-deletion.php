<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user || $user['role'] !== 'admin' || (int) $user['family_id'] !== 1) {
    http_response_code(403);
    exit('Forbidden');
}

header('Cache-Control: no-store');
$requests = $db->query(
    'SELECT r.user_id, r.requested_at, u.email, u.family_id
     FROM account_deletion_requests r
     JOIN users u ON u.id = r.user_id
     ORDER BY r.requested_at ASC'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account deletion requests | Relatives</title>
    <style><?php readfile(__DIR__ . '/../legal/css/public-legal.css'); ?></style>
</head>
<body>
<main>
    <p><a href="/admin/">Family administration</a></p>
    <h1>Account deletion requests</h1>
    <p>Review requests with the privacy team. Remove the account and its associated personal data from production systems under the retention policy. An account's pending request is removed when its user record is deleted.</p>
    <?php if (!$requests): ?>
        <p>No pending requests.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($requests as $request): ?>
                <li><?= htmlspecialchars($request['email']) ?>.
                    User <?= (int) $request['user_id'] ?>, family <?= (int) $request['family_id'] ?>.
                    Received <?= htmlspecialchars($request['requested_at']) ?>.</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
