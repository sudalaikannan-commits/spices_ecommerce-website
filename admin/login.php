<?php
/** Admin login. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/auth.php';

if (auth_user()) {
    header('Location: ' . admin_url('index.php'));
    exit;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $email = input('email');
    $password = (string) ($_POST['password'] ?? '');
    $ok = auth_attempt($email, $password);

    if ($ok) {
        header('Location: ' . admin_url('index.php'));
        exit;
    }
    $error = 'Invalid email or password.';
    usleep(400000);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login &middot; Samand Co.</title>
<link rel="icon" href="<?= e(base_url()) ?>/assets/samand-co-logo.png">
<link rel="stylesheet" href="<?= e(admin_url('assets/admin.css')) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="brand">
      <span class="brand-mark">&#10047;</span>
      <span><b style="font-size:18px">Samand Co.</b><small class="muted">Admin Panel</small></span>
    </div>

    <h1>Welcome back</h1>
    <p class="muted" style="margin-top:-6px">Sign in to manage the store.</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (db_installed()): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Email
          <input type="email" name="email" required autofocus value="<?= e(input('email')) ?>" placeholder="admin@samandco.com">
        </label>
        <label>Password
          <input type="password" name="password" required placeholder="Your password">
        </label>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
      </form>
      <p class="muted center" style="margin:14px 0 0"><a href="<?= e(base_url()) ?>/index.php">&larr; Back to store</a></p>
    <?php else: ?>
      <div class="alert alert-info">Samand Co. is not installed yet. Run the web installer first.</div>
      <a class="btn btn-primary btn-block" href="<?= e(base_url()) ?>/install.php">Open installer</a>
    <?php endif; ?>
  </div>
</div>
<div id="toast-box"></div>
<script src="<?= e(admin_url('assets/admin.js')) ?>"></script>
</body>
</html>