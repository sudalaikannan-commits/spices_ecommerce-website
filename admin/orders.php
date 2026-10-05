<?php
/** Orders list with filters + search. */

declare(strict_types=1);

$pageTitle = 'Orders';
$navKey = 'orders';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'status' && $id) {
        $status = input('status');
        if (in_array($status, order_statuses(), true)) {
            $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
            $stmt->execute([$id]);
            $old = (string) ($stmt->fetchColumn() ?? '');
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
            $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')
                ->execute([$id, $status, 'Status changed from ' . $old . ' to ' . $status, auth_user()['name'] ?? 'admin']);
            flash('success', 'Order status updated to ' . $status . '.');
        }
        redirect(admin_url('orders.php' . ($_POST['back'] ?? '')));
    }
}

$status = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];
if (in_array($status, order_statuses(), true)) {
    $where[] = 'status = :st';
    $params[':st'] = $status;
}
if ($search !== '') {
        /* distinct placeholders: native prepares cannot reuse one named parameter */
        $where[] = '(order_code LIKE :s1 OR customer_name LIKE :s2 OR email LIKE :s3 OR phone LIKE :s4)';
        $params[':s1'] = '%' . $search . '%';
        $params[':s2'] = '%' . $search . '%';
        $params[':s3'] = '%' . $search . '%';
        $params[':s4'] = '%' . $search . '%';
    }
if ($from !== '') {
    $where[] = 'placed_at >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'placed_at <= :to';
    $params[':to'] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE $whereSql");
$cnt->execute($params);
$pager = paginate((int) $cnt->fetchColumn(), $perPage);
$offset = $pager['offset'];

$stmt = $pdo->prepare(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
       FROM orders o WHERE $whereSql
   ORDER BY o.placed_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function keep_q(array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $k) {
        unset($q[$k]);
    }
    $s = http_build_query($q);
    return $s ? '?' . $s : '';
}

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$allCount = array_sum($counts);
$revenue = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled'")->fetchColumn();
?>

<div class="stats">
  <div class="stat"><span class="ic green">&#9776;</span><div><b><?= e($allCount) ?></b><span>Total orders</span></div></div>
  <div class="stat"><span class="ic gold">&#9203;</span><div><b><?= e($counts['pending'] ?? 0) ?></b><span>Pending</span><a href="<?= e(admin_url('orders.php?status=pending')) ?>">Open</a></div></div>
  <div class="stat"><span class="ic blue">&#10142;</span><div><b><?= e($counts['shipped'] ?? 0) ?></b><span>Shipped</span></div></div>
  <div class="stat"><span class="ic orange">&#8377;</span><div><b><?= money($revenue) ?></b><span>Lifetime revenue</span></div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="inline-form" method="get" style="flex:1">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Order code, name, email or phone">
      <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
      <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
      <button class="btn" type="submit">Filter</button>
      <a class="btn" href="<?= e(admin_url('orders.php')) ?>">Reset</a>
    </form>
  </div>

  <div class="card-head" style="border-top:0;flex-wrap:wrap;gap:6px">
    <a class="btn btn-sm<?= $status === '' ? ' btn-primary' : '' ?>" href="<?= e(admin_url('orders.php')) ?>">All (<?= e($allCount) ?>)</a>
    <?php foreach (order_statuses() as $st): ?>
      <a class="btn btn-sm<?= $status === $st ? ' btn-primary' : '' ?>"
         href="<?= e(admin_url('orders.php?status=' . $st)) ?>"><?= e(ucfirst($st)) ?> (<?= e($counts[$st] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No orders match this filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Order</th><th>Customer</th><th class="num">Items</th><th class="num">Total</th><th>Payment</th><th>Status</th><th>Placed</th><th class="right">Manage</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
          <tr>
            <td><a class="mono t-title" href="<?= e(admin_url('order-view.php?id=' . (int) $o['id'])) ?>"><?= e($o['order_code']) ?></a></td>
            <td>
              <span class="t-title"><?= e($o['customer_name']) ?></span>
              <div class="t-sub"><?= e($o['email']) ?><?= $o['phone'] ? ' &middot; ' . e($o['phone']) : '' ?></div>
            </td>
            <td class="num"><?= (int) $o['item_count'] ?></td>
            <td class="num"><b><?= money($o['total']) ?></b><?php if ((float) $o['discount'] > 0): ?>
              <div class="t-sub"><?= money($o['discount']) ?> off<?= $o['coupon_code'] ? ' (' . e($o['coupon_code']) . ')' : '' ?></div>
            <?php endif; ?></td>
            <td>
              <span class="small"><?= e($o['payment_method']) ?></span><br>
              <?= status_badge((string) $o['payment_status']) ?>
            </td>
            <td><?= status_badge((string) $o['status']) ?></td>
            <td class="small muted"><?= e(date('d M Y', strtotime($o['placed_at']))) ?><br><?= e(date('H:i', strtotime($o['placed_at']))) ?></td>
            <td class="right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn btn-sm" href="<?= e(admin_url('order-view.php?id=' . (int) $o['id'])) ?>">View</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                  <input type="hidden" name="back" value="<?= e(keep_q(['page'])) ?>">
                  <?php if ($o['status'] === 'pending'): ?>
                    <input type="hidden" name="status" value="confirmed">
                    <button class="btn btn-sm btn-green" type="submit">Confirm</button>
                  <?php elseif ($o['status'] === 'confirmed'): ?>
                    <input type="hidden" name="status" value="packed">
                    <button class="btn btn-sm" type="submit">Mark packed</button>
                  <?php elseif ($o['status'] === 'packed'): ?>
                    <input type="hidden" name="status" value="shipped">
                    <button class="btn btn-sm" type="submit">Mark shipped</button>
                  <?php elseif ($o['status'] === 'shipped'): ?>
                    <input type="hidden" name="status" value="delivered">
                    <button class="btn btn-sm btn-green" type="submit">Delivered</button>
                  <?php else: ?>
                    <span class="muted small">Closed</span>
                  <?php endif; ?>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($pager, admin_url('orders.php' . keep_q(['page']))) ?>
  <?php endif; ?>
</div>