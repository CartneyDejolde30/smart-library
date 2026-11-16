<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pagination.php';
$user = require_role('librarian', 'admin');
$q = clean($_GET['q'] ?? ''); $cat = (int)($_GET['category'] ?? 0); $status = $_GET['status'] ?? ''; $sort = $_GET['sort'] ?? 'title';
$sorts = ['title' => 'b.title ASC', 'newest' => 'b.id DESC', 'author' => 'b.author ASC'];
$where = ['b.is_archived = 0']; $p = [];
if ($q !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR EXISTS (SELECT 1 FROM book_copies c WHERE c.book_id=b.id AND (c.accession_number LIKE ? OR c.qr_code_id LIKE ?)))';
    array_push($p, ...array_fill(0, 5, "%$q%"));
}
if ($cat) { $where[] = 'b.category_id = ?'; $p[] = $cat; }
if (in_array($status, ['available', 'borrowed', 'lost', 'damaged'], true)) {
    $where[] = 'EXISTS (SELECT 1 FROM book_copies c WHERE c.book_id=b.id AND c.is_archived=0 AND c.status=?)'; $p[] = $status;
} elseif ($status === 'overdue') {
    $where[] = "EXISTS (SELECT 1 FROM book_copies c JOIN borrowing_items bi ON bi.copy_id=c.id AND bi.status='borrowed' WHERE c.book_id=b.id AND bi.due_date < CURDATE())";
}
$W = implode(' AND ', $where);
$total = db_count("SELECT COUNT(*) FROM books b WHERE $W", $p);
[$page, $pages, $off, $per] = paginate($total);
$st = db()->prepare("SELECT b.*, cat.name AS category,
    (SELECT COUNT(*) FROM book_copies c WHERE c.book_id=b.id AND c.is_archived=0) AS total,
    (SELECT COUNT(*) FROM book_copies c WHERE c.book_id=b.id AND c.is_archived=0 AND c.status='available') AS avail
    FROM books b LEFT JOIN book_categories cat ON cat.id=b.category_id WHERE $W ORDER BY " . ($sorts[$sort] ?? $sorts['title']) . " LIMIT $per OFFSET $off");
$st->execute($p); $books = $st->fetchAll();
$cats = db()->query('SELECT id, name FROM book_categories ORDER BY name')->fetchAll();
$pageTitle = 'Books';
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 mb-3">
  <form class="row g-2" method="get">
    <div class="col-12 col-md-4"><input class="form-control" name="q" placeholder="Search title, author, ISBN, accession, QR…" value="<?= e($q) ?>"></div>
    <div class="col-6 col-md-2"><select class="form-select" name="category"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select class="form-select" name="status"><option value="">Any status</option><?php foreach (['available', 'borrowed', 'overdue', 'lost', 'damaged'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select class="form-select" name="sort"><option value="title">Sort: Title</option><option value="author" <?= $sort === 'author' ? 'selected' : '' ?>>Author</option><option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option></select></div>
    <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i></button><a class="btn btn-light" href="?" title="Reset"><i class="bi bi-x-lg"></i></a></div>
  </form>
</div>
<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small"><?= $total ?> title(s)</span>
  <a class="btn btn-primary btn-sm" href="<?= e(url('librarian/books/form.php')) ?>"><i class="bi bi-plus-lg"></i> Add book</a></div>
<div class="card p-3">
<?php if (!$books): ?><div class="empty-state"><i class="bi bi-book"></i><p class="mt-2 mb-0">No books found.</p></div>
<?php else: ?>
  <table class="table table-cards align-middle mb-0"><thead><tr><th>Title</th><th>Author</th><th>Category</th><th>Available</th><th></th></tr></thead><tbody>
  <?php foreach ($books as $b): ?><tr>
    <td data-label="Title"><a class="fw-semibold text-decoration-none" href="<?= e(url('librarian/books/view.php?id=' . $b['id'])) ?>"><?= e($b['title']) ?></a><div class="small text-muted"><?= e($b['isbn'] ?? '') ?></div></td>
    <td data-label="Author"><?= e($b['author']) ?></td><td data-label="Category"><?= e($b['category'] ?? '—') ?></td>
    <td data-label="Available"><span class="badge text-bg-<?= $b['avail'] > 0 ? 'success' : 'secondary' ?>"><?= (int)$b['avail'] ?> / <?= (int)$b['total'] ?></span></td>
    <td data-label="" class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('librarian/books/view.php?id=' . $b['id'])) ?>"><i class="bi bi-eye"></i></a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('librarian/books/form.php?id=' . $b['id'])) ?>"><i class="bi bi-pencil"></i></a></td>
  </tr><?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../../includes/footer.php';