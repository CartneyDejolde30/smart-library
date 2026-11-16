<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pagination.php';
$user = require_role('librarian', 'admin');
$q = clean($_GET['q'] ?? ''); $grade = clean($_GET['grade'] ?? ''); $section = clean($_GET['section'] ?? ''); $stat = $_GET['status'] ?? '';
$where = ['s.is_archived = 0']; $p = [];
if ($q !== '') { $where[] = "(s.student_id LIKE ? OR s.qr_code_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)"; array_push($p, ...array_fill(0, 5, "%$q%")); }
if ($grade !== '') { $where[] = 's.grade_level = ?'; $p[] = $grade; }
if ($section !== '') { $where[] = 's.section = ?'; $p[] = $section; }
if (in_array($stat, ['active', 'inactive'], true)) { $where[] = 's.account_status = ?'; $p[] = $stat; }
$W = implode(' AND ', $where);
$total = db_count("SELECT COUNT(*) FROM students s WHERE $W", $p);
[$page, $pages, $off, $per] = paginate($total);
$st = db()->prepare("SELECT s.*, (SELECT COUNT(*) FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id WHERE bt.student_id=s.id AND bi.status='borrowed') AS loans
                     FROM students s WHERE $W ORDER BY s.last_name, s.first_name LIMIT $per OFFSET $off");
$st->execute($p); $rows = $st->fetchAll();
$grades = db()->query('SELECT DISTINCT grade_level FROM students WHERE is_archived=0 ORDER BY grade_level')->fetchAll(PDO::FETCH_COLUMN);
$sections = db()->query("SELECT DISTINCT section FROM students WHERE is_archived=0 AND section IS NOT NULL AND section<>'' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = 'Students';
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 mb-3"><form class="row g-2" method="get">
  <div class="col-12 col-md-4"><input class="form-control" name="q" placeholder="Search name, student ID or QR ID…" value="<?= e($q) ?>"></div>
  <div class="col-4 col-md-2"><select class="form-select" name="grade"><option value="">Year Level</option><?php foreach ($grades as $g): ?><option <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
  <div class="col-4 col-md-2"><select class="form-select" name="section"><option value="">Course</option><?php foreach ($sections as $s): ?><option <?= $section === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <div class="col-4 col-md-2"><select class="form-select" name="status"><option value="">Status</option><option value="active" <?= $stat === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $stat === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
  <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i></button><a class="btn btn-light" href="?"><i class="bi bi-x-lg"></i></a></div>
</form></div>
<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small"><?= $total ?> student(s)</span>
  <div class="d-flex gap-2"><form id="bulkForm" method="get" action="<?= e(url('librarian/students/card.php')) ?>" target="_blank"><button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-person-vcard"></i> Print selected ID cards</button></form>
  <a class="btn btn-primary btn-sm" href="<?= e(url('librarian/students/form.php')) ?>"><i class="bi bi-person-plus"></i> Register student</a></div></div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-people"></i><p class="mt-2 mb-0">No students found.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th><input type="checkbox" id="selAll" class="form-check-input" aria-label="Select all"></th><th>Student</th><th>ID</th><th>Year Level / Course</th><th>Loans</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $s): ?><tr>
  <td data-label="Select"><input type="checkbox" class="form-check-input pick" name="ids[]" value="<?= (int)$s['id'] ?>" form="bulkForm"></td>
  <td data-label="Student"><a class="fw-semibold text-decoration-none" href="<?= e(url('librarian/students/view.php?id=' . $s['id'])) ?>"><?= e($s['last_name'] . ', ' . $s['first_name']) ?></a></td>
  <td data-label="ID"><?= e($s['student_id']) ?></td><td data-label="Year Level / Course"><?= e($s['grade_level'] . ($s['section'] ? ' – ' . $s['section'] : '')) ?></td>
  <td data-label="Loans"><?= (int)$s['loans'] ?></td><td data-label="Status"><?= status_badge($s['account_status']) ?></td>
  <td data-label="" class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('librarian/students/view.php?id=' . $s['id'])) ?>"><i class="bi bi-eye"></i></a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('librarian/students/card.php?id=' . $s['id'])) ?>" title="ID card"><i class="bi bi-person-vcard"></i></a></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var all = document.getElementById('selAll'); if (!all) return;
  all.addEventListener('change', function () { document.querySelectorAll('.pick').forEach(function (c) { c.checked = all.checked; }); });
  document.getElementById('bulkForm').addEventListener('submit', function (e) {
    if (!document.querySelector('.pick:checked')) { e.preventDefault(); alert('Select at least one student first.'); }
  });
});
</script>
<?php include __DIR__ . '/../../includes/footer.php';