<?php
// One-time setup: creates the first administrator. DELETE THIS FILE afterwards.
require_once __DIR__ . '/includes/auth.php';

$errors = []; $setupError = null; $adminExists = false;
try { $adminExists = db_count("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin'") > 0; }
catch (Throwable $e) { $setupError = 'Database tables not found. Import database/database.sql in phpMyAdmin first.'; }

if (!$setupError && !$adminExists && is_post()) {
    csrf_require();
    $username = clean($_POST['username'] ?? ''); $full = clean($_POST['full_name'] ?? '');
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) $errors[] = 'Username must be 3-50 letters, numbers, dots or underscores.';
    if ($full === '' || mb_strlen($full) > 120) $errors[] = 'Full name is required.';
    if ($e = password_error($pw)) $errors[] = $e;
    if ($pw !== $pw2) $errors[] = 'Passwords do not match.';
    if (!$errors) {
        db()->prepare("INSERT INTO users (role_id, username, password_hash, full_name) VALUES (1,?,?,?)")
            ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $full]);
        log_activity('install', "Initial administrator '$username' created", 'users', (int)db()->lastInsertId(), (int)db()->lastInsertId());
        flash('success', 'Administrator created. Log in, then DELETE install.php from the server.');
        redirect('login.php');
    }
}
$pageTitle = 'Install'; $bare = true;
include __DIR__ . '/includes/header.php';
?>
<div class="card auth-card" style="max-width:480px">
  <h1 class="h4 mb-3"><i class="bi bi-tools me-2"></i>First-time setup</h1>
  <?php if ($setupError): ?><div class="alert alert-danger"><?= e($setupError) ?></div>
  <?php elseif ($adminExists): ?>
    <div class="alert alert-success mb-3">Setup is already complete. <strong>Delete install.php</strong> from the server.</div>
    <a class="btn btn-primary w-100" href="<?= e(url('login.php')) ?>">Go to login</a>
  <?php else: ?>
    <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2 mb-2"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" novalidate><?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Administrator full name</label><input class="form-control" name="full_name" value="<?= old('full_name') ?>" required></div>
      <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" value="<?= old('username') ?>" required autocapitalize="none"></div>
      <div class="mb-3"><label class="form-label">Password <small class="text-muted">(10+ chars, letters &amp; numbers)</small></label><input class="form-control" type="password" name="password" required></div>
      <div class="mb-4"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="password2" required></div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle"></i> Create administrator</button>
    </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php';