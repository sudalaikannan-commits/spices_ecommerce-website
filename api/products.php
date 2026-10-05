<?php
/**
 * GET /api/products.php
 * Public product listing. Admin changes appear here immediately.
 *   ?category=whole-spices&search=pepper&limit=200&offset=0
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

try {
    $products = query_products([
        'category' => (string) ($_GET['category'] ?? ''),
        'search'   => (string) ($_GET['search'] ?? ''),
        'limit'    => (int) ($_GET['limit'] ?? 200),
        'offset'   => (int) ($_GET['offset'] ?? 0),
    ]);

    $catStmt = db()->query("SELECT c.id, c.name, c.slug, c.description, c.image_url, c.display_order,
                                   (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 'active') AS product_count
                              FROM categories c
                             WHERE c.status = 'active'
                          ORDER BY c.display_order ASC, c.name ASC");

    json_out([
        'ok'         => true,
        'count'      => count($products),
        'products'   => $products,
        'categories' => $catStmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    json_out(['error' => 'Could not load products.'], 500);
}