<?php
/**
 * Samand Co. — database connection (PDO / MySQL)
 * Edit the constants below, or define them in config/local.php (git-ignored).
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

if (is_file(APP_ROOT . '/config/local.php')) {
    require_once APP_ROOT . '/config/local.php';
}

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', '3306');
if (!defined('DB_NAME')) define('DB_NAME', 'samand_co');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/**
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Database connection failed. Check config/db.php credentials.');
    }

    return $pdo;
}

/**
 * Connect without dying — used by the installer and login screen so an
 * unreachable database produces a friendly page instead of a fatal error.
 *
 * @return PDO|null
 */
function db_try(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($tried) {
        return null;
    }
    $tried = true;

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        return null;
    }

    return $pdo;
}

/** True when the schema is present (and the server is reachable). */
function db_installed(): bool
{
    $pdo = db_try();
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'products'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}