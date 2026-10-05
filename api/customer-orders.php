<?php
/**
 * GET /api/customer-orders.php?email=a@b.com[&order_code=SC...]
 * Lets a customer track order status — reflects admin status updates instantly.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$email = strtolower(trim((string) ($_GET['email'] ?? '')));
$code = trim((string) ($_GET['order_code'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['error' => 'Provide the email used at checkout.'], 422);
}

$sql = 'SELECT id, order_code, customer_name, email, phone, address, city, state, pincode,
               subtotal, discount, shipping, total, coupon_code, payment_method, payment_status,
               status, placed_at
          FROM orders WHERE email = ?';
$params = [$email];

if ($code !== '') {
    $sql .= ' AND order_code = ?';
    $params[] = $code;
}
$sql .= ' ORDER BY placed_at DESC LIMIT 25';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

if (!$orders) {
    json_out(['ok' => true, 'orders' => [], 'message' => 'No orders found for this email yet.'], 200);
}

$ids = array_column($orders, 'id');
$in = implode(',', array_fill(0, count($ids), '?'));
$itemsStmt = db()->prepare("SELECT order_id, product_name, sku, unit_price, quantity, line_total
                              FROM order_items WHERE order_id IN ($in)");
$itemsStmt->execute($ids);

$histStmt = db()->prepare("SELECT order_id, status, note, created_at
                             FROM order_status_history WHERE order_id IN ($in) ORDER BY created_at ASC");
$histStmt->execute($ids);

$itemsByOrder = [];
foreach ($itemsStmt->fetchAll() as $it) {
    $itemsByOrder[$it['order_id']][] = $it;
}
$histByOrder = [];
foreach ($histStmt->fetchAll() as $h) {
    $histByOrder[$h['order_id']][] = $h;
}

foreach ($orders as &$o) {
    $o['items'] = $itemsByOrder[(int) $o['id']] ?? [];
    $o['history'] = $histByOrder[(int) $o['id']] ?? [];
}
unset($o);

json_out(['ok' => true, 'orders' => $orders]);