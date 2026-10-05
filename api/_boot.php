<?php
/**
 * Samand Co. — shared bootstrap for the public JSON API.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/helpers.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');

/* Never leak stack traces to the storefront — return clean JSON instead. */
set_exception_handler(static function (Throwable $e): void {
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Something went wrong on our side. Please try again.']);
});

/* Every endpoint fails gracefully while the store is not installed yet. */
if (!db_installed()) {
    json_out(['error' => 'Store API not installed. Run install.php first.'], 503);
}

/** Shape a DB product row into the exact object the customer site already uses. */
function shape_product(array $row): array
{
    $price = (float) $row['price'];
    $sale = $row['sale_price'];
    $eff = effective_price($row);
    $stock = (int) ($row['stock'] ?? 0);
    $packs = pack_sizes_of($row['pack_sizes'] ?? null);

    return [
        'id'          => (int) $row['id'],
        'name'        => $row['name'],
        'category'    => $row['category_name'] ?? 'Uncategorised',
        'slug'        => $row['slug'],
        'sku'         => $row['sku'],
        'pack'        => $packs[0] ?? '',
        'packs'       => $packs,
        'price'       => $eff,
        'old'         => ($eff < $price) ? $price : null,
        'img'         => $row['image_url'] ?: 'assets/prod_turmeric.jpg',
        'rating'      => (float) $row['rating_avg'],
        'reviews'     => (int) $row['rating_count'],
        'stock'       => $stock,
        'in_stock'    => $stock > 0,
        'desc'        => $row['short_description'] ?: $row['description'] ?: '',
        'description' => $row['description'] ?: '',
        'ingredients' => $row['ingredients'] ?: '100% natural',
        'usage'       => $row['usage'] ?: 'As per recipe.',
        'featured'    => (bool) $row['is_featured'],
    ];
}

/** Shared product query with filters + pagination. */
function query_products(array $opt = []): array
{
    $where = ["p.status = 'active'"];
    $params = [];

    /* Positional placeholders only: native prepares reject mixing ? with :named
       and reject the same named placeholder repeated in one statement. */
    if (!empty($opt['category'])) {
        $where[] = 'c.slug = ?';
        $params[] = $opt['category'];
    }
    if (!empty($opt['ids']) && is_array($opt['ids'])) {
        $ids = array_map('intval', $opt['ids']);
        $ids = array_values(array_filter($ids, static fn ($v) => $v > 0));
        if ($ids) {
            $where[] = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }
    }
    if (!empty($opt['search'])) {
        $like = '%' . $opt['search'] . '%';
        $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.short_description LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $limit = max(1, min(500, (int) ($opt['limit'] ?? 200)));
    $offset = max(0, (int) ($opt['offset'] ?? 0));

    $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug, i.stock, i.reorder_level
              FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN inventory  i ON i.product_id = p.id
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY p.is_featured DESC, p.id ASC
             LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('shape_product', $stmt->fetchAll());
}

function category_slug(string $name): string
{
    return slugify($name);
}