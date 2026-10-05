<?php
/** Admin dashboard — live KPIs, charts and recent activity. */

declare(strict_types=1);

$pageTitle = 'Dashboard';
$navKey = 'dashboard';

require __DIR__ . '/_head.php';

$pdo = db();

$revenue30 = (float) ($pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled' AND placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
$orders30 = (int) ($pdo->query("SELECT COUNT(*) FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
$customers = (int) ($pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn() ?: 0);
$products = (int) ($pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn() ?: 0);
$lowStock = (int) ($pdo->query("SELECT COUNT(*) FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.status='active' AND i.stock <= i.reorder_level")->fetchColumn() ?: 0);
$pendingReviews = (int) ($pdo->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn() ?: 0);
$pendingOrders = (int) ($pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn() ?: 0);
$openCarts = (int) ($pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('delivered','cancelled') AND placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn() ?: 0);
$growth = (float) ($pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled' AND placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND placed_at < DATE_SUB(NOW(), INTERVAL 60 DAY)")->fetchColumn() ?: 0);
$growthPct = $growth > 0 ? round((($revenue30 - $growth) / $growth) * 100, 1) : 0;

/* ---- 14-day revenue series ---- */
$series = $pdo->query(
    "SELECT DATE(placed_at) AS d, COALESCE(SUM(total),0) AS v
       FROM orders WHERE placed_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND status <> 'cancelled'
   GROUP BY DATE(placed_at)"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$labels = $values = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d M', strtotime($day));
    $values[] = (float) ($series[$day] ?? 0);
}

/* ---- top products ---- */
$topProducts = $pdo->query(
    "SELECT oi.product_name, SUM(oi.quantity) AS units, SUM(oi.line_total) AS revenue
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status <> 'cancelled'
   GROUP BY oi.product_name ORDER BY revenue DESC LIMIT 5"
)->fetchAll();

/* ---- status split ---- */
$statusRows = $pdo->query("SELECT status, COUNT(*) AS n FROM orders GROUP BY status")->fetchAll();
$statusLabels = $statusValues = [];
foreach ($statusRows as $r) {
    $statusLabels[] = ucfirst($r['status']);
    $statusValues[] = (int) $r['n'];
}

/* ---- category revenue split ---- */
$catRows = $pdo->query(
    "SELECT c.name, COALESCE(SUM(oi.line_total),0) AS revenue
       FROM categories c
  LEFT JOIN order_items oi ON oi.product_id IN (SELECT id FROM products WHERE category_id = c.id)
  LEFT JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
   GROUP BY c.id ORDER BY revenue DESC LIMIT 6"
)->fetchAll();
$catLabels = $catValues = [];
foreach ($catRows as $r) {
    $catLabels[] = $r['name'];
    $catValues[] = (float) $r['revenue'];
}

$recentOrders = $pdo->query("SELECT id, order_code, customer_name, email, total, status, payment_method, placed_at
                               FROM orders ORDER BY placed_at DESC LIMIT 8")->fetchAll();
$lowList = $pdo->query("SELECT p.id, p.name, i.stock, i.reorder_level
                          FROM inventory i JOIN products p ON p.id = i.product_id
                      ORDER BY (i.stock - i.reorder_level) ASC LIMIT 6")->fetchAll();
?>

<div class="stats">
  <div class="stat"><span class="ic orange">&#8377;</span><div><b><?= money($revenue30) ?></b><span>Revenue (30 days)</span>
    <a href="<?= e(admin_url('reports.php')) ?>"><?= $growthPct >= 0 ? '&#9650; +' : '&#9660; ' ?><?= e(abs($growthPct)) ?>% vs prev</a></div></div>
  <div class="stat"><span class="ic green">&#9776;</span><div><b><?= e($orders30) ?></b><span>Orders (30 days)</span>
    <a href="<?= e(admin_url('orders.php')) ?>"><?= e($pendingOrders) ?> pending</a></div></div>
  <div class="stat"><span class="ic blue">&#128101;</span><div><b><?= e($customers) ?></b><span>Customers</span>
    <a href="<?= e(admin_url('customers.php')) ?>">View all</a></div></div>
  <div class="stat"><span class="ic gold">&#9733;</span><div><b><?= e($products) ?></b><span>Active products</span>
    <a href="<?= e(admin_url('products.php')) ?>"><?= e($lowStock) ?> low on stock</a></div></div>
</div>

<div class="grid-2-1">
  <div class="card">
    <div class="card-head"><h2>Revenue trend</h2><span class="muted">Last 14 days</span></div>
    <div class="card-body"><div class="chart-wrap"><canvas id="chartRevenue" data-chart></canvas></div></div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Orders by status</h3></div>
    <div class="card-body">
      <?php if ($statusValues): ?>
        <canvas id="chartStatus" data-chart height="210" style="height:210px"></canvas>
      <?php else: ?>
        <div class="empty">No orders yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-top:14px">
  <div class="card">
    <div class="card-head"><h3>Top products</h3><a class="btn btn-sm" href="<?= e(admin_url('products.php')) ?>">Manage</a></div>
    <?php if ($topProducts): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $r): ?>
          <tr>
            <td class="t-title"><?= e($r['product_name']) ?></td>
            <td class="num"><?= e((int) $r['units']) ?></td>
            <td class="num"><?= money($r['revenue']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="card-body empty">Sales will appear here.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h3>Revenue by category</h3><a class="btn btn-sm" href="<?= e(admin_url('reports.php')) ?>">Reports</a></div>
    <div class="card-body">
      <?php if (array_sum($catValues) > 0): ?>
        <canvas id="chartCats" data-chart height="240" style="height:240px"></canvas>
      <?php else: ?>
        <div class="empty">No sales recorded yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-top:14px">
  <div class="card">
    <div class="card-head"><h3>Recent orders</h3><a class="btn btn-sm" href="<?= e(admin_url('orders.php')) ?>">All orders</a></div>
    <?php if ($recentOrders): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Order</th><th>Customer</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td><a class="mono" href="<?= e(admin_url('order-view.php?id=' . (int) $o['id'])) ?>"><?= e($o['order_code']) ?></a>
              <div class="t-sub"><?= e(date('d M, H:i', strtotime($o['placed_at']))) ?></div></td>
            <td><span class="t-title"><?= e($o['customer_name']) ?></span><div class="t-sub"><?= e($o['email']) ?></div></td>
            <td class="num"><?= money($o['total']) ?></td>
            <td><?= status_badge((string) $o['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="card-body empty">No orders yet.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h3>Stock alerts</h3><a class="btn btn-sm" href="<?= e(admin_url('inventory.php')) ?>">Inventory</a></div>
    <div class="card-body">
      <?php if ($pendingReviews > 0): ?>
        <div class="alert alert-info" style="display:flex;justify-content:space-between;align-items:center">
          <span><b><?= e($pendingReviews) ?></b> review(s) awaiting moderation</span>
          <a class="btn btn-sm" href="<?= e(admin_url('reviews.php?status=pending')) ?>">Review</a>
        </div>
      <?php endif; ?>
      <?php if ($lowList): ?>
        <ul class="step-list">
        <?php foreach ($lowList as $r): $low = (int) $r['stock'] <= (int) $r['reorder_level']; ?>
          <li>
            <span><?= e($r['name']) ?></span>
            <span class="stock-pill">
              <span class="badge <?= $low ? 'badge-low' : 'badge-active' ?>"><?= e((int) $r['stock']) ?> left</span>
              (min <?= e((int) $r['reorder_level']) ?>)
            </span>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty">All products are well stocked.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$pageScript = <<<'JS'
skChart.line("chartRevenue", REVENUE_LABELS, REVENUE_VALUES, {color:"#d2691e"});
JS;
$pageScript = str_replace(
    ['REVENUE_LABELS', 'REVENUE_VALUES'],
    [json_encode($labels), json_encode($values)],
    $pageScript
);

if ($statusValues) {
    $pageScript .= 'skChart.donut("chartStatus",' . json_encode($statusLabels) . ',' . json_encode($statusValues) . ',{label:"Orders"});';
}
if (array_sum($catValues) > 0) {
    $pageScript .= 'skChart.bars("chartCats",' . json_encode($catLabels) . ',' . json_encode($catValues) . ',{color:"#173a25",color2:"#4b9c6a"});';
}
require __DIR__ . '/_foot.php';