<?php
/**
 * Shared admin header + sidebar shell.
 * Requires: $pageTitle, $navKey. Auto-requires an authenticated admin.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/auth.php';
require_admin();

$pageTitle = $pageTitle ?? 'Dashboard';
$navKey = $navKey ?? '';
$adminName = auth_user()['name'] ?? 'Admin';

function nav_link(string $key, string $file, string $label, string $icon): string
{
    global $navKey;
    $active = $navKey === $key ? ' class="active"' : '';
    return '<a href="' . e(admin_url($file)) . '"' . $active . '><span class="ico">' . $icon . '</span>' . e($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; Samand Co. Admin</title>
<link rel="icon" href="<?= e(base_url()) ?>/assets/samand-co-logo.png">
<link rel="stylesheet" href="<?= e(admin_url('assets/admin.css')) ?>">
</head>
<body>
<div class="layout">

  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-mark">&#10047;</span>
      <span><b>Samand Co.</b><small>Admin Panel</small></span>
    </div>

    <nav class="nav">
      <div class="group">Overview</div>
      <?= nav_link('dashboard', 'index.php', 'Dashboard', '&#9632;') ?>

      <div class="group">Catalogue</div>
      <?= nav_link('products', 'products.php', 'Products', '&#9679;') ?>
      <?= nav_link('categories', 'categories.php', 'Categories', '&#9707;') ?>
      <?= nav_link('inventory', 'inventory.php', 'Inventory', '&#9634;') ?>
      <?= nav_link('reviews', 'reviews.php', 'Reviews', '&#9733;') ?>

      <div class="group">Sales</div>
      <?= nav_link('orders', 'orders.php', 'Orders', '&#9776;') ?>
      <?= nav_link('payments', 'payments.php', 'Payments', '&#128176;') ?>
      <?= nav_link('coupons', 'coupons.php', 'Coupons', '&#37;') ?>
      <?= nav_link('customers', 'customers.php', 'Customers', '&#128101;') ?>

      <div class="group">Insights</div>
      <?= nav_link('reports', 'reports.php', 'Reports', '&#9641;') ?>
      <?= nav_link('settings', 'settings.php', 'Settings', '&#9881;') ?>
    </nav>

    <div class="sidebar-foot">
      Signed in as <b><?= e($adminName) ?></b><br>
      <a href="<?= e(admin_url('logout.php')) ?>">Sign out</a> &middot;
      <a href="<?= e(base_url()) ?>/index.php" target="_blank" rel="noopener">View store</a>
    </div>
  </aside>

  <div style="min-width:0">
    <header class="topbar">
      <button class="menu-btn" type="button" aria-label="Menu">&#9776;</button>
      <div>
        <h1><?= e($pageTitle) ?></h1>
        <div class="crumb"><?= e(setting('store_name', 'Samand Co.')) ?> &middot; <?= e(date('D, d M Y')) ?></div>
      </div>
      <a class="btn btn-sm" href="<?= e(admin_url('product-form.php')) ?>">+ Add product</a>
    </header>

    <main class="content">
      <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>