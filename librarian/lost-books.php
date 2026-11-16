<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/pagination.php';
$user = require_role('librarian', 'admin');
$tab = ($_GET['tab'] ?? 'lost') === 'damaged' ? 'damaged' : 'lost';

if (is_post()) {
    csrf_require();
    $act = $_POST['action'] ?? '';
    if ($act === 'report') {
        $copy = find_copy(clean($_POST['code'] ?? ''));
        if (!$copy) flash('danger', 'No book copy found for that QR code / accession number.');
        else {
            $type = ($_POST['type'] ?? '') === 'damaged' ? 'damaged' : 'lost';
            $r = report_incident($type, (int)$copy['id'], trim((string)($_POST['description'] ?? '')), (float)($_POST['fee'] ?? 0), (int)$user['id']);
            flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? $r['message'] : $r['error']);
            if ($r['ok']) redirect('librarian/lost-books.php?tab=' . $type);
        }
    } elseif ($act === 'resolve') {
        $type = ($_POST['type'] ?? '') === 'damaged' ? 'damaged' : 'lost';
        $r = resolve_incident($type, (int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''), (int)$user['id']);
        flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Record updated.' : $r['error']);
        redirect('librarian/lost-books.php?tab=' . $type);
    }
    redirect('librarian/lost-books.php?tab=' . $tab);
}
$table = $tab === 'lost' ? 'lost_books' : 'damaged_books';
$total = db_count("SELECT COUNT(*) FROM $table");
[$page, $pages, $off, $per] = paginate($total);
$st = db()->prepare("SELECT x.*, c.accession_number, b.title, CONCAT(s.first_name,' ',s.last_name) AS sname, s.student_id AS sno, u.full_name AS reporter
    FROM $table x JOIN book_copies c ON c.id=x.copy_id JOIN books b ON b.id=c.book_id LEFT JOIN students s ON s.id=x.student_id JOIN users u ON u.id=x.reported_by
    ORDER BY x.id DESC LIMIT $per OFFSET $off");
$st->execute(); $rows = $st->fetchAll();
$opts = $tab === 'lost' ? ['open', 'paid', 'replaced', 'written_off', 'found'] : ['open', 'repaired', 'paid', 'written_off'];
$pageTitle = 'Lost & Damaged Books';
include __DIR__ . '/../includes/header.php';
?>
<div class="card p-3 mb-3"><h2 class="h6 mb-3">Report a book</h2>
  <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="report">
    <div class="col-12 col-md-4"><input class="form-control" name="code" required placeholder="Book QR code or accession no." value="<?= old('code') ?>"></div>
    <div class="col-6 col-md-2"><select class="form-select" name="type"><option value="lost" <?= $tab === 'lost' ? 'selected' : '' ?>>Lost</option><option value="damaged" <?= $tab === 'damaged' ? 'selected' : '' ?>>Damaged</option></select></div>
    <div class="col-6 col-md-2"><input class="form-control" type="number" step="0.01" min="0" name="fee" placeholder="Replacement fee" value="<?= old('fee') ?>"></div>
    <div class="col-12 col-md-4"><input class="form-control" name="description" placeholder="Incident description" value="<?= old('description') ?>"></div>
    <div class="col-12"><button class="btn btn-danger" type="submit"><i class="bi bi-flag"></i> Record</button>
      <span class="small text-muted ms-2">If the copy is currently borrowed, the borrower is recorded automatically. Damaged copies that are out must go through Return Book.</span></div>
  </form></div>
<ul class="nav nav-pills mb-3"><li class="nav-item"><a class="nav-link <?= $tab === 'lost' ? 'active' : '' ?>" href="?tab=lost">Lost</a></li><li class="nav-item"><a class="nav-link <?= $tab === 'damaged' ? 'active' : '' ?>" href="?tab=damaged">Damaged</a></li></ul>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-x-octagon"></i><p class="mt-2 mb-0">No <?= $tab ?> book records.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Book</th><th>Borrower</th><th>Reported</th><th>Fee</th><th>Details</th><th>Resolution</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td data-label="Book"><strong><?= e($r['title']) ?></strong><div class="small text-muted"><?= e($r['accession_number']) ?></div></td>
  <td data-label="Borrower"><?= $r['sname'] ? e($r['sname']) . '<div class="small text-muted">' . e($r['sno']) . '</div>' : '—' ?></td>
  <td data-label="Reported"><?= e(date('M j, Y', strtotime($r['date_reported']))) ?><div class="small text-muted">by <?= e($r['reporter']) ?></div></td>
  <td data-label="Fee"><?= number_format((float)$r['replacement_fee'], 2) ?></td><td data-label="Details"><?= e($r['description'] ?? '—') ?></td>
  <td data-label="Resolution"><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="resolve"><input type="hidden" name="type" value="<?= $tab ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <select name="status" class="form-select form-select-sm"><?php foreach ($opts as $o): ?><option value="<?= $o ?>" <?= $r['resolution_status'] === $o ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $o))) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-outline-primary" type="submit">Save</button></form></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../includes/footer.php';