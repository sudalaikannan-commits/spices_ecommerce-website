<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/auth.php';
auth_logout();
header('Location: ' . admin_url('login.php'));
exit;