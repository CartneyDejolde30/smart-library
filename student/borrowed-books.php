<?php
require_once __DIR__ . '/../includes/student_portal.php';
$st = db()->prepare("SELECT bt.transaction_no, bt.borrowed_at, bi.due_date, b.title, b.author, c.accession_number FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
    JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id WHERE bt.student_id=? AND bi.status='borrowed' ORDER BY bi.due_date");
$st->execute([$me['id']]); $loans = $st->fetchAll();
$pn = db()->prepare("SELECT p.*, b.title FROM penalties p LEFT JOIN borrowing_items bi ON bi.id=p.item_id LEFT JOIN book_copies c ON c.id=bi.copy_id LEFT JOIN books b ON b.id=c.book_id WHERE p.student_id=? ORDER BY p.id DESC LIMIT 20");
$pn->execute([$me['id']]); $pens = $pn->fetchAll();
$accruing = 0.0; foreach ($loans as $l) $accruing += calc_overdue($l['due_date'])['penalty'];
$unpaid = 0.0; foreach ($pens as $p) if ($p['status'] === 'unpaid') $unpaid += (float)($p['adjusted_amount'] ?? $p['amount']);
$pageTitle = 'Borrowed Books';
include __DIR__ . '/../includes/header.php';
?>
<div class="card p-3 mb-3"><h3 class="h6">Currently borrowed</h3>
<?php if (!$loans): ?><div class="empty-state py-3"><i class="bi bi-book"></i><p class="mb-0 mt-2">You have no borrowed books.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Book</th><th>Borrowed</th><th>Due date</th><th>Status</th><th>Penalty so far</th></tr></thead><tbody>
<?php foreach ($loans as $l): $c = calc_overdue($l['due_date']); ?><tr><td data-label="Book"><strong><?= e($l['title']) ?></strong><div class="small text-muted"><?= e($l['author']) ?> · <?= e($l['accession_number']) ?></div></td>
  <td data-label="Borrowed"><?= e(date('M j, Y', strtotime($l['borrowed_at']))) ?></td><td data-label="Due date"><?= e(date('M j, Y', strtotime($l['due_date']))) ?></td>
  <td data-label="Status"><?= status_badge('borrowed', $l['due_date']) ?><?= $c['days'] > 0 ? ' <small class="text-danger">' . (int)$c['days'] . ' day(s) late</small>' : '' ?></td>
  <td data-label="Penalty so far"><?= $c['penalty'] > 0 ? '<strong class="text-danger">' . number_format($c['penalty'], 2) . '</strong>' : '—' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<div class="card p-3"><div class="d-flex justify-content-between"><h3 class="h6">Penalties</h3><span class="small">Unpaid: <strong class="<?= $unpaid > 0 ? 'text-danger' : '' ?>"><?= number_format($unpaid, 2) ?></strong><?= $accruing > 0 ? ' · accruing ' . number_format($accruing, 2) : '' ?></span></div>
<?php if (!$pens): ?><div class="text-muted small">No penalties.</div><?php else: ?>
<table class="table table-cards table-sm align-middle mb-0"><thead><tr><th>Type</th><th>Book</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach ($pens as $p): ?><tr><td data-label="Type"><?= e(ucfirst($p['type'])) ?></td><td data-label="Book"><?= e($p['title'] ?? '—') ?></td><td data-label="Amount"><?= number_format((float)($p['adjusted_amount'] ?? $p['amount']), 2) ?></td>
  <td data-label="Status"><span class="badge text-bg-<?= $p['status'] === 'unpaid' ? 'danger' : ($p['status'] === 'paid' ? 'success' : 'secondary') ?>"><?= e(ucfirst($p['status'])) ?></span></td><td data-label="Date"><?= e(date('M j, Y', strtotime($p['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php include __DIR__ . '/../includes/footer.php';