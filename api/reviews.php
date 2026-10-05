<?php
/**
 * GET  /api/reviews.php?product_id=1      -> approved reviews
 * POST /api/reviews.php                   -> submit review (goes to admin moderation)
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (is_post()) {
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        $in = $_POST;
    }

    $productId = (int) ($in['product_id'] ?? 0);
    $name = trim((string) ($in['name'] ?? ''));
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $rating = max(1, min(5, (int) ($in['rating'] ?? 5)));
    $title = trim((string) ($in['title'] ?? ''));
    $comment = trim((string) ($in['comment'] ?? ''));

    $stmt = db()->prepare('SELECT id FROM products WHERE id = ? AND status = "active"');
    $stmt->execute([$productId]);
    $productId = (int) $stmt->fetchColumn();
    if (!$productId) {
        json_out(['error' => 'Product not found.'], 404);
    }
    if ($name === '' || $comment === '') {
        json_out(['error' => 'Name and review text are required.'], 422);
    }

    db()->prepare('INSERT INTO reviews (product_id, customer_name, rating, title, comment, status) VALUES (?,?,?,?,?,"pending")')
        ->execute([$productId, $name, $rating, $title ?: null, $comment]);

    json_out(['ok' => true, 'message' => 'Thank you! Your review will appear after approval.']);
}

$productId = (int) ($_GET['product_id'] ?? 0);
if (!$productId) {
    json_out(['error' => 'product_id is required.'], 400);
}

$stmt = db()->prepare('SELECT customer_name, rating, title, comment, created_at
                         FROM reviews WHERE product_id = ? AND status = "approved"
                     ORDER BY created_at DESC LIMIT 30');
$stmt->execute([$productId]);

json_out(['ok' => true, 'reviews' => $stmt->fetchAll()]);