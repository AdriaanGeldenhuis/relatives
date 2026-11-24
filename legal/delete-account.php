<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';

header('Cache-Control: no-store');
$user = null;
$pending = false;
$error = '';
$csrf = '';
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/bootstrap.php';
    require_once __DIR__ . '/../core/CSRF.php';

    $user = (new Auth($db))->getCurrentUser();
    if ($user) {
        try {
            $status = $db->prepare('SELECT requested_at FROM account_deletion_requests WHERE user_id = ?');
            $status->execute([$user['id']]);
            $pending = $status->fetchColumn() !== false;
            $csrf = CSRF::generate();

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pending) {
                $token = $_POST['csrf_token'] ?? null;
                $password = $_POST['password'] ?? null;

                if (!is_string($token) || !CSRF::validate($token)) {
                    http_response_code(403);
                    $error = 'Reload the page and try again.';
                } elseif (!isset($_POST['confirm']) || !is_string($password) || $password === '') {
                    $error = 'Confirm the request and enter your password.';
                } else {
                    $account = $db->prepare("SELECT password_hash FROM users WHERE id = ? AND status = 'active'");
                    $account->execute([$user['id']]);
                    $hash = $account->fetchColumn();
                    if (!is_string($hash) || !password_verify($password, $hash)) {
                        http_response_code(403);
                        $error = 'The password did not match.';
                    } else {
                        $db->beginTransaction();
                        $request = $db->prepare(
                            'INSERT INTO account_deletion_requests (user_id) VALUES (?)
                             ON DUPLICATE KEY UPDATE requested_at = requested_at'
                        );
                        $request->execute([$user['id']]);
                        $disableSharing = $db->prepare('UPDATE users SET location_sharing = 0 WHERE id = ?');
                        $disableSharing->execute([$user['id']]);
                        $clearPosition = $db->prepare('DELETE FROM tracking_current WHERE user_id = ?');
                        $clearPosition->execute([$user['id']]);
                        $db->commit();
                        header('Location: /legal/delete-account.php', true, 303);
                        exit;
                    }
                }
            }
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Could not process account deletion request: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'The request form is temporarily unavailable. Contact privacy@relatives.co.za for help.';
        }
    }
}
$activeTab = 'deletion';
$pageTitle = 'Delete your account | Relatives';
$legalHeading = 'Delete your Relatives account';
$legalSummary = 'Request permanent deletion of your account and its associated personal data.';
require __DIR__ . '/layout-start.php';
?>
<article class="legal-document">
    <h2>Request account deletion</h2>
    <p>Use this form to request deletion of your account and associated personal data, including the location history linked to your account. The Relatives privacy team processes these requests. A family administrator does not need to approve your request.</p>
    <p>Store subscriptions need to be cancelled separately. You can submit this request before cancelling. Manage your subscription in <a href="https://play.google.com/store/account/subscriptions">Google Play</a> or the <a href="https://apps.apple.com/account/subscriptions">App Store</a>.</p>
    <?php if ($error !== ''): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if (!$user): ?>
        <p><a href="/login.php?redirect=%2Flegal%2Fdelete-account.php">Sign in to request deletion</a></p>
        <p>If you cannot sign in, contact <a href="mailto:privacy@relatives.co.za">privacy@relatives.co.za</a> to request deletion and get help verifying your account.</p>
    <?php elseif ($pending): ?>
        <script>window.TrackingBridge?.stopTracking();</script>
        <p role="status">Your request has been received and is awaiting processing. Family location sharing has been turned off. Contact <a href="mailto:privacy@relatives.co.za">privacy@relatives.co.za</a> if you need help with your request.</p>
    <?php elseif ($csrf !== ''): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <label for="password">Current password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
            <label><input type="checkbox" name="confirm" required> Request permanent deletion of my Relatives account.</label>
            <button type="submit">Request account deletion</button>
        </form>
    <?php endif; ?>
</article>
</main>
</body>
</html>
