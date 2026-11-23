<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('not_authenticated', 401);
}
$familyId = (int) $user['family_id'];
$owner = $db->prepare('SELECT owner_user_id FROM families WHERE id = ?');
$owner->execute([$familyId]);
if ((int) $owner->fetchColumn() !== (int) $user['id']) {
    Response::error('family_owner_required', 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$platform = $input['platform'] ?? null;
$requestedFamily = $input['family_id'] ?? $familyId;
$productId = $input['product_id'] ?? $input['plan_code'] ?? null;
$purchaseToken = $input['purchase_token'] ?? null;
if (!is_scalar($requestedFamily) || (string) $requestedFamily !== (string) $familyId) {
    Response::error('wrong_family', 403);
}
if (!in_array($platform, ['google_play', 'apple_app_store'], true) ||
    !is_string($productId) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $productId) ||
    !is_string($purchaseToken) || $purchaseToken === '' || strlen($purchaseToken) > 255
) {
    Response::error('invalid_purchase_request', 422);
}

$manager = new SubscriptionManager($db);
if ($platform === 'google_play' && !in_array($productId, SubscriptionManager::GOOGLE_PLAY_MONTHLY_PRODUCTS, true)) {
    Response::error('unsupported_product', 422);
}
session_write_close();

try {
    $verifier = $platform === 'google_play' ? new GooglePlayVerifier() : new AppleAppStoreVerifier($db);
    $result = $verifier->verifySubscription($productId, $purchaseToken);
    if (($result['status'] ?? null) === 'unavailable') {
        Response::error('store_verification_unavailable', 503);
    }
    if (($result['ok'] ?? false) !== true || $result['status'] !== 'active') {
        Response::error('purchase_not_verified', 422);
    }

    $manager->activateSubscription($familyId, $platform, $productId, $purchaseToken, $result);
    $acknowledged = true;
    if ($verifier instanceof GooglePlayVerifier && !$result['acknowledged']) {
        $acknowledged = $verifier->acknowledgeSubscription($productId, $purchaseToken);
    }
    Response::json([
        'ok' => true,
        'status' => 'active',
        'acknowledged' => $acknowledged,
        'expiry' => $result['expiry_time'],
    ]);
} catch (DomainException $error) {
    Response::error($error->getMessage(), 409);
} catch (Throwable $error) {
    error_log('Native subscription activation failed (' . get_class($error) . ')');
    Response::error('subscription_temporarily_unavailable', 503);
}
