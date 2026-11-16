<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/pagination.php';
$user = require_role('librarian', 'admin');
$q = clean($_GET['q'] ?? ''); $grade = clean($_GET['grade'] ?? ''); $f = $_GET['filter'] ?? '';
$where = ["bi.status = 'borrowed'"]; $p = [];
if ($q !== '') { $where[] = "(s.student_id LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ? OR b.title LIKE ? OR c.accession_number LIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($grade !== '') { $where[] = 's.grade_level = ?'; $p[] = $grade; }
if ($f === 'overdue') $where[] = 'bi.due_date < CURDATE()';
elseif ($f === 'ontime') $where[] = 'bi.due_date >= CURDATE()';
$W = implode(' AND ', $where);
$from = "FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN students s ON s.id=bt.student_id
         JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id";
$total = db_count("SELECT COUNT(*) $from WHERE $W", $p);
[$page, $pages, $off, $per] = paginate($total);
$st = db()->prepare("SELECT bt.transaction_no, bt.borrowed_at, bi.due_date, s.id AS sid, s.student_id AS sno, CONCAT(s.first_name,' ',s.last_name) AS sname, s.grade_level,
    b.title, c.accession_number $from WHERE $W ORDER BY bi.due_date ASC, bt.borrowed_at LIMIT $per OFFSET $off");
$st->execute($p); $rows = $st->fetchAll();
$grades = db()->query('SELECT DISTINCT grade_level FROM students WHERE is_archived=0 ORDER BY grade_level')->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = 'Currently Borrowed Books';
include __DIR__ . '/../includes/header.php';
?>
<div class="card p-3 mb-3 no-print"><form class="row g-2" method="get">
  <div class="col-12 col-md-4"><input class="form-control" name="q" placeholder="Search student, ID, book or accession…" value="<?= e($q) ?>"></div>
  <div class="col-6 col-md-3"><select class="form-select" name="grade"><option value="">All year levels</option><?php foreach ($grades as $g): ?><option <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><select class="form-select" name="filter"><option value="">All loans</option><option value="ontime" <?= $f === 'ontime' ? 'selected' : '' ?>>On time</option><option value="overdue" <?= $f === 'overdue' ? 'selected' : '' ?>>Overdue</option></select></div>
  <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i></button><a class="btn btn-light" href="?"><i class="bi bi-x-lg"></i></a></div>
</form></div>
<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small"><?= $total ?> book(s) currently borrowed</span>
  <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-journal-check"></i><p class="mt-2 mb-0">No books are currently borrowed.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Book</th><th>Borrower</th><th>Borrowed</th><th>Due</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): $late = calc_overdue($r['due_date'])['days']; ?><tr>
  <td data-label="Book"><strong><?= e($r['title']) ?></strong><div class="small text-muted"><?= e($r['accession_number']) ?> · <?= e($r['transaction_no']) ?></div></td>
  <td data-label="Borrower"><a class="text-decoration-none fw-semibold" href="<?= e(url('librarian/students/view.php?id=' . $r['sid'])) ?>"><?= e($r['sname']) ?></a><div class="small text-muted"><?= e($r['sno']) ?> · <?= e($r['grade_level']) ?></div></td>
  <td data-label="Borrowed"><?= e(date('M j, Y', strtotime($r['borrowed_at']))) ?></td><td data-label="Due"><?= e(date('M j, Y', strtotime($r['due_date']))) ?></td>
  <td data-label="Status"><?= status_badge('borrowed', $r['due_date']) ?><?= $late > 0 ? ' <small class="text-danger">' . $late . ' day(s) late</small>' : '' ?></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../includes/footer.php';