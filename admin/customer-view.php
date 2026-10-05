<?php
/** Customer profile: details, stats, orders, reviews. */

declare(strict_types=1);

$pageTitle = 'Customer';
$navKey = 'customers';

require __DIR__ . '/_head.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    flash('error', 'Customer not found.');
    redirect(admin_url('customers.php'));
}

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'status') {
        $pdo->prepare("UPDATE customers SET status = IF(status='active','disabled','active') WHERE id = ?")->execute([$id]);
        flash('success', 'Customer status updated.');
    }
    if ($action === 'save') {
        $pdo->prepare('UPDATE customers SET name=?, email=?, phone=?, address=?, city=?, state=?, pincode=? WHERE id=?')
            ->execute([
                input('name'), input('email'), input('phone'), input('address'),
                input('city'), input('state'), input('pincode'), $id,
            ]);
        flash('success', 'Customer details saved.');
    }
    redirect(admin_url('customer-view.php?id=' . $id));
}

$ordersStmt = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY placed_at DESC');
$ordersStmt->execute([$id]);
$orders = $ordersStmt->fetchAll();

$reviewStmt = $pdo->prepare('SELECT r.*, p.name AS product_name FROM reviews r
                                LEFT JOIN products p ON p.id = r.product_id
                               WHERE r.customer_id = ? OR r.customer_name = ?
                            ORDER BY r.created_at DESC LIMIT 20');
$reviewStmt->execute([$id, $customer['name']]);
$reviews = $reviewStmt->fetchAll();

$cancelledStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE customer_id = ? AND status='cancelled'");
$cancelledStmt->execute([$id]);
$cancelledValue = (float) $cancelledStmt->fetchColumn();

$avgOrder = count($orders) > 0 ? (float) $customer['total_spent'] / max(1, count($orders)) : 0.0;
$topProducts = $pdo->prepare(
    'SELECT oi.product_name, SUM(oi.quantity) qty, SUM(oi.line_total) spent
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.customer_id = ? AND o.status <> "cancelled"
   GROUP BY oi.product_name ORDER BY spent DESC LIMIT 5'
);
$topProducts->execute([$id]);
$topProducts = $topProducts->fetchAll();
?>

<div class="page-head">
  <a class="btn btn-sm" href="<?= e(admin_url('customers.php')) ?>">&larr; All customers</a>
  <h1><?= e($customer['name']) ?></h1>
  <?= status_badge((string) $customer['status']) ?>
</div>

<div class="stats">
  <div class="stat"><span class="ic blue">&#9776;</span><div><b><?= count($orders) ?></b><span>Orders</span></div></div>
  <div class="stat"><span class="ic orange">&#8377;</span><div><b><?= money($customer['total_spent']) ?></b><span>Total spent</span></div></div>
  <div class="stat"><span class="ic green">&#8721;</span><div><b><?= money($avgOrder) ?></b><span>Avg order value</span></div></div>
  <div class="stat"><span class="ic gold">&#9825;</span><div><b><?= e(date('d M Y', strtotime($customer['created_at']))) ?></b><span>Customer since</span></div></div>
</div>

<div class="grid-2-1">
  <div>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h2>Order history</h2></div>
      <?php if (!$orders): ?>
        <div class="empty">No orders yet.</div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Order</th><th class="num">Total</th><th>Payment</th><th>Status</th><th>Placed</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td class="mono t-title"><?= e($o['order_code']) ?></td>
              <td class="num"><?= money($o['total']) ?></td>
              <td><?= e($o['payment_method']) ?> &middot; <?= status_badge((string) $o['payment_status']) ?></td>
              <td><?= status_badge((string) $o['status']) ?></td>
              <td class="small muted"><?= e(date('d M Y', strtotime($o['placed_at']))) ?></td>
              <td class="right"><a class="btn btn-sm" href="<?= e(admin_url('order-view.php?id=' . (int) $o['id'])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="card-head"><h3>Favourite products</h3></div>
        <div class="card-body">
          <?php if (!$topProducts): ?>
            <div class="empty">No purchase history.</div>
          <?php else: ?>
            <ul class="step-list">
              <?php foreach ($topProducts as $t): ?>
                <li><span><?= e($t['product_name']) ?></span><span><?= (int) $t['qty'] ?> pcs &middot; <b><?= money($t['spent']) ?></b></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Reviews written</h3></div>
        <div class="card-body">
          <?php if (!$reviews): ?>
            <div class="empty">No reviews yet.</div>
          <?php else: ?>
            <?php foreach (array_slice($reviews, 0, 5) as $r): ?>
              <div class="kv">
                <span>
                  <b><?= str_repeat('&#9733;', (int) $r['rating']) ?></b>
                  <span class="muted"><?= e((string) ($r['product_name'] ?? 'Store')) ?></span>
                  <div class="t-sub"><?= e(mb_strimwidth((string) $r['comment'], 0, 90, '…')) ?></div>
                </span>
                <span><?= status_badge((string) $r['status']) ?></span>
              </div>
            <?php endforeach; ?>
            <a class="btn btn-sm btn-block" style="margin-top:10px" href="<?= e(admin_url('reviews.php')) ?>">Moderate reviews</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h3>Details</h3></div>
      <div class="card-body">
        <div class="kv"><span>Email</span><b><?= e($customer['email']) ?></b></div>
        <div class="kv"><span>Phone</span><b><?= e((string) ($customer['phone'] ?: '—')) ?></b></div>
        <div class="kv"><span>Address</span><b style="text-align:right;max-width:65%"><?= nl2br(e((string) ($customer['address'] ?: '—'))) ?></b></div>
        <div class="kv"><span>City / State</span><b><?= e(trim((string) ($customer['city'] . ', ' . $customer['state']), ', ')) ?: '—' ?></b></div>
        <div class="kv"><span>PIN</span><b><?= e((string) ($customer['pincode'] ?: '—')) ?></b></div>
        <div class="kv"><span>Cancelled value</span><b><?= money($cancelledValue) ?></b></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Edit details</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <label>Name<input name="name" required value="<?= e($customer['name']) ?>"></label>
          <label>Email<input type="email" name="email" required value="<?= e($customer['email']) ?>"></label>
          <label>Phone<input name="phone" value="<?= e((string) ($customer['phone'] ?? '')) ?>"></label>
          <label>Address<textarea name="address" rows="3"><?= e((string) ($customer['address'] ?? '')) ?></textarea></label>
          <div class="row">
            <label>City<input name="city" value="<?= e((string) ($customer['city'] ?? '')) ?>"></label>
            <label>State<input name="state" value="<?= e((string) ($customer['state'] ?? '')) ?>"></label>
            <label>PIN<input name="pincode" value="<?= e((string) ($customer['pincode'] ?? '')) ?>"></label>
          </div>
          <button class="btn btn-primary btn-block" type="submit">Save details</button>
        </form>

        <form method="post" style="margin-top:10px" data-confirm="Toggle account status for <?= e($customer['name']) ?>?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <button class="btn btn-block<?= $customer['status'] === 'active' ? ' btn-danger' : ' btn-green' ?>" type="submit">
            <?= $customer['status'] === 'active' ? 'Block this customer' : 'Unblock this customer' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>