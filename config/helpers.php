<?php
/**
 * Samand Co. — shared helpers (escaping, CSRF, uploads, settings, formatting)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ---------- output / request ---------- */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, $default = '')
{
    if (is_post()) {
        $v = $_POST[$key] ?? $default;
    } else {
        $v = $_GET[$key] ?? $default;
    }
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key, (string) $default);
    return is_numeric($v) ? (int) $v : $default;
}

function input_float(string $key, float $default = 0.0): float
{
    $v = input($key, (string) $default);
    return is_numeric($v) ? (float) $v : $default;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Project root URL, e.g. "/samand-co" (or "" when served from the web root).
 * Works from /index.php, /admin/*.php and /api/*.php alike.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    // Strip known sub-folders so helpers can be called from anywhere in the app.
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach (['/admin', '/api'] as $sub) {
            if (substr($dir, -strlen($sub)) === $sub) {
                $dir = substr($dir, 0, -strlen($sub));
                $changed = true;
            }
        }
    }

    $base = $dir === '/' ? '' : $dir;
    return $base;
}

function site_url(): string
{
    return base_url();
}

function admin_url(string $path = ''): string
{
    return base_url() . '/admin/' . ltrim($path, '/');
}

function api_url(string $path = ''): string
{
    return base_url() . '/api/' . ltrim($path, '/');
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf_token'])
        && is_string($sent)
        && hash_equals($_SESSION['csrf_token'], $sent);
}

function csrf_guard(): void
{
    if (!csrf_verify()) {
        http_response_code(419);
        exit('Security token mismatch. Please go back and try again.');
    }
}

/* ---------- flash messages ---------- */

function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ---------- formatting ---------- */

function setting(string $key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, $value, string $group = 'general'): void
{
    db()->prepare(
        'INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)'
    )->execute([$key, (string) $value, $group]);
}

function money($amount): string
{
    return (string) setting('currency_symbol', '₹') . number_format((float) $amount, 2);
}

function effective_price(array $product): float
{
    $sale = $product['sale_price'];
    if ($sale !== null && $sale !== '' && (float) $sale > 0 && (float) $sale < (float) $product['price']) {
        return (float) $sale;
    }
    return (float) $product['price'];
}

function original_price(array $product)
{
    $sale = $product['sale_price'];
    if ($sale !== null && $sale !== '' && (float) $sale > 0 && (float) $sale < (float) $product['price']) {
        return $product['price'];
    }
    return null;
}

function pack_sizes_of(?string $raw): array
{
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'item-' . substr(md5($text . microtime()), 0, 6);
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- uploads ---------- */

function upload_dir(): string
{
    $dir = APP_ROOT . '/uploads/products';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Handle an uploaded image. Returns the stored relative path or null.
 */
function handle_image_upload(string $field = 'image'): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error code ' . (int) $_FILES[$field]['error'] . ').');
    }
    if ($_FILES[$field]['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('Image is larger than 4 MB.');
    }

    $info = new finfo(FILEINFO_MIME_TYPE);
    $mime = $info->file($_FILES[$field]['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    }

    $name = slugify(pathinfo($_FILES[$field]['name'], PATHINFO_FILENAME))
        . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $allowed[$mime];

    $target = upload_dir() . '/' . $name;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return 'uploads/products/' . $name;
}

function delete_upload(?string $path): void
{
    if ($path && strpos($path, 'uploads/') === 0) {
        $full = APP_ROOT . '/' . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/* ---------- pagination ---------- */

function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = min(max(1, input_int('page', 1)), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage, 'total' => $total];
}

/** Render pagination links for the current query string. */
function render_pagination(array $p, string $base): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $qs = $_GET;
    $html = '<div class="pager"><span>Page ' . (int) $p['page'] . ' of ' . (int) $p['pages'] . ' (' . (int) $p['total'] . ' records)</span><div class="pager-links">';
    for ($i = 1; $i <= $p['pages']; $i++) {
        $qs['page'] = $i;
        $html .= '<a class="' . ($i === $p['page'] ? 'active' : '') . '" href="' . e($base . '?' . http_build_query($qs)) . '">' . $i . '</a>';
    }
    return $html . '</div></div>';
}

function order_statuses(): array
{
    return ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'returned'];
}

function status_badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(ucfirst($status)) . '</span>';
}