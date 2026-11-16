<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/qr.php';
$user = require_role('librarian', 'admin');
$pdo = db(); $isAdmin = $user['role'] === 'admin';
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, c.name AS category FROM books b LEFT JOIN book_categories c ON c.id=b.category_id WHERE b.id=? AND b.is_archived=0');
$st->execute([$id]); $book = $st->fetch();
if (!$book) { flash('danger', 'Book not found.'); redirect('librarian/books/index.php'); }
$self = 'librarian/books/view.php?id=' . $id;

if (is_post()) {
    csrf_require();
    $act = $_POST['action'] ?? ''; $copyId = (int)($_POST['copy_id'] ?? 0);
    $activeLoans = fn(int $cid) => db_count("SELECT COUNT(*) FROM borrowing_items WHERE copy_id=? AND status='borrowed'", [$cid]);
    try {
        if ($act === 'add_copies') {
            $qty = (int)($_POST['quantity'] ?? 0); $shelf = clean($_POST['shelf_location'] ?? '');
            if ($qty < 1 || $qty > 100) { flash('danger', 'Quantity must be between 1 and 100.'); }
            else { $pdo->beginTransaction(); create_copies($id, $qty, $shelf); $pdo->commit();
                   log_activity('copies_add', "$qty copy(ies) added to '{$book['title']}'", 'books', $id); flash('success', "$qty copy(ies) added."); }
        } elseif ($act === 'regenerate_qr' && $isAdmin) {
            $new = unique_code('BK', 'book_copies');
            $pdo->prepare('UPDATE book_copies SET qr_code_id=? WHERE id=? AND book_id=?')->execute([$new, $copyId, $id]);
            log_activity('qr_regenerate', "QR regenerated for copy #$copyId (old labels are now invalid)", 'book_copies', $copyId); flash('success', 'QR code regenerated. Reprint the label.');
        } elseif ($act === 'archive_copy' && $isAdmin) {
            if ($activeLoans($copyId)) flash('danger', 'Cannot archive a copy that is currently borrowed.');
            else { $pdo->prepare('UPDATE book_copies SET is_archived=1 WHERE id=? AND book_id=?')->execute([$copyId, $id]); log_activity('copy_archive', "Copy #$copyId archived", 'book_copies', $copyId); flash('success', 'Copy archived. History is preserved.'); }
        } elseif ($act === 'archive_book' && $isAdmin) {
            $busy = db_count("SELECT COUNT(*) FROM borrowing_items bi JOIN book_copies c ON c.id=bi.copy_id WHERE c.book_id=? AND bi.status='borrowed'", [$id]);
            if ($busy) flash('danger', 'Cannot archive: some copies are currently borrowed.');
            else {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE books SET is_archived=1 WHERE id=?')->execute([$id]);
                $pdo->prepare('UPDATE book_copies SET is_archived=1 WHERE book_id=?')->execute([$id]);
                $pdo->commit();
                log_activity('book_archive', "Book '{$book['title']}' archived", 'books', $id); flash('success', 'Book archived. Transaction history is preserved.'); redirect('librarian/books/index.php');
            }
        }
    } catch (PDOException $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log($ex->getMessage()); flash('danger', 'A database error occurred.'); }
    redirect($self);
}

$cs = $pdo->prepare("SELECT c.*, bi.due_date, s.id AS sid, s.student_id AS sno, CONCAT(s.first_name,' ',s.last_name) AS sname
    FROM book_copies c
    LEFT JOIN borrowing_items bi ON bi.copy_id=c.id AND bi.status='borrowed'
    LEFT JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
    LEFT JOIN students s ON s.id=bt.student_id
    WHERE c.book_id=? AND c.is_archived=0 ORDER BY c.id");
$cs->execute([$id]); $copies = $cs->fetchAll();
$hs = $pdo->prepare("SELECT bt.transaction_no, bt.borrowed_at, bi.due_date, bi.status, bi.returned_at, c.accession_number, s.student_id AS sno, CONCAT(s.first_name,' ',s.last_name) AS sname
    FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN book_copies c ON c.id=bi.copy_id JOIN students s ON s.id=bt.student_id
    WHERE c.book_id=? ORDER BY bt.borrowed_at DESC LIMIT 50");
$hs->execute([$id]); $hist = $hs->fetchAll();
$avail = count(array_filter($copies, fn($c) => $c['status'] === 'available'));

$pageTitle = 'Book Details';
$extraScripts = ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', url('assets/js/qr.js')];
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <div><h2 class="h5 mb-1"><?= e($book['title']) ?></h2><div class="text-muted"><?= e($book['author']) ?></div></div>
    <div class="d-flex gap-2 align-items-start no-print">
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('librarian/books/form.php?id=' . $id)) ?>"><i class="bi bi-pencil"></i> Edit</a>
      <?php if ($isAdmin): ?><form method="post" data-confirm="Archive this book and all its copies? History is kept."><?= csrf_field() ?><input type="hidden" name="action" value="archive_book"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-archive"></i> Archive</button></form><?php endif; ?>
    </div>
  </div>
  <hr>
  <dl class="row mb-0 small">
    <?php foreach (['ISBN' => $book['isbn'], 'Publisher' => $book['publisher'], 'Year' => $book['publication_year'], 'Edition' => $book['edition'], 'Category' => $book['category']] as $k => $v): ?>
      <dt class="col-5 col-md-2"><?= $k ?></dt><dd class="col-7 col-md-4"><?= e($v ?: '—') ?></dd><?php endforeach; ?>
    <dt class="col-5 col-md-2">Availability</dt><dd class="col-7 col-md-4"><span class="badge text-bg-<?= $avail ? 'success' : 'secondary' ?>"><?= $avail ?> of <?= count($copies) ?> available</span></dd>
    <dt class="col-5 col-md-2">Added</dt><dd class="col-7 col-md-4"><?= e(date('M j, Y', strtotime($book['created_at']))) ?></dd>
    <?php if ($book['description']): ?><dt class="col-12 col-md-2">Description</dt><dd class="col-12 col-md-10"><?= nl2br(e($book['description'])) ?></dd><?php endif; ?>
  </dl>
</div>

<div class="card p-3 mb-3">
  <form method="get" action="<?= e(url('librarian/books/labels.php')) ?>" target="_blank">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2"><h3 class="h6 mb-0">Physical copies</h3>
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-printer"></i> Print selected labels</button></div>
    <?php if (!$copies): ?><div class="empty-state"><i class="bi bi-upc-scan"></i><p class="mb-0 mt-2">No copies yet.</p></div><?php else: ?>
    <table class="table table-cards align-middle mb-0"><thead><tr><th></th><th>QR</th><th>Accession</th><th>Shelf</th><th>Status</th><th>Borrower</th><th></th></tr></thead><tbody>
    <?php foreach ($copies as $c): ?><tr>
      <td data-label="Print"><input class="form-check-input" type="checkbox" name="copy[]" value="<?= (int)$c['id'] ?>" checked></td>
      <td data-label="QR"><div id="qr<?= (int)$c['id'] ?>" data-qr="<?= e($c['qr_code_id']) ?>" data-size="72"></div></td>
      <td data-label="Accession"><strong><?= e($c['accession_number']) ?></strong><div class="small text-muted font-monospace"><?= e($c['qr_code_id']) ?></div></td>
      <td data-label="Shelf"><?= e($c['shelf_location'] ?: '—') ?></td>
      <td data-label="Status"><?= status_badge($c['status'], $c['due_date']) ?></td>
      <td data-label="Borrower"><?php if ($c['sid']): ?><a href="<?= e(url('librarian/students/view.php?id=' . $c['sid'])) ?>"><?= e($c['sname']) ?></a><div class="small text-muted">Due <?= e(date('M j, Y', strtotime($c['due_date']))) ?></div><?php else: ?>—<?php endif; ?></td>
      <td data-label="" class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-qr-download="#qr<?= (int)$c['id'] ?>" data-name="<?= e($c['accession_number']) ?>" title="Download PNG"><i class="bi bi-download"></i></button>
      </td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  </form>
  <?php if ($isAdmin && $copies): ?>
  <div class="mt-3 pt-3 border-top"><div class="small text-muted mb-2">Admin actions per copy</div><div class="d-flex flex-wrap gap-2">
    <?php foreach ($copies as $c): ?>
      <div class="btn-group btn-group-sm"><span class="btn btn-light disabled"><?= e($c['accession_number']) ?></span>
        <form method="post" data-confirm="Regenerate QR for <?= e($c['accession_number']) ?>? The old printed label will stop working."><?= csrf_field() ?><input type="hidden" name="action" value="regenerate_qr"><input type="hidden" name="copy_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-outline-warning" type="submit" title="Regenerate QR"><i class="bi bi-arrow-repeat"></i></button></form>
        <form method="post" data-confirm="Archive copy <?= e($c['accession_number']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="archive_copy"><input type="hidden" name="copy_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-outline-danger" type="submit" title="Archive copy"><i class="bi bi-archive"></i></button></form></div>
    <?php endforeach; ?></div></div><?php endif; ?>
  <form method="post" class="row g-2 mt-3 pt-3 border-top no-print"><?= csrf_field() ?><input type="hidden" name="action" value="add_copies">
    <div class="col-5 col-md-2"><input class="form-control" type="number" name="quantity" min="1" max="100" value="1" aria-label="Quantity"></div>
    <div class="col-7 col-md-4"><input class="form-control" name="shelf_location" placeholder="Shelf location" aria-label="Shelf"></div>
    <div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-plus-lg"></i> Add copies</button></div>
  </form>
</div>

<div class="card p-3">
  <h3 class="h6 mb-2">Borrowing history</h3>
  <?php if (!$hist): ?><div class="empty-state py-3"><i class="bi bi-clock-history"></i><p class="mb-0 mt-2">This book has not been borrowed yet.</p></div><?php else: ?>
  <table class="table table-cards table-sm align-middle mb-0"><thead><tr><th>Txn</th><th>Student</th><th>Copy</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($hist as $h): ?><tr><td data-label="Txn"><?= e($h['transaction_no']) ?></td><td data-label="Student"><?= e($h['sname']) ?> <span class="text-muted small">(<?= e($h['sno']) ?>)</span></td>
    <td data-label="Copy"><?= e($h['accession_number']) ?></td><td data-label="Borrowed"><?= e(date('M j, Y', strtotime($h['borrowed_at']))) ?></td><td data-label="Due"><?= e(date('M j, Y', strtotime($h['due_date']))) ?></td>
    <td data-label="Returned"><?= $h['returned_at'] ? e(date('M j, Y', strtotime($h['returned_at']))) : '—' ?></td><td data-label="Status"><?= status_badge($h['status'] === 'returned' ? 'available' : $h['status'], $h['status'] === 'borrowed' ? $h['due_date'] : null) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php';