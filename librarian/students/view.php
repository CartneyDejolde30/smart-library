<?php
require_once __DIR__ . '/../../includes/auth.php';
$user = require_role('librarian', 'admin');
$pdo = db(); $id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM students WHERE id=? AND is_archived=0'); $st->execute([$id]); $s = $st->fetch();
if (!$s) { flash('danger', 'Student not found.'); redirect('librarian/students/index.php'); }
$self = 'librarian/students/view.php?id=' . $id;

if (is_post()) {
    csrf_require();
    if (($_POST['action'] ?? '') === 'toggle') {
        $new = $s['account_status'] === 'active' ? 'inactive' : 'active';
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE students SET account_status=? WHERE id=?')->execute([$new, $id]);
        if ($s['user_id']) $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$new, $s['user_id']]);
        $pdo->commit();
        log_activity('student_status', "Student {$s['student_id']} set to $new", 'students', $id);
        flash('success', "Borrower account is now $new.");
    }
    if (($_POST['action'] ?? '') === 'archive' && $user['role'] === 'admin') {
        if (db_count("SELECT COUNT(*) FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id WHERE bt.student_id=? AND bi.status='borrowed'", [$id]) > 0) {
            flash('danger', 'Cannot delete: this student still has borrowed books.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE students SET is_archived=1, account_status='inactive' WHERE id=?")->execute([$id]);
            if ($s['user_id']) $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$s['user_id']]);
            $pdo->commit();
            log_activity('student_archive', "Student {$s['student_id']} archived (deleted from lists; history kept)", 'students', $id);
            flash('success', 'Student deleted. Their transaction history is preserved.');
            redirect('librarian/students/index.php');
        }
    }
    redirect($self);
}
$loans = $pdo->prepare("SELECT bt.transaction_no, bt.borrowed_at, bi.due_date, c.accession_number, b.title, DATEDIFF(CURDATE(), bi.due_date) AS late
    FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id
    WHERE bt.student_id=? AND bi.status='borrowed' ORDER BY bi.due_date");
$loans->execute([$id]); $loans = $loans->fetchAll();
$hist = $pdo->prepare("SELECT bt.transaction_no, bt.borrowed_at, bi.due_date, bi.status, bi.returned_at, b.title, c.accession_number
    FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id
    WHERE bt.student_id=? ORDER BY bt.borrowed_at DESC LIMIT 50");
$hist->execute([$id]); $hist = $hist->fetchAll();
$q = db()->prepare("SELECT COALESCE(SUM(COALESCE(adjusted_amount, amount)),0) FROM penalties WHERE student_id=? AND status='unpaid'"); $q->execute([$id]); $unpaid = (float)$q->fetchColumn();
$rate = (float)setting('penalty_per_day', '0'); $cap = (int)setting('max_overdue_days', '0');
$accrued = 0.0; foreach ($loans as $l) if ($l['late'] > 0) $accrued += min($l['late'], $cap ?: $l['late']) * $rate;
$name = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
$pageTitle = 'Student Profile';
$extraScripts = ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', url('assets/js/qr.js')];
include __DIR__ . '/../../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card p-3 p-md-4 h-100">
    <div class="d-flex gap-3 align-items-center mb-3">
      <?php if ($s['photo']): ?><img class="rounded-3" style="width:72px;height:72px;object-fit:cover" src="<?= e(url('uploads/students/' . $s['photo'])) ?>" alt=""><?php else: ?><div class="stat-icon" style="width:72px;height:72px"><i class="bi bi-person fs-1"></i></div><?php endif; ?>
      <div><h2 class="h5 mb-1"><?= e($name) ?></h2><?= status_badge($s['account_status']) ?> <span class="text-muted small ms-1"><?= e($s['student_id']) ?></span></div>
    </div>
    <dl class="row small mb-3">
      <?php foreach (['Gender' => $s['gender'], 'Year Level / Course' => $s['grade_level'] . ($s['section'] ? ' – ' . $s['section'] : ''), 'Contact' => $s['contact_number'], 'Email' => $s['email'], 'Address' => $s['address'], 'Registered' => date('M j, Y', strtotime($s['registered_at']))] as $k => $v): ?>
        <dt class="col-5 col-md-3"><?= $k ?></dt><dd class="col-7 col-md-9"><?= e($v ?: '—') ?></dd><?php endforeach; ?>
      <dt class="col-5 col-md-3">Unpaid penalties</dt><dd class="col-7 col-md-9"><strong class="<?= $unpaid > 0 ? 'text-danger' : '' ?>"><?= number_format($unpaid, 2) ?></strong>
        <?php if ($accrued > 0): ?><span class="text-muted">+ <?= number_format($accrued, 2) ?> accruing on overdue loans</span><?php endif; ?></dd>
    </dl>
    <div class="d-flex flex-wrap gap-2 no-print">
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('librarian/students/form.php?id=' . $id)) ?>"><i class="bi bi-pencil"></i> Edit</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= e(url('librarian/students/card.php?id=' . $id)) ?>"><i class="bi bi-person-vcard"></i> QR ID card</a>
      <form method="post" data-confirm="<?= $s['account_status'] === 'active' ? 'Deactivate this borrower? They will not be able to borrow or log in.' : 'Reactivate this borrower?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
        <button class="btn btn-sm btn-outline-<?= $s['account_status'] === 'active' ? 'danger' : 'success' ?>" type="submit"><i class="bi bi-power"></i> <?= $s['account_status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
      <?php if ($user['role'] === 'admin'): ?><form method="post" data-confirm="Delete this student? They will be removed from all lists and their login disabled. Borrowing history is kept."><?= csrf_field() ?><input type="hidden" name="action" value="archive">
        <button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></form><?php endif; ?>
    </div></div></div>
  <div class="col-lg-4"><div class="card p-3 text-center h-100">
    <div class="d-inline-block mx-auto p-2 bg-white" id="stuqr" data-qr="<?= e($s['qr_code_id']) ?>" data-size="160"></div>
    <div class="font-monospace small mt-2"><?= e($s['qr_code_id']) ?></div>
    <button class="btn btn-sm btn-outline-secondary mt-2 mx-auto no-print" data-qr-download="#stuqr" data-name="<?= e($s['student_id']) ?>"><i class="bi bi-download"></i> Download QR</button>
  </div></div>
</div>
<div class="card p-3 mb-3"><h3 class="h6">Currently borrowed</h3>
<?php if (!$loans): ?><div class="empty-state py-3"><i class="bi bi-book"></i><p class="mb-0 mt-2">No books currently borrowed.</p></div><?php else: ?>
<table class="table table-cards table-sm align-middle mb-0"><thead><tr><th>Book</th><th>Copy</th><th>Borrowed</th><th>Due</th><th>Status</th></tr></thead><tbody>
<?php foreach ($loans as $l): ?><tr><td data-label="Book"><?= e($l['title']) ?></td><td data-label="Copy"><?= e($l['accession_number']) ?></td><td data-label="Borrowed"><?= e(date('M j, Y', strtotime($l['borrowed_at']))) ?></td><td data-label="Due"><?= e(date('M j, Y', strtotime($l['due_date']))) ?></td>
  <td data-label="Status"><?= status_badge('borrowed', $l['due_date']) ?><?= $l['late'] > 0 ? ' <small class="text-danger">' . (int)$l['late'] . ' day(s) late</small>' : '' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<div class="card p-3"><h3 class="h6">Borrowing history</h3>
<?php if (!$hist): ?><div class="empty-state py-3"><i class="bi bi-clock-history"></i><p class="mb-0 mt-2">No borrowing history yet.</p></div><?php else: ?>
<table class="table table-cards table-sm align-middle mb-0"><thead><tr><th>Txn</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead><tbody>
<?php foreach ($hist as $h): ?><tr><td data-label="Txn"><?= e($h['transaction_no']) ?></td><td data-label="Book"><?= e($h['title']) ?></td><td data-label="Borrowed"><?= e(date('M j, Y', strtotime($h['borrowed_at']))) ?></td><td data-label="Due"><?= e(date('M j, Y', strtotime($h['due_date']))) ?></td>
  <td data-label="Returned"><?= $h['returned_at'] ? e(date('M j, Y', strtotime($h['returned_at']))) : '—' ?></td><td data-label="Status"><?= status_badge($h['status'] === 'returned' ? 'available' : $h['status'], $h['status'] === 'borrowed' ? $h['due_date'] : null) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php include __DIR__ . '/../../includes/footer.php';