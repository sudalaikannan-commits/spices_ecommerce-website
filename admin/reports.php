<?php
/** Reports: revenue, orders, AOV, top products, category split, low stock, customer growth. */

declare(strict_types=1);

$pageTitle = 'Reports';
$navKey = 'reports';

require __DIR__ . '/_head.php';

$pdo = db();

$range = (string) ($_GET['range'] ?? '30');
$allowed = ['7', '30', '90', '365'];
$days = in_array($range, $allowed, true) ? (int) $range : 30;
$fromDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

$prevFrom = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
$prevTo = date('Y-m-d', strtotime('-' . $days . ' days'));

function scalar(string $sql, array $params = []): float
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

$revenue = scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled' AND placed_at >= ?", [$fromDate]);
$prevRevenue = scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled' AND placed_at >= ? AND placed_at < ?", [$prevFrom, $prevTo]);
$orders = (int) scalar("SELECT COUNT(*) FROM orders WHERE placed_at >= ?", [$fromDate]);
$prevOrders = (int) scalar("SELECT COUNT(*) FROM orders WHERE placed_at >= ? AND placed_at < ?", [$prevFrom, $prevTo]);
$cancelled = (int) scalar("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND placed_at >= ?", [$fromDate]);
$aov = $orders > 0 ? $revenue / $orders : 0.0;
$prevAov = $prevOrders > 0 ? $prevRevenue / $prevOrders : 0.0;
$discounts = scalar("SELECT COALESCE(SUM(discount),0) FROM orders WHERE status <> 'cancelled' AND placed_at >= ?", [$fromDate]);
$shipping = scalar("SELECT COALESCE(SUM(shipping),0) FROM orders WHERE status <> 'cancelled' AND placed_at >= ?", [$fromDate]);
$newCustomers = (int) scalar('SELECT COUNT(*) FROM customers WHERE created_at >= ?', [$fromDate]);
$unitsSold = (int) scalar("SELECT COALESCE(SUM(quantity),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                             WHERE o.status <> 'cancelled' AND o.placed_at >= ?", [$fromDate]);

$pct = fn(float $now, float $before) => $before > 0 ? round((($now - $before) / $before) * 100, 1) : ($now > 0 ? 100.0 : 0.0);

/* daily series */
$seriesStmt = $pdo->prepare(
    "SELECT DATE(placed_at) d, COALESCE(SUM(total),0) v, COUNT(*) n
       FROM orders WHERE status <> 'cancelled' AND placed_at >= ?
   GROUP BY DATE(placed_at) ORDER BY d ASC"
);
$seriesStmt->execute([$fromDate]);
$map = [];
foreach ($seriesStmt->fetchAll() as $r) {
    $map[$r['d']] = [(float) $r['v'], (int) $r['n']];
}
$labels = $revSeries = [];
$step = $days > 90 ? 7 : 1;
$i = 0;
for ($d = $fromDate; $d <= date('Y-m-d'); $d = date('Y-m-d', strtotime($d . ' +' . $step . ' days'))) {
    if ($step > 1 && $i % $step !== 0) {
        $i++;
        continue;
    }
    $labels[] = date('d M', strtotime($d));
    $revSeries[] = $map[$d][0] ?? 0;
    $i++;
}

/* status split */
$statusLabels = $statusValues = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status ORDER BY n DESC') as $r) {
    $statusLabels[] = ucfirst($r['status']);
    $statusValues[] = (int) $r['n'];
}

/* payment method split */
$payLabels = $payValues = [];
$payStmt = $pdo->prepare('SELECT payment_method, COUNT(*) n FROM orders WHERE placed_at >= ? GROUP BY payment_method');
$payStmt->execute([$fromDate]);
foreach ($payStmt->fetchAll() as $r) {
    $payLabels[] = $r['payment_method'];
    $payValues[] = (int) $r['n'];
}

/* top products + categories */
$topStmt = $pdo->prepare(
    "SELECT oi.product_name, SUM(oi.quantity) units, SUM(oi.line_total) revenue
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status <> 'cancelled' AND o.placed_at >= ?
   GROUP BY oi.product_name ORDER BY revenue DESC LIMIT 10"
);
$topStmt->execute([$fromDate]);
$topProducts = $topStmt->fetchAll();

$catStmt = $pdo->prepare(
    "SELECT c.name, COALESCE(SUM(oi.line_total),0) revenue
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
       JOIN products p ON p.id = oi.product_id
  LEFT JOIN categories c ON c.id = p.category_id
      WHERE o.placed_at >= ?
   GROUP BY c.id, c.name ORDER BY revenue DESC"
);
$catStmt->execute([$fromDate]);
$catRows = $catStmt->fetchAll();
$catLabels = array_column($catRows, 'name');
$catValues = array_map('floatval', array_column($catRows, 'revenue'));

/* best customers in range */
$bestStmt = $pdo->prepare(
    "SELECT c.name, c.email, COALESCE(SUM(o.total),0) spent, COUNT(*) orders
       FROM orders o JOIN customers c ON c.id = o.customer_id
      WHERE o.status <> 'cancelled' AND o.placed_at >= ?
   GROUP BY c.id ORDER BY spent DESC LIMIT 8"
);
$bestStmt->execute([$fromDate]);
$bestCustomers = $bestStmt->fetchAll();

/* low stock */
$lowStock = $pdo->query(
    "SELECT p.name, p.sku, i.stock, i.reorder_level
       FROM inventory i JOIN products p ON p.id = i.product_id
      WHERE p.status = 'active' AND i.stock <= i.reorder_level
   ORDER BY i.stock ASC"
)->fetchAll();

/* stock valuation */
$valuation = scalar("SELECT COALESCE(SUM(i.stock * p.price),0) FROM inventory i JOIN products p ON p.id = i.product_id");
?>

<div class="page-head">
  <h1>Reports</h1>
  <div class="btn-row">
    <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'] as $k => $lbl): ?>
      <a class="btn btn-sm<?= $range === $k ? ' btn-primary' : '' ?>" href="<?= e(admin_url('reports.php?range=' . $k)) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="stats">
  <div class="stat"><span class="ic orange">&#8377;</span><div><b><?= money($revenue) ?></b><span>Revenue</span>
    <span class="<?= $pct($revenue, $prevRevenue) >= 0 ? 'muted' : 'muted' ?>"><?= $pct($revenue, $prevRevenue) >= 0 ? '&#9650;' : '&#9660;' ?> <?= e(abs($pct($revenue, $prevRevenue))) ?>% vs prev period</span></div></div>
  <div class="stat"><span class="ic green">&#9776;</span><div><b><?= e($orders) ?></b><span>Orders</span>
    <span class="muted"><?= $pct((float) $orders, (float) $prevOrders) >= 0 ? '&#9650;' : '&#9660;' ?> <?= e(abs($pct((float) $orders, (float) $prevOrders))) ?>% vs prev</span></div></div>
  <div class="stat"><span class="ic blue">&#8721;</span><div><b><?= money($aov) ?></b><span>Avg order value</span>
    <span class="muted">prev <?= money($prevAov) ?></span></div></div>
  <div class="stat"><span class="ic gold">&#10003;</span><div><b><?= e($newCustomers) ?></b><span>New customers</span></div></div>
  <div class="stat"><span class="ic blue">&#128230;</span><div><b><?= e($unitsSold) ?></b><span>Units sold</span></div></div>
  <div class="stat"><span class="ic red">&#10007;</span><div><b><?= e($cancelled) ?></b><span>Cancelled</span></div></div>
  <div class="stat"><span class="ic green">&#37;</span><div><b><?= money($discounts) ?></b><span>Discounts given</span></div></div>
  <div class="stat"><span class="ic orange">&#9873;</span><div><b><?= money($shipping) ?></b><span>Shipping collected</span></div></div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-head"><h2>Revenue &amp; orders over time</h2><span class="muted"><?= e(date('d M Y', strtotime($fromDate))) ?> &rarr; today</span></div>
  <div class="card-body"><div class="chart-wrap" style="height:300px"><canvas id="chartRev" data-chart></canvas></div></div>
</div>

<div class="grid-2" style="margin-bottom:14px">
  <div class="card">
    <div class="card-head"><h3>Orders by status</h3></div>
    <div class="card-body">
      <?php if ($statusValues): ?><canvas id="chartStatus" data-chart style="height:240px"></canvas><?php else: ?><div class="empty">No data.</div><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Orders by payment method</h3></div>
    <div class="card-body">
      <?php if ($payValues): ?><canvas id="chartPay" data-chart style="height:240px"></canvas><?php else: ?><div class="empty">No orders in this period.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:14px">
  <div class="card">
    <div class="card-head"><h3>Top products by revenue</h3></div>
    <?php if ($topProducts): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $i => $r): ?>
          <tr>
            <td class="muted"><?= $i + 1 ?></td>
            <td class="t-title"><?= e($r['product_name']) ?></td>
            <td class="num"><?= (int) $r['units'] ?></td>
            <td class="num"><b><?= money($r['revenue']) ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?><div class="card-body empty">No sales in this period.</div><?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h3>Revenue by category</h3></div>
    <div class="card-body">
      <?php if (array_sum($catValues) > 0): ?>
        <canvas id="chartCat" data-chart style="height:250px"></canvas>
      <?php else: ?><div class="empty">No sales in this period.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h3>Best customers</h3></div>
    <?php if ($bestCustomers): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Customer</th><th class="num">Orders</th><th class="num">Spent</th></tr></thead>
        <tbody>
        <?php foreach ($bestCustomers as $c): ?>
          <tr>
            <td>
              <?php if ($c['email'] !== ''): ?>
                <a class="t-title" href="<?= e(admin_url('customers.php?q=' . urlencode($c['email']))) ?>"><?= e($c['name']) ?></a>
              <?php else: ?><span class="t-title"><?= e($c['name']) ?></span><?php endif; ?>
              <div class="t-sub"><?= e($c['email']) ?></div>
            </td>
            <td class="num"><?= (int) $c['orders'] ?></td>
            <td class="num"><b><?= money($c['spent']) ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?><div class="card-body empty">No customer orders yet.</div><?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head">
      <h3>Low stock &amp; valuation</h3>
      <span class="muted"><?= money($valuation) ?> on shelves</span>
    </div>
    <div class="card-body">
      <?php if (!$lowStock): ?>
        <div class="empty">Every active product is above its reorder level.</div>
      <?php else: ?>
        <ul class="step-list">
          <?php foreach ($lowStock as $r): ?>
            <li>
              <span><?= e($r['name']) ?> <span class="muted mono"><?= e($r['sku']) ?></span></span>
              <span class="badge badge-<?= (int) $r['stock'] === 0 ? 'out' : 'low' ?>">
                <?= (int) $r['stock'] ?> left / reorder at <?= (int) $r['reorder_level'] ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-sm btn-block" style="margin-top:12px" href="<?= e(admin_url('inventory.php?low=1')) ?>">Restock in inventory</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$pageScript = 'skChart.line("chartRev",' . json_encode($labels) . ',' . json_encode($revSeries) . ',{color:"#d2691e"});';
if ($statusValues) {
    $pageScript .= 'skChart.donut("chartStatus",' . json_encode($statusLabels) . ',' . json_encode($statusValues) . ',{label:"Orders"});';
}
if ($payValues) {
    $pageScript .= 'skChart.bars("chartPay",' . json_encode($payLabels) . ',' . json_encode($payValues) . ',{color:"#173a25",color2:"#4b9c6a"});';
}
if (array_sum($catValues) > 0) {
    $pageScript .= 'skChart.bars("chartCat",' . json_encode($catLabels) . ',' . json_encode($catValues) . ',{color:"#8a4b12",color2:"#d2691e"});';
}
require __DIR__ . '/_foot.php';