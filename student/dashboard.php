<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('student');
$st = db()->prepare('SELECT id FROM students WHERE user_id = ?'); $st->execute([$user['id']]);
$sid = (int)$st->fetchColumn();
$loans = $sid ? db_count("SELECT COUNT(*) FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
                          WHERE bt.student_id=? AND bi.status='borrowed'", [$sid]) : 0;
$pageTitle = 'My Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Welcome, <?= e($user['full_name']) ?>.</p>
<div class="card stat-card" style="max-width:320px">
  <div class="stat-icon"><i class="bi bi-book-half"></i></div>
  <div><div class="stat-value"><?= $loans ?></div><div class="text-muted small">Books currently borrowed</div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php';