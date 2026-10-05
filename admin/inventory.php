<?php
/** Inventory: adjust stock, reorder levels, restock log. */

declare(strict_types=1);

$pageTitle = 'Inventory';
$navKey = 'inventory';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);

    if (in_array($action, ['set', 'add', 'subtract'], true) && $productId) {
        $stmt = $pdo->prepare('SELECT stock FROM inventory WHERE product_id = ?');
        $stmt->execute([$productId]);
        $current = (int) ($stmt->fetchColumn() ?? -1);
        $delta = (int) ($_POST['quantity'] ?? 0);
        $reorder = (int) ($_POST['reorder_level'] ?? 0);

        if ($current < 0) {
            $pdo->prepare('INSERT INTO inventory (product_id, stock, reorder_level) VALUES (?,0,?)')
                ->execute([$productId, max(0, $reorder)]);
            $current = 0;
        }

        $newStock = match ($action) {
            'add'      => $current + abs($delta),
            'subtract' => $current - abs($delta),
            default    => $delta,
        };
        $newStock = max(0, $newStock);

        $pdo->prepare('UPDATE inventory SET stock = ?, reorder_level = ? WHERE product_id = ?')
            ->execute([$newStock, max(0, $reorder), $productId]);

        flash('success', sprintf('Stock for product #%d set to %d (was %d).', $productId, $newStock, $current));
        redirect(admin_url('inventory.php' . ($_POST['filter'] ?? '')));
    }

    if ($action === 'bulk_threshold' ) {
        $threshold = max(0, (int) input('threshold', '8'));
        db()->prepare('UPDATE inventory SET reorder_level = ?')->execute([$threshold]);
        setting_set('low_stock_threshold', (string) $threshold);
        flash('success', 'Reorder level set to ' . $threshold . ' for all products.');
        redirect(admin_url('inventory.php'));
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$onlyLow = isset($_GET['low']);

$where = ['p.status <> "archived"'];
$params = [];
if ($search !== '') {
    /* distinct placeholders: native prepares cannot reuse one named parameter */
    $where[] = '(p.name LIKE :s1 OR p.sku LIKE :s2)';
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}
if ($onlyLow) {
    $where[] = 'i.stock <= i.reorder_level';
}
$whereSql = implode(' AND ', $where);

$rows = $pdo->prepare(
    "SELECT p.id, p.name, p.sku, p.image_url, i.stock, i.reorder_level, i.updated_at
       FROM products p JOIN inventory i ON i.product_id = p.id
      WHERE $whereSql
   ORDER BY (i.stock - i.reorder_level) ASC, p.name ASC"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$lowCount = (int) $pdo->query("SELECT COUNT(*) FROM inventory i JOIN products p ON p.id=i.product_id
                                WHERE p.status='active' AND i.stock <= i.reorder_level")->fetchColumn();
$outCount = (int) $pdo->query("SELECT COUNT(*) FROM inventory i JOIN products p ON p.id=i.product_id
                                WHERE p.status='active' AND i.stock = 0")->fetchColumn();
$totalUnits = (int) $pdo->query('SELECT COALESCE(SUM(stock),0) FROM inventory')->fetchColumn();
$threshold = (int) setting('low_stock_threshold', '8');
?>

<div class="stats">
  <div class="stat"><span class="ic orange">&#9634;</span><div><b><?= e($totalUnits) ?></b><span>Units in stock</span></div></div>
  <div class="stat"><span class="ic gold">&#9888;</span><div><b><?= e($lowCount) ?></b><span>Low stock</span><a href="<?= e(admin_url('inventory.php?low=1')) ?>">View</a></div></div>
  <div class="stat"><span class="ic red">&#8709;</span><div><b><?= e($outCount) ?></b><span>Out of stock</span></div></div>
  <div class="stat"><span class="ic blue">&#9881;</span><div><b><?= e($threshold) ?></b><span>Reorder level</span></div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="inline-form" method="get" style="flex:1">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search product or SKU">
      <label class="check" style="margin:0">
        <input type="checkbox" name="low" value="1"<?= $onlyLow ? ' checked' : '' ?> data-autosubmit>
        <span class="small">Low stock only</span>
      </label>
      <button class="btn" type="submit">Search</button>
      <a class="btn" href="<?= e(admin_url('inventory.php')) ?>">Reset</a>
    </form>
    <form method="post" class="inline-form" data-confirm="Apply this reorder level to every product?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_threshold">
      <input type="number" name="threshold" min="0" value="<?= $threshold ?>" style="width:90px" aria-label="Reorder level">
      <button class="btn btn-sm" type="submit">Apply to all</button>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No products match.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Reorder at</th><th>Level</th><th>Updated</th><th>Adjust stock</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $stock = (int) $r['stock'];
          $reorder = (int) $r['reorder_level'];
          $pct = $reorder > 0 ? min(100, round($stock / ($reorder * 3) * 100)) : ($stock > 0 ? 100 : 0);
          $state = $stock === 0 ? 'badge-out' : ($stock <= $reorder ? 'badge-low' : 'badge-active');
          $stateText = $stock === 0 ? 'Out of stock' : ($stock <= $reorder ? 'Low' : 'Healthy');
        ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <img class="thumb" src="<?= e(base_url() . '/' . ltrim((string) ($r['image_url'] ?: 'assets/prod_turmeric.jpg'), '/')) ?>" alt="">
                <span><span class="t-title"><?= e($r['name']) ?></span><div class="t-sub mono"><?= e($r['sku']) ?></div></span>
              </div>
            </td>
            <td class="num"><b><?= $stock ?></b></td>
            <td class="num"><?= $reorder ?></td>
            <td style="min-width:130px">
              <span class="badge <?= e($state) ?>"><?= e($stateText) ?></span>
              <div class="progress" style="margin-top:6px"><i style="width:<?= (int) $pct ?>%;background:<?= $stock === 0 ? '#c0392b' : ($stock <= $reorder ? '#d2691e' : '#1e7d3c') ?>"></i></div>
            </td>
            <td class="small muted"><?= e(date('d M H:i', strtotime($r['updated_at']))) ?></td>
            <td>
              <form method="post" class="inline-form" style="flex-wrap:nowrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="reorder_level" value="<?= $reorder ?>">
                <input type="hidden" name="filter" value="<?= e($search !== '' || $onlyLow ? '?' . http_build_query(array_filter(['q' => $search, 'low' => $onlyLow ? '1' : ''])) : '') ?>">
                <input type="number" name="quantity" min="0" value="10" style="width:74px" aria-label="Quantity">
                <button class="btn btn-sm btn-green" type="submit" title="Add units">+</button>
              </form>
              <form method="post" class="inline-form" style="flex-wrap:nowrap;margin-top:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set">
                <input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="reorder_level" value="<?= $reorder ?>">
                <input type="number" name="quantity" min="0" value="<?= $stock ?>" style="width:74px" aria-label="New quantity">
                <button class="btn btn-sm" type="submit">Set</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>