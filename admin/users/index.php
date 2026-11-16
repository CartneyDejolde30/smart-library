<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pagination.php';
$me = require_role('admin');
$pdo = db();

/** True if at least one OTHER active admin exists. */
function other_active_admin(int $exceptId): bool
{
    return db_count("SELECT COUNT(*) FROM users WHERE role_id = 1 AND status = 'active' AND id <> ?", [$exceptId]) > 0;
}

if (is_post()) {
    csrf_require();
    $act = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND role_id IN (1,2)'); $st->execute([$id]); $t = $st->fetch();
    if (!$t) { flash('danger', 'User not found.'); redirect('admin/users/index.php'); }
    if ($id === (int)$me['id']) { flash('danger', 'You cannot change or delete your own account from this list.'); redirect('admin/users/index.php'); }

    if ($act === 'toggle') {
        $new = $t['status'] === 'active' ? 'inactive' : 'active';
        if ($new === 'inactive' && $t['role_id'] == 1 && !other_active_admin($id)) flash('danger', 'Cannot deactivate the last active administrator.');
        else {
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $id]);
            log_activity('user_status', "User '{$t['username']}' set to $new", 'users', $id);
            flash('success', "Account '{$t['username']}' is now $new.");
        }
    } elseif ($act === 'delete') {
        if ($t['role_id'] == 1 && !other_active_admin($id)) flash('danger', 'Cannot delete the last active administrator.');
        else {
            try {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                log_activity('user_delete', "User '{$t['username']}' deleted", 'users', $id);
                flash('success', "User '{$t['username']}' deleted.");
            } catch (PDOException $e) {
                // Foreign keys protect transaction history: users who processed transactions cannot be erased.
                flash('warning', "'{$t['username']}' has transaction history and cannot be deleted. Deactivate the account instead.");
            }
        }
    }
    redirect('admin/users/index.php');
}

$q = clean($_GET['q'] ?? ''); $role = (int)($_GET['role'] ?? 0); $stat = $_GET['status'] ?? '';
$where = ['u.role_id IN (1,2)']; $p = [];
if ($q !== '') { $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
if (in_array($role, [1, 2], true)) { $where[] = 'u.role_id = ?'; $p[] = $role; }
if (in_array($stat, ['active', 'inactive'], true)) { $where[] = 'u.status = ?'; $p[] = $stat; }
$W = implode(' AND ', $where);
$total = db_count("SELECT COUNT(*) FROM users u WHERE $W", $p);
[$page, $pages, $off, $per] = paginate($total);
$st = $pdo->prepare("SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE $W ORDER BY u.status, u.full_name LIMIT $per OFFSET $off");
$st->execute($p); $rows = $st->fetchAll();

$pageTitle = 'User Accounts';
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 mb-3"><form class="row g-2" method="get">
  <div class="col-12 col-md-5"><input class="form-control" name="q" placeholder="Search name, username or email…" value="<?= e($q) ?>"></div>
  <div class="col-6 col-md-2"><select class="form-select" name="role"><option value="">All roles</option><option value="1" <?= $role === 1 ? 'selected' : '' ?>>Admin</option><option value="2" <?= $role === 2 ? 'selected' : '' ?>>Librarian</option></select></div>
  <div class="col-6 col-md-2"><select class="form-select" name="status"><option value="">Any status</option><option value="active" <?= $stat === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $stat === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
  <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i></button><a class="btn btn-light" href="?"><i class="bi bi-x-lg"></i></a></div>
</form></div>
<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small"><?= $total ?> account(s) · student accounts are managed under Students</span>
  <a class="btn btn-primary btn-sm" href="<?= e(url('admin/users/form.php')) ?>"><i class="bi bi-person-plus"></i> Add user</a></div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-person-gear"></i><p class="mt-2 mb-0">No users found.</p></div><?php else: ?>
<table class="table table-cards align-middle mb-0"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last login</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $u): $self = (int)$u['id'] === (int)$me['id']; ?><tr>
  <td data-label="Name"><strong><?= e($u['full_name']) ?></strong><?= $self ? ' <span class="badge text-bg-light border">You</span>' : '' ?><div class="small text-muted"><?= e($u['email'] ?? '') ?></div></td>
  <td data-label="Username"><?= e($u['username']) ?></td>
  <td data-label="Role"><span class="badge text-bg-<?= $u['role'] === 'admin' ? 'primary' : 'info' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
  <td data-label="Last login"><?= $u['last_login_at'] ? e(date('M j, Y g:i A', strtotime($u['last_login_at']))) : '<span class="text-muted">Never</span>' ?></td>
  <td data-label="Status"><?= status_badge($u['status']) ?></td>
  <td data-label="" class="text-end text-nowrap">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/users/form.php?id=' . $u['id'])) ?>" title="Edit / reset password"><i class="bi bi-pencil"></i></a>
    <?php if (!$self): ?>
    <form method="post" class="d-inline" data-confirm="<?= $u['status'] === 'active' ? 'Deactivate this account? They will be unable to log in.' : 'Reactivate this account?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <button class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'warning' : 'success' ?>" type="submit" title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-power"></i></button></form>
    <form method="post" class="d-inline" data-confirm="Permanently delete user '<?= e($u['username']) ?>'? Accounts with transaction history cannot be deleted."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button></form>
    <?php endif; ?>
  </td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?= pager($page, $pages) ?>
<?php include __DIR__ . '/../../includes/footer.php';