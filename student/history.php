<?php
require_once __DIR__ . '/../includes/student_portal.php';
require_once __DIR__ . '/../includes/pagination.php';
$total = db_count('SELECT COUNT(*) FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id WHERE bt.student_id=?', [$me['id']]);
[$page, $pages, $off, $per] = paginate($total, 20);
$st = db()->prepare("SELECT bt.transaction_no, bt.borrowed_at, bi.due_date, bi.status, bi.returned_at, b.title FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
    JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id WHERE bt.student_id=? ORDER BY bt.borrowed_at DESC, bi.id DESC LIMIT $per OFFSET $off");
$st->execute([$me['id']]); $rows = $st->fetchAll();
$pageTitle = 'Borrowing History';
include __DIR__ . '/../includes/header.php';
?>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-clock-history"></i><p class="mt-2 mb-0">No borrowing history yet.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Transaction</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-label="Transaction"><?= e($r['transaction_no']) ?></td><td data-label="Book"><?= e($r['title']) ?></td><td data-label="Borrowed"><?= e(date('M j, Y', strtotime($r['borrowed_at']))) ?></td>
  <td data-label="Due"><?= e(date('M j, Y', strtotime($r['due_date']))) ?></td><td data-label="Returned"><?= $r['returned_at'] ? e(date('M j, Y', strtotime($r['returned_at']))) : '—' ?></td>
  <td data-label="Status"><?= $r['status'] === 'returned' ? '<span class="badge text-bg-success">Returned</span>' : status_badge($r['status'], $r['status'] === 'borrowed' ? $r['due_date'] : null) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../includes/footer.php';