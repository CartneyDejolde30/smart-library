<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/qr.php';
$user = require_role('librarian', 'admin');
$pdo = db(); $id = (int)($_GET['id'] ?? 0); $errors = [];
$cats = $pdo->query('SELECT id, name FROM book_categories ORDER BY name')->fetchAll();
$book = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM books WHERE id = ? AND is_archived = 0'); $st->execute([$id]); $book = $st->fetch();
    if (!$book) { flash('danger', 'Book not found.'); redirect('librarian/books/index.php'); }
}
$f = $book ?: ['title' => '', 'author' => '', 'isbn' => '', 'publisher' => '', 'publication_year' => '', 'edition' => '', 'category_id' => '', 'description' => ''];
$qty = 1; $shelf = '';
if (is_post()) {
    csrf_require();
    foreach (['title', 'author', 'isbn', 'publisher', 'edition', 'publication_year'] as $k) $f[$k] = clean($_POST[$k] ?? '');
    $f['category_id'] = (int)($_POST['category_id'] ?? 0) ?: '';
    $f['description'] = trim((string)($_POST['description'] ?? ''));
    $qty = (int)($_POST['quantity'] ?? 1); $shelf = clean($_POST['shelf_location'] ?? '');
    if ($f['title'] === '' || mb_strlen($f['title']) > 255) $errors[] = 'Title is required (max 255 characters).';
    if ($f['author'] === '') $errors[] = 'Author is required.';
    if (mb_strlen($f['author']) > 500) $errors[] = 'Author field is too long (max 500 characters). Use "et al." after the main authors.';
    if ($f['publication_year'] !== '' && (!ctype_digit($f['publication_year']) || (int)$f['publication_year'] < 1000 || (int)$f['publication_year'] > (int)date('Y') + 1)) $errors[] = 'Publication year is not valid.';
    if (!$id && ($qty < 1 || $qty > 100)) $errors[] = 'Quantity must be between 1 and 100.';
    if ($f['isbn'] !== '') {
        $d = $pdo->prepare('SELECT id FROM books WHERE isbn = ? AND id <> ? AND is_archived = 0'); $d->execute([$f['isbn'], $id]);
        if ($d->fetch()) $errors[] = 'A book with this ISBN already exists. Open it and add more copies instead.';
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $vals = [$f['isbn'] ?: null, $f['title'], $f['author'], $f['publisher'] ?: null, $f['publication_year'] ?: null, $f['edition'] ?: null, $f['category_id'] ?: null, $f['description'] ?: null];
            if ($id) {
                $pdo->prepare('UPDATE books SET isbn=?, title=?, author=?, publisher=?, publication_year=?, edition=?, category_id=?, description=? WHERE id=?')->execute([...$vals, $id]);
                log_activity('book_update', "Book '{$f['title']}' updated", 'books', $id);
                flash('success', 'Book updated.');
            } else {
                $pdo->prepare('INSERT INTO books (isbn,title,author,publisher,publication_year,edition,category_id,description,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([...$vals, $user['id']]);
                $id = (int)$pdo->lastInsertId();
                create_copies($id, $qty, $shelf);
                log_activity('book_create', "Book '{$f['title']}' added with $qty copy(ies)", 'books', $id);
                flash('success', "Book added with $qty copy(ies) and QR codes.");
            }
            $pdo->commit();
            redirect('librarian/books/view.php?id=' . $id);
        } catch (PDOException $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log($ex->getMessage()); $errors[] = 'A database error occurred. Nothing was saved.'; }
    }
}
$pageTitle = $book ? 'Edit Book' : 'Add Book';
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 p-md-4" style="max-width:900px">
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" novalidate><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-12 col-md-8"><label class="form-label">Title *</label><input class="form-control" name="title" required value="<?= e($f['title']) ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label">ISBN</label><input class="form-control" name="isbn" value="<?= e($f['isbn']) ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label">Author(s) *</label><input class="form-control" name="author" required maxlength="500" placeholder="Separate multiple authors with commas" value="<?= e($f['author']) ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label">Publisher</label><input class="form-control" name="publisher" value="<?= e($f['publisher']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label">Year</label><input class="form-control" name="publication_year" inputmode="numeric" maxlength="4" value="<?= e($f['publication_year']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label">Edition</label><input class="form-control" name="edition" value="<?= e($f['edition']) ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label">Category</label><select class="form-select" name="category_id"><option value="">— None —</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$f['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?= e($f['description']) ?></textarea></div>
    <?php if (!$book): ?>
    <div class="col-6 col-md-3"><label class="form-label">Number of copies *</label><input class="form-control" type="number" name="quantity" min="1" max="100" value="<?= (int)$qty ?>"></div>
    <div class="col-6 col-md-5"><label class="form-label">Shelf location</label><input class="form-control" name="shelf_location" placeholder="e.g. A-3" value="<?= e($shelf) ?>"></div>
    <div class="col-12"><div class="form-text">Each copy gets its own accession number and unique QR code.</div></div>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button>
    <a class="btn btn-light" href="<?= e(url($book ? 'librarian/books/view.php?id=' . $id : 'librarian/books/index.php')) ?>">Cancel</a></div>
</form></div>
<?php include __DIR__ . '/../../includes/footer.php';