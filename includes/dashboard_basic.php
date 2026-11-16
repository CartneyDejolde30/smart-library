<?php
// Full dashboard. All figures come from MySQL. Included by admin/dashboard.php and librarian/dashboard.php.
$pdo = db();
$c = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(status='available'),0) av, COALESCE(SUM(status='borrowed'),0) bo, COALESCE(SUM(status='lost'),0) lo, COALESCE(SUM(status='damaged'),0) da FROM book_copies WHERE is_archived=0")->fetch();
$overdue = db_count("SELECT COUNT(*) FROM borrowing_items WHERE status='borrowed' AND due_date < CURDATE()");
$cards = [
  ['Total Books', $c['total'], 'bi-book', 'primary'], ['Available Books', $c['av'], 'bi-check-circle', 'success'],
  ['Borrowed Books', $c['bo'], 'bi-arrow-left-right', 'primary'], ['Overdue Books', $overdue, 'bi-exclamation-triangle', 'danger'],
  ['Lost Books', $c['lo'], 'bi-x-octagon', 'dark'], ['Registered Students', db_count('SELECT COUNT(*) FROM students WHERE is_archived=0'), 'bi-people', 'primary'],
  ['Library Personnel', db_count("SELECT COUNT(*) FROM users WHERE role_id IN (1,2) AND status='active'"), 'bi-person-badge', 'primary'],
  ['Total Transactions', db_count('SELECT COUNT(*) FROM borrowing_transactions'), 'bi-receipt', 'primary'],
];
$recentBorrow = $pdo->query("SELECT bt.transaction_no, bt.borrowed_at, CONCAT(s.first_name,' ',s.last_name) sname, (SELECT COUNT(*) FROM borrowing_items WHERE transaction_id=bt.id) n
    FROM borrowing_transactions bt JOIN students s ON s.id=bt.student_id ORDER BY bt.id DESC LIMIT 5")->fetchAll();
$recentReturn = $pdo->query("SELECT r.returned_at, b.title, CONCAT(s.first_name,' ',s.last_name) sname, r.days_overdue FROM returns r JOIN borrowing_items bi ON bi.id=r.item_id
    JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN students s ON s.id=bt.student_id JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id ORDER BY r.id DESC LIMIT 5")->fetchAll();
$overdueList = $pdo->query("SELECT b.title, CONCAT(s.first_name,' ',s.last_name) sname, DATEDIFF(CURDATE(), bi.due_date) late FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
    JOIN students s ON s.id=bt.student_id JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id WHERE bi.status='borrowed' AND bi.due_date < CURDATE() ORDER BY bi.due_date LIMIT 5")->fetchAll();
$newStudents = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) n, student_id, registered_at FROM students WHERE is_archived=0 ORDER BY id DESC LIMIT 5")->fetchAll();
$newBooks = $pdo->query("SELECT id, title, author, created_at FROM books WHERE is_archived=0 ORDER BY id DESC LIMIT 5")->fetchAll();
$top = $pdo->query("SELECT b.title, COUNT(*) n FROM borrowing_items bi JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id GROUP BY b.id, b.title ORDER BY n DESC, b.title LIMIT 5")->fetchAll();
$mrows = $pdo->query("SELECT DATE_FORMAT(bt.borrowed_at,'%Y-%m') ym, COUNT(*) n FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
    WHERE bt.borrowed_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY ym")->fetchAll(PDO::FETCH_KEY_PAIR);
$months = []; for ($i = 5; $i >= 0; $i--) { $k = date('Y-m', strtotime("first day of -$i month")); $months[date('M', strtotime($k . '-01'))] = (int)($mrows[$k] ?? 0); }
$maxM = max(1, max($months));
$tot = max(1, (int)$c['total']);
$actions = [['Add New Book', 'bi-plus-circle', 'librarian/books/form.php'], ['Register Student', 'bi-person-plus', 'librarian/students/form.php'], ['Scan QR Code', 'bi-qr-code-scan', 'librarian/scanner.php'],
            ['Borrow Book', 'bi-box-arrow-up-right', 'librarian/borrow.php'], ['Return Book', 'bi-box-arrow-in-down-left', 'librarian/return.php'], ['Generate Report', 'bi-bar-chart-line', ($user['role'] === 'admin' ? 'admin' : 'librarian') . '/reports/index.php']];
$empty = fn(string $t) => '<div class="text-muted small py-2">' . e($t) . '</div>';
?>
<p class="text-muted">Welcome back, <?= e($user['full_name']) ?>.</p>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($actions as [$l, $i, $path]): if (!is_file(BASE_PATH . '/' . $path)) continue; ?>
    <a class="btn btn-outline-primary" href="<?= e(url($path)) ?>"><i class="bi <?= e($i) ?>"></i> <?= e($l) ?></a><?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <?php foreach ($cards as [$label, $val, $icon, $tone]): ?>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon"><i class="bi <?= e($icon) ?>"></i></div>
      <div><div class="stat-value"><?= (int)$val ?></div><div class="text-muted small"><?= e($label) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6 mb-3">Book availability</h3>
    <div class="progress mb-3" style="height:14px">
      <div class="progress-bar bg-success" style="width:<?= round($c['av'] / $tot * 100, 1) ?>%"></div><div class="progress-bar bg-primary" style="width:<?= round($c['bo'] / $tot * 100, 1) ?>%"></div>
      <div class="progress-bar bg-dark" style="width:<?= round($c['lo'] / $tot * 100, 1) ?>%"></div><div class="progress-bar bg-warning" style="width:<?= round($c['da'] / $tot * 100, 1) ?>%"></div></div>
    <div class="small d-flex flex-wrap gap-3"><span><i class="bi bi-square-fill text-success"></i> Available <?= (int)$c['av'] ?></span><span><i class="bi bi-square-fill text-primary"></i> Borrowed <?= (int)$c['bo'] ?></span>
      <span><i class="bi bi-square-fill text-dark"></i> Lost <?= (int)$c['lo'] ?></span><span><i class="bi bi-square-fill text-warning"></i> Damaged <?= (int)$c['da'] ?></span></div></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6 mb-3">Monthly borrowing (last 6 months)</h3>
    <div class="d-flex align-items-end gap-2" style="height:120px">
      <?php foreach ($months as $m => $n): ?><div class="flex-fill text-center"><div class="small fw-semibold"><?= $n ?></div>
        <div class="bg-primary rounded-top" style="height:<?= max(3, round($n / $maxM * 85)) ?>px"></div><div class="small text-muted"><?= e($m) ?></div></div><?php endforeach; ?></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6">Overdue notifications</h3>
    <?php if (!$overdueList) echo $empty('No overdue books.'); foreach ($overdueList as $o): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><div><div class="fw-semibold small"><?= e($o['title']) ?></div><div class="small text-muted"><?= e($o['sname']) ?></div></div><span class="badge text-bg-danger align-self-center"><?= (int)$o['late'] ?> day(s)</span></div><?php endforeach; ?>
    <?php if ($overdue > 5 && is_file(BASE_PATH . '/librarian/overdue.php')): ?><a class="small mt-2" href="<?= e(url('librarian/overdue.php')) ?>">View all <?= $overdue ?> overdue</a><?php endif; ?></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6">Most borrowed books</h3>
    <?php if (!$top) echo $empty('No borrowing yet.'); foreach ($top as $i => $t): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><span class="small"><?= $i + 1 ?>. <?= e($t['title']) ?></span><span class="badge text-bg-light border"><?= (int)$t['n'] ?>×</span></div><?php endforeach; ?></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6">Recent borrowing transactions</h3>
    <?php if (!$recentBorrow) echo $empty('No transactions yet.'); foreach ($recentBorrow as $r): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><div><div class="small fw-semibold"><?= e($r['sname']) ?></div><div class="small text-muted"><?= e($r['transaction_no']) ?> · <?= (int)$r['n'] ?> book(s)</div></div><span class="small text-muted"><?= e(date('M j, g:i A', strtotime($r['borrowed_at']))) ?></span></div><?php endforeach; ?></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6">Recent returns</h3>
    <?php if (!$recentReturn) echo $empty('No returns yet.'); foreach ($recentReturn as $r): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><div><div class="small fw-semibold"><?= e($r['title']) ?></div><div class="small text-muted"><?= e($r['sname']) ?><?= $r['days_overdue'] > 0 ? ' · ' . (int)$r['days_overdue'] . ' day(s) late' : '' ?></div></div><span class="small text-muted"><?= e(date('M j, g:i A', strtotime($r['returned_at']))) ?></span></div><?php endforeach; ?></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6">Recently registered students</h3>
    <?php if (!$newStudents) echo $empty('No students yet.'); foreach ($newStudents as $s): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><a class="small text-decoration-none" href="<?= e(url('librarian/students/view.php?id=' . $s['id'])) ?>"><?= e($s['n']) ?> <span class="text-muted">(<?= e($s['student_id']) ?>)</span></a><span class="small text-muted"><?= e(date('M j', strtotime($s['registered_at']))) ?></span></div><?php endforeach; ?></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100"><h3 class="h6">Recently added books</h3>
    <?php if (!$newBooks) echo $empty('No books yet.'); foreach ($newBooks as $b): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><a class="small text-decoration-none" href="<?= e(url('librarian/books/view.php?id=' . $b['id'])) ?>"><?= e($b['title']) ?> <span class="text-muted">· <?= e($b['author']) ?></span></a><span class="small text-muted"><?= e(date('M j', strtotime($b['created_at']))) ?></span></div><?php endforeach; ?></div></div>
</div>