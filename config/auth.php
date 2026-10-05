<?php
/**
 * Samand Co. — admin authentication (sessions, login guard, CSRF)
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SAMANDADMINSESS');
    session_start();
}

function auth_user(): ?array
{
    auth_boot();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT id, name, email, role, status FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $row = $stmt->fetch();
        $user = $row ?: null;
        if (!$user || $user['status'] !== 'active') {
            auth_logout();
            $user = null;
        }
    }
    return $user;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

function auth_attempt(string $email, string $password): bool
{
    auth_boot();
    $stmt = db()->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    // Always run a hash comparison to keep timing similar.
    $hash = $admin['password_hash'] ?? '$2y$10$usesomesillystringforsalt0000000000000000000000000000000000';
    $ok = password_verify($password, $hash);

    if (!$admin || !$ok || $admin['status'] !== 'active') {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id']    = (int) $admin['id'];
    $_SESSION['admin_name']  = $admin['name'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_role']  = $admin['role'];

    db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([(int) $admin['id']]);

    return true;
}

function auth_logout(): void
{
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Protect an admin page: redirects to login when not authenticated. */
function require_admin(): array
{
    $user = auth_user();
    if (!$user) {
        flash('error', 'Please sign in to access the admin panel.');
        redirect(admin_url('login.php'));
    }
    return $user;
}