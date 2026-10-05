<?php
/** Review moderation. */

declare(strict_types=1);

$pageTitle = 'Reviews';
$navKey = 'reviews';

require __DIR__ . '/_head.php';

$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'approve' && $id) {
        $pdo->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?")->execute([$id]);
        recalc_rating((int) ($pdo->query('SELECT product_id FROM reviews WHERE id = ' . $id)->fetchColumn() ?: 0));
        flash('success', 'Review approved and published.');
    }
    if ($action === 'reject' && $id) {
        $pdo->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?")->execute([$id]);
        recalc_rating((int) ($pdo->query('SELECT product_id FROM reviews WHERE id = ' . $id)->fetchColumn() ?: 0));
        flash('success', 'Review rejected.');
    }
    if ($action === 'delete' && $id) {
        $pid = (int) ($pdo->query('SELECT product_id FROM reviews WHERE id = ' . $id)->fetchColumn() ?: 0);
        $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
        recalc_rating($pid);
        flash('success', 'Review deleted.');
    }
    redirect(admin_url('reviews.php' . ($_POST['back'] ?? '')));
}

function recalc_rating(int $productId): void
{
    if (!$productId) {
        return;
    }
    $stmt = db()->prepare("SELECT COUNT(*) n, COALESCE(AVG(rating),0) a FROM reviews WHERE product_id = ? AND status = 'approved'");
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    db()->prepare('UPDATE products SET rating_avg = ?, rating_count = ? WHERE id = ?')
        ->execute([round((float) $row['a'], 2), (int) $row['n'], $productId]);
}

$status = (string) ($_GET['status'] ?? 'pending');
$status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$cnt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE status = ?');
$cnt->execute([$status]);
$pager = paginate((int) $cnt->fetchColumn(), $perPage);
$offset = $pager['offset'];

$stmt = $pdo->prepare(
    "SELECT r.*, p.name AS product_name, p.image_url
       FROM reviews r LEFT JOIN products p ON p.id = r.product_id
      WHERE r.status = ?
   ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$status]);
$rows = $stmt->fetchAll();

function keep_rq(array $drop = []): string
{
    $q = $_GET;
    foreach ($drop as $k) {
        unset($q[$k]);
    }
    $s = http_build_query($q);
    return $s ? '?' . $s : '';
}

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM reviews GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
?>

<div class="stats">
  <div class="stat"><span class="ic gold">&#9203;</span><div><b><?= e($counts['pending'] ?? 0) ?></b><span>Awaiting moderation</span></div></div>
  <div class="stat"><span class="ic green">&#10003;</span><div><b><?= e($counts['approved'] ?? 0) ?></b><span>Published</span></div></div>
  <div class="stat"><span class="ic red">&#10007;</span><div><b><?= e($counts['rejected'] ?? 0) ?></b><span>Rejected</span></div></div>
  <div class="stat"><span class="ic blue">&#9733;</span><div><b><?= e((int) $pdo->query('SELECT ROUND(AVG(rating),1) FROM reviews WHERE status="approved"')->fetchColumn() ?: 0) ?></b><span>Avg rating</span></div></div>
</div>

<div class="card">
  <div class="card-head">
    <div class="btn-row">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Published', 'rejected' => 'Rejected'] as $k => $lbl): ?>
        <a class="btn btn-sm<?= $status === $k ? ' btn-primary' : '' ?>" href="<?= e(admin_url('reviews.php?status=' . $k)) ?>">
          <?= e($lbl) ?> (<?= e($counts[$k] ?? 0) ?>)
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">Nothing here. <?= $status === 'pending' ? 'New customer reviews will appear for approval.' : '' ?></div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Reviewer</th><th>Rating</th><th>Comment</th><th>When</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:9px">
                <?php if (!empty($r['image_url'])): ?>
                  <img class="thumb" src="<?= e(base_url() . '/' . ltrim($r['image_url'], '/')) ?>" alt="">
                <?php endif; ?>
                <span class="t-title"><?= e((string) ($r['product_name'] ?? '—')) ?></span>
              </div>
            </td>
            <td><?= e($r['customer_name']) ?></td>
            <td style="color:var(--o);white-space:nowrap"><?= str_repeat('&#9733;', (int) $r['rating']) . str_repeat('&#9734;', max(0, 5 - (int) $r['rating'])) ?></td>
            <td style="max-width:340px">
              <?php if (!empty($r['title'])): ?><b><?= e($r['title']) ?></b><br><?php endif; ?>
              <?= nl2br(e((string) $r['comment'])) ?>
            </td>
            <td class="small muted"><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
            <td class="right">
              <div class="btn-row" style="justify-content:flex-end">
                <?php if ($r['status'] !== 'approved'): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="back" value="<?= e(keep_rq(['page'])) ?>">
                    <button class="btn btn-sm btn-green" type="submit">Approve</button>
                  </form>
                <?php endif; ?>
                <?php if ($r['status'] !== 'rejected'): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="back" value="<?= e(keep_rq(['page'])) ?>">
                    <button class="btn btn-sm" type="submit">Reject</button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($r['product_id'])): ?>
                  <a class="btn btn-sm" href="<?= e(admin_url('product-form.php?id=' . (int) $r['product_id'])) ?>">Product</a>
                <?php endif; ?>
                <form method="post" style="display:inline" data-confirm="Delete this review?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="back" value="<?= e(keep_rq(['page'])) ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($pager, admin_url('reviews.php' . keep_rq(['page']))) ?>
  <?php endif; ?>
</div>