<?php
require_once __DIR__ . '/../includes/student_portal.php';
$errors = []; $photoErrors = [];

if (is_post()) {
    csrf_require();
    $act = $_POST['action'] ?? '';
    if ($act === 'photo') {
        try {
            $new = save_image_upload($_FILES['photo'] ?? [], 'students');
            if ($new === null) { $photoErrors[] = 'Choose a photo first.'; }
            else {
                $old = $me['photo'];
                db()->prepare('UPDATE students SET photo = ? WHERE id = ?')->execute([$new, $me['id']]);
                if ($old && is_file(BASE_PATH . '/uploads/students/' . basename($old))) @unlink(BASE_PATH . '/uploads/students/' . basename($old));
                log_activity('student_photo', 'Student updated own profile photo', 'students', (int)$me['id']);
                flash('success', 'Profile picture updated.'); redirect('student/profile.php');
            }
        } catch (RuntimeException $ex) { $photoErrors[] = $ex->getMessage(); }
    } elseif ($act === 'remove_photo') {
        if ($me['photo'] && is_file(BASE_PATH . '/uploads/students/' . basename($me['photo']))) @unlink(BASE_PATH . '/uploads/students/' . basename($me['photo']));
        db()->prepare('UPDATE students SET photo = NULL WHERE id = ?')->execute([$me['id']]);
        log_activity('student_photo', 'Student removed own profile photo', 'students', (int)$me['id']);
        flash('success', 'Profile picture removed.'); redirect('student/profile.php');
    } elseif ($act === 'password') {
        $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
        $h = db()->prepare('SELECT password_hash FROM users WHERE id=?'); $h->execute([$user['id']]);
        if (!password_verify($cur, (string)$h->fetchColumn())) $errors[] = 'Your current password is incorrect.';
        if ($e = password_error($new)) $errors[] = $e; elseif ($new !== $new2) $errors[] = 'New passwords do not match.';
        if (!$errors) {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            log_activity('password_change', 'Student changed own password', 'users', (int)$user['id']);
            flash('success', 'Password changed.'); redirect('student/profile.php');
        }
    }
}
$name = trim($me['first_name'] . ' ' . ($me['middle_name'] ? $me['middle_name'] . ' ' : '') . $me['last_name']);
$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3 p-md-4 mb-3">
      <div class="d-flex gap-3 align-items-center mb-3">
        <?php if ($me['photo']): ?><img class="rounded-3" style="width:88px;height:88px;object-fit:cover" src="<?= e(url('uploads/students/' . $me['photo'])) ?>" alt="My profile picture">
        <?php else: ?><div class="stat-icon" style="width:88px;height:88px"><i class="bi bi-person fs-1"></i></div><?php endif; ?>
        <div><h2 class="h5 mb-1"><?= e($name) ?></h2><?= status_badge($me['account_status']) ?></div>
      </div>
      <dl class="row small mb-0"><?php foreach (['Student ID' => $me['student_id'], 'Gender' => $me['gender'], 'Year Level / Course' => $me['grade_level'] . ($me['section'] ? ' – ' . $me['section'] : ''), 'Contact' => $me['contact_number'], 'Email' => $me['email'], 'Address' => $me['address'], 'Registered' => date('M j, Y', strtotime($me['registered_at']))] as $k => $val): ?>
        <dt class="col-5 col-md-3"><?= $k ?></dt><dd class="col-7 col-md-9"><?= e($val ?: '—') ?></dd><?php endforeach; ?></dl>
      <div class="small text-muted mt-3">To correct any of this information, please ask the librarian.</div>
    </div>
    <div class="card p-3 p-md-4"><h3 class="h6">Profile picture</h3>
      <?php foreach ($photoErrors as $er): ?><div class="alert alert-danger py-2 small"><?= e($er) ?></div><?php endforeach; ?>
      <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="photo">
        <div class="col-12 col-md-8"><label class="form-label small mb-1">Upload a new photo <span class="text-muted">(JPG, PNG or WEBP, max 5 MB)</span></label>
          <input class="form-control" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></div>
        <div class="col-12 col-md-4"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-upload"></i> Upload</button></div></form>
      <?php if ($me['photo']): ?><form method="post" class="mt-2" data-confirm="Remove your profile picture?"><?= csrf_field() ?><input type="hidden" name="action" value="remove_photo"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Remove photo</button></form><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5"><div class="card p-3"><h3 class="h6">Change password</h3>
    <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2 small"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="mb-2"><label class="form-label small">Current password</label><input class="form-control" type="password" name="current" required autocomplete="current-password"></div>
      <div class="mb-2"><label class="form-label small">New password <span class="text-muted">(10+ chars, letters &amp; numbers)</span></label><input class="form-control" type="password" name="new" required autocomplete="new-password"></div>
      <div class="mb-3"><label class="form-label small">Confirm new password</label><input class="form-control" type="password" name="new2" required autocomplete="new-password"></div>
      <button class="btn btn-primary w-100" type="submit">Update password</button></form></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php';