<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
$user = require_role('librarian', 'admin');
$pdo = db(); $isAdmin = $user['role'] === 'admin';

if (is_post()) {
    csrf_require();
    $id = (int)($_POST['id'] ?? 0); $act = $_POST['action'] ?? '';
    $st = $pdo->prepare("SELECT * FROM penalties WHERE id = ? AND status = 'unpaid'"); $st->execute([$id]); $pen = $st->fetch();
    if (!$pen) flash('danger', 'Penalty not found or already settled.');
    elseif ($act === 'pay') {
        $pdo->prepare("UPDATE penalties SET status='paid', paid_at=NOW() WHERE id=?")->execute([$id]);
        log_activity('penalty_paid', "Penalty #$id marked paid", 'penalties', $id); flash('success', 'Penalty marked as paid.');
    } elseif ($act === 'waive' && $isAdmin) {
        $reason = clean($_POST['reason'] ?? '');
        if (mb_strlen($reason) < 3) flash('danger', 'A reason is required to waive a penalty.');
        else {
            $pdo->prepare("UPDATE penalties SET status='waived', adjustment_reason=?, adjusted_by=? WHERE id=?")->execute([$reason, $user['id'], $id]);
            log_activity('penalty_waived', "Penalty #$id waived. Reason: $reason", 'penalties', $id); flash('success', 'Penalty waived.');
        }
    }
    redirect('librarian/penalties.php?' . http_build_query(array_intersect_key($_GET, ['status' => 1, 'q' => 1])));
}
$status = in_array($_GET['status'] ?? '', ['unpaid', 'paid', 'waived'], true) ? $_GET['status'] : 'unpaid';
$q = clean($_GET['q'] ?? '');
$W = 'p.status = ?'; $p = [$status];
if ($q !== '') { $W .= " AND (s.student_id LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)"; array_push($p, "%$q%", "%$q%"); }
$total = db_count("SELECT COUNT(*) FROM penalties p JOIN students s ON s.id=p.student_id WHERE $W", $p);
[$page, $pages, $off, $per] = paginate($total);
$st = $pdo->prepare("SELECT p.*, s.id AS sid, s.student_id AS sno, CONCAT(s.first_name,' ',s.last_name) AS sname, b.title
    FROM penalties p JOIN students s ON s.id=p.student_id LEFT JOIN borrowing_items bi ON bi.id=p.item_id LEFT JOIN book_copies c ON c.id=bi.copy_id LEFT JOIN books b ON b.id=c.book_id
    WHERE $W ORDER BY p.id DESC LIMIT $per OFFSET $off");
$st->execute($p); $rows = $st->fetchAll();
$sum = $pdo->query("SELECT COALESCE(SUM(COALESCE(adjusted_amount, amount)),0) FROM penalties WHERE status='unpaid'")->fetchColumn();
$pageTitle = 'Penalties';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <ul class="nav nav-pills"><?php foreach (['unpaid', 'paid', 'waived'] as $s): ?><li class="nav-item"><a class="nav-link <?= $status === $s ? 'active' : '' ?>" href="?status=<?= $s ?>"><?= ucfirst($s) ?></a></li><?php endforeach; ?></ul>
  <span class="text-muted">Total unpaid: <strong class="text-danger"><?= number_format((float)$sum, 2) ?></strong></span></div>
<div class="card p-3 mb-3"><form class="row g-2" method="get"><input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="col-9 col-md-10"><input class="form-control" name="q" placeholder="Search student name or ID…" value="<?= e($q) ?>"></div>
  <div class="col-3 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i></button></div></form></div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-cash-coin"></i><p class="mt-2 mb-0">No <?= e($status) ?> penalties.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Student</th><th>Type</th><th>Book</th><th>Amount</th><th>Date</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $eff = $r['adjusted_amount'] ?? $r['amount']; ?><tr>
  <td data-label="Student"><a class="text-decoration-none fw-semibold" href="<?= e(url('librarian/students/view.php?id=' . $r['sid'])) ?>"><?= e($r['sname']) ?></a><div class="small text-muted"><?= e($r['sno']) ?></div></td>
  <td data-label="Type"><?= e(ucfirst($r['type'])) ?></td><td data-label="Book"><?= e($r['title'] ?? '—') ?></td>
  <td data-label="Amount"><strong><?= number_format((float)$eff, 2) ?></strong><?= $r['adjusted_amount'] !== null ? '<div class="small text-muted">was ' . number_format((float)$r['amount'], 2) . '</div>' : '' ?></td>
  <td data-label="Date"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
  <td data-label="" class="text-end text-nowrap"><?php if ($r['status'] === 'unpaid'): ?>
    <form method="post" class="d-inline" data-confirm="Mark this penalty as paid (<?= number_format((float)$eff, 2) ?>)?"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2"></i> Paid</button></form>
    <?php if ($isAdmin): ?><form method="post" class="d-inline" onsubmit="var r=prompt('Reason for waiving this penalty:');if(!r){return false;}this.reason.value=r;return true;"><?= csrf_field() ?><input type="hidden" name="action" value="waive"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="reason" value=""><button class="btn btn-sm btn-outline-secondary" type="submit">Waive</button></form><?php endif; ?>
  <?php else: ?><?= e(ucfirst($r['status'])) ?><?= $r['paid_at'] ? ' · ' . e(date('M j', strtotime($r['paid_at']))) : '' ?><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../includes/footer.php';