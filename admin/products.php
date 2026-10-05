<?php
/** Products list: search, category/status filter, pagination, quick actions. */

declare(strict_types=1);

$pageTitle = 'Products';
$navKey = 'products';

require __DIR__ . '/_head.php';

$pdo = db();

/** Preserve current filters (dropping some keys) when building a URL. */
function query_keep(array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $k) {
        unset($q[$k]);
    }
    $s = http_build_query($q);
    return $s ? '?' . $s : '';
}

/* ---------------- actions ---------------- */
if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $stmt = $pdo->prepare("UPDATE products SET status = IF(status='active','draft','active') WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Product status updated.');
    } elseif ($action === 'delete' && $id) {
        $img = $pdo->prepare('SELECT image_url FROM products WHERE id = ?');
        $img->execute([$id]);
        $url = $img->fetchColumn();
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        delete_upload(is_string($url) ? $url : null);
        flash('success', 'Product deleted along with its stock and reviews.');
    } elseif ($action === 'feature' && $id) {
        $pdo->prepare('UPDATE products SET is_featured = IF(is_featured=1,0,1) WHERE id = ?')->execute([$id]);
        flash('success', 'Featured flag updated.');
    }

    redirect(admin_url('products.php' . query_keep(['page'])));
}

/* ---------------- filters ---------------- */
$search = trim((string) ($_GET['q'] ?? ''));
$catId = (int) ($_GET['category'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$where = ['1=1'];
$params = [];

if ($search !== '') {
        /* distinct placeholders: native prepares cannot reuse one named parameter */
        $where[] = '(p.name LIKE :s1 OR p.sku LIKE :s2 OR p.short_description LIKE :s3)';
        $params[':s1'] = '%' . $search . '%';
        $params[':s2'] = '%' . $search . '%';
        $params[':s3'] = '%' . $search . '%';
    }
if ($catId > 0) {
    $where[] = 'p.category_id = :cat';
    $params[':cat'] = $catId;
}
if (in_array($status, ['active', 'draft', 'archived'], true)) {
    $where[] = 'p.status = :st';
    $params[':st'] = $status;
}
$whereSql = implode(' AND ', $where);

$total = 0;
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $whereSql");
$cntStmt->execute($params);
$total = (int) $cntStmt->fetchColumn();
$pager = paginate($total, $perPage);
$page = $pager['page'];
$offset = $pager['offset'];

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS cat_name, i.stock, i.reorder_level
       FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  LEFT JOIN inventory  i ON i.product_id = p.id
      WHERE $whereSql
   ORDER BY p.updated_at DESC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
?>

<div class="card">
  <div class="card-head">
    <form class="inline-form" method="get" style="flex:1">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, SKU or description">
      <select name="category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= $catId === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" data-autosubmit>
        <option value="">All statuses</option>
        <?php foreach (['active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived'] as $k => $lbl): ?>
          <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Filter</button>
      <a class="btn" href="<?= e(admin_url('products.php')) ?>">Reset</a>
    </form>
    <a class="btn btn-primary" href="<?= e(admin_url('product-form.php')) ?>">+ Add product</a>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">
      No products match this filter.<br>
      <a class="btn btn-primary" style="margin-top:12px" href="<?= e(admin_url('product-form.php')) ?>">Add your first product</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Product</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th>
            <th>Rating</th><th>Status</th><th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
          $stock = (int) ($r['stock'] ?? 0);
          $low = $stock <= (int) $r['reorder_level'];
          $stockClass = $stock === 0 ? 'badge-out' : ($low ? 'badge-low' : 'badge-active');
          $stockText = $stock === 0 ? 'Out of stock' : $stock . ($low ? ' (low)' : '');
          $eff = effective_price($r);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <img class="thumb" src="<?= e(base_url() . '/' . ltrim((string) ($r['image_url'] ?: 'assets/prod_turmeric.jpg'), '/')) ?>" alt="" loading="lazy">
                <span>
                  <span class="t-title"><?= e($r['name']) ?></span><?= $r['is_featured'] ? ' <span class="badge badge-shipped">featured</span>' : '' ?>
                  <div class="t-sub mono"><?= e($r['sku']) ?> &middot; <?= e(implode(', ', pack_sizes_of($r['pack_sizes']))) ?></div>
                </span>
              </div>
            </td>
            <td><?= e($r['cat_name'] ?? '—') ?></td>
            <td class="num">
              <b><?= money($eff) ?></b>
              <?php if ($eff < (float) $r['price']): ?><div class="t-sub"><s><?= money($r['price']) ?></s></div><?php endif; ?>
            </td>
            <td class="num"><span class="badge <?= e($stockClass) ?>"><?= e($stockText) ?></span></td>
            <td class="num"><?= e(number_format((float) $r['rating_avg'], 1)) ?> <span class="t-sub">(<?= (int) $r['rating_count'] ?>)</span></td>
            <td><?= status_badge((string) $r['status']) ?></td>
            <td class="right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn btn-sm" href="<?= e(admin_url('product-form.php?id=' . (int) $r['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" data-confirm="Change visibility of &quot;<?= e($r['name']) ?>&quot;?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm" type="submit"><?= $r['status'] === 'active' ? 'Unpublish' : 'Publish' ?></button>
                </form>
                <form method="post" style="display:inline" data-confirm="Delete &quot;<?= e($r['name']) ?>&quot; permanently?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($pager, admin_url('products.php' . query_keep(['page']))) ?>
  <?php endif; ?>
</div>