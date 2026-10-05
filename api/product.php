<?php
/**
 * GET /api/product.php?id=1   or   ?slug=turmeric-powder
 * Single product with its approved reviews.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$id = (int) ($_GET['id'] ?? 0);
$slug = trim((string) ($_GET['slug'] ?? ''));

if (!$id && $slug === '') {
    json_out(['error' => 'Provide ?id= or ?slug='], 400);
}

/* resolve ?id= or ?slug= to a real active product id */
$found = 0;
if ($id > 0) {
    $found = $id;
} elseif ($slug !== '') {
    $stmt = db()->prepare("SELECT id FROM products WHERE slug = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$slug]);
    $found = (int) $stmt->fetchColumn();
}

$product = null;
if ($found > 0) {
    $rows = query_products(['ids' => [$found]]);
    $product = $rows[0] ?? null;
}

if (!$product) {
    json_out(['error' => 'Product not found.'], 404);
}

$stmt = db()->prepare("SELECT customer_name, rating, title, comment, created_at
                         FROM reviews
                        WHERE product_id = ? AND status = 'approved'
                     ORDER BY created_at DESC LIMIT 20");
$stmt->execute([(int) $product['id']]);

/* prefer same-category items, then fall back to any other product */
$bySlug = db()->prepare('SELECT slug FROM categories WHERE id = (SELECT category_id FROM products WHERE id = ?) LIMIT 1');
$bySlug->execute([(int) $product['id']]);
$catSlug = (string) ($bySlug->fetchColumn() ?: '');

$related = $catSlug !== ''
    ? query_products(['category' => $catSlug, 'limit' => 8])
    : [];
if (count($related) < 4) {
    $seen = array_column($related, 'id');
    $seen[] = (int) $product['id'];
    foreach (query_products(['limit' => 12]) as $p) {
        if (!in_array((int) $p['id'], $seen, true)) {
            $related[] = $p;
            $seen[] = (int) $p['id'];
        }
    }
}
$related = array_values(array_filter($related, fn($p) => (int) $p['id'] !== (int) $product['id']));

json_out([
    'ok'       => true,
    'product'  => $product,
    'reviews'  => $stmt->fetchAll(),
    'related'  => array_slice($related, 0, 3),
]);