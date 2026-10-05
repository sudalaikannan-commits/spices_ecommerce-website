<?php
/**
 * GET /api/categories.php — active categories with product counts.
 * GET /api/store-config.php merged in via categories.php?with_config=1
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$stmt = db()->query("SELECT c.id, c.name, c.slug, c.description, c.image_url, c.display_order,
                            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 'active') AS product_count
                       FROM categories c
                      WHERE c.status = 'active'
                   ORDER BY c.display_order ASC, c.name ASC");

$out = [
    'ok'         => true,
    'categories' => $stmt->fetchAll(),
];

if (isset($_GET['with_config'])) {
    $out['config'] = [
        'store_name'     => setting('store_name', 'Samand Co.'),
        'tagline'        => setting('store_tagline'),
        'email'          => setting('store_email'),
        'phone'          => setting('store_phone'),
        'address'        => setting('store_address'),
        'currency'       => setting('currency_symbol', '₹'),
        'shipping_fee'         => (float) setting('shipping_fee', '49'),
        'free_shipping_above'  => (float) setting('free_shipping_above', '999'),
        'cod_enabled'          => (int) setting('cod_enabled', '1') === 1,
        'online_payment_enabled' => (int) setting('online_payment_enabled', '1') === 1,
        'tax_percent'          => (float) setting('tax_percent', '0'),
    ];
}

json_out($out);