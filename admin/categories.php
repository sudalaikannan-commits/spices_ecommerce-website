<?php
/** Categories CRUD. */

declare(strict_types=1);

$pageTitle = 'Categories';
$navKey = 'categories';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = input('name');
        $slug = slugify(input('slug') ?: $name);
        $desc = input('description');
        $order = (int) input('display_order', '0');
        $status = input('status', 'active') === 'active' ? 'active' : 'inactive';
        $image = handle_image_upload('image');

        if ($name === '' || $slug === '') {
            flash('error', 'Name is required.');
        } else {
            $chk = $pdo->prepare('SELECT id FROM categories WHERE slug = ? AND id <> ?');
            $chk->execute([$slug, $id]);
            if ($chk->fetch()) {
                flash('error', 'Another category already uses this slug.');
            } elseif ($id) {
                $cur = $pdo->prepare('SELECT image_url FROM categories WHERE id = ?');
                $cur->execute([$id]);
                $oldImage = (string) ($cur->fetchColumn() ?: '');
                $img = $image ?: $oldImage;
                if (isset($_POST['remove_image'])) {
                    delete_upload($oldImage);
                    $img = '';
                }
                $pdo->prepare('UPDATE categories SET name=?,slug=?,description=?,display_order=?,status=?,image_url=? WHERE id=?')
                    ->execute([$name, $slug, $desc ?: null, $order, $status, $img ?: null, $id]);
                flash('success', 'Category updated.');
            } else {
                $pdo->prepare('INSERT INTO categories (name,slug,description,display_order,status,image_url) VALUES (?,?,?,?,?,?)')
                    ->execute([$name, $slug, $desc ?: null, $order, $status, $image ?: null]);
                flash('success', 'Category created.');
            }
        }
        redirect(admin_url('categories.php' . ($id ? '#edit-' . $id : '')));
    }

    if ($action === 'delete' && $id) {
        $cur = $pdo->prepare('SELECT image_url FROM categories WHERE id = ?');
        $cur->execute([$id]);
        delete_upload((string) ($cur->fetchColumn() ?: ''));
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        flash('success', 'Category deleted. Its products are now uncategorised.');
        redirect(admin_url('categories.php'));
    }

    if ($action === 'reorder' && $id) {
        $pdo->prepare('UPDATE categories SET display_order = ? WHERE id = ?')
            ->execute([(int) input('display_order', '0'), $id]);
        flash('success', 'Display order saved.');
        redirect(admin_url('categories.php'));
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    $s = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $s->execute([$editId]);
    $editing = $s->fetch() ?: null;
}

$rows = $pdo->query(
    "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
       FROM categories c ORDER BY c.display_order ASC, c.name ASC"
)->fetchAll();
?>

<div class="grid-2-1">
  <div class="card">
    <div class="card-head">
      <h2><?= $editing ? 'Edit category' : 'Add category' ?></h2>
      <?php if ($editing): ?><a class="btn btn-sm" href="<?= e(admin_url('categories.php')) ?>">Cancel edit</a><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

        <div class="row">
          <label>Name
            <input name="name" required value="<?= e($editing['name'] ?? '') ?>" data-slug-source placeholder="Whole Spices">
          </label>
          <label>Slug
            <input name="slug" value="<?= e($editing['slug'] ?? '') ?>" data-slug-target placeholder="whole-spices">
          </label>
        </div>

        <label>Description
          <textarea name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea>
        </label>

        <div class="row">
          <label>Display order
            <input type="number" name="display_order" value="<?= (int) ($editing['display_order'] ?? 0) ?>">
          </label>
          <label>Status
            <select name="status">
              <option value="active"<?= ($editing['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
              <option value="inactive"<?= ($editing['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive (hidden)</option>
            </select>
          </label>
          <label>Image
            <input type="file" name="image" accept="image/*">
          </label>
        </div>

        <?php if (!empty($editing['image_url'])): ?>
          <label class="check">
            <input type="checkbox" name="remove_image" value="1">
            <span style="font-size:13px">Remove current image</span>
          </label>
        <?php endif; ?>

        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save category' : 'Add category' ?></button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Tips</h3></div>
    <div class="card-body">
      <p class="muted">Categories drive the <span class="mono">products.html</span> filter tabs and the
        <span class="mono">/api/categories.php</span> endpoint. Inactive categories are hidden everywhere,
        but their products stay in the catalogue.</p>
      <p class="muted">Deleting a category does not delete products — they become uncategorised.</p>
    </div>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-head"><h2>All categories</h2><span class="muted"><?= count($rows) ?> total</span></div>
  <?php if (!$rows): ?>
    <div class="empty">No categories yet — add your first one.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th>Slug</th><th class="num">Products</th><th>Order</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <?php if (!empty($r['image_url'])): ?>
                  <img class="thumb" src="<?= e(base_url() . '/' . ltrim($r['image_url'], '/')) ?>" alt="">
                <?php endif; ?>
                <span class="t-title"><?= e($r['name']) ?></span>
              </div>
            </td>
            <td class="mono"><?= e($r['slug']) ?></td>
            <td class="num"><?= (int) $r['product_count'] ?></td>
            <td>
              <form method="post" class="inline-form" style="flex-wrap:nowrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="number" name="display_order" value="<?= (int) $r['display_order'] ?>" style="width:70px" data-autosubmit>
              </form>
            </td>
            <td><?= status_badge((string) $r['status']) ?></td>
            <td class="right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn btn-sm" href="<?= e(admin_url('categories.php?edit=' . (int) $r['id'] . '#edit-' . (int) $r['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" data-confirm="Delete category &quot;<?= e($r['name']) ?>&quot;?">
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
  <?php endif; ?>
</div>