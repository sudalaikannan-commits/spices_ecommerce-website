<?php
/** Payments ledger. */

declare(strict_types=1);

$pageTitle = 'Payments';
$navKey = 'payments';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    $status = input('status');
    if ($id && in_array($status, ['pending', 'paid', 'failed', 'refunded'], true)) {
        $stmt = $pdo->prepare('SELECT order_id FROM payments WHERE id = ?');
        $stmt->execute([$id]);
        $orderId = (int) ($stmt->fetchColumn() ?: 0);

        $pdo->prepare('UPDATE payments SET status = ?, paid_at = IF(? = "paid", NOW(), paid_at) WHERE id = ?')
            ->execute([$status, $status, $id]);
        if ($orderId) {
            $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?')->execute([$status, $orderId]);
            $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')
                ->execute([$orderId, 'pending', 'Payment marked ' . $status . ' by admin', auth_user()['name'] ?? 'admin']);
        }
        flash('success', 'Payment marked as ' . $status . '.');
    }
    redirect(admin_url('payments.php' . ($_POST['back'] ?? '')));
}

$status = (string) ($_GET['status'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['1=1'];
$params = [];
if (in_array($status, ['pending', 'paid', 'failed', 'refunded'], true)) {
    $where[] = 'p.status = :st';
    $params[':st'] = $status;
}
if ($method !== '') {
    $where[] = 'p.method = :m';
    $params[':m'] = $method;
}
$whereSql = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM payments p WHERE $whereSql");
$cnt->execute($params);
$pager = paginate((int) $cnt->fetchColumn(), $perPage);
$offset = $pager['offset'];

$stmt = $pdo->prepare(
    "SELECT p.*, o.order_code, o.customer_name, o.customer_id, o.status AS order_status
       FROM payments p JOIN orders o ON o.id = p.order_id
      WHERE $whereSql
   ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function keep_pq(array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $k) {
        unset($q[$k]);
    }
    $s = http_build_query($q);
    return $s ? '?' . $s : '';
}

$sums = [];
foreach ($pdo->query('SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) v FROM payments GROUP BY status') as $r) {
    $sums[$r['status']] = ['n' => (int) $r['n'], 'v' => (float) $r['v']];
}
$byMethod = $pdo->query('SELECT method, COUNT(*) n, COALESCE(SUM(amount),0) v FROM payments GROUP BY method')->fetchAll();
?>

<div class="stats">
  <div class="stat"><span class="ic green">&#128176;</span><div><b><?= money($sums['paid']['v'] ?? 0) ?></b><span>Collected</span>
    <span class="muted"><?= (int) ($sums['paid']['n'] ?? 0) ?> payment(s)</span></div></div>
  <div class="stat"><span class="ic gold">&#9203;</span><div><b><?= money($sums['pending']['v'] ?? 0) ?></b><span>Pending</span></div></div>
  <div class="stat"><span class="ic blue">&#8635;</span><div><b><?= money($sums['refunded']['v'] ?? 0) ?></b><span>Refunded</span></div></div>
  <div class="stat"><span class="ic red">&#10007;</span><div><b><?= money($sums['failed']['v'] ?? 0) ?></b><span>Failed</span></div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="inline-form" method="get" style="flex:1">
      <select name="status" data-autosubmit>
        <option value="">All statuses</option>
        <?php foreach (['pending', 'paid', 'failed', 'refunded'] as $k): ?>
          <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e(ucfirst($k)) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="method" data-autosubmit>
        <option value="">All methods</option>
        <?php foreach ($byMethod as $m): ?>
          <option value="<?= e($m['method']) ?>"<?= $method === $m['method'] ? ' selected' : '' ?>><?= e($m['method']) ?></option>
        <?php endforeach; ?>
      </select>
      <a class="btn" href="<?= e(admin_url('payments.php')) ?>">Reset</a>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No payment records yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Order</th><th>Customer</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Date</th><th class="right">Update</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
          <tr>
            <td><a class="mono t-title" href="<?= e(admin_url('order-view.php?id=' . (int) $p['order_id'])) ?>"><?= e($p['order_code']) ?></a>
              <div class="t-sub"><?= status_badge((string) $p['order_status']) ?></div></td>
            <td><?= e($p['customer_name']) ?></td>
            <td><?= e($p['method']) ?><div class="t-sub"><?= e((string) ($p['gateway'] ?? '')) ?></div></td>
            <td class="mono"><?= e((string) ($p['txn_ref'] ?? '—')) ?></td>
            <td class="num"><b><?= money($p['amount']) ?></b></td>
            <td><?= status_badge((string) $p['status']) ?></td>
            <td class="small muted"><?= e(date('d M Y', strtotime($p['created_at']))) ?></td>
            <td class="right">
              <form method="post" class="inline-form" style="justify-content:flex-end;flex-wrap:nowrap">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="back" value="<?= e(keep_pq(['page'])) ?>">
                <select name="status" style="width:auto;min-width:110px">
                  <?php foreach (['pending', 'paid', 'failed', 'refunded'] as $k): ?>
                    <option value="<?= e($k) ?>"<?= $p['status'] === $k ? ' selected' : '' ?>><?= e(ucfirst($k)) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm" type="submit">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($pager, admin_url('payments.php' . keep_pq(['page']))) ?>
  <?php endif; ?>
</div>