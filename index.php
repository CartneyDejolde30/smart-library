<?php
require_once __DIR__ . '../includes/auth.php';
$user = require_role('admin');
$pdo = db(); $errors = [];
$editId = (int)($_GET['edit'] ?? 0);

if (is_post()) {
    csrf_require();
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = clean($_POST['name'] ?? ''); $desc = clean($_POST['description'] ?? '');
    if ($act === 'save') {
        if ($name === '' || mb_strlen($name) > 80) $errors[] = 'Category name is required (max 80 characters).';
        $d = $pdo->prepare('SELECT id FROM book_categories WHERE name = ? AND id <> ?'); $d->execute([$name, $id]);
        if ($d->fetch()) $errors[] = 'A category with that name already exists.';
        if (!$errors) {
            if ($id) { $pdo->prepare('UPDATE book_categories SET name=?, description=? WHERE id=?')->execute([$name, $desc ?: null, $id]); log_activity('category_update', "Category '$name' updated", 'book_categories', $id); flash('success', 'Category updated.'); }
            else { $pdo->prepare('INSERT INTO book_categories (name, description) VALUES (?,?)')->execute([$name, $desc ?: null]); log_activity('category_create', "Category '$name' created", 'book_categories', (int)$pdo->lastInsertId()); flash('success', 'Category added.'); }
            redirect('admin/categories/index.php');
        }
        $editId = $id;
    } elseif ($act === 'delete') {
        if (db_count('SELECT COUNT(*) FROM books WHERE category_id = ?', [$id]) > 0) flash('danger', 'Cannot delete: books are using this category.');
        else { $pdo->prepare('DELETE FROM book_categories WHERE id = ?')->execute([$id]); log_activity('category_delete', "Category #$id deleted", 'book_categories', $id); flash('success', 'Category deleted.'); }
        redirect('admin/categories/index.php');
    }
}
$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM books b WHERE b.category_id=c.id AND b.is_archived=0) AS n FROM book_categories c ORDER BY c.name')->fetchAll();
$edit = null; foreach ($cats as $c) if ($c['id'] == $editId) $edit = $c;
$pageTitle = 'Book Categories';
include __DIR__ . '/../../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card p-3">
    <h2 class="h6 mb-3"><?= $edit ? 'Edit category' : 'Add category' ?></h2>
    <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required maxlength="80" value="<?= e($_POST['name'] ?? $edit['name'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255" value="<?= e($_POST['description'] ?? $edit['description'] ?? '') ?>"></div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2"></i> Save</button>
      <?php if ($edit): ?><a class="btn btn-light w-100 mt-2" href="?">Cancel</a><?php endif; ?>
    </form></div></div>
  <div class="col-lg-8"><div class="card p-3">
    <?php if (!$cats): ?><div class="empty-state"><i class="bi bi-tags"></i><p class="mb-0 mt-2">No categories yet.</p></div>
    <?php else: ?><table class="table table-cards align-middle mb-0"><thead><tr><th>Name</th><th>Description</th><th>Books</th><th class="text-end">Actions</th></tr></thead><tbody>
      <?php foreach ($cats as $c): ?><tr>
        <td data-label="Name"><strong><?= e($c['name']) ?></strong></td><td data-label="Description"><?= e($c['description'] ?? '—') ?></td><td data-label="Books"><?= (int)$c['n'] ?></td>
        <td data-label="Actions" class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$c['id'] ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" data-confirm="Delete category '<?= e($c['name']) ?>'?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button></form>
        </td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  </div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php';