<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/borrowing.php';
require_once __DIR__ . '/includes/pagination.php';
$user = require_login();
[$w, $p] = notif_scope($user);
if (is_post()) {
    csrf_require();
    if (($_POST['action'] ?? '') === 'read_all') {
        db()->prepare("UPDATE notifications n SET n.is_read = 1 WHERE $w AND n.is_read = 0")->execute($p);
        flash('success', 'All notifications marked as read.');
    }
    redirect('notifications.php');
}
$total = db_count("SELECT COUNT(*) FROM notifications n WHERE $w", $p);
[$page, $pages, $off, $per] = paginate($total, 20);
$st = db()->prepare("SELECT n.* FROM notifications n WHERE $w ORDER BY n.id DESC LIMIT $per OFFSET $off"); $st->execute($p); $rows = $st->fetchAll();
$unread = unread_notifications($user);
$icons = ['borrow' => 'bi-box-arrow-up-right', 'return' => 'bi-box-arrow-in-down-left', 'overdue' => 'bi-exclamation-triangle', 'lost' => 'bi-x-octagon', 'damaged' => 'bi-exclamation-diamond', 'penalty' => 'bi-cash-coin', 'penalty_due' => 'bi-cash-coin'];
$pageTitle = 'Notifications';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><span class="text-muted"><?= $unread ?> unread</span>
  <?php if ($unread): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check2-all"></i> Mark all as read</button></form><?php endif; ?></div>
<div class="card p-2">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-bell-slash"></i><p class="mt-2 mb-0">You have no notifications.</p></div><?php endif; ?>
<?php foreach ($rows as $n): $href = $n['link'] && is_file(BASE_PATH . '/' . ltrim($n['link'], '/')) ? url($n['link']) : null; ?>
  <div class="d-flex gap-3 p-2 border-bottom <?= $n['is_read'] ? '' : 'bg-primary-subtle rounded' ?>">
    <div class="stat-icon flex-shrink-0" style="width:40px;height:40px"><i class="bi <?= e($icons[$n['type']] ?? 'bi-bell') ?>"></i></div>
    <div class="flex-grow-1"><div class="fw-semibold small"><?= $href ? '<a class="text-decoration-none" href="' . e($href) . '">' . e($n['title']) . '</a>' : e($n['title']) ?></div>
      <div class="small"><?= e($n['message']) ?></div><div class="small text-muted"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div></div></div>
<?php endforeach; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/includes/footer.php';