<?php
/** Coupons CRUD. */

declare(strict_types=1);

$pageTitle = 'Coupons';
$navKey = 'coupons';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string) input('code')));
        $type = input('type') === 'fixed' ? 'fixed' : 'percent';
        $value = input_float('value');
        $minOrder = input_float('min_order');
        $maxDiscount = input('max_discount');
        $usageLimit = input('usage_limit');
        $starts = input('starts_at');
        $expires = input('expires_at');
        $status = input('status') === 'active' ? 'active' : 'disabled';

        $errors = [];
        if ($code === '') {
            $errors[] = 'Code is required.';
        }
        if ($value <= 0) {
            $errors[] = 'Discount value must be greater than zero.';
        }
        if ($type === 'percent' && $value > 100) {
            $errors[] = 'Percentage discount cannot exceed 100.';
        }

        $chk = $pdo->prepare('SELECT id FROM coupons WHERE code = ? AND id <> ?');
        $chk->execute([$code, $id]);
        if ($chk->fetch()) {
            $errors[] = 'That coupon code already exists.';
        }

        if ($errors) {
            foreach ($errors as $err) {
                flash('error', $err);
            }
        } elseif ($id) {
            $pdo->prepare(
                'UPDATE coupons SET code=?,type=?,value=?,min_order=?,max_discount=?,usage_limit=?,starts_at=?,expires_at=?,status=? WHERE id=?'
            )->execute([
                $code, $type, $value, $minOrder,
                $maxDiscount === '' ? null : (float) $maxDiscount,
                $usageLimit === '' ? null : (int) $usageLimit,
                $starts ?: null, $expires ?: null, $status, $id,
            ]);
            flash('success', 'Coupon updated.');
        } else {
            $pdo->prepare(
                'INSERT INTO coupons (code,type,value,min_order,max_discount,usage_limit,starts_at,expires_at,status)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $code, $type, $value, $minOrder,
                $maxDiscount === '' ? null : (float) $maxDiscount,
                $usageLimit === '' ? null : (int) $usageLimit,
                $starts ?: null, $expires ?: null, $status,
            ]);
            flash('success', 'Coupon created.');
        }
        redirect(admin_url('coupons.php'));
    }

    if ($action === 'toggle' && $id) {
        $pdo->prepare("UPDATE coupons SET status = IF(status='active','disabled','active') WHERE id = ?")->execute([$id]);
        flash('success', 'Coupon status updated.');
        redirect(admin_url('coupons.php'));
    }

    if ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
        flash('success', 'Coupon deleted.');
        redirect(admin_url('coupons.php'));
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    $s = $pdo->prepare('SELECT * FROM coupons WHERE id = ?');
    $s->execute([$editId]);
    $editing = $s->fetch() ?: null;
}

$rows = $pdo->query('SELECT * FROM coupons ORDER BY status ASC, code ASC')->fetchAll();
$today = date('Y-m-d');

function coupon_state(array $c, string $today): array
{
    if ($c['status'] !== 'active') {
        return ['disabled', 'Disabled'];
    }
    if (!empty($c['starts_at']) && $c['starts_at'] > $today) {
        return ['pending', 'Scheduled'];
    }
    if (!empty($c['expires_at']) && $c['expires_at'] < $today) {
        return ['cancelled', 'Expired'];
    }
    if (!empty($c['usage_limit']) && (int) $c['used_count'] >= (int) $c['usage_limit']) {
        return ['archived', 'Used up'];
    }
    return ['active', 'Live'];
}
?>

<div class="grid-2-1">
  <div class="card">
    <div class="card-head">
      <h2><?= $editing ? 'Edit coupon' : 'Create coupon' ?></h2>
      <?php if ($editing): ?><a class="btn btn-sm" href="<?= e(admin_url('coupons.php')) ?>">Cancel edit</a><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

        <div class="row">
          <label>Code
            <input name="code" required value="<?= e($editing['code'] ?? '') ?>" placeholder="WELCOME10" style="text-transform:uppercase">
          </label>
          <label>Type
            <select name="type">
              <option value="percent"<?= ($editing['type'] ?? 'percent') === 'percent' ? ' selected' : '' ?>>Percentage (%)</option>
              <option value="fixed"<?= ($editing['type'] ?? '') === 'fixed' ? ' selected' : '' ?>>Fixed amount</option>
            </select>
          </label>
          <label>Value
            <input type="number" step="0.01" min="0" required name="value" value="<?= e($editing['value'] ?? '') ?>">
          </label>
        </div>

        <div class="row">
          <label>Minimum order
            <input type="number" step="0.01" min="0" name="min_order" value="<?= e($editing['min_order'] ?? '0') ?>">
          </label>
          <label>Max discount <span class="hint">Blank = no cap</span>
            <input type="number" step="0.01" min="0" name="max_discount" value="<?= e($editing['max_discount'] ?? '') ?>">
          </label>
          <label>Usage limit <span class="hint">Blank = unlimited</span>
            <input type="number" min="1" name="usage_limit" value="<?= e($editing['usage_limit'] ?? '') ?>">
          </label>
        </div>

        <div class="row">
          <label>Starts
            <input type="date" name="starts_at" value="<?= e($editing['starts_at'] ?? '') ?>">
          </label>
          <label>Expires
            <input type="date" name="expires_at" value="<?= e($editing['expires_at'] ?? '') ?>">
          </label>
          <label>Status
            <select name="status">
              <option value="active"<?= ($editing['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
              <option value="disabled"<?= ($editing['status'] ?? '') === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            </select>
          </label>
        </div>

        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save coupon' : 'Create coupon' ?></button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>How coupons work</h3></div>
    <div class="card-body">
      <p class="muted">Coupons are validated server-side by
        <span class="mono">/api/coupon.php</span> and re-checked inside the order transaction, so a code can
        never be over-applied.</p>
      <p class="muted"><b>Usage limit</b> caps total redemptions; <b>max discount</b> caps a percentage discount.</p>
    </div>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-head"><h2>All coupons</h2><span class="muted"><?= count($rows) ?> total</span></div>
  <?php if (!$rows): ?>
    <div class="empty">No coupons yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Code</th><th>Offer</th><th class="num">Min order</th><th class="num">Used</th><th>Window</th><th>State</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): [$cls, $txt] = coupon_state($c, $today); ?>
          <tr>
            <td><span class="mono t-title"><?= e($c['code']) ?></span></td>
            <td>
              <?= $c['type'] === 'percent' ? e(rtrim(rtrim((string) $c['value'], '0'), '.')) . '% off' : money($c['value']) . ' off' ?>
              <?php if (!empty($c['max_discount'])): ?>
                <div class="t-sub">capped at <?= money($c['max_discount']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= money($c['min_order']) ?></td>
            <td class="num"><?= (int) $c['used_count'] ?><?= $c['usage_limit'] ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
            <td class="small muted">
              <?= $c['starts_at'] ? e(date('d M Y', strtotime($c['starts_at']))) : 'Any time' ?>
              &rarr;
              <?= $c['expires_at'] ? e(date('d M Y', strtotime($c['expires_at']))) : 'No expiry' ?>
            </td>
            <td><span class="badge badge-<?= e($cls) ?>"><?= e($txt) ?></span></td>
            <td class="right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn btn-sm" href="<?= e(admin_url('coupons.php?edit=' . (int) $c['id'])) ?>">Edit</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm" type="submit"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" style="display:inline" data-confirm="Delete coupon &quot;<?= e($c['code']) ?>&quot;?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>