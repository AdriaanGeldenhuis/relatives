<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../core/bootstrap.php';

$verifier = new GooglePlayVerifier();
if (!$verifier->isConfigured()) {
    fwrite(STDERR, "Google Play service account is not configured for this package.\n");
    exit(1);
}

$lockName = 'relatives:google-play-reconcile';
$getLock = $db->prepare('SELECT GET_LOCK(?, 0)');
$getLock->execute([$lockName]);
if ((int) $getLock->fetchColumn() !== 1) {
    exit(0);
}

$manager = new SubscriptionManager($db);
$cursor = 0;
$checked = 0;
$failed = 0;
try {
    $select = $db->prepare("
        SELECT id, family_id, store_product_id, store_purchase_token
        FROM subscriptions
        WHERE id > ? AND provider = 'google_play' AND status IN ('active', 'paused', 'expired')
            AND store_product_id IS NOT NULL AND store_purchase_token IS NOT NULL
            AND expires_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND (last_verified_at IS NULL OR last_verified_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
        ORDER BY id LIMIT 50
    ");
    do {
        $select->execute([$cursor]);
        $subscriptions = $select->fetchAll();
        foreach ($subscriptions as $subscription) {
            $cursor = (int) $subscription['id'];
            $productId = $subscription['store_product_id'];
            $token = $subscription['store_purchase_token'];
            try {
                $result = $verifier->verifySubscription($productId, $token);
                if (!$result['ok']) {
                    if ($result['status'] === 'unavailable') {
                        throw new RuntimeException('Google Play Publisher API unavailable.');
                    }
                    $failed++;
                    continue;
                }

                if ($result['status'] === 'active') {
                    $manager->activateSubscription((int) $subscription['family_id'], 'google_play', $productId, $token, $result);
                    if (!$result['acknowledged'] && !$verifier->acknowledgeSubscription($productId, $token)) {
                        $failed++;
                    }
                } else {
                    $manager->expireGooglePlaySubscription($cursor, $result['expiry_time']);
                }
                $checked++;
            } catch (DomainException $error) {
                $failed++;
                fwrite(STDERR, "Google Play subscription binding conflict for row $cursor.\n");
            }
        }
    } while (count($subscriptions) === 50);
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, "Google Play reconciliation stopped before reaching the end of the queue.\n");
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
printf("Google Play reconciliation verified %d subscription(s), %d need retry or review.\n", $checked, $failed);
exit($failed === 0 ? 0 : 1);
