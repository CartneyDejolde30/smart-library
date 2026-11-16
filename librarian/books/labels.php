<?php
require_once __DIR__ . '/../../includes/auth.php';
$user = require_role('librarian', 'admin');
$ids = array_values(array_filter(array_map('intval', (array)($_GET['copy'] ?? []))));
$bookId = (int)($_GET['book_id'] ?? 0);
$sql = 'SELECT c.id, c.accession_number, c.qr_code_id, c.shelf_location, b.title FROM book_copies c JOIN books b ON b.id=c.book_id WHERE c.is_archived=0 AND ';
if ($ids) { $in = implode(',', array_fill(0, count($ids), '?')); $st = db()->prepare($sql . "c.id IN ($in) ORDER BY c.id"); $st->execute($ids); }
elseif ($bookId) { $st = db()->prepare($sql . 'c.book_id = ? ORDER BY c.id'); $st->execute([$bookId]); }
else { flash('warning', 'Select at least one copy to print.'); redirect('librarian/books/index.php'); }
$labels = $st->fetchAll();
$pageTitle = 'Print QR Labels';
$extraScripts = ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', url('assets/js/qr.js')];
include __DIR__ . '/../../includes/header.php';
?>
<div class="no-print mb-3 d-flex gap-2"><button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print <?= count($labels) ?> label(s)</button>
  <button class="btn btn-light" onclick="history.back()">Back</button></div>
<?php if (!$labels): ?><div class="empty-state"><i class="bi bi-upc"></i><p class="mt-2">No matching copies.</p></div><?php else: ?>
<div class="label-grid"><?php foreach ($labels as $l): ?>
  <div class="qr-label"><div class="qr-box" data-qr="<?= e($l['qr_code_id']) ?>" data-size="120"></div>
    <div class="t"><?= e($l['title']) ?></div><div class="a"><?= e($l['accession_number']) ?><?= $l['shelf_location'] ? ' · ' . e($l['shelf_location']) : '' ?></div></div>
<?php endforeach; ?></div><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php';