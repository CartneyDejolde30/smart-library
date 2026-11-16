<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pagination.php';
$user = require_role('admin');   // audit records are read-only and visible to administrators only
$q = clean($_GET['q'] ?? ''); $action = clean($_GET['action'] ?? ''); $uid = (int)($_GET['user'] ?? 0);
$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
$where = ['1=1']; $p = [];
if ($q !== '') { $where[] = '(l.description LIKE ? OR l.related_table LIKE ?)'; array_push($p, "%$q%", "%$q%"); }
if ($action !== '') { $where[] = 'l.action = ?'; $p[] = $action; }
if ($uid) { $where[] = 'l.user_id = ?'; $p[] = $uid; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(l.created_at) >= ?'; $p[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'DATE(l.created_at) <= ?'; $p[] = $to; }
$W = implode(' AND ', $where);
$total = db_count("SELECT COUNT(*) FROM activity_logs l WHERE $W", $p);
[$page, $pages, $off, $per] = paginate($total, 25);
$st = db()->prepare("SELECT l.*, u.username, u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE $W ORDER BY l.id DESC LIMIT $per OFFSET $off");
$st->execute($p); $rows = $st->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$users = db()->query('SELECT id, full_name FROM users WHERE role_id IN (1,2) ORDER BY full_name')->fetchAll();
$pageTitle = 'Activity Logs';
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 mb-3"><form class="row g-2" method="get">
  <div class="col-12 col-md-3"><input class="form-control" name="q" placeholder="Search description…" value="<?= e($q) ?>"></div>
  <div class="col-6 col-md-2"><select class="form-select" name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><select class="form-select" name="user"><option value="">All users</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $uid === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div><div class="col-6 col-md-2"><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-12 col-md-1 d-flex gap-1"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i></button></div></form></div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-journal-text"></i><p class="mt-2 mb-0">No log entries match.</p></div><?php else: ?>
<table class="table table-cards table-sm align-middle mb-0"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>Related</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-label="When" class="text-nowrap"><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
  <td data-label="User"><?= $r['username'] ? e($r['full_name']) . ' <span class="text-muted small">(' . e($r['username']) . ')</span>' : '<span class="text-muted">System / unknown</span>' ?></td>
  <td data-label="Action"><span class="badge text-bg-light border"><?= e($r['action']) ?></span></td><td data-label="Description"><?= e($r['description']) ?></td>
  <td data-label="Related" class="small text-muted"><?= $r['related_table'] ? e($r['related_table'] . ' #' . $r['related_id']) : '—' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../../includes/footer.php';