<?php
/**
 * GET /api/coupon.php?code=WELCOME10&subtotal=1200
 * Validates a coupon against the live DB and returns the discount.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$subtotal = (float) ($_GET['subtotal'] ?? 0);

if ($code === '') {
    json_out(['ok' => false, 'error' => 'Enter a coupon code.'], 400);
}

$stmt = db()->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
$stmt->execute([$code]);
$coupon = $stmt->fetch();

if (!$coupon) {
    json_out(['ok' => false, 'error' => 'Invalid coupon code.'], 404);
}
if ($coupon['status'] !== 'active') {
    json_out(['ok' => false, 'error' => 'This coupon is no longer active.'], 400);
}
$today = date('Y-m-d');
if (!empty($coupon['starts_at']) && $coupon['starts_at'] > $today) {
    json_out(['ok' => false, 'error' => 'This coupon is not active yet.'], 400);
}
if (!empty($coupon['expires_at']) && $coupon['expires_at'] < $today) {
    json_out(['ok' => false, 'error' => 'This coupon has expired.'], 400);
}
if (!empty($coupon['usage_limit']) && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
    json_out(['ok' => false, 'error' => 'This coupon has reached its usage limit.'], 400);
}
if ($subtotal > 0 && $subtotal < (float) $coupon['min_order']) {
    json_out(['ok' => false, 'error' => 'Minimum order of ' . money($coupon['min_order']) . ' for this coupon.'], 400);
}

$discount = $coupon['type'] === 'percent'
    ? $subtotal * ((float) $coupon['value'] / 100)
    : (float) $coupon['value'];

if (!empty($coupon['max_discount'])) {
    $discount = min($discount, (float) $coupon['max_discount']);
}
$discount = min($discount, $subtotal);

json_out([
    'ok'            => true,
    'code'          => $coupon['code'],
    'type'          => $coupon['type'],
    'value'         => (float) $coupon['value'],
    'discount'      => round($discount, 2),
    'discount_text' => money(round($discount, 2)),
]);