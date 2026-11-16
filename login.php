<?php
require_once __DIR__ . '/includes/auth.php';

if ($u = current_user()) redirect(role_home($u['role']));

$error = null; $username = '';
if (is_post()) {
    csrf_require();
    $username = clean($_POST['username'] ?? '');
    $error = attempt_login($username, (string)($_POST['password'] ?? ''));
    if ($error === null) redirect(role_home($_SESSION['user']['role']));
}
$pageTitle = 'Login'; $bare = true;
include __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
  <img class="auth-seal" src="<?= e(school_logo_url()) ?>" alt="School seal">
  <h1 class="h4 text-center mb-1"><?= e(setting('library_name', APP_NAME)) ?></h1>
  <p class="text-center text-muted small mb-4"><?= e(setting('school_name')) ?></p>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="username">Username</label>
      <input class="form-control" id="username" name="username" value="<?= e($username) ?>" required autofocus autocapitalize="none"></div>
    <div class="mb-4"><label class="form-label" for="password">Password</label>
      <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
  </form>
  <p class="text-center text-muted small mt-3 mb-0">Students use the username and password issued by the library.</p>
</div>
<?php include __DIR__ . '/includes/footer.php';