<?php
/** Order detail: items, payment, status control, cancel, history timeline. */

declare(strict_types=1);

$pageTitle = 'Order';
$navKey = 'orders';

require __DIR__ . '/_head.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$id) {
    flash('error', 'Order not found.');
    redirect(admin_url('orders.php'));
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    flash('error', 'Order not found.');
    redirect(admin_url('orders.php'));
}

$itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$payStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC');
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

$histStmt = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC');
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'status') {
        $status = input('status');
        if (in_array($status, order_statuses(), true)) {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
            $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')
                ->execute([$id, $status, input('note') ?: ('Status set to ' . $status), auth_user()['name'] ?? 'admin']);
            flash('success', 'Order marked as ' . $status . '. The customer sees this instantly.');
        }
    }

    if ($action === 'payment') {
        $payStatus = input('payment_status');
        if (in_array($payStatus, ['pending', 'paid', 'failed', 'refunded'], true)) {
            $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?')->execute([$payStatus, $id]);
            $pdo->prepare('UPDATE payments SET status = ?, paid_at = IF(? = "paid", NOW(), paid_at) WHERE order_id = ?')
                ->execute([$payStatus, $payStatus, $id]);
            $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')
                ->execute([$id, $order['status'], 'Payment marked ' . $payStatus, auth_user()['name'] ?? 'admin']);
            flash('success', 'Payment marked as ' . $payStatus . '.');
        }
    }

    if ($action === 'cancel') {
        if (!in_array($order['status'], ['cancelled', 'delivered'], true)) {
            $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$id]);

            /* restore stock */
            $restore = $pdo->prepare('UPDATE inventory SET stock = stock + ? WHERE product_id = ?');
            foreach ($items as $it) {
                if ($it['product_id']) {
                    $restore->execute([(int) $it['quantity'], (int) $it['product_id']]);
                }
            }

            /* release coupon + customer totals */
            if (!empty($order['coupon_id'])) {
                $pdo->prepare('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?')
                    ->execute([(int) $order['coupon_id']]);
            }
            if ($order['customer_id']) {
                $pdo->prepare('UPDATE customers SET total_orders = GREATEST(total_orders - 1, 0),
                                     total_spent = GREATEST(total_spent - ?, 0) WHERE id = ?')
                    ->execute([(float) $order['total'], (int) $order['customer_id']]);
            }

            $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')
                ->execute([$id, 'cancelled', input('note') ?: 'Order cancelled, stock restored', auth_user()['name'] ?? 'admin']);
            flash('success', 'Order cancelled. Stock and coupon usage were restored.');
        } else {
            flash('error', 'This order cannot be cancelled.');
        }
    }

    if ($action === 'note') {
        $note = input('note');
        if ($note !== '') {
            $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')
                ->execute([$id, $order['status'], $note, auth_user()['name'] ?? 'admin']);
            flash('success', 'Note added to the timeline.');
        }
    }

    redirect(admin_url('order-view.php?id=' . $id));
}

$canCancel = !in_array($order['status'], ['cancelled', 'delivered'], true);
$sub = (float) $order['subtotal'];
?>

<div class="page-head">
  <a class="btn btn-sm" href="<?= e(admin_url('orders.php')) ?>">&larr; All orders</a>
  <h1 class="mono"><?= e($order['order_code']) ?></h1>
  <?= status_badge((string) $order['status']) ?>
  <?= status_badge((string) $order['payment_status']) ?>
</div>

<div class="grid-2-1">
  <div>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h2>Items</h2><span class="muted"><?= count($items) ?> line(s)</span></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Product</th><th class="num">Unit price</th><th class="num">Qty</th><th class="num">Line total</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td>
                <?php if ($it['product_id']): ?>
                  <a class="t-title" href="<?= e(admin_url('product-form.php?id=' . (int) $it['product_id'])) ?>"><?= e($it['product_name']) ?></a>
                <?php else: ?>
                  <span class="t-title"><?= e($it['product_name']) ?></span>
                <?php endif; ?>
                <div class="t-sub mono"><?= e((string) $it['sku']) ?></div>
              </td>
              <td class="num"><?= money($it['unit_price']) ?></td>
              <td class="num"><?= (int) $it['quantity'] ?></td>
              <td class="num"><b><?= money($it['line_total']) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-foot">
        <div class="kv"><span>Subtotal</span><b><?= money($sub) ?></b></div>
        <?php if ((float) $order['discount'] > 0): ?>
          <div class="kv"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><b style="color:var(--ok)">- <?= money($order['discount']) ?></b></div>
        <?php endif; ?>
        <div class="kv"><span>Shipping</span><b><?= money($order['shipping']) ?></b></div>
        <div class="kv" style="font-size:16px"><span>Total</span><b><?= money($order['total']) ?></b></div>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h2>Timeline</h2></div>
      <div class="card-body">
        <ul class="timeline">
          <?php foreach (array_reverse($history) as $h): ?>
            <li>
              <b><?= e(ucfirst((string) $h['status'])) ?></b>
              <span><?= e(date('d M Y, H:i', strtotime($h['created_at']))) ?> &middot; <?= e((string) ($h['changed_by'] ?: 'system')) ?></span>
              <div><?= e((string) ($h['note'] ?? '')) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$history): ?><li><b>No history yet</b></li><?php endif; ?>
        </ul>

        <form method="post" style="margin-top:12px" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="note">
          <input type="text" name="note" placeholder="Add an internal note…" style="flex:1;min-width:200px">
          <button class="btn" type="submit">Add note</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Payments</h2></div>
      <?php if (!$payments): ?>
        <div class="empty">No payment records.</div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Method</th><th>Gateway</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= e($p['method']) ?></td>
              <td class="mono"><?= e((string) ($p['gateway'] ?? '—')) ?></td>
              <td class="mono"><?= e((string) ($p['txn_ref'] ?? '—')) ?></td>
              <td class="num"><?= money($p['amount']) ?></td>
              <td><?= status_badge((string) $p['status']) ?></td>
              <td class="small muted"><?= e($p['paid_at'] ? date('d M Y H:i', strtotime($p['paid_at'])) : date('d M Y H:i', strtotime($p['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h3>Customer</h3></div>
      <div class="card-body">
        <div class="kv"><span>Name</span><b><?= e($order['customer_name']) ?></b></div>
        <div class="kv"><span>Email</span><b><?= e($order['email']) ?></b></div>
        <div class="kv"><span>Phone</span><b><?= e((string) ($order['phone'] ?: '—')) ?></b></div>
        <div class="kv"><span>Address</span><b style="text-align:right;max-width:65%"><?= nl2br(e($order['address'])) ?></b></div>
        <div class="kv"><span>City / State</span><b><?= e(trim((string) ($order['city'] . ', ' . $order['state']), ', ')) ?: '—' ?></b></div>
        <div class="kv"><span>PIN</span><b><?= e((string) ($order['pincode'] ?: '—')) ?></b></div>
        <?php if ($order['customer_id']): ?>
          <a class="btn btn-sm btn-block" style="margin-top:12px" href="<?= e(admin_url('customer-view.php?id=' . (int) $order['customer_id'])) ?>">View customer profile</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h3>Update status</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <label>Order status
            <select name="status">
              <?php foreach (order_statuses() as $st): ?>
                <option value="<?= e($st) ?>"<?= $order['status'] === $st ? ' selected' : '' ?>><?= e(ucfirst($st)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Note (optional)
            <input name="note" placeholder="e.g. Packed and handed to courier">
          </label>
          <button class="btn btn-primary btn-block" type="submit">Save status</button>
        </form>

        <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="payment">
          <label>Payment status
            <select name="payment_status">
              <?php foreach (['pending', 'paid', 'failed', 'refunded'] as $ps): ?>
                <option value="<?= e($ps) ?>"<?= $order['payment_status'] === $ps ? ' selected' : '' ?>><?= e(ucfirst($ps)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn btn-block" type="submit">Update payment</button>
        </form>
      </div>
    </div>

    <?php if ($canCancel): ?>
      <div class="card" style="border-color:#f0c2bd">
        <div class="card-head"><h3 style="color:var(--bad)">Cancel order</h3></div>
        <div class="card-body">
          <p class="muted">Cancelling restores stock, releases the coupon and updates customer totals.</p>
          <form method="post" data-confirm="Cancel <?= e($order['order_code']) ?> and restore stock?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <label>Reason
              <input name="note" placeholder="Customer requested cancellation">
            </label>
            <button class="btn btn-danger btn-block" type="submit">Cancel this order</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card" style="margin-top:14px">
      <div class="card-body small muted">
        Placed <?= e(date('d M Y, H:i', strtotime($order['placed_at']))) ?><br>
        Last updated <?= e(date('d M Y, H:i', strtotime($order['updated_at']))) ?>
      </div>
    </div>
  </div>
</div>