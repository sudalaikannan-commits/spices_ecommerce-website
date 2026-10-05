<?php
/**
 * POST /api/order.php   (JSON body)
 * Places a real order: validates stock, writes orders + order_items + payments,
 * decrements inventory, consumes the coupon, upserts the customer.
 *
 * Body: { customer:{name,email,phone,address,city,state,pincode},
 *         items:[{id,qty}], coupon:"CODE", payment_method:"COD|CARD|UPI", notes:"..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (!is_post()) {
    json_out(['error' => 'Use POST.'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}

$customer = is_array($in['customer'] ?? null) ? $in['customer'] : $in;
$name = trim((string) ($customer['name'] ?? ''));
$email = strtolower(trim((string) ($customer['email'] ?? '')));
$phone = trim((string) ($customer['phone'] ?? ''));
$address = trim((string) ($customer['address'] ?? ''));
$city = trim((string) ($customer['city'] ?? ''));
$state = trim((string) ($customer['state'] ?? ''));
$pincode = trim((string) ($customer['pincode'] ?? ''));
$paymentMethod = strtoupper(trim((string) ($in['payment_method'] ?? 'COD'))) ?: 'COD';
$couponCode = strtoupper(trim((string) ($in['coupon'] ?? '')));
$notes = trim((string) ($in['notes'] ?? ''));
$items = is_array($in['items'] ?? null) ? $in['items'] : [];

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $address === '') {
    json_out(['error' => 'Name, valid email and delivery address are required.'], 422);
}
if (!$items) {
    json_out(['error' => 'Your cart is empty.'], 422);
}
if (!in_array($paymentMethod, ['COD', 'CARD', 'UPI', 'NETBANKING'], true)) {
    $paymentMethod = 'COD';
}
if ($paymentMethod !== 'COD' && (int) setting('online_payment_enabled', '1') !== 1) {
    json_out(['error' => 'Online payment is currently unavailable.'], 422);
}
if ($paymentMethod === 'COD' && (int) setting('cod_enabled', '1') !== 1) {
    json_out(['error' => 'Cash on Delivery is currently unavailable.'], 422);
}

$ids = [];
foreach ($items as $it) {
    $pid = (int) ($it['id'] ?? 0);
    $qty = max(1, (int) ($it['qty'] ?? 1));
    if ($pid > 0) {
        $ids[$pid] = ($ids[$pid] ?? 0) + $qty;
    }
}
if (!$ids) {
    json_out(['error' => 'No valid products in cart.'], 422);
}

$pdo = db();
$pdo->beginTransaction();

try {
    /* ---- lock + price every product, verify stock ---- */
    $subtotal = 0.0;
    $lines = [];
    $select = $pdo->prepare(
        'SELECT p.id, p.name, p.sku, p.price, p.sale_price, p.image_url, i.stock
           FROM products p
      LEFT JOIN inventory i ON i.product_id = p.id
          WHERE p.id = ? AND p.status = "active"
          FOR UPDATE'
    );

    foreach ($ids as $pid => $qty) {
        $select->execute([$pid]);
        $row = $select->fetch();
        if (!$row) {
            throw new RuntimeException('A product in your cart is no longer available.');
        }
        $stock = (int) ($row['stock'] ?? 0);
        if ($stock < $qty) {
            throw new RuntimeException($row['name'] . ' — only ' . $stock . ' left in stock.');
        }
        $unit = effective_price($row);
        $lineTotal = $unit * $qty;
        $subtotal += $lineTotal;
        $lines[] = [
            'id' => (int) $row['id'], 'name' => $row['name'], 'sku' => $row['sku'],
            'unit' => $unit, 'qty' => $qty, 'line' => $lineTotal,
        ];
    }

    /* ---- coupon ---- */
    $discount = 0.0;
    $couponId = null;
    if ($couponCode !== '') {
        $cstmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? FOR UPDATE');
        $cstmt->execute([$couponCode]);
        $coupon = $cstmt->fetch();
        $today = date('Y-m-d');
        $valid = $coupon
            && $coupon['status'] === 'active'
            && (empty($coupon['starts_at']) || $coupon['starts_at'] <= $today)
            && (empty($coupon['expires_at']) || $coupon['expires_at'] >= $today)
            && (empty($coupon['usage_limit']) || (int) $coupon['used_count'] < (int) $coupon['usage_limit'])
            && $subtotal >= (float) $coupon['min_order'];

        if (!$valid) {
            throw new RuntimeException('That coupon cannot be applied to this order.');
        }
        $discount = $coupon['type'] === 'percent'
            ? $subtotal * ((float) $coupon['value'] / 100)
            : (float) $coupon['value'];
        if (!empty($coupon['max_discount'])) {
            $discount = min($discount, (float) $coupon['max_discount']);
        }
        $discount = min($discount, $subtotal);
        $couponId = (int) $coupon['id'];
    }

    /* ---- shipping + tax ---- */
    $shipping = (float) setting('shipping_fee', '49');
    $freeAbove = (float) setting('free_shipping_above', '999');
    if ($freeAbove > 0 && ($subtotal - $discount) >= $freeAbove) {
        $shipping = 0.0;
    }
    $taxPercent = (float) setting('tax_percent', '0');
    $tax = ($subtotal - $discount) * $taxPercent / 100;
    $total = max(0, $subtotal - $discount + $shipping + $tax);

    /* ---- customer upsert ---- */
    $custId = null;
    $ustmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $ustmt->execute([$email]);
    $custId = (int) ($ustmt->fetchColumn() ?: 0);

    if ($custId > 0) {
        $pdo->prepare('UPDATE customers SET name=?, phone=?, address=?, city=?, state=?, pincode=?, status="active" WHERE id=?')
            ->execute([$name, $phone, $address, $city, $state, $pincode, $custId]);
    } else {
        $pdo->prepare('INSERT INTO customers (name,email,phone,address,city,state,pincode) VALUES (?,?,?,?,?,?,?)')
            ->execute([$name, $email, $phone, $address, $city, $state, $pincode]);
        $custId = (int) $pdo->lastInsertId();
    }

    /* ---- order ---- */
    $orderCode = 'SC' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
    $paid = $paymentMethod !== 'COD';

    $pdo->prepare(
        'INSERT INTO orders
            (order_code, customer_id, customer_name, email, phone, address, city, state, pincode,
             subtotal, discount, shipping, total, coupon_id, coupon_code, payment_method, payment_status, status, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $orderCode, $custId, $name, $email, $phone, $address, $city, $state, $pincode,
        round($subtotal, 2), round($discount, 2), round($shipping, 2), round($total, 2),
        $couponId, $couponCode ?: null, $paymentMethod,
        $paid ? 'paid' : 'pending', 'pending', $notes ?: null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    /* ---- items + stock decrement ---- */
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, sku, unit_price, quantity, line_total) VALUES (?,?,?,?,?,?,?)');
    $stockStmt = $pdo->prepare('UPDATE inventory SET stock = stock - ? WHERE product_id = ?');

    foreach ($lines as $l) {
        $itemStmt->execute([$orderId, $l['id'], $l['name'], $l['sku'], $l['unit'], $l['qty'], round($l['line'], 2)]);
        $stockStmt->execute([$l['qty'], $l['id']]);
    }

    /* ---- coupon usage + customer aggregates ---- */
    if ($couponId) {
        $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$couponId]);
    }
    $pdo->prepare('UPDATE customers SET total_orders = total_orders + 1, total_spent = total_spent + ? WHERE id = ?')
        ->execute([round($total, 2), $custId]);

    /* ---- payment + history ---- */
    $pdo->prepare('INSERT INTO payments (order_id, method, gateway, txn_ref, amount, status, paid_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$orderId, $paymentMethod, $paymentMethod === 'COD' ? 'cash_on_delivery' : 'demo_gateway',
                   $paid ? strtoupper(bin2hex(random_bytes(4))) : null, round($total, 2),
                   $paid ? 'paid' : 'pending', $paid ? date('Y-m-d H:i:s') : null]);

    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, changed_by) VALUES (?,?,?,?)')
        ->execute([$orderId, 'pending', 'Order placed by customer', $name]);

    $pdo->commit();

    json_out([
        'ok'         => true,
        'order_id'   => $orderId,
        'order_code' => $orderCode,
        'total'      => round($total, 2),
        'total_text' => money($total),
        'message'    => 'Order placed successfully.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['error' => $e->getMessage()], 422);
}