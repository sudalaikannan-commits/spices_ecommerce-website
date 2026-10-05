<?php
/** Customers list: search + spend ranking. */

declare(strict_types=1);

$pageTitle = 'Customers';
$navKey = 'customers';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    if ((string) ($_POST['action'] ?? '') === 'status' && $id) {
        $pdo->prepare("UPDATE customers SET status = IF(status='active','disabled','active') WHERE id = ?")->execute([$id]);
        flash('success', 'Customer status updated.');
    }
    redirect(admin_url('customers.php'));
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['1=1'];
$params = [];
if ($search !== '') {
    /* distinct placeholders: native prepares cannot reuse one named parameter */
    $where[] = '(c.name LIKE :s1 OR c.email LIKE :s2 OR c.phone LIKE :s3)';
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
    $params[':s3'] = '%' . $search . '%';
}
if (in_array($status, ['active', 'disabled'], true)) {
    $where[] = 'c.status = :st';
    $params[':st'] = $status;
}
$whereSql = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
$cnt->execute($params);
$pager = paginate((int) $cnt->fetchColumn(), $perPage);
$offset = $pager['offset'];

$stmt = $pdo->prepare(
    "SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status <> 'cancelled') AS orders_count,
            (SELECT MAX(placed_at) FROM orders o WHERE o.customer_id = c.id) AS last_order
       FROM customers c WHERE $whereSql
   ORDER BY c.total_spent DESC, c.id DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function keep_cq(array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $k) {
        unset($q[$k]);
    }
    $s = http_build_query($q);
    return $s ? '?' . $s : '';
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$active = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status='active'")->fetchColumn();
$lifetime = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled'")->fetchColumn();
$new30 = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
?>

<div class="stats">
  <div class="stat"><span class="ic blue">&#128101;</span><div><b><?= e($total) ?></b><span>Customers</span></div></div>
  <div class="stat"><span class="ic green">&#10003;</span><div><b><?= e($active) ?></b><span>Active</span></div></div>
  <div class="stat"><span class="ic gold">&#9733;</span><div><b><?= e($new30) ?></b><span>New (30 days)</span></div></div>
  <div class="stat"><span class="ic orange">&#8377;</span><div><b><?= money($lifetime) ?></b><span>Revenue</span>
    <span class="muted">avg <?= $total > 0 ? money($lifetime / $total) : money(0) ?></span></div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="inline-form" method="get" style="flex:1">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email or phone">
      <select name="status" data-autosubmit>
        <option value="">All statuses</option>
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
        <option value="disabled"<?= $status === 'disabled' ? ' selected' : '' ?>>Disabled</option>
      </select>
      <button class="btn" type="submit">Search</button>
      <a class="btn" href="<?= e(admin_url('customers.php')) ?>">Reset</a>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No customers found. They are created automatically at checkout.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Customer</th><th>Location</th><th class="num">Orders</th><th class="num">Spent</th><th>Last order</th><th>Joined</th><th>Status</th><th class="right">Manage</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
          <tr>
            <td>
              <a class="t-title" href="<?= e(admin_url('customer-view.php?id=' . (int) $c['id'])) ?>"><?= e($c['name']) ?></a>
              <div class="t-sub"><?= e($c['email']) ?><?= $c['phone'] ? ' &middot; ' . e($c['phone']) : '' ?></div>
            </td>
            <td class="small"><?= e(trim((string) ($c['city'] . ', ' . $c['state']), ', ')) ?: '—' ?></td>
            <td class="num"><?= (int) $c['orders_count'] ?></td>
            <td class="num"><b><?= money($c['total_spent']) ?></b></td>
            <td class="small muted"><?= $c['last_order'] ? e(date('d M Y', strtotime($c['last_order']))) : '—' ?></td>
            <td class="small muted"><?= e(date('d M Y', strtotime($c['created_at']))) ?></td>
            <td><?= status_badge((string) $c['status']) ?></td>
            <td class="right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn btn-sm" href="<?= e(admin_url('customer-view.php?id=' . (int) $c['id'])) ?>">View</a>
                <form method="post" data-confirm="Change status of <?= e($c['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm" type="submit"><?= $c['status'] === 'active' ? 'Block' : 'Unblock' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($pager, admin_url('customers.php' . keep_cq(['page']))) ?>
  <?php endif; ?>
</div>