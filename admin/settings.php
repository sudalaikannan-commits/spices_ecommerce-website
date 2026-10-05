<?php
/** Store settings (key/value), admin account + password, plus read-only system info. */

declare(strict_types=1);

$pageTitle = 'Settings';
$navKey = 'settings';

require __DIR__ . '/_head.php';

$pdo = db();

$groups = ['store', 'contact', 'general', 'shipping', 'payments', 'inventory'];

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    /* ---- save settings ---- */
    if ($action === 'save_settings') {
        $labels = [
            'store_name' => 'store', 'store_tagline' => 'store',
            'store_email' => 'contact', 'store_phone' => 'contact', 'store_address' => 'contact',
            'currency_symbol' => 'general', 'tax_percent' => 'general', 'site_status' => 'general',
            'shipping_fee' => 'shipping', 'free_shipping_above' => 'shipping', 'shipping_flat_rate' => 'shipping',
            'cod_enabled' => 'payments', 'online_payment_enabled' => 'payments',
            'low_stock_threshold' => 'inventory',
        ];
        /* unchecked checkboxes are not posted — normalise them to 0 first */
        foreach (['cod_enabled', 'online_payment_enabled'] as $flag) {
            if (!array_key_exists($flag, $_POST)) {
                $_POST[$flag] = '0';
            }
        }
        foreach ($labels as $key => $group) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            setting_set($key, input($key), $group);
        }
        flash('success', 'Settings saved. The storefront picks these up on the next API request.');
        redirect(admin_url('settings.php'));
    }

    /* ---- admin profile ---- */
    if ($action === 'profile') {
        $name = input('name');
        $email = strtolower(input('email'));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'A valid name and email are required.');
        } else {
            $admin = auth_user();
            $chk = $pdo->prepare('SELECT id FROM admins WHERE email = ? AND id <> ?');
            $chk->execute([$email, (int) $admin['id']]);
            if ($chk->fetch()) {
                flash('error', 'That email is already used by another admin.');
            } else {
                $pdo->prepare('UPDATE admins SET name = ?, email = ? WHERE id = ?')
                    ->execute([$name, $email, (int) $admin['id']]);
                $_SESSION['admin_name'] = $name;
                flash('success', 'Profile updated.');
            }
        }
        redirect(admin_url('settings.php'));
    }

    /* ---- password ---- */
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([(int) auth_user()['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'The two new passwords do not match.');
        } else {
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int) auth_user()['id']]);
            flash('success', 'Password changed successfully.');
        }
        redirect(admin_url('settings.php'));
    }

    /* ---- add admin ---- */
    if ($action === 'add_admin') {
        $name = input('name');
        $email = strtolower(input('email'));
        $pass = (string) ($_POST['password'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('error', 'Name, valid email and a password of 8+ characters are required.');
        } else {
            try {
                $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)')
                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                flash('success', 'Admin ' . $email . ' added.');
            } catch (PDOException $ex) {
                flash('error', 'That email is already registered.');
            }
        }
        redirect(admin_url('settings.php'));
    }

    if ($action === 'delete_admin') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $id !== (int) auth_user()['id']) {
            $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            flash('success', 'Admin removed.');
        } else {
            flash('error', 'You cannot remove your own account.');
        }
        redirect(admin_url('settings.php'));
    }
}

$admins = $pdo->query('SELECT id, name, email, created_at, last_login_at FROM admins ORDER BY id')->fetchAll();
$counts = [];
foreach (['products', 'orders', 'customers', 'reviews', 'coupons', 'payments'] as $t) {
    $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
?>

<div class="grid-2">
  <div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_settings">
      <div class="card" style="margin-bottom:14px">
        <div class="card-head"><h2>Store details</h2></div>
        <div class="card-body">
          <div class="row">
            <label>Store name<input name="store_name" value="<?= e(setting('store_name', 'Samand Co.')) ?>"></label>
            <label>Tagline<input name="store_tagline" value="<?= e(setting('store_tagline')) ?>"></label>
          </div>
          <div class="row">
            <label>Support email<input type="email" name="store_email" value="<?= e(setting('store_email')) ?>"></label>
            <label>Phone<input name="store_phone" value="<?= e(setting('store_phone')) ?>"></label>
          </div>
          <label>Address<textarea name="store_address" rows="2"><?= e(setting('store_address')) ?></textarea></label>
        </div>
      </div>

      <div class="card" style="margin-bottom:14px">
        <div class="card-head"><h2>Checkout &amp; payments</h2></div>
        <div class="card-body">
          <div class="row">
            <label>Currency symbol<input name="currency_symbol" value="<?= e(setting('currency_symbol', '₹')) ?>" style="max-width:110px"></label>
            <label>Shipping fee<input type="number" step="0.01" min="0" name="shipping_fee" value="<?= e(setting('shipping_fee', '49')) ?>"></label>
            <label>Free shipping above<input type="number" step="0.01" min="0" name="free_shipping_above" value="<?= e(setting('free_shipping_above', '999')) ?>"></label>
            <label>Tax %<input type="number" step="0.01" min="0" name="tax_percent" value="<?= e(setting('tax_percent', '0')) ?>"></label>
          </div>
          <div class="check">
            <input type="checkbox" id="cod" name="cod_enabled" value="1"<?= (int) setting('cod_enabled', '1') === 1 ? ' checked' : '' ?>>
            <label for="cod" style="margin:0">Cash on delivery enabled</label>
          </div>
          <div class="check">
            <input type="checkbox" id="online" name="online_payment_enabled" value="1"<?= (int) setting('online_payment_enabled', '1') === 1 ? ' checked' : '' ?>>
            <label for="online" style="margin:0">Online payment enabled</label>
          </div>
          <label>Low stock threshold<input type="number" min="0" name="low_stock_threshold" value="<?= e(setting('low_stock_threshold', '8')) ?>"></label>
        </div>
      </div>

      <button class="btn btn-primary" type="submit">Save settings</button>
    </form>

    <div class="card" style="margin-top:14px">
      <div class="card-head"><h3>Live API endpoints</h3></div>
      <div class="card-body">
        <p class="muted">These power the customer site — edit data in the admin and it reflects here immediately.</p>
        <ul class="step-list mono">
          <li><span>Products</span><span><?= e(api_url('products.php')) ?></span></li>
          <li><span>Categories</span><span><?= e(api_url('categories.php')) ?></span></li>
          <li><span>Coupon check</span><span><?= e(api_url('coupon.php?code=…')) ?></span></li>
          <li><span>Place order</span><span><?= e(api_url('order.php')) ?> (POST)</span></li>
          <li><span>Track orders</span><span><?= e(api_url('customer-orders.php?email=…')) ?></span></li>
          <li><span>Reviews</span><span><?= e(api_url('reviews.php')) ?></span></li>
        </ul>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h3>My account</h3></div>
      <div class="card-body">
        <form method="post" style="border-bottom:1px dashed var(--line);padding-bottom:14px;margin-bottom:14px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          <label>Name<input name="name" required value="<?= e((string) (auth_user()['name'] ?? '')) ?>"></label>
          <label>Email<input type="email" name="email" required value="<?= e((string) (auth_user()['email'] ?? '')) ?>"></label>
          <button class="btn" type="submit">Update profile</button>
        </form>

        <form method="post" data-confirm="Change your password?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          <label>Current password<input type="password" name="current_password" required></label>
          <label>New password <span class="hint">At least 8 characters</span><input type="password" name="new_password" required minlength="8"></label>
          <label>Confirm new password<input type="password" name="confirm_password" required minlength="8"></label>
          <button class="btn btn-green btn-block" type="submit">Change password</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><h3>Admin users</h3><span class="muted"><?= count($admins) ?></span></div>
      <div class="table-wrap"><table style="min-width:0">
        <thead><tr><th>Admin</th><th>Last login</th><th class="right"></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td>
              <span class="t-title"><?= e($a['name']) ?></span>
              <div class="t-sub"><?= e($a['email']) ?><?= (int) $a['id'] === (int) auth_user()['id'] ? ' (you)' : '' ?></div>
            </td>
            <td class="small muted"><?= $a['last_login_at'] ? e(date('d M Y H:i', strtotime($a['last_login_at']))) : 'never' ?></td>
            <td class="right">
              <?php if ((int) $a['id'] !== (int) auth_user()['id']): ?>
                <form method="post" data-confirm="Remove admin <?= e($a['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_admin">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="card-foot">
        <form method="post" class="row" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_admin">
          <label>Name<input name="name" required></label>
          <label>Email<input type="email" name="email" required></label>
          <label>Password<input type="password" name="password" required minlength="8"></label>
          <div class="shrink"><button class="btn btn-primary" type="submit">Add</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>System</h3></div>
      <div class="card-body">
        <div class="kv"><span>PHP</span><b><?= e(PHP_VERSION) ?></b></div>
        <div class="kv"><span>Database</span><b><?= e((string) DB_NAME) ?></b></div>
        <div class="kv"><span>Products</span><b><?= e($counts['products']) ?></b></div>
        <div class="kv"><span>Orders</span><b><?= e($counts['orders']) ?></b></div>
        <div class="kv"><span>Customers</span><b><?= e($counts['customers']) ?></b></div>
        <div class="kv"><span>Reviews</span><b><?= e($counts['reviews']) ?></b></div>
        <div class="kv"><span>Coupons</span><b><?= e($counts['coupons']) ?></b></div>
        <div class="kv"><span>Payments</span><b><?= e($counts['payments']) ?></b></div>
      </div>
    </div>
  </div>
</div>