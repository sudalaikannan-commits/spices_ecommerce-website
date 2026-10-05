<?php
/** Create / edit a product (with image upload + stock). */

declare(strict_types=1);

$pageTitle = 'Product';
$navKey = 'products';

require __DIR__ . '/_head.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$product = [
    'id' => 0, 'category_id' => 0, 'name' => '', 'slug' => '', 'sku' => '',
    'short_description' => '', 'description' => '', 'ingredients' => '', 'usage' => '',
    'price' => '', 'sale_price' => '', 'image_url' => '', 'pack_sizes' => '["100g","500g"]',
    'status' => 'active', 'is_featured' => 0,
];
$stock = 0;
$reorder = (int) setting('low_stock_threshold', '8');

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Product not found.');
        redirect(admin_url('products.php'));
    }
    $product = $found;
    $inv = $pdo->prepare('SELECT stock, reorder_level FROM inventory WHERE product_id = ?');
    $inv->execute([$id]);
    $row = $inv->fetch();
    $stock = (int) ($row['stock'] ?? 0);
    $reorder = (int) ($row['reorder_level'] ?? $reorder);
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $product['name'] = input('name');
    $product['sku'] = strtoupper(input('sku'));
    $product['slug'] = slugify(input('slug') ?: $product['name']);
    $product['category_id'] = (int) input('category_id');
    $product['short_description'] = input('short_description');
    $product['description'] = input('description');
    $product['ingredients'] = input('ingredients');
    $product['usage'] = input('usage');
    $product['price'] = input_float('price');
    $saleRaw = input('sale_price');
    $product['sale_price'] = $saleRaw === '' ? null : input_float('sale_price');
    $product['status'] = input('status', 'active');
    $product['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;
    $product['pack_sizes'] = (string) ($_POST['pack_sizes_json'] ?? '["100g"]');
    $stock = max(0, (int) ($_POST['stock'] ?? 0));
    $reorder = max(0, (int) ($_POST['reorder_level'] ?? 0));
    $removeImage = isset($_POST['remove_image']);

    if ($product['name'] === '') {
        $errors[] = 'Product name is required.';
    }
    if ($product['sku'] === '') {
        $errors[] = 'SKU is required.';
    }
    if ($product['price'] <= 0) {
        $errors[] = 'Price must be greater than zero.';
    }
    if ($product['sale_price'] !== null && $product['sale_price'] >= $product['price']) {
        $errors[] = 'Sale price must be lower than the regular price.';
    }
    if ($product['slug'] === '') {
        $errors[] = 'Slug could not be generated — please enter it manually.';
    }
    if (!in_array($product['status'], ['active', 'draft', 'archived'], true)) {
        $product['status'] = 'draft';
    }

    /* unique slug / sku */
    $dup = $pdo->prepare('SELECT id FROM products WHERE (slug = ? OR sku = ?) AND id <> ? LIMIT 1');
    $dup->execute([$product['slug'], $product['sku'], $id]);
    if ($dup->fetch()) {
        $errors[] = 'Another product already uses this slug or SKU.';
    }

    $image = $product['image_url'];
    if (!$errors) {
        $uploaded = handle_image_upload('image');
        if ($uploaded) {
            $image = $uploaded;
        } elseif ($removeImage && $id) {
            delete_upload((string) ($product['image_url'] ?: ''));
            $image = '';
        }
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
    } else {
        if ($id) {
            $pdo->prepare(
                'UPDATE products SET category_id=?, name=?, slug=?, sku=?, short_description=?, description=?,
                        ingredients=?, `usage`=?, price=?, sale_price=?, image_url=?, pack_sizes=?, status=?, is_featured=?
                 WHERE id=?'
            )->execute([
                $product['category_id'] ?: null, $product['name'], $product['slug'], $product['sku'],
                $product['short_description'] ?: null, $product['description'] ?: null,
                $product['ingredients'] ?: null, $product['usage'] ?: null,
                $product['price'], $product['sale_price'], $image ?: null, $product['pack_sizes'],
                $product['status'], $product['is_featured'], $id,
            ]);
            $pdo->prepare('INSERT INTO inventory (product_id, stock, reorder_level) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE stock = VALUES(stock), reorder_level = VALUES(reorder_level)')
                ->execute([$id, $stock, $reorder]);
            flash('success', 'Product updated. The storefront now shows the new data.');
        } else {
            $pdo->prepare(
                'INSERT INTO products (category_id,name,slug,sku,short_description,description,ingredients,`usage`,
                        price,sale_price,image_url,pack_sizes,status,is_featured)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $product['category_id'] ?: null, $product['name'], $product['slug'], $product['sku'],
                $product['short_description'] ?: null, $product['description'] ?: null,
                $product['ingredients'] ?: null, $product['usage'] ?: null,
                $product['price'], $product['sale_price'], $image ?: null, $product['pack_sizes'],
                $product['status'], $product['is_featured'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO inventory (product_id, stock, reorder_level) VALUES (?,?,?)')
                ->execute([$newId, $stock, $reorder]);
            $id = $newId;
            flash('success', 'Product created and published to the storefront.');
        }
        redirect(admin_url('product-form.php?id=' . $id));
    }
}

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$packs = pack_sizes_of($product['pack_sizes']);
$currentImage = (string) ($product['image_url'] ?? '');
$imageSrc = $currentImage !== ''
    ? (str_starts_with($currentImage, 'http') ? $currentImage : base_url() . '/' . ltrim($currentImage, '/'))
    : base_url() . '/assets/prod_turmeric.jpg';
?>

<div class="grid-2-1">
  <div class="card">
    <div class="card-head">
      <h2><?= $id ? 'Edit product' : 'New product' ?></h2>
      <a class="btn btn-sm" href="<?= e(admin_url('products.php')) ?>">&larr; Back</a>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div class="row">
          <label style="flex:2">Name
            <input name="name" required value="<?= e($product['name']) ?>" data-slug-source placeholder="Turmeric Powder">
          </label>
          <label>SKU
            <input name="sku" required value="<?= e($product['sku']) ?>" placeholder="SC-TUR-500">
          </label>
        </div>

        <div class="row">
          <label>Category
            <select name="category_id">
              <option value="">Uncategorised</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) $product['category_id'] === (int) $c['id'] ? ' selected' : '' ?>>
                  <?= e($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>URL slug
            <input name="slug" value="<?= e($product['slug']) ?>" data-slug-target placeholder="turmeric-powder">
          </label>
        </div>

        <label>Short description
          <input name="short_description" maxlength="255" value="<?= e($product['short_description']) ?>" placeholder="One line shown on cards">
        </label>

        <label>Full description
          <textarea name="description" rows="4"><?= e($product['description']) ?></textarea>
        </label>

        <div class="row">
          <label>Ingredients
            <input name="ingredients" value="<?= e($product['ingredients']) ?>" placeholder="100% turmeric">
          </label>
          <label>Usage
            <input name="usage" value="<?= e($product['usage']) ?>" placeholder="Curries, rice, marinades">
          </label>
        </div>

        <div class="row">
          <label>Regular price (<?= e(setting('currency_symbol', '₹')) ?>)
            <input type="number" step="0.01" min="0" name="price" required value="<?= e($product['price']) ?>">
          </label>
          <label>Sale price <span class="hint">Leave empty for no offer</span>
            <input type="number" step="0.01" min="0" name="sale_price" value="<?= e($product['sale_price']) ?>">
          </label>
          <label>Pack sizes <span class="hint">Comma separated</span>
            <input data-packs value="<?= e(implode(', ', $packs)) ?>" placeholder="100g, 500g, 1kg">
          </label>
          <input type="hidden" name="pack_sizes_json" data-packs-json value="<?= e($product['pack_sizes']) ?>">
        </div>

        <div class="row">
          <label>Stock quantity
            <input type="number" min="0" name="stock" value="<?= (int) $stock ?>">
          </label>
          <label>Reorder level
            <input type="number" min="0" name="reorder_level" value="<?= (int) $reorder ?>">
          </label>
          <label>Status
            <select name="status">
              <?php foreach (['active' => 'Active (visible)', 'draft' => 'Draft (hidden)', 'archived' => 'Archived'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>"<?= $product['status'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="check">
          <input type="checkbox" name="is_featured" id="feat" value="1"<?= $product['is_featured'] ? ' checked' : '' ?>>
          <label for="feat" style="margin:0">Feature on the homepage</label>
        </div>

        <button class="btn btn-primary" type="submit"><?= $id ? 'Save changes' : 'Create product' ?></button>
      </form>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h3>Product image</h3></div>
      <div class="card-body">
        <img id="imgPreview" src="<?= e($imageSrc) ?>" alt="" style="width:100%;aspect-ratio:1/1;object-fit:contain;background:#fbfaf7;border:1px solid var(--line);border-radius:10px">
        <label style="margin-top:12px">Upload image
          <input type="file" name="image" accept="image/*" data-preview="imgPreview">
          <span class="hint">JPG / PNG / WEBP up to 2 MB.</span>
        </label>
        <?php if ($currentImage !== ''): ?>
          <label class="check" style="margin-top:4px">
            <input type="checkbox" name="remove_image" value="1">
            <span style="font-size:13px">Remove current image</span>
          </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Storefront preview</h3></div>
      <div class="card-body">
        <p class="muted">The public API serves this product at
          <span class="mono"><?= e(api_url('product.php?slug=' . $product['slug'])) ?></span>
        </p>
        <a class="btn btn-sm" target="_blank" rel="noopener"
           href="<?= e(base_url()) ?>/product-details.html?slug=<?= e(urlencode($product['slug'])) ?>">Open on storefront</a>
      </div>
    </div>
  </div>
</div>