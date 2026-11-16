<?php
require_once __DIR__ . '/../../includes/auth.php';
$me = require_role('admin');
$pdo = db(); $id = (int)($_GET['id'] ?? 0); $errors = [];
$u = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND role_id IN (1,2)'); $st->execute([$id]); $u = $st->fetch();
    if (!$u) { flash('danger', 'User not found.'); redirect('admin/users/index.php'); }
}
$isSelf = $u && (int)$u['id'] === (int)$me['id'];
$f = $u ?: ['username' => '', 'full_name' => '', 'email' => '', 'contact_number' => '', 'role_id' => 2, 'status' => 'active'];

if (is_post()) {
    csrf_require();
    $f['username'] = clean($_POST['username'] ?? ''); $f['full_name'] = clean($_POST['full_name'] ?? '');
    $f['email'] = clean($_POST['email'] ?? ''); $f['contact_number'] = clean($_POST['contact_number'] ?? '');
    $f['role_id'] = $isSelf ? (int)$u['role_id'] : (int)($_POST['role_id'] ?? 2);       // cannot change own role/status
    $f['status'] = $isSelf ? 'active' : (($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active');
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $f['username'])) $errors[] = 'Username must be 3-50 letters, numbers, dots or underscores.';
    if ($f['full_name'] === '' || mb_strlen($f['full_name']) > 120) $errors[] = 'Full name is required.';
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if (!in_array($f['role_id'], [1, 2], true)) $errors[] = 'Select a valid role.';
    $d = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?'); $d->execute([$f['username'], $id]);
    if ($d->fetch()) $errors[] = 'That username is already taken.';
    if ($f['email'] !== '') { $d = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?'); $d->execute([$f['email'], $id]); if ($d->fetch()) $errors[] = 'That email is already used by another account.'; }
    if (!$u || $pw !== '') { if ($e = password_error($pw)) $errors[] = $e; elseif ($pw !== $pw2) $errors[] = 'Passwords do not match.'; }
    if ($u && $u['role_id'] == 1 && ($f['role_id'] != 1 || $f['status'] === 'inactive')
        && db_count("SELECT COUNT(*) FROM users WHERE role_id=1 AND status='active' AND id<>?", [$id]) < 1) $errors[] = 'You cannot demote or deactivate the last active administrator.';

    if (!$errors) {
        try {
            if ($u) {
                $pdo->prepare('UPDATE users SET username=?, full_name=?, email=?, contact_number=?, role_id=?, status=? WHERE id=?')
                    ->execute([$f['username'], $f['full_name'], $f['email'] ?: null, $f['contact_number'] ?: null, $f['role_id'], $f['status'], $id]);
                log_activity('user_update', "User '{$f['username']}' updated", 'users', $id);
                if ($pw !== '') {
                    $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                    log_activity('password_reset', "Password reset for '{$f['username']}'", 'users', $id);
                }
                flash('success', 'User updated.');
            } else {
                $pdo->prepare('INSERT INTO users (role_id, username, email, password_hash, full_name, contact_number, status) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$f['role_id'], $f['username'], $f['email'] ?: null, password_hash($pw, PASSWORD_DEFAULT), $f['full_name'], $f['contact_number'] ?: null, $f['status']]);
                log_activity('user_create', "User '{$f['username']}' created", 'users', (int)$pdo->lastInsertId());
                flash('success', 'User created.');
            }
            redirect('admin/users/index.php');
        } catch (PDOException $ex) { error_log($ex->getMessage()); $errors[] = 'A database error occurred. Nothing was saved.'; }
    }
}
$acts = [];
if ($u) { $a = $pdo->prepare("SELECT action, description, ip_address, created_at FROM activity_logs WHERE user_id = ? AND action IN ('login','logout','login_failed') ORDER BY id DESC LIMIT 10"); $a->execute([$id]); $acts = $a->fetchAll(); }

$pageTitle = $u ? 'Edit User' : 'Add User';
include __DIR__ . '/../../includes/header.php';
?>
<div class="row g-3">
<div class="col-lg-7"><div class="card p-3 p-md-4">
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" novalidate><?= csrf_field() ?><div class="row g-3">
  <div class="col-12"><label class="form-label">Full name *</label><input class="form-control" name="full_name" required value="<?= e($f['full_name']) ?>"></div>
  <div class="col-12 col-md-6"><label class="form-label">Username *</label><input class="form-control" name="username" required autocapitalize="none" value="<?= e($f['username']) ?>"></div>
  <div class="col-12 col-md-6"><label class="form-label">Role *</label>
    <select class="form-select" name="role_id" <?= $isSelf ? 'disabled' : '' ?>><option value="2" <?= (int)$f['role_id'] === 2 ? 'selected' : '' ?>>Librarian</option><option value="1" <?= (int)$f['role_id'] === 1 ? 'selected' : '' ?>>Administrator</option></select></div>
  <div class="col-12 col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= e($f['email'] ?? '') ?>"></div>
  <div class="col-12 col-md-6"><label class="form-label">Contact number</label><input class="form-control" name="contact_number" value="<?= e($f['contact_number'] ?? '') ?>"></div>
  <?php if ($u && !$isSelf): ?><div class="col-12 col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $f['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div><?php endif; ?>
  <div class="col-12"><hr class="my-1"><div class="small text-muted"><?= $u ? 'Reset password (leave blank to keep the current one)' : 'Set an initial password (10+ characters, letters and numbers)' ?></div></div>
  <div class="col-12 col-md-6"><label class="form-label"><?= $u ? 'New password' : 'Password *' ?></label><input class="form-control" type="password" name="password" autocomplete="new-password"></div>
  <div class="col-12 col-md-6"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="password2" autocomplete="new-password"></div>
</div>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button><a class="btn btn-light" href="<?= e(url('admin/users/index.php')) ?>">Cancel</a></div>
</form></div></div>
<?php if ($u): ?><div class="col-lg-5"><div class="card p-3"><h3 class="h6">Recent login activity</h3>
<?php if (!$acts): ?><div class="empty-state py-3"><i class="bi bi-clock-history"></i><p class="mb-0 mt-2">No login activity recorded.</p></div><?php else: ?>
<ul class="list-unstyled small mb-0"><?php foreach ($acts as $a): ?><li class="d-flex justify-content-between border-bottom py-2"><span><?= e(str_replace('_', ' ', $a['action'])) ?> <span class="text-muted">· <?= e($a['ip_address']) ?></span></span><span class="text-muted"><?= e(date('M j, g:i A', strtotime($a['created_at']))) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
<div class="small text-muted mt-3">Last login: <?= $u['last_login_at'] ? e(date('M j, Y g:i A', strtotime($u['last_login_at']))) : 'Never' ?></div></div></div><?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php';