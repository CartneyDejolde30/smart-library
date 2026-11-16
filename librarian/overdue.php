<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/pagination.php';
$user = require_role('librarian', 'admin');
$view = ($_GET['view'] ?? 'current') === 'history' ? 'history' : 'current';
$q = clean($_GET['q'] ?? ''); $grade = clean($_GET['grade'] ?? '');
$where = []; $p = [];
if ($q !== '') { $where[] = "(s.student_id LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ? OR b.title LIKE ? OR c.accession_number LIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($grade !== '') { $where[] = 's.grade_level = ?'; $p[] = $grade; }
$extra = $where ? ' AND ' . implode(' AND ', $where) : '';
$from = "FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN students s ON s.id=bt.student_id
         JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id";
if ($view === 'current') {
    $base = "$from WHERE bi.status='borrowed' AND bi.due_date < CURDATE() $extra";
    $order = 'bi.due_date ASC';
    $cols = "bi.id, bt.borrowed_at, bi.due_date, NULL AS returned_at, DATEDIFF(CURDATE(), bi.due_date) AS late";
} else {
    $base = "$from JOIN returns r ON r.item_id=bi.id WHERE r.days_overdue > 0 $extra";
    $order = 'r.returned_at DESC';
    $cols = "bi.id, bt.borrowed_at, bi.due_date, r.returned_at, r.days_overdue AS late";
}
$total = db_count("SELECT COUNT(*) $base", $p);
[$page, $pages, $off, $per] = paginate($total);
$st = db()->prepare("SELECT $cols, s.id AS sid, s.student_id AS sno, CONCAT(s.first_name,' ',s.last_name) AS sname, s.grade_level, s.contact_number,
                     b.title, c.accession_number $base ORDER BY $order LIMIT $per OFFSET $off");
$st->execute($p); $rows = $st->fetchAll();
$grades = db()->query('SELECT DISTINCT grade_level FROM students WHERE is_archived=0 ORDER BY grade_level')->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = $view === 'current' ? 'Overdue Books' : 'Overdue History';
include __DIR__ . '/../includes/header.php';
?>
<ul class="nav nav-pills mb-3 no-print">
  <li class="nav-item"><a class="nav-link <?= $view === 'current' ? 'active' : '' ?>" href="?view=current">Currently overdue</a></li>
  <li class="nav-item"><a class="nav-link <?= $view === 'history' ? 'active' : '' ?>" href="?view=history">History (returned late)</a></li>
</ul>
<div class="card p-3 mb-3 no-print"><form class="row g-2" method="get"><input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="col-12 col-md-6"><input class="form-control" name="q" placeholder="Search student, ID, book or accession…" value="<?= e($q) ?>"></div>
  <div class="col-6 col-md-3"><select class="form-select" name="grade"><option value="">All year levels</option><?php foreach ($grades as $g): ?><option <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i></button><a class="btn btn-light" href="?view=<?= e($view) ?>"><i class="bi bi-x-lg"></i></a></div>
</form></div>
<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small"><?= $total ?> record(s) · penalty <?= e(setting('penalty_per_day')) ?>/day<?= (int)setting('max_overdue_days') > 0 ? ', capped at ' . (int)setting('max_overdue_days') . ' days' : '' ?> · printed <?= e(date('M j, Y g:i A')) ?> by <?= e($user['full_name']) ?></span>
  <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-emoji-smile"></i><p class="mt-2 mb-0"><?= $view === 'current' ? 'No overdue books. 🎉' : 'No late returns recorded.' ?></p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Borrower</th><th>Book</th><th>Borrowed</th><th>Due</th><?= $view === 'history' ? '<th>Returned</th>' : '' ?><th>Days late</th><th>Penalty</th></tr></thead><tbody>
<?php foreach ($rows as $r): $pen = calc_overdue($r['due_date'], $r['returned_at'] ? date('Y-m-d', strtotime($r['returned_at'])) : null)['penalty']; ?><tr>
  <td data-label="Borrower"><a class="fw-semibold text-decoration-none" href="<?= e(url('librarian/students/view.php?id=' . $r['sid'])) ?>"><?= e($r['sname']) ?></a><div class="small text-muted"><?= e($r['sno']) ?> · <?= e($r['grade_level']) ?><?= $r['contact_number'] ? ' · ' . e($r['contact_number']) : '' ?></div></td>
  <td data-label="Book"><?= e($r['title']) ?><div class="small text-muted"><?= e($r['accession_number']) ?></div></td>
  <td data-label="Borrowed"><?= e(date('M j, Y', strtotime($r['borrowed_at']))) ?></td><td data-label="Due"><?= e(date('M j, Y', strtotime($r['due_date']))) ?></td>
  <?php if ($view === 'history'): ?><td data-label="Returned"><?= e(date('M j, Y', strtotime($r['returned_at']))) ?></td><?php endif; ?>
  <td data-label="Days late"><span class="badge text-bg-danger"><?= (int)$r['late'] ?></span></td><td data-label="Penalty"><strong><?= number_format($pen, 2) ?></strong></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../includes/footer.php';